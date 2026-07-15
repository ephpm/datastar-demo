<?php

/**
 * Pixelboard — a multiplayer realtime Datastar demo on ePHPm worker mode.
 *
 * One PHP script is the whole backend. It boots ONCE per worker thread and
 * then loops over requests via the ePHPm worker primitives:
 *
 *   \Ephpm\Worker\take_request(): ?\Ephpm\Worker\Envelope
 *   \Ephpm\Worker\send_response(int $status, array $headers, string $body): void
 *   \Ephpm\Worker\send_response_stream(int $status, array $headers, $bodyResource): void
 *
 * Routes (ePHPm worker mode sends every non-static request here):
 *
 *   GET  /            full page (Datastar CDN + data-* attributes)
 *   GET  /sse         long-lived SSE stream (datastar-patch-* events)
 *   POST /tap         increment the shared counter
 *   POST /paint?i=&c= paint one pixel (i = cell index, c = palette index)
 *   GET  /healthz     liveness probe
 *
 * Shared state lives in ePHPm's native in-process KV store (ephpm_kv_*
 * functions — the same store all worker threads see):
 *
 *   board:ver     monotonically increasing state version (any mutation bumps it)
 *   board:count   the shared tap counter
 *   board:online  connected SSE clients (incr on connect, decr on disconnect)
 *   board:px:<i>  palette index of pixel i (absent = background)
 *
 * The SSE loop is push-driven when the server provides ephpm_kv_wait()
 * (ePHPm > v0.5.0): each connected client's worker BLOCKS on board:ver and
 * wakes sub-millisecond on any mutation, with zero idle CPU. On older
 * servers it degrades to the original version-poll loop (board:ver every
 * POLL_US microseconds) — feature-detected via function_exists(). Either
 * way, a change triggers a grid re-render + signals re-send ("fat
 * re-render" — Datastar morphs it into the DOM by element id). See
 * docs/ephpm-integration-spec.md for the analysis that led to the
 * ephpm_kv_wait() and streaming-brotli ePHPm PRs.
 *
 * CONSTRAINT (verified in ephpm source, see the spec doc): one SSE
 * connection occupies one worker thread for its whole lifetime, so
 * [php] worker_count in ephpm.toml is the max concurrent SSE clients
 * (plus headroom for the short-lived /tap & /paint requests).
 */

declare(strict_types=1);

const GRID_W  = 12;
const GRID_H  = 12;
const CELLS   = GRID_W * GRID_H;
const POLL_US = 100_000;          // SSE version-poll interval: 100 ms
                                  // (fallback only — unused when the server
                                  // provides ephpm_kv_wait())
const KEEPALIVE_S = 15.0;         // SSE comment ping — must stay well under
                                  // ephpm's [server.timeouts] idle (60 s)

/** Palette (index = wire value; keeps '#' out of query strings). */
const PALETTE = [
    '#18181b', // 0 near-black
    '#ffffff', // 1 white
    '#e11d48', // 2 rose
    '#f59e0b', // 3 amber
    '#22c55e', // 4 green
    '#0ea5e9', // 5 sky
    '#8b5cf6', // 6 violet
    '#f472b6', // 7 pink
];
const BG = '#27272a';             // unpainted cell

// ── KV helpers ───────────────────────────────────────────────────────
// The ephpm_kv_* functions are provided natively by the ePHPm SAPI
// (crates/ephpm-php/ephpm_wrapper.c). Thin wrappers with fallbacks so a
// missing store degrades to an explicit error instead of a fatal.

function kv_available(): bool
{
    return \function_exists('ephpm_kv_get');
}

function kv_int(string $key): int
{
    $v = \ephpm_kv_get($key);
    return $v === null ? 0 : (int) $v;
}

/** Bump the global state version — every mutation calls this. */
function bump_version(): void
{
    \ephpm_kv_incr('board:ver');
}

// ── Rendering ────────────────────────────────────────────────────────

/**
 * Render the pixel grid as ONE line of HTML (no newlines — that keeps the
 * SSE framing to a single `data: elements ...` line).
 */
function render_grid(): string
{
    $html = '<div id="grid">';
    for ($i = 0; $i < CELLS; $i++) {
        $c   = \ephpm_kv_get('board:px:' . $i);
        $bgc = ($c !== null && isset(PALETTE[(int) $c])) ? PALETTE[(int) $c] : BG;
        $html .= '<button id="c-' . $i . '" style="background:' . $bgc . '"'
               . ' data-on:click="@post(\'/paint?i=' . $i . '&amp;c=\' + $color)"></button>';
    }
    return $html . '</div>';
}

/** One Datastar SSE event. $dataLines are the raw `data:` payloads. */
function sse_event(string $event, array $dataLines): string
{
    $out = "event: {$event}\n";
    foreach ($dataLines as $line) {
        $out .= "data: {$line}\n";
    }
    return $out . "\n";
}

/** Signals patch (count + online) for the current KV state. */
function sse_signals(): string
{
    $json = \json_encode([
        'count'  => kv_int('board:count'),
        'online' => kv_int('board:online'),
    ]);
    return sse_event('datastar-patch-signals', ['signals ' . $json]);
}

/** Full snapshot: signals + grid morph. */
function sse_snapshot(): string
{
    return sse_signals()
         . sse_event('datastar-patch-elements', ['elements ' . render_grid()]);
}

function render_page(): string
{
    $count  = kv_available() ? kv_int('board:count') : 0;
    $online = kv_available() ? kv_int('board:online') : 0;
    $grid   = kv_available() ? render_grid() : '<div id="grid"></div>';
    $kvWarn = kv_available() ? ''
        : '<p class="warn">ephpm_kv_* functions unavailable — is this a stub build?</p>';

    $palette = '';
    foreach (PALETTE as $idx => $hex) {
        $palette .= '<button class="swatch" style="background:' . $hex . '"'
                  . ' data-on:click="$color = ' . $idx . '"'
                  . ' data-class:sel="$color == ' . $idx . '"></button>';
    }

    $cols = GRID_W;
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pixelboard — Datastar on ePHPm</title>
<script type="module" src="https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.2/bundles/datastar.js"></script>
<style>
  :root { color-scheme: dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; background: #09090b;
         color: #e4e4e7; max-width: 640px; margin: 2rem auto; padding: 0 1rem; }
  h1 { font-size: 1.3rem; } h1 small { color: #71717a; font-weight: normal; }
  .stats { display: flex; gap: 1.5rem; align-items: center; margin: 1rem 0; }
  .stats b { font-variant-numeric: tabular-nums; color: #fafafa; }
  button.tap { background: #e11d48; color: white; border: 0; border-radius: 8px;
               padding: .5rem 1.2rem; font-size: 1rem; cursor: pointer; }
  button.tap:active { transform: scale(.96); }
  .palette { display: flex; gap: .4rem; margin: 1rem 0; }
  .swatch { width: 28px; height: 28px; border-radius: 6px; border: 2px solid transparent;
            cursor: pointer; }
  .swatch.sel { border-color: #fafafa; }
  #grid { display: grid; grid-template-columns: repeat({$cols}, 1fr); gap: 3px;
          touch-action: manipulation; }
  #grid button { aspect-ratio: 1; border: 0; border-radius: 3px; cursor: crosshair;
                 padding: 0; }
  .warn { color: #f59e0b; }
  footer { margin-top: 2rem; color: #52525b; font-size: .8rem; }
</style>
</head>
<body data-signals='{"count": {$count}, "online": {$online}, "color": 2}'
      data-init="@get('/sse')">
  <h1>Pixelboard <small>— Datastar × ePHPm worker mode + native KV</small></h1>
  {$kvWarn}
  <div class="stats">
    <span>online <b data-text="\$online"></b></span>
    <span>taps <b data-text="\$count"></b></span>
    <button class="tap" data-on:click="@post('/tap')">Tap</button>
  </div>
  <div class="palette">{$palette}</div>
  {$grid}
  <footer>Every browser tab holds one SSE stream; state lives in ePHPm's
  in-process KV store; updates are fat re-renders morphed by Datastar.</footer>
</body>
</html>
HTML;
}

// ── SSE stream (userland stream wrapper) ─────────────────────────────
// \Ephpm\Worker\send_response_stream() pumps any readable PHP stream to the
// client in chunks (headers first, then each read() result — verified in
// crates/ephpm-php/ephpm_wrapper.c PHP_FUNCTION(ephpm_worker_send_response_stream)).
// A userland stream wrapper turns that pull-based pump into a push-style SSE
// generator: stream_read() BLOCKS until it has an event (or a keepalive) and
// never returns '' — an empty read would end the pump.

final class SseStream
{
    /** @var resource|null set by PHP for wrapper instances */
    public $context;

    private int $lastVer = -1;          // poll fallback: -1 forces first snapshot
    private int $watchVer = 0;          // ephpm_kv_wait protocol: 0 = register+snapshot
    private float $lastWrite = 0.0;
    private string $buf = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->lastWrite = \microtime(true);
        return true;
    }

    public function stream_read(int $count): string
    {
        // Drain any event bytes left over from a previous short read first.
        if ($this->buf !== '') {
            $out = \substr($this->buf, 0, $count);
            $this->buf = (string) \substr($this->buf, $count);
            return $out;
        }

        // Client disconnects are detected by ePHPm on WRITE (response_chunk
        // fails and the pump stops), so the keepalive path below also bounds
        // how long a dead client can keep this worker (<= KEEPALIVE_S).
        return \function_exists('ephpm_kv_wait')
            ? $this->readWait($count)
            : $this->readPoll($count);
    }

    /**
     * Push path (ePHPm with ephpm_kv_wait): BLOCK on board:ver until it is
     * written, or until the keepalive budget runs out. Zero CPU while idle;
     * wakeup latency is sub-millisecond instead of the poll interval.
     *
     * Protocol: the first call passes version 0, which registers the watch
     * and returns the current version immediately (race-free snapshot);
     * every later call passes the version the previous wakeup returned.
     */
    private function readWait(int $count): string
    {
        $budgetMs = (int) \max(1.0, (KEEPALIVE_S - (\microtime(true) - $this->lastWrite)) * 1000.0);
        $r = \ephpm_kv_wait('board:ver', $this->watchVer, $budgetMs);
        if ($r === false) {                     // keepalive tick
            $this->lastWrite = \microtime(true);
            return ": keepalive\n\n";
        }
        $this->watchVer = (int) $r['version'];
        return $this->emitSnapshot($count);
    }

    /** Poll fallback (ePHPm <= v0.5.0): the original 100 ms version poll. */
    private function readPoll(int $count): string
    {
        while (true) {
            $ver = kv_int('board:ver');
            if ($ver !== $this->lastVer) {
                $this->lastVer = $ver;
                return $this->emitSnapshot($count);
            }
            if (\microtime(true) - $this->lastWrite >= KEEPALIVE_S) {
                $this->lastWrite = \microtime(true);
                return ": keepalive\n\n";
            }
            \usleep(POLL_US);
        }
    }

    /** Render a full snapshot into the buffer and return the first chunk. */
    private function emitSnapshot(int $count): string
    {
        $this->buf = sse_snapshot();
        $this->lastWrite = \microtime(true);
        $out = \substr($this->buf, 0, $count);
        $this->buf = (string) \substr($this->buf, $count);
        return $out;
    }

    public function stream_eof(): bool
    {
        return false; // the stream ends when the client goes away, not by EOF
    }

    public function stream_close(): void {}

    /** @return array|false */
    public function stream_stat()
    {
        return false;
    }
}

\stream_wrapper_register('ephpmsse', SseStream::class);

function handle_sse(): void
{
    if (!kv_available()) {
        \Ephpm\Worker\send_response(503, ['Content-Type' => 'text/plain'], "KV store unavailable\n");
        return;
    }

    // Presence: this connection = one online client. The decrement runs when
    // send_response_stream returns, i.e. when ePHPm aborts the pump because
    // the client disconnected (or stalled past the idle timeout).
    \ephpm_kv_incr('board:online');
    bump_version();

    $stream = \fopen('ephpmsse://events', 'rb');

    \Ephpm\Worker\send_response_stream(
        200,
        [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-store',
            'X-Accel-Buffering' => 'no',
        ],
        $stream
    );

    \fclose($stream);
    \ephpm_kv_decr('board:online');
    bump_version();
}

// ── Actions ──────────────────────────────────────────────────────────
// Mutations return 204: the client's own /sse stream (and everyone else's)
// delivers the resulting UI update, so state has a single source of truth.

function handle_tap(): void
{
    \ephpm_kv_incr('board:count');
    bump_version();
    \Ephpm\Worker\send_response(204, [], '');
}

function handle_paint(array $query): void
{
    $i = isset($query['i']) ? (int) $query['i'] : -1;
    $c = isset($query['c']) ? (int) $query['c'] : -1;
    if ($i < 0 || $i >= CELLS || !isset(PALETTE[$c])) {
        \Ephpm\Worker\send_response(400, ['Content-Type' => 'text/plain'], "bad pixel\n");
        return;
    }
    \ephpm_kv_set('board:px:' . $i, (string) $c);
    bump_version();
    \Ephpm\Worker\send_response(204, [], '');
}

// ── Boot-once + request loop ─────────────────────────────────────────

\error_log('[pixelboard] worker booted');

while (($envelope = \Ephpm\Worker\take_request()) !== null) {
    $server = $envelope->serverVars();
    $method = \strtoupper($server['REQUEST_METHOD'] ?? 'GET');
    $uri    = $server['REQUEST_URI'] ?? '/';
    $path   = \strtok($uri, '?') ?: '/';

    match (true) {
        $path === '/' && $method === 'GET'
            => \Ephpm\Worker\send_response(200, ['Content-Type' => 'text/html; charset=utf-8'], render_page()),
        $path === '/sse' && $method === 'GET'
            => handle_sse(),
        $path === '/tap' && $method === 'POST'
            => handle_tap(),
        $path === '/paint' && $method === 'POST'
            => handle_paint($envelope->query()),
        $path === '/healthz'
            => \Ephpm\Worker\send_response(200, ['Content-Type' => 'text/plain'], "ok\n"),
        default
            => \Ephpm\Worker\send_response(404, ['Content-Type' => 'text/plain'], "not found\n"),
    };
}

\error_log('[pixelboard] worker loop ended');
