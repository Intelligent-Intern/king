# Model Inference Backend (King / PHP)

This is the King PHP backend that fronts one inference node. It mirrors the
shape of `demo/video-chat/backend-king-php/`: env-driven config, extension
load gate, deterministic module-order dispatcher, per-surface contract tests.

## What Is Verified In This Directory Today

- `#M-1` Server boots against a loaded King extension; `/health` and
  `/api/runtime` return deterministic JSON envelopes; bootstrap envelope at
  `/api/bootstrap` and `/api/version` are stable.
- `#M-2` HTTP/WS request dispatcher wires focused modules in fixed order; a
  contract test asserts the order list.

The full model-inference proof is summarized in
[`documentation/dev/model-inference.md`](./model-inference.md). Remaining
distributed placement and fine-tuning work lives in `BACKLOG.md` Batch 4.

## Running It Locally

### With the repo-local extension build

```
./run-dev.sh
```

This script auto-detects a compiled `extension/modules/king.so` (override via
`KING_EXTENSION_PATH`) or trusts a `php.ini`-enabled `king` module. It fails
closed if neither is available.

### Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `MODEL_INFERENCE_KING_HOST` | `127.0.0.1` | HTTP/WS bind host |
| `MODEL_INFERENCE_KING_PORT` | `18090` | HTTP port (distinct from video-chat 18080) |
| `MODEL_INFERENCE_KING_WS_PORT` | `18091` | WS port (distinct from video-chat 18081) |
| `MODEL_INFERENCE_KING_WS_PATH` | `/ws` | WS upgrade path |
| `MODEL_INFERENCE_KING_DB_PATH` | `.local/model-inference.sqlite` | SQLite path for registry + transcripts index |
| `MODEL_INFERENCE_KING_ENV` | `development` | Environment label exposed in runtime envelope |
| `MODEL_INFERENCE_KING_BACKEND_VERSION` | `1.0.6-beta` | App version label |
| `MODEL_INFERENCE_KING_NODE_ID` | generated | Stable node identity used by Semantic-DNS (set this in production so a restarted node reclaims its identity) |
| `MODEL_INFERENCE_KING_SERVER_MODE` | `all` | `all` / `http` / `ws` — split listeners when non-`all` |
| `MODEL_INFERENCE_DEBUG_REQUESTS` | `0` | When truthy, each request is logged to stderr |

### Docker

The `Dockerfile` bases on `php:8.4-cli-trixie`, installs `pdo_sqlite`, copies
the backend tree, and copies `extension/modules/king.so` to `/opt/king/king.so`.
At `#M-7` it also copies a pinned `llama.cpp` server binary. Until then the
image still boots the HTTP + WS surfaces on ports `18090` and `18091`.

## Chat UI (M-11b)

Once the backend is running with at least one model registered, a minimal
browser chat is available at:

```
http://<host>:18090/ui
```

The page is a single static HTML file
(`backend-king-php/public/chat.html`) served by `module_ui.php`. It opens a
WebSocket to `/ws`, sends an `infer.start` text frame, decodes incoming
TokenFrame binaries client-side (via `DataView` against the pinned big-endian
24-byte header), and paints token deltas into the live assistant bubble.
The footer shows real-time `tokens_in / tokens_out / ttft_ms / duration_ms /
tokens_per_second`.

Easiest way to see it running with the SmolLM2 fixture:

```bash
# Inside the dev container or any host with the extension loaded:
MODEL_INFERENCE_AUTOSEED=1 \
MODEL_INFERENCE_KING_HOST=0.0.0.0 \
./run-dev.sh
```

The `MODEL_INFERENCE_AUTOSEED=1` flag boots with the SmolLM2 GGUF
auto-registered if the registry is empty and the fixture is at its default
path. Bind to `0.0.0.0` only when you intend the backend to be reachable
beyond loopback.

## Target-Shape Fences

The backend inherits every fence listed in
[`documentation/dev/model-inference.md`](./model-inference.md). The most
important one locally is this:

> **Inference endpoints will not silently fall back to a mock model.** When
> a leaf is not yet landed, the route returns `501 not_implemented` with an
> explicit error code. There is no placeholder mode. If `POST /api/infer` is
> missing, it is missing.

## Layout

```
documentation/dev/model-inference-backend.md # this file

backend-king-php/
  Dockerfile                      # containerized backend (M-1 / M-7)
  run-dev.sh                      # local runner
  server.php                      # accept loop + bootstrap (M-1)
  http/
    router.php                    # fixed module-order dispatcher (M-2)
    module_runtime.php            # /health /api/runtime /api/bootstrap /api/version (M-1)
  support/
    database.php                  # SQLite schema bootstrap (grows with registry + transcripts)
```

Further modules (`module_profile.php`, `module_registry.php`,
`module_worker.php`, `module_inference.php`, `module_telemetry.php`,
`module_routing.php`, `module_realtime.php`) land with their corresponding
leaves.
