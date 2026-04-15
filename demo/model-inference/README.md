# King Model Inference Demo

This directory is the active build-out of a first-class **inference-serving**
role on top of the King native runtime. It maps to tracker sections **V**
(AI/SLM Platform) and **Z** (Inference Serving) in `READYNESS_TRACKER.md` —
both of which currently sit in the PLANNING / FUTURE WORK block. Nothing in
those tracker sections is verified yet; this demo is the first honest slice
that will tick the first V/Z boxes only after its contract tests land green
on `main`.

> The demo lives on branch `feature/model-inference`. The sprint backlog is
> in the root `ISSUES.md` under **"M-batch: Model Inference"**
> (`#M-1` → `#M-18`). Tracker boxes in `READYNESS_TRACKER.md` and
> `PROJECT_ASSESSMENT.md` are **not** ticked from this branch; a post-merge
> sweep ticks V/Z bullets whose contract test is green on `main`.

---

## What works today

Twelve of eighteen leaves are closed, thirteen contract tests green in the
dev container. You can:

- `GET /health`, `/api/runtime`, `/api/bootstrap`, `/api/version`
- `GET /api/node/profile` — real CPU / RAM / GPU probes (`sysctl` on darwin,
  `/proc/meminfo` + `nvidia-smi` + `rocminfo` on linux; no faked VRAM)
- `GET /api/models`, `POST /api/models`, `GET /api/models/{id}`,
  `DELETE /api/models/{id}` — registry + object-store-backed GGUF artifacts
  with bit-identical SHA-256 round-trip through
  `king_object_store_put_from_stream`
- `POST /api/infer` — real `llama.cpp` round-trip (HTTP non-streaming)
- `GET /ws` → `infer.start` — **real streaming** via pinned IIBIN-style
  binary `TokenFrame` (24-byte big-endian header, magic `KITF`, `delta` /
  `end` / `error` frame types)
- `GET /api/telemetry/inference/recent` — per-request telemetry ring
  (TTFT, tokens/s, VRAM budget + observed, prompt + completion counts)
- `GET /ui` — minimal browser chat (single-file HTML + CSS + JS, no build,
  decodes `TokenFrame` client-side, streams deltas live, shows telemetry
  footer)
- `scripts/install-llama-runtime.sh` — pinned `llama.cpp b8802` + SmolLM2-135M
  Q4_K_S fixture with committed SHA-256 checksums
- `scripts/seed-model.php` — admin CLI to register a GGUF into the registry
  (bypasses the 1 MiB king HTTP/1 body cap)
- `scripts/demo-walkthrough.sh` — drives every live endpoint end-to-end
- `scripts/run-proxy.sh` — spins an `alpine/socat` sidecar that exposes
  `:18091` → container bridge IP for reliable WS streaming (see UI section)

Leaves closed per ISSUES:

| Leaf | Proof |
|------|-------|
| #M-1 | server + health + runtime envelope |
| #M-2 | deterministic module-order dispatcher |
| #M-3 | versioned API+WS catalog + parity gate |
| #M-4 | hardware profile kernel + `/api/node/profile` |
| #M-5 | object-store model registry + SHA-256 round-trip |
| #M-6 | pure model-fit selector |
| #M-7 | `LlamaCppWorker` lifecycle (real spawn + drain + reap) |
| #M-8 | typed `inference-request` envelope (33 validation rules) |
| #M-9 | `TokenFrame` wire contract (3 sample vectors bit-identical) |
| #M-10 | `POST /api/infer` with real SmolLM2 completion |
| #M-11 | WS streaming (streamed == batched at seed=1, temp=0) |
| #M-11b | browser chat UI at `GET /ui` |
| #M-12 | inference telemetry ring |

Open:

- `#M-13` Semantic-DNS self-registration (`king.inference.v1`)
- `#M-14` capability-based routing across nodes
- `#M-15` two-node deterministic failover
- `#M-16` transcript persistence
- `#M-17` two-node `docker-compose` smoke
- `#M-18` honest README + fence review

---

## Quickstart

All commands below assume you're running inside the King dev container (the
one that has `king.so` built). From the Mac host, `docker exec` into the
container first.

### 1. Install llama.cpp + the GGUF fixture

Idempotent; safe to re-run. Pins `llama.cpp b8802` + `SmolLM2-135M-Instruct-Q4_K_S.gguf`
with committed SHA-256 checksums.

```bash
demo/model-inference/backend-king-php/scripts/install-llama-runtime.sh
```

### 2. Start the backend

```bash
MODEL_INFERENCE_AUTOSEED=1 \
MODEL_INFERENCE_KING_HOST=0.0.0.0 \
demo/model-inference/backend-king-php/run-dev.sh
```

`AUTOSEED=1` registers the SmolLM2 GGUF on startup if the registry is empty.
Binding to `0.0.0.0` lets the Mac reach the server.

### 3. Talk to it

**CLI (always works, always local):**

```bash
demo/model-inference/backend-king-php/scripts/demo-walkthrough.sh
```

**Browser chat UI:** see the next section — the URL depends on your dev
container setup.

---

## Browser UI and the port story

The backend binds `:18090` inside the container. **Two paths reach it from a
browser on the host**, and they behave very differently:

| URL | Works for | Known issue |
|-----|-----------|-------------|
| `http://localhost:18090/ui` | one-shot HTTP (`/health`, `/api/*`) | VS Code's dev-container port forwarder drops long-lived binary WebSocket frames mid-burst — streaming chat cuts off with `[ws_closed 1006]` |
| `http://localhost:18091/ui` | **everything**, including WS streaming | — |

The `:18091` URL is served by a lightweight `alpine/socat` sidecar container
that bridges host `:18091` directly to the dev container's bridge IP on port
`18090`, bypassing VS Code's forwarder.

Start the sidecar (idempotent; safe to re-run):

```bash
demo/model-inference/backend-king-php/scripts/run-proxy.sh
```

Then open **`http://localhost:18091/ui`** in your browser. If you
accidentally land on `:18090`, the UI shows a yellow banner with a clickable
link to the working URL.

### What the UI does

- Single static HTML file (`public/chat.html`) — no build step, no
  dependencies, no framework.
- Opens a **fresh WebSocket per prompt** (short-lived sessions — avoids the
  current synchronous WS upgrade handler blocking the HTTP listen loop;
  proper non-blocking poll is the future hardening leaf fenced in `#M-11`).
- Decodes `TokenFrame` binaries client-side using `DataView` against the
  pinned header layout. UTF-8 streaming decode per session handles tokens
  that straddle multi-byte char boundaries.
- Footer shows real live telemetry on completion:
  `tokens_in / tokens_out / ttft_ms / duration_ms / tokens/s`.
- Settings row: model, quantization, temperature, top_p, top_k, max_tokens
  are editable per request.
- No login, no history persistence (transcripts land in `#M-16`).

---

## Running the contract tests

All thirteen green in the dev container:

```bash
for t in demo/model-inference/backend-king-php/tests/*.sh; do
  echo "=== $(basename "$t") ==="
  "$t" || exit 1
done
```

The tests that need the King extension auto-load it from
`extension/modules/king.so` or SKIP cleanly if it's not available. The tests
that need `llama.cpp` + a GGUF SKIP cleanly if
`scripts/install-llama-runtime.sh` hasn't been run.

---

## Honest scope fences (target-shape, not verified here)

Every item below is explicitly target-shape. A capability claim on any of
these is deferred to a future sprint whose contract test proves it end to
end. None of them tick any tracker V/Z box from this branch.

- **MoE multi-node expert routing** (tracker V.5).
- **Sharded / distributed inference across nodes.** This demo proves
  capability routing and failover between independent inference nodes. It
  does **not** prove sharded execution across nodes. Tracker V.4's
  "distributed execution" bullet stays fenced.
- **Fine-tuning pipelines** (entire tracker section Y).
- **RAG / embeddings / retrieval** (tracker sections W and X). The
  lifecycle-event state-machine pattern from
  `demo/userland/flow-php/src/RagOrchestrationReference.php` is reused only
  for shape, not retrieval semantics.
- **External provider wrappers** (tracker section AA). OpenAI, Anthropic,
  Bedrock, larger model families live under `packages/` as explicit
  extensions, not inside this demo.
- **Cross-region GPU placement.**
- **GPU CI matrix beyond CPU `llama.cpp`.** Apple Metal paths run on dev
  machines; no GPU-required tracker box ticks on dev-only proof.
- **Prompt-loss-free mid-stream failover.** The failover leaf proves "the
  next request routes elsewhere," not "in-flight generation migrates."
- **Context-window engineering / prompt caching.**
- **Admission control / rate limiting / quota.** Future
  `packages/inference-policy` extension.
- **Concurrent WebSocket streaming.** The current WS upgrade handler runs
  synchronously (one session at a time blocks the HTTP listen loop during
  inference). A non-blocking poll from the main loop is a future hardening
  leaf; called out in `ISSUES.md` on the `#M-11` entry.
- **Large GGUFs uploaded through `POST /api/models`.**
  `king_http1_server_listen_once` caps request bodies at 1 MiB — use
  `scripts/seed-model.php` (same domain function, no HTTP) for real-sized
  artifacts.

---

## Layout

```
demo/model-inference/
  README.md                                     # this file
  contracts/v1/
    api-ws-contract.catalog.json                # canonical API + WS catalog (#M-3)
    inference-request.contract.json             # client→server envelope (#M-8)
    token-frame.contract.json                   # IIBIN binary frame + sample vectors (#M-9)
    node-profile.contract.json                  # GET /api/node/profile envelope (#M-4)
    model-registry-entry.contract.json          # registry row + http_surface (#M-5)
  backend-king-php/
    README.md                                   # backend operational notes
    Dockerfile                                  # PHP 8.4 + king.so + llama.cpp + pdo_sqlite
    run-dev.sh                                  # local runner
    server.php                                  # accept loop + InferenceSession bootstrap
    public/
      chat.html                                 # single-file browser UI (#M-11b)
    http/
      router.php                                # deterministic module-order dispatcher
      module_runtime.php                        # /health /api/runtime /api/bootstrap /api/version /favicon.ico
      module_profile.php                        # /api/node/profile
      module_registry.php                       # /api/models{,/:id}
      module_inference.php                      # POST /api/infer
      module_realtime.php                       # GET /ws + WS streaming
      module_telemetry.php                      # /api/telemetry/inference/recent
      module_ui.php                             # GET /ui
    domain/
      profile/hardware_profile.php              # real CPU/RAM/GPU probes (#M-4)
      registry/model_registry.php               # SQLite index + object-store glue (#M-5)
      registry/model_fit_selector.php           # pure fit/selector (#M-6)
      inference/inference_request.php           # envelope validation (#M-8)
      inference/inference_session.php           # worker cache + per-request complete (#M-10)
      inference/inference_stream.php            # llama.cpp SSE → TokenFrame bridge (#M-11)
      telemetry/inference_metrics.php           # bounded-FIFO metrics ring (#M-12)
    support/
      database.php                              # SQLite schema bootstrap
      object_store.php                          # king_object_store_init wrapper
      llama_cpp_worker.php                      # LlamaCppWorker lifecycle (#M-7)
      token_frame.php                           # TokenFrame encode/decode codec (#M-9)
    scripts/
      install-llama-runtime.sh                  # pinned llama.cpp b8802 + SmolLM2 GGUF
      seed-model.php                            # admin CLI: register a GGUF (bypasses 1 MiB HTTP cap)
      demo-walkthrough.sh                       # drive every live endpoint end-to-end
      run-proxy.sh                              # socat :18091 → container bridge IP
    tests/
      *-contract.{sh,php}                       # one script per leaf
```

---

## Related

- `EPIC.md` — stable charter + non-negotiables
  (no-capability-claim-without-proof, no-simulated-as-real,
  no-contract-shrink).
- `ISSUES.md` — active execution queue including the M-batch.
- `PROJECT_ASSESSMENT.md` — what is verified now (post-merge sweep, not
  here).
- `READYNESS_TRACKER.md` — long-form closure tracker including V / W / X /
  Y / Z / AA sections this demo starts unfencing.
- `demo/video-chat/` — structural convention this demo mirrors (module
  dispatcher, contract catalog, `*-contract.sh` tests, compose smoke).
- `demo/userland/flow-php/src/RagOrchestrationReference.php` —
  lifecycle-event state-machine pattern reused here for inference runs.
