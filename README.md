# Pixelboard — Datastar on ePHPm

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

## Honest limitations (verified, see the spec doc)

1. **SSE requires worker mode.** ePHPm's fpm/drop-in mode buffers the whole
   response (`flush()` is a SAPI no-op) — a plain `sse.php` cannot stream.
   This demo is worker-mode by necessity, not preference.
2. **One SSE connection parks one worker thread.** `worker_count` in
   `ephpm.toml` (16 here) is the ceiling on concurrent viewers; actions
   compete for the remaining threads. Fine for a demo and small deployments;
   the spec doc proposes the ePHPm changes for real scale.
3. **Fan-out is version-polling** (100 ms interval) — ePHPm's KV has no
   pub/sub or blocking wait yet. In-process reads make this cheap, but it
   sets the latency floor.
4. **No streaming compression.** ePHPm compresses buffered responses
   (brotli/gzip) but streamed responses go out identity-encoded, so
   Datastar's shared-brotli-window trick isn't available yet. Proposed as
   PR-1 in the spec doc.
