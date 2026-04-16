# King Model Inference Demo

This directory is the active build-out of a first-class **inference-serving**
role on top of the King native runtime. It maps to tracker sections **V**
(AI/SLM Platform) and **Z** (Inference Serving) in `READYNESS_TRACKER.md`.

> The demo lives on branch `feature/model-inference`. The sprint backlog is
> in the root `ISSUES.md` under **"M-batch: Model Inference"**
> (`#M-1` → `#M-18`). Tracker boxes in `READYNESS_TRACKER.md` and
> `PROJECT_ASSESSMENT.md` are **not** ticked from this branch; a post-merge
> sweep ticks V/Z bullets whose contract test is green on `main`.

---

## What works today

All eighteen leaves are closed. Seventeen contract tests green (the
model-registry test additionally requires the King extension). You can:

**Runtime + Profile**
- `GET /health`, `/api/runtime`, `/api/bootstrap`, `/api/version`
- `GET /api/node/profile` — real CPU / RAM / GPU probes (`sysctl` on darwin,
  `/proc/meminfo` + `nvidia-smi` + `rocminfo` on linux; no faked VRAM)

**Model Registry**
- `GET /api/models`, `POST /api/models`, `GET /api/models/{id}`,
  `DELETE /api/models/{id}` — SQLite index + object-store-backed GGUF
  artifacts with bit-identical SHA-256 round-trip via
  `king_object_store_put_from_stream`

**Inference (real completions)**
- `POST /api/infer` — real `llama.cpp` round-trip (HTTP non-streaming)
- `GET /ws` → `infer.start` — real streaming via IIBIN-style binary
  `TokenFrame` (24-byte big-endian header, magic `KITF`, `delta` / `end` /
  `error` frame types)
- 8 quantization levels verified: Q2_K, Q3_K, Q4_0, Q4_K, Q5_K, Q6_K,
  Q8_0, F16 (SmolLM2-135M-Instruct)

**Observability + Diagnostics**
- `GET /api/telemetry/inference/recent` — per-request telemetry ring
  (TTFT, tokens/s, VRAM budget, prompt + completion counts)
- `GET /api/transcripts/{request_id}` — persistent transcript retrieval
  from the King object store
- `GET /api/route` — routing diagnostic showing Semantic-DNS resolution
  with primary + failover candidates

**Service Discovery**
- Semantic-DNS self-registration as `king.inference.v1` on ready, with
  hardware profile attributes (GPU kind, VRAM, capabilities)
- Deregister on drain/shutdown
- Bounded-retry heartbeat-after-ready (no sleep)

**Multi-Node**
- `docker-compose.v1.yml` — two-node compose (node-a + node-b)
- `scripts/failover-smoke.sh` — deterministic failover: prompt-1 on
  node-a, stop node-a, prompt-2 on node-b without reconfiguration
- `scripts/smoke.sh` — 9-phase end-to-end: syntax, contract tests,
  compose boot, probes, registry, inference, transcripts, telemetry,
  routing, failover

**Browser UI**
- `GET /ui` — minimal chat (single-file HTML + CSS + JS, no build,
  decodes TokenFrame client-side, streams deltas live, shows telemetry)

**Tooling**
- `scripts/install-llama-runtime.sh` — pinned `llama.cpp b8802` + SmolLM2
  fixture with committed SHA-256 checksums
- `scripts/seed-model.php` — admin CLI to register GGUFs (bypasses
  the 1 MiB king HTTP/1 body cap)
- `scripts/demo-walkthrough.sh` — drives every live endpoint end-to-end
- `scripts/run-proxy.sh` — `alpine/socat` sidecar for reliable WS on
  `:18091` (see UI section)

---

### Sprint leaves

| Leaf | Status | Proof |
|------|--------|-------|
| #M-1 | done | server + health + runtime envelope |
| #M-2 | done | deterministic module-order dispatcher |
| #M-3 | done | versioned API+WS catalog + parity gate |
| #M-4 | done | hardware profile kernel + `/api/node/profile` |
| #M-5 | done | object-store model registry + SHA-256 round-trip |
| #M-6 | done | pure model-fit selector |
| #M-7 | done | `LlamaCppWorker` lifecycle (real spawn + drain + reap) |
| #M-8 | done | typed `inference-request` envelope (33 validation rules) |
| #M-9 | done | `TokenFrame` wire contract (3 sample vectors bit-identical) |
| #M-10 | done | `POST /api/infer` with real SmolLM2 completion |
| #M-11 | done | WS streaming + browser chat UI at `GET /ui` |
| #M-12 | done | inference telemetry ring |
| #M-13 | done | Semantic-DNS self-registration + heartbeat + deregister |
| #M-14 | done | `InferenceRouting` + `GET /api/route` diagnostic |
| #M-15 | done | two-node failover compose + `failover-smoke.sh` |
| #M-16 | done | transcript persistence + `GET /api/transcripts/{id}` |
| #M-17 | done | 9-phase compose end-to-end `smoke.sh` |
| #M-18 | done | this README + scope fences + ISSUES update |

---

## Quickstart

All commands below assume you're running inside the King dev container (the
one that has `king.so` built). From the Mac host, `docker exec` into the
container first.

### 1. Install llama.cpp + the GGUF fixture

Idempotent; safe to re-run. Pins `llama.cpp b8802` +
`SmolLM2-135M-Instruct-Q4_K_S.gguf` with committed SHA-256 checksums.

```bash
demo/model-inference/backend-king-php/scripts/install-llama-runtime.sh
```

### 2. Start the backend

```bash
MODEL_INFERENCE_AUTOSEED=1 \
MODEL_INFERENCE_KING_HOST=0.0.0.0 \
demo/model-inference/backend-king-php/run-dev.sh
```

`AUTOSEED=1` registers every SmolLM2 GGUF found in `.local/fixtures/` on
startup. Binding to `0.0.0.0` lets the Mac reach the server.

### 3. Talk to it

**CLI (always works, always local):**

```bash
demo/model-inference/backend-king-php/scripts/demo-walkthrough.sh
```

**Browser chat UI:** see the next section — the URL depends on your dev
container setup.

### 4. Two-node compose

From the repo root (no dev container needed — compose builds its own image):

```bash
docker compose -f demo/model-inference/docker-compose.v1.yml up -d --build
```

Node-a on `:18090`, node-b on `:18092`. Both autoseed independently.

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

Then open **`http://localhost:18091/ui`** in your browser.

---

## Running the contract tests

All seventeen in the dev container:

```bash
for t in demo/model-inference/backend-king-php/tests/*-contract.sh; do
  echo "=== $(basename "$t") ==="
  "$t" || exit 1
done
```

The tests that need the King extension auto-load it or SKIP cleanly. The
tests that need `llama.cpp` + a GGUF SKIP cleanly if
`scripts/install-llama-runtime.sh` hasn't been run.

### Two-node smoke

```bash
MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 \
demo/model-inference/scripts/smoke.sh
```

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
- **RAG / embeddings / retrieval** (tracker sections W and X).
- **External provider wrappers** (tracker section AA). OpenAI, Anthropic,
  Bedrock, larger model families live under `packages/` as explicit
  extensions, not inside this demo.
- **Cross-region GPU placement.**
- **GPU CI matrix beyond CPU `llama.cpp`.** Apple Metal paths run on dev
  machines; no GPU-required tracker box ticks on dev-only proof.
- **Prompt-loss-free mid-stream failover.** The failover leaf (#M-15) proves
  "the next request routes elsewhere," not "in-flight generation migrates."
- **Context-window engineering / prompt caching.**
- **Admission control / rate limiting / quota.**
- **Concurrent WebSocket streaming.** The current WS upgrade handler runs
  synchronously (one session at a time blocks the HTTP listen loop during
  inference). A non-blocking poll is a future hardening leaf.
- **Large GGUFs uploaded through `POST /api/models`.**
  `king_http1_server_listen_once` caps request bodies at 1 MiB — use
  `scripts/seed-model.php` for real-sized artifacts.
- **Fleet-wide optimal model placement.** Per-node fit is proved in #M-6;
  fleet-wide placement is fenced.

---

## Layout

```
demo/model-inference/
  README.md                                     # this file
  docker-compose.v1.yml                         # two-node compose (#M-15)
  contracts/v1/
    api-ws-contract.catalog.json                # canonical API + WS catalog (#M-3)
    inference-request.contract.json             # client->server envelope (#M-8)
    token-frame.contract.json                   # IIBIN binary frame + sample vectors (#M-9)
    node-profile.contract.json                  # GET /api/node/profile envelope (#M-4)
    model-registry-entry.contract.json          # registry row + http_surface (#M-5)
  scripts/
    smoke.sh                                    # 9-phase compose end-to-end (#M-17)
    failover-smoke.sh                           # two-node failover proof (#M-15)
  backend-king-php/
    Dockerfile                                  # PHP 8.4 + king.so + llama.cpp + pdo_sqlite
    run-dev.sh                                  # local runner
    server.php                                  # accept loop + bootstrap
    public/
      chat.html                                 # single-file browser UI (#M-11)
    http/
      router.php                                # deterministic module-order dispatcher
      module_runtime.php                        # /health /api/runtime /api/bootstrap /api/version
      module_profile.php                        # /api/node/profile
      module_registry.php                       # /api/models{,/:id}
      module_inference.php                      # POST /api/infer + GET /api/transcripts/:id
      module_realtime.php                       # GET /ws + WS streaming
      module_telemetry.php                      # /api/telemetry/inference/recent
      module_routing.php                        # GET /api/route
      module_ui.php                             # GET /ui
    domain/
      profile/hardware_profile.php              # real CPU/RAM/GPU probes (#M-4)
      registry/model_registry.php               # SQLite index + object-store glue (#M-5)
      registry/model_fit_selector.php           # pure fit/selector (#M-6)
      inference/inference_request.php           # envelope validation (#M-8)
      inference/inference_session.php           # worker cache + per-request complete (#M-10)
      inference/inference_stream.php            # llama.cpp SSE -> TokenFrame bridge (#M-11)
      inference/transcript_store.php            # object-store persistence (#M-16)
      routing/inference_routing.php             # Semantic-DNS routing helper (#M-14)
      telemetry/inference_metrics.php           # bounded-FIFO metrics ring (#M-12)
    support/
      database.php                              # SQLite schema bootstrap
      object_store.php                          # king_object_store_init wrapper
      semantic_dns.php                          # Semantic-DNS register/deregister (#M-13)
      llama_cpp_worker.php                      # LlamaCppWorker lifecycle (#M-7)
      token_frame.php                           # TokenFrame encode/decode codec (#M-9)
    scripts/
      install-llama-runtime.sh                  # pinned llama.cpp b8802 + SmolLM2 GGUF
      seed-model.php                            # admin CLI: register a GGUF
      demo-walkthrough.sh                       # drive every live endpoint end-to-end
      run-proxy.sh                              # socat :18091 -> container bridge IP
    tests/
      *-contract.{sh,php}                       # 17 test pairs, one per leaf
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
- `demo/userland/flow-php/src/McpServiceDiscovery.php` —
  `McpServiceResolution` failover shape reused by `inference_routing.php`.
