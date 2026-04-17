# King Model Inference Demo

This directory is the active build-out of a first-class **inference-serving**
role on top of the King native runtime. It maps to tracker sections **V**
(AI/SLM Platform) and **Z** (Inference Serving) in `READYNESS_TRACKER.md`.

> The demo lives on branch `feature/rag-pipeline` (R-batch), extending
> `feature/model-inference` (M-batch). The sprint backlog is in the root
> `ISSUES.md` under **"M-batch: Model Inference"** (`#M-1` → `#M-18`) and
> **"R-batch: RAG Pipeline"** (`#R-1` → `#R-16`). Tracker boxes in
> `READYNESS_TRACKER.md` and `PROJECT_ASSESSMENT.md` are **not** ticked from
> this branch; a post-merge sweep ticks W/X bullets whose contract test is
> green on `main`.

---

## What works today

All thirty-four leaves are closed (18 M-batch + 16 R-batch). Thirty contract
tests green (the model-registry test additionally requires the King extension;
four M-batch tests require the llama.cpp runtime). You can:

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

**RAG Pipeline (R-batch)**
- `POST /api/embed` — real embedding generation via llama.cpp `--embedding`
  mode with L2 normalization; nomic-embed-text-v1.5 Q8_0 (768 dimensions)
- `POST /api/documents` — plain text document ingest with auto-chunking
  (fixed-size 512-byte chunks, 64-byte overlap)
- `GET /api/documents/{id}/chunks` — chunk listing per document
- `POST /api/retrieve` — semantic retrieval: embed query → brute-force
  cosine similarity → top-K ranked chunks with scores
- `POST /api/rag` — end-to-end RAG: retrieve context → augment prompt →
  inference completion (dual model selector: chat + embedding)
- `GET /api/telemetry/rag/recent` — per-request RAG telemetry ring
  (embedding_ms, retrieval_ms, inference_ms, chunks_used, vectors_scanned)
- Embedding model registry: `model_type` column (`chat`/`embedding`),
  separate autoseed for embedding GGUFs
- Semantic-DNS extended with `supports_embedding`, `supports_retrieval`,
  `supports_rag`, `embedding_dimensions` attributes

**Tooling**
- `scripts/install-llama-runtime.sh` — pinned `llama.cpp b8802` + SmolLM2
  fixture with committed SHA-256 checksums
- `scripts/install-embedding-model.sh` — pinned nomic-embed-text-v1.5 Q8_0
  GGUF with SHA-256 verification
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

**R-batch: RAG Pipeline (branch `feature/rag-pipeline`)**

| Leaf | Status | Proof |
|------|--------|-------|
| #R-1 | done | `model_type` column + embedding model registry + install script |
| #R-2 | done | `EmbeddingSession` with `--embedding` flag + L2 normalization |
| #R-3 | done | `embedding-request.contract.json` + 33-rule validator |
| #R-4 | done | `POST /api/embed` endpoint wired through dispatcher |
| #R-5 | done | `POST /api/documents` + document ingest via object store |
| #R-6 | done | `text_chunker.php` + `chunk-envelope.contract.json` (60 rules) |
| #R-7 | done | auto-chunk on ingest + `GET /api/documents/{id}/chunks` |
| #R-8 | done | vector store: object store + SQLite metadata |
| #R-9 | done | brute-force cosine similarity (16 rules) |
| #R-10 | done | `POST /api/retrieve` + `retrieval-request.contract.json` |
| #R-11 | done | `POST /api/rag` end-to-end pipeline |
| #R-12 | done | `RagMetricsRing` + `GET /api/telemetry/rag/recent` (24 rules) |
| #R-13 | done | Semantic-DNS embedding/retrieval/rag attributes |
| #R-14 | done | catalog parity: 18 live API surfaces + probes |
| #R-15 | done | `scripts/rag-smoke.sh` 10-phase end-to-end |
| #R-16 | done | this README update + scope fences |

---

## Quickstart

All commands below assume you're running inside the King dev container (the
one that has `king.so` built). From the Mac host, `docker exec` into the
container first.

### 1. Install llama.cpp + the GGUF fixtures

Idempotent; safe to re-run. Pins `llama.cpp b8802` +
`SmolLM2-135M-Instruct-Q4_K_S.gguf` + `nomic-embed-text-v1.5.Q8_0.gguf`
with committed SHA-256 checksums.

```bash
demo/model-inference/backend-king-php/scripts/install-llama-runtime.sh
demo/model-inference/backend-king-php/scripts/install-embedding-model.sh
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

All thirty in the dev container:

```bash
for t in demo/model-inference/backend-king-php/tests/*-contract.sh; do
  echo "=== $(basename "$t") ==="
  "$t" || exit 1
done
```

The tests that need the King extension auto-load it or SKIP cleanly. The
tests that need `llama.cpp` + a GGUF SKIP cleanly if
`scripts/install-llama-runtime.sh` hasn't been run.

### Two-node smoke (M-batch)

```bash
MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 \
demo/model-inference/scripts/smoke.sh
```

### RAG pipeline smoke (R-batch)

```bash
MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 \
demo/model-inference/scripts/rag-smoke.sh
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
- **Hybrid retrieval** (BM25/TF-IDF + vector fusion). R-batch proves semantic
  retrieval only; keyword search is fenced.
- **External vector databases** (pgvector, Pinecone, Weaviate). Brute-force
  over King object store only.
- **HNSW / IVF / ANN indexes.** Honest brute-force; approximate methods fenced.
- **PDF / HTML / Markdown parsing.** Plain text ingestion only.
- **Multimodal embedding** (images, audio). Text only.
- **Large-scale indexing** (>10K vectors). Demo corpus sizes only.
- **WS streaming of RAG results.** HTTP only for RAG pipeline.
- **Concurrent RAG execution.** Single pipeline at a time (same serial
  listener constraint as M-batch).
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
    api-ws-contract.catalog.json                # canonical API + WS catalog (#M-3, R-14)
    inference-request.contract.json             # client->server inference envelope (#M-8)
    embedding-request.contract.json             # client->server embedding envelope (#R-3)
    retrieval-request.contract.json             # client->server retrieval envelope (#R-10)
    chunk-envelope.contract.json                # chunk shape contract (#R-6)
    token-frame.contract.json                   # IIBIN binary frame + sample vectors (#M-9)
    node-profile.contract.json                  # GET /api/node/profile envelope (#M-4)
    model-registry-entry.contract.json          # registry row + http_surface (#M-5)
  scripts/
    smoke.sh                                    # 9-phase compose end-to-end (#M-17)
    rag-smoke.sh                                # 10-phase RAG pipeline smoke (#R-15)
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
      module_embed.php                          # POST /api/embed (#R-4)
      module_ingest.php                         # /api/documents + /api/documents/:id/chunks (#R-5, R-7)
      module_retrieve.php                       # POST /api/retrieve + POST /api/rag (#R-10, R-11)
      module_inference.php                      # POST /api/infer + GET /api/transcripts/:id
      module_realtime.php                       # GET /ws + WS streaming
      module_telemetry.php                      # /api/telemetry/{inference,rag}/recent
      module_routing.php                        # GET /api/route
      module_ui.php                             # GET /ui
    domain/
      profile/hardware_profile.php              # real CPU/RAM/GPU probes (#M-4)
      registry/model_registry.php               # SQLite index + model_type (#M-5, R-1)
      registry/model_fit_selector.php           # pure fit/selector (#M-6)
      embedding/embedding_request.php           # embedding envelope validation (#R-3)
      embedding/embedding_session.php           # embedding worker cache + /v1/embeddings (#R-2)
      inference/inference_request.php           # inference envelope validation (#M-8)
      inference/inference_session.php           # worker cache + per-request complete (#M-10)
      inference/inference_stream.php            # llama.cpp SSE -> TokenFrame bridge (#M-11)
      inference/transcript_store.php            # object-store persistence (#M-16)
      retrieval/document_store.php              # document ingest + object store (#R-5)
      retrieval/text_chunker.php                # fixed-size chunking (#R-6)
      retrieval/vector_store.php                # vector persistence (#R-8)
      retrieval/cosine_similarity.php           # brute-force scorer (#R-9)
      retrieval/retrieval_pipeline.php          # embed query → scan → rank (#R-10)
      retrieval/rag_orchestrator.php            # retrieve → augment → infer (#R-11)
      routing/inference_routing.php             # Semantic-DNS routing helper (#M-14)
      telemetry/inference_metrics.php           # inference metrics ring (#M-12)
      telemetry/rag_metrics.php                 # RAG metrics ring (#R-12)
    support/
      database.php                              # SQLite schema bootstrap
      object_store.php                          # king_object_store_init wrapper
      semantic_dns.php                          # Semantic-DNS register/deregister (#M-13, R-13)
      llama_cpp_worker.php                      # LlamaCppWorker lifecycle (#M-7)
      token_frame.php                           # TokenFrame encode/decode codec (#M-9)
    scripts/
      install-llama-runtime.sh                  # pinned llama.cpp b8802 + SmolLM2 GGUF
      install-embedding-model.sh                # pinned nomic-embed-text-v1.5 Q8_0 GGUF (#R-1)
      seed-model.php                            # admin CLI: register a GGUF
      demo-walkthrough.sh                       # drive every live endpoint end-to-end
      run-proxy.sh                              # socat :18091 -> container bridge IP
    tests/
      *-contract.{sh,php}                       # 30 test pairs
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
