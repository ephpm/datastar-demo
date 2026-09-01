# Pixelboard — Datastar on ePHPm

> **Note (2026-08):** this demo was recorded against ePHPm **v0.5.0**; current
> ePHPm is **v0.8.6**. The compose pin is kept at v0.5.0 so the validated
> behaviour stays reproducible. Two of the "honest limitations" below have
> since shipped fixes in ePHPm — see the notes inline.

A multiplayer realtime demo: a shared tap counter, a live presence count,
and a collaborative 12×12 pixel grid — synced across every open browser tab
over Server-Sent Events. The whole backend is **one PHP file** running on
[ePHPm](https://github.com/ephpm/ephpm) (a single-binary Rust application
server that embeds PHP — no php-fpm, no Redis, no Node), driven by
[Datastar](https://data-star.dev) v1 (~12 KB of JS, no build step).

```
   Browser tab A ──┐                       ┌─► worker thread (SSE loop A)
   Browser tab B ──┤  GET /sse (SSE) ──────┼─► worker thread (SSE loop B)
   Browser tab C ──┘                       └─► worker thread (SSE loop C)
        │                                        ▲        poll board:ver
        │ @post('/tap') / @post('/paint')        │             │
        └────────► worker thread ── ephpm_kv_incr/set ──► native KV store
                                                  (one process, shared state)
```

- **ePHPm worker mode** — `public/worker.php` boots once per worker thread,
  then loops over requests. The SSE endpoint uses
  `\Ephpm\Worker\send_response_stream()` to push chunks as they're produced.
- **ePHPm native KV** — shared state (`ephpm_kv_*` functions) visible to all
  worker threads, no external store.
- **Datastar** — the server pushes `datastar-patch-elements` /
  `datastar-patch-signals` SSE events; the client morphs them into the DOM
  by id. Updates are "fat re-renders" of the whole grid.

## Run it

```sh
docker compose up          # or: podman compose up
# open http://localhost:8080 in two or more tabs
```

That's it — the stack is one container
(`docker.io/ephpm/ephpm:v0.5.0-php8.4`) with `public/` and `ephpm.toml`
mounted.

### Poke it from the CLI

```sh
curl -N http://localhost:8080/sse            # watch the event stream
curl -X POST http://localhost:8080/tap       # every open tab updates
curl -X POST "http://localhost:8080/paint?i=5&c=4"   # paint cell 5 green
```

Load-test the action path (each tap broadcasts to every connected client):

```sh
oha -z 10s -m POST http://localhost:8080/tap
```

## Files

| File | Purpose |
|---|---|
| `public/worker.php` | The entire backend: routing, page render, SSE stream, actions |
| `ephpm.toml` | Tuned ePHPm config — every knob commented with *why* |
| `compose.yaml` | One-command run against the published ePHPm image |
| `docs/ephpm-integration-spec.md` | Verified findings on ePHPm's streaming/compression/KV internals + proposed ePHPm PRs |
| `docs/wordpress-feasibility.md` | Can WordPress be the flashy demo? (verdict: hybrid, later) |

## Upstream upgrades (ePHPm > v0.5.0)

The two ePHPm PRs this repo's spec doc proposed have landed upstream, and
this demo uses both when available:

- **Push, not poll — `ephpm_kv_wait()`.** `worker.php` feature-detects the
  function (`function_exists`) and switches the SSE loop from a 100 ms
  version poll to a blocking wait on `board:ver`. Measured on a local
  release build (WSL2, same box, same binary, poll vs wait `worker.php`,
  8 idle SSE clients over 60 s): the poll loop burned **0.35% of one core**
  at idle (0.21 cpu-s) vs **0.02%** (0.01 cpu-s) for the wait loop, and
  paint→event first-byte latency (including the POST round-trip) dropped
  from **p50 100.4 ms** (the poll-interval floor) to **p50 1.2 ms**. On a
  v0.5.0 image the demo behaves exactly as before.
- **Streaming brotli for the SSE stream** — uncomment
  `compression_streaming = "sse"` in `ephpm.toml` on a server that supports
  it. One brotli encoder per connection, flushed per event, window shared
  across the stream: a 60 s pixelboard session (120 paints, one fat
  ~15.6 KB signals+grid re-render each) went from **1,889,173 wire bytes**
  (~15.6 KB/event) to **4,504 wire bytes** (~37 B/event) — a **~420×**
  reduction, every event still decodable the instant it arrives. Verify
  yourself with `curl -N --compressed http://localhost:8080/sse`.

## Honest limitations (verified, see the spec doc)

1. **SSE requires worker mode.** ePHPm's fpm/drop-in mode buffers the whole
   response (`flush()` is a SAPI no-op) — a plain `sse.php` cannot stream.
   This demo is worker-mode by necessity, not preference.
2. **One SSE connection parks one worker thread.** `worker_count` in
   `ephpm.toml` (16 here) is the ceiling on concurrent viewers; actions
   compete for the remaining threads. Fine for a demo and small deployments;
   the render-once/fan-out SSE hub that lifts this is on the ePHPm roadmap
   (`site/content/roadmap/sse-realtime.md`, targeted v0.6.0).
