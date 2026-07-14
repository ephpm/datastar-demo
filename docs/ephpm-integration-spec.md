# Datastar on ePHPm — integration findings & proposed ePHPm PRs

Engineering spec for running Datastar (data-star.dev, v1.0.2) on ePHPm.
All claims below were verified against the ePHPm source tree (v0.5.0-era
`main`, July 2026) and validated live against the published
`docker.io/ephpm/ephpm:v0.5.0-php8.4` image with the Pixelboard demo in this
repo. File references are to the ephpm repository.

**Verdict up front: ePHPm needs NO code changes to run a real Datastar app
today** — worker mode's streaming response path carries long-lived SSE
correctly. Three PRs would make it *better* (ranked in §5).

---

## 1. fpm/drop-in mode cannot stream — SSE requires worker mode

Evidence:

- `crates/ephpm-php/ephpm_wrapper.c:292-306` — the SAPI `ub_write` callback
  appends every byte of PHP output to a growable in-memory buffer
  (`output_buf`). Nothing is written to the socket during script execution.
- `crates/ephpm-php/ephpm_wrapper.c:312-315` — the SAPI `flush` callback is
  a documented **no-op**: "we buffer the entire response and send it at
  once." `echo` + `flush()` in a PHP script therefore delivers nothing until
  the script exits.
- `crates/ephpm-php/ephpm_wrapper.c:321-325` — `send_headers` is also
  deferred; headers are captured after execution
  (`capture_response_headers`, :415-467).
- `crates/ephpm-server/src/router.rs:1992-2027` (`build_php_response`) — the
  fpm path receives one complete `PhpResponse` (status, headers, full body)
  and only then builds the hyper response, optionally compressing the whole
  body.
- `crates/ephpm-server/src/router.rs:658` — the **entire** fpm request
  lifecycle (PHP execution included) runs inside
  `tokio::time::timeout(self.request_timeout, ...)`; default
  `[server.timeouts] request = 300` (`ephpm-config/src/lib.rs:2897`). Even
  if output streamed, an fpm SSE loop would be killed at 300 s.

Conclusion: an "SSE endpoint as a plain `.php` file" does not work in fpm
mode — events would arrive in one batch when the script ends (equivalent to
polling), and the loop is time-capped. **The demo pivoted to worker mode.**

Note on the v0.3.0 release-note phrases (they are all *worker-mode*
mechanisms, none help fpm SSE):

- *stream-stall timeout* — `worker_pool.rs:60-62` + `ephpm-server/src/lib.rs:382-385`:
  a streaming `response_chunk` that waits longer than `[server.timeouts] idle`
  (default 60 s) for a stalled client aborts the stream and frees the worker.
- *chunked body cap* — `router.rs:1939`: `max_body_size` is enforced
  mid-stream on **request** bodies in the Phase-3 streaming upload path.
- *ob-flush on exit* — `ephpm_wrapper.c:2161-2205`: when a worker script
  `exit()`s mid-request, userland output buffers are flushed
  (`php_output_end_all`) and a synthesized response is delivered.

## 2. Worker mode has a true streaming send API

- `crates/ephpm-php/ephpm_wrapper.c:1735-1792` —
  `\Ephpm\Worker\send_response_stream(int $status, array $headers, $bodyResource)`:
  sends status+headers immediately (`response_begin`), then pumps the given
  PHP stream resource in 64 KiB reads, forwarding each read to the client
  (`response_chunk`) until EOF. If the client disconnects, `response_chunk`
  returns negative and the pump stops (:1781).
- `crates/ephpm-server/src/router.rs:1385-1389` — for streaming responses
  the request timeout covers **only the wait for headers**; "a long streamed
  download is NOT cut off by this timeout — the body flows afterward."
  Long-lived SSE is therefore legal.
- `crates/ephpm-server/src/router.rs:1968-1990`
  (`build_streamed_worker_response`) — the body is sent with chunked
  transfer encoding via a channel body.
- `examples/worker/worker-stream.php` — upstream reference proving the
  primitives with a flat-memory echo (streams a multi-GB body both ways).

**How the demo turns the pull-based pump into an SSE generator:** a userland
`stream_wrapper_register` class whose `stream_read()` *blocks* (poll KV +
`usleep`) until it has an event, and never returns an empty string (an empty
read would end the pump). PHP's `_php_stream_read` performs one underlying
read per pump call, so each event chunk reaches the client immediately.
Verified live: events arrive mid-stream with `curl -N`, keepalive comments
arrive after 15 s of idle, disconnects free the worker (presence counter
decrements).

### The one-worker-per-SSE-connection constraint (real, by design)

- `crates/ephpm-server/src/worker_pool.rs:1-24` — a fixed pool of dedicated
  OS threads; each thread loops `take_request()` → handle → respond,
  handling **exactly one request at a time**. A worker executing
  `send_response_stream` stays inside that call for the stream's lifetime.
- Therefore `[php] worker_count` = hard cap on concurrent SSE clients, and
  short action requests (`/tap`, `/paint`) compete for the remaining
  workers. Queued actions wait in the bounded dispatch queue
  (`worker_pool.rs:43-46`) and become 504s if starved (`router.rs:1085-1090`).
- Guard rails that keep this safe: stalled-client abort after
  `timeouts.idle` (see §1), hung-worker replacement (`worker_pool.rs:18-21`),
  worker recycling only *between* requests.

Demo mitigation: `worker_count = 16`, SSE keepalive every 15 s so dead
clients are reaped within one keepalive interval. Scaling past ~hundreds of
clients needs PR-3 (§5).

## 3. Response compression: whole-body only — no streaming compression

What exists today (all buffered, none applies to SSE):

- `crates/ephpm-server/src/router.rs:2106` (`gzip_compress`) and `:2126`
  (`brotli_compress`) — one-shot whole-body compression for buffered PHP
  responses (`build_php_response`, :2018-2027, brotli preferred).
- `crates/ephpm-server/src/static_files.rs:113-141` — static files: brotli
  preferred, gzip fallback, plus a pre-compressed gzip cache
  (`file_cache.rs:123-135`).
- The `brotli` crate is already a workspace dependency
  (`crates/ephpm-server/Cargo.toml:33`).

What is missing: `build_streamed_worker_response` (router.rs:1968) is
explicitly documented "No compression" — streamed worker responses
(including `text/event-stream`) always go out identity-encoded.

This blocks Datastar's signature optimization: a long-lived SSE stream
compressed with **one brotli encoder whose window spans the whole stream**.
Successive fat re-renders of the same elements are nearly identical, so the
shared window turns each re-render into a tiny wire delta (this demo's
~5.4 KB grid re-render would compress to tens of bytes after the first).
See PR-1.

## 4. Realtime fan-out: what exists vs. what's missing

What PHP can reach today (`crates/ephpm-php/ephpm_wrapper.c:2225-2236`, the
complete `EphpmKvOps` table; PHP functions :2242-2412):

`ephpm_kv_get / set / setnx / del / exists / incr / decr / incr_by / expire
/ ttl / pttl / flush_all`

That is the whole surface. **No pub/sub, no blocking read, no key scan, no
version/CAS primitives.** (The ops are installed process-globally —
`crates/ephpm-php/src/lib.rs:689-694` — so all worker threads share one
store: correct for fan-out state.)

Consequently the only fan-out pattern available is **version polling**,
which is what the demo does:

- every mutation `incr`s a single `board:ver` key;
- each SSE loop polls `board:ver` every 100 ms (in-process DashMap read,
  effectively free) and re-renders on change.

Cost: 10 reads/s per client of a nanosecond-scale operation — fine for
hundreds of clients; latency floor = poll interval. A blocking-wait
primitive would remove both the idle churn and the 100 ms latency (PR-2).

## 5. Proposed ePHPm PRs, ranked by necessity

None are required for a working demo. Ranked by value to the Datastar story:

### PR-1 — Streaming brotli for worker-mode streamed responses (high)

The headline perf feature; makes ePHPm the best-in-class Datastar backend.

- **Hook point:** `router.rs` `build_streamed_worker_response` (:1968).
  When the knob is on, the client sent `Accept-Encoding: br`, and the
  response content type is compressible (at minimum `text/event-stream`):
  spawn a task that owns a `brotli` streaming encoder
  (`BrotliEncoderCompressStream`), pulls `Bytes` chunks from the existing
  `body_rx`, feeds each through the encoder with `BROTLI_OPERATION_FLUSH`
  (byte-aligned flush per chunk = per SSE event; window state persists), and
  forwards the compressed frames to a new channel that becomes the hyper
  body. Add `Content-Encoding: br` + `Vary: Accept-Encoding`; still chunked,
  still no content-length.
- **Config knob** (per the repo's add-config-knob rules — read and enforced
  in the same PR, no silent no-op):

  ```toml
  [server.compression]
  streaming = "sse"   # "off" (default) | "sse" (text/event-stream only) | "all"
  ```

  Reuses the existing `CompressionSettings` plumbing (`router.rs:44`);
  streaming quality mapped lower than buffered (brotli q5, lgwin 22) since
  flush-per-event costs ratio and the window sharing is the real win.
- **Flush semantics:** one flush per channel message. The PHP side already
  emits event-sized chunks (each `stream_read` return = one `response_chunk`
  = one SSE event), so event boundaries and flush boundaries align.
- **Tests:** unit test round-tripping a synthetic chunk sequence through the
  encoder task; e2e `curl --compressed -N` against a worker SSE script
  asserting incremental decodability per event.

### PR-2 — `ephpm_kv_wait(key, last_version, timeout_ms)` (medium)

Kills the poll loop.

- **KV side (`ephpm-kv`):** per-key version counter + a notification
  registry (`DashMap<key, tokio::sync::watch::Sender<u64>>`); `set/del/incr`
  bump the watch.
- **Bridge:** one new op in `EphpmKvOps` (`ephpm_wrapper.c:2225`) +
  `kv_bridge.rs`: `wait(key, last_seen, timeout_ms) -> new_version`
  (blocking is fine — worker threads are dedicated OS threads, not tokio
  workers).
- **PHP:** `ephpm_kv_wait(string $key, int $lastVersion, int $timeoutMs): int|false`
  — returns the new version, or `false` on timeout (caller emits a
  keepalive and re-waits). SSE latency drops from ~100 ms to sub-ms, idle
  CPU to zero.

### PR-3 — SSE fan-out that doesn't park a worker per client (roadmap)

Only needed beyond `worker_count`-scale concurrency. A PHP context is
thread-bound, so "detaching" a stream from its worker isn't possible;
the realistic shape is a server-side SSE hub: Rust owns the N client
connections and subscribes them to a topic; on a KV notification it asks
*one* worker to render the fragment once and broadcasts the bytes to all N.
Big design surface (new SAPI functions, topic registry, backpressure
policy) — belongs in `site/content/roadmap/`, not in this demo.

## 6. Demo validation record (podman, 2026-07-14)

Image `docker.io/ephpm/ephpm:v0.5.0-php8.4`, this repo's `ephpm.toml` +
`public/` mounted, port 8087:

- `GET /` → 200 HTML; `GET /healthz` → `ok`; 16 workers booted in ~2 ms each.
- `curl -N /sse` → immediate `datastar-patch-signals` +
  `datastar-patch-elements` snapshot; headers
  `content-type: text/event-stream`, `transfer-encoding: chunked`.
- `POST /tap`, `POST /paint?i=5&c=4` → 204; the open stream received new
  signal patches (`count` 0→1) and a grid re-render with cell `c-5` turned
  `#22c55e` — **live push confirmed, not end-of-script batching**.
- Three concurrent `curl -N` clients + one `POST /tap` → all three received
  `{"count":2,"online":3}`; after disconnect, a fresh probe saw `online: 1`
  (presence increment/decrement both work).
- 18 s idle stream → `: keepalive` comment observed (15 s cadence, inside
  the 60 s idle/stall window).
