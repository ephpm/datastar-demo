# WordPress as the flashy Datastar-on-ePHPm demo — feasibility

**Verdict: feasible, but only as a hybrid — WordPress served in fpm mode,
with Datastar SSE handled by a separate worker-mode ePHPm endpoint/service.
Not recommended as the *first* demo.** The purpose-built Pixelboard in this
repo demonstrates the same mechanics with none of the caveats; a WP demo is
a good follow-up once PR-1/PR-2 (see ephpm-integration-spec.md) land.

## Why SSE inside WordPress itself doesn't work

The natural shapes — an `admin-ajax.php` action or a REST route
(`/wp-json/...`) that loops emitting SSE events — both die on verified
ePHPm fpm-mode facts:

1. **No streaming in fpm mode.** The SAPI buffers all output and `flush()`
   is a no-op (`ephpm_wrapper.c:292-315`); the response is delivered only
   when the script exits. An SSE loop's events would arrive as one batch at
   the end — functionally a poll, not a stream.
2. **Hard request cap.** The entire fpm request runs inside
   `[server.timeouts] request` (default 300 s, `router.rs:658`). A
   "forever" SSE loop is killed at the cap; PHP's own `max_execution_time`
   stacks on top of that.
3. **WP in worker mode is not the answer either.** Known constraints from
   ePHPm's WordPress worker-mode work: block-theme rendering crashes the
   worker (classic themes only), and every SSE connection would park one of
   the few heavy WP worker contexts forever — the worst possible tenant for
   the one-worker-per-SSE-connection model. Running WP's multi-hundred-MB
   footprint × `worker_count` threads just to hold idle SSE connections is
   a non-starter.

## The shape that works: hybrid

```
 Browser ── page loads from WP ──────► ePHPm #1 (fpm mode, WordPress)
    │                                        │ Predis / RESP2
    │                                        ▼
    └── data-init="@get(sse.example/…)" ► ePHPm #2 (worker mode, SSE service)
                                             └─ native KV = shared state
```

- **ePHPm #1 — WordPress, fpm mode** (the already-proven deployment). A
  small WP plugin:
  - enqueues `datastar.js` and stamps `data-*` attributes onto comment
    lists / admin dashboard widgets;
  - on events worth broadcasting (`comment_post`, `transition_post_status`,
    order created, …) writes a fragment/payload into the SSE service's KV
    via Predis and bumps a version key — ePHPm's KV exposes a RESP2
    listener (`[kv.redis_compat]`), and WP already ships Predis via the
    redis-cache plugin that ePHPm's KV shim supports.
- **ePHPm #2 — SSE service, worker mode**: exactly this repo's pattern (a
  ~100-line worker script), watching version keys and pushing
  `datastar-patch-elements` fragments (new comment `<li>`s, live dashboard
  numbers). CORS or a shared reverse-proxy path (`/rt/…`) puts it on the
  same origin.

What the demo would show: a comment posted on one browser appears on every
other visitor's page (and on the admin dashboard) without a reload — on a
stock WordPress, no Node, no Pusher, two copies of one static binary.

## Cost/benefit

| | |
|---|---|
| Flash factor | High — "live WordPress" lands with a huge audience |
| Build cost | Medium — a WP plugin + the SSE worker script + CORS/proxy glue |
| Fragility | Medium — WP updates, theme markup coupling, two-service topology |
| Honesty risk | Low if presented as "WP + companion SSE service"; high if implied WP itself streams |

## Recommendation

Ship Pixelboard as the reference demo now. Build the WP hybrid as demo #2,
ideally after PR-2 (`ephpm_kv_wait`) so the SSE service is event-driven, and
frame it precisely: *WordPress renders pages; a worker-mode ePHPm sidecar
streams the realtime layer; ePHPm's native KV (RESP2) is the bus between
them.*
