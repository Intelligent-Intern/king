# King Model Inference Demo

This directory is the active build-out of a first-class **inference-serving**
role on top of the King native runtime. It maps to tracker sections **V**
(AI/SLM Platform) and **Z** (Inference Serving) in `READYNESS_TRACKER.md`.

The landed local inference and RAG proof is recorded in
`READYNESS_TRACKER.md`. Remaining distributed inference, model placement, and
fine-tuning work lives in `BACKLOG.md` Batch 4.

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
| #M-18 | done | this dev doc + scope fences + readiness update |

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

**A-batch: Simple Auth / Identity (branch `feature/rag-pipeline`)**

Closes readiness tracker section **AB** (4 bullets: AB.1–AB.4). Demo-grade auth layer mirroring the video-chat auth approach but intentionally minimal: opaque 32-hex bearer sessions, bcrypt password hashing, handshake-time WS validation only (per-frame fenced), flat `user | admin` role, demo users autoseed from `fixtures/demo-users.json`. **Auth is OPTIONAL** — every pre-A-batch caller that omits `Authorization` continues to work anonymously.

| Leaf | Status | Proof |
|------|--------|-------|
| #A-1 | done | `users` + `sessions` SQLite schema + bcrypt store (72-rule `auth-store-contract`) |
| #A-2 | done | `POST /api/auth/login` + `POST /api/auth/logout` + `GET /api/auth/whoami` (45-rule `auth-endpoint-contract`) |
| #A-3 | done | Non-blocking auth middleware (dispatcher-wide hook, 25-rule `auth-middleware-contract`) |
| #A-4 | done | `conversations.user_ref` + ownership gate + `GET /api/conversations/me` (28-rule `conversation-ownership-contract`) |
| #A-5 | done | WS handshake-time auth via Bearer header OR `?auth_token=<32-hex>` query fallback (13-rule `realtime-auth-contract`) |
| #A-6 | done | Demo user autoseed (`admin/alice/bob`) from `fixtures/demo-users.json`, env-overridable, idempotent re-seed (44-rule `auth-seed-contract`) |
| #A-7 | done | Inline login banner in `/ui`, Bearer attached to REST + WS, `(username @ role)` chip, click-to-logout |
| #A-8 | done | Catalog parity + router order + this README + tracker AB section + `scripts/auth-smoke.sh` |

**Demo credentials** (seeded at boot from `fixtures/demo-users.json`, env-overridable):

| Username | Password | Role |
|---|---|---|
| `admin` | `admin123` | admin |
| `alice` | `alice123` | user |
| `bob` | `bob123` | user |

**Honest scope fences (A-batch):** per-frame WS revalidation is NOT implemented (handshake-time only); session refresh/rotation is NOT implemented (logout + re-login covers the demo); no RBAC path-rule matrix; no SSO/OAuth/OIDC; no password reset; no rate-limit or brute-force lockout. Credentials travel the wire in plaintext over the /api/auth/login POST body — TLS termination is the deployer's responsibility.

**C-batch: Conversation Persistence (branch `feature/rag-pipeline`)**

Closes readiness tracker bullet **V.8** (prompt/cache/checkpoint persistence). Every chat turn is now persisted server-side keyed by `session_id`; the browser UI survives page reloads because it writes its `session_id` into `localStorage` and rehydrates `state.history` from the server on next boot.

| Leaf | Status | Proof |
|------|--------|-------|
| #C-1 | done | `conversations` + `conversation_messages` SQLite schema (60-rule `conversation-store-contract`) |
| #C-2 | done | `GET /api/conversations/{session_id}/messages`, `GET /api/conversations/{session_id}`, `DELETE /api/conversations/{session_id}` (42-rule `conversations-endpoint-contract`) |
| #C-3 | done | HTTP + WS inference paths auto-append each turn (best-effort, never corrupts response) |
| #C-4 | done | Chat UI persists `session_id` in `localStorage` and rehydrates prior turns as `(restored)` bubbles on load |

**Scope fences (C-batch):** session_id is client-supplied and NOT authenticated — any caller with the string can read/delete the conversation. SQLite-only persistence at this leaf; durable object-store replay is out-of-scope (V.8's "checkpoint" side is the harder axis and stays fenced). No pagination on the message list yet (1000-message cap per request).

**G-batch: Graph-aware Discovery (branch `feature/rag-pipeline`)**

Closes readiness tracker bullets **W.8** (graph-aware metadata + relationship traversal) and **W.9** (public contract boundary between core semantic discovery and optional graph integrations).

| Leaf | Status | Proof |
|------|--------|-------|
| #G-1 | done | `service_edges` SQLite schema + `graph_store.php` with upsert / delete / list_outgoing / traverse_outgoing (1..3-hop cap, 512-visit budget) (46-rule `graph-store-contract`) |
| #G-2 | done | `graph_expand.php` enriches a ranked /api/discover result with 1- or 2-hop neighbors tagged `source: "graph_expand"`, `semantic_score: null` (28-rule `graph-expand-contract`) |
| #G-3 | done | `POST /api/discover` accepts optional `graph_expand: {edge_types?, max_hops?: 1..2}`; core `results` is bit-identical with or without the field (W.9 contract boundary assertion) |
| #G-4 | done | `contracts/v1/service-graph.contract.json` pins the core-vs-extension boundary |

**Scope fences (G-batch):** directional edges only (undirected must be written twice). No weighted scoring — traversal is plain BFS. Edges are never a ranking signal; they only widen the candidate set. No HTTP write endpoint for edges yet (direct SQLite or a future admin route). MoE / expert routing (tracker V.5) stays fenced — that is a different problem.

**S-batch: Semantic Discovery (branch `feature/rag-pipeline`)**

Reuses the R-batch embedding infrastructure to replace keyword-only service/tool selection with vector + BM25 ranked discovery. Closes W.5, W.7, X.4, X.5, X.6 in the readiness tracker.

| Leaf | Status | Proof |
|------|--------|-------|
| #S-1 | done | `service-descriptor.contract.json` + 40-rule validator |
| #S-2 | done | `service_embeddings` SQLite + `svec-{hex}` object-store (39 rules) |
| #S-3 | done | `service_embedding_upsert()` composition + session-adapter (15 rules) |
| #S-4 | done | `POST /api/discover` module + envelope parser (43 rules) |
| #S-5 | done | `semantic_discover()` brute-force cosine over service embeddings (11 rules) |
| #S-6 | done | `hybrid_discover()` BM25 (k1=1.2, b=0.75) + cosine fusion with alpha (30 rules) |
| #S-7 | done | `tool-descriptor.contract.json` + `tool_embeddings` table (43 rules) |
| #S-8 | done | `tool_embedding_upsert()` (covered by S-7 test) |
| #S-9 | done | `POST /api/tools/discover` semantic + hybrid (11 rules) |
| #S-10 | done | `POST /api/tools/pick` + fail-closed `no_semantic_match` (5 rules) |
| #S-11 | done | `DiscoveryMetricsRing` + `GET /api/telemetry/discovery/recent` (32 rules) |
| #S-12 | done | `dns_semantic_query()` overlay intersecting DNS candidates with embedding ranking (10 rules) |
| #S-13 | done | catalog parity grown to 4 new surfaces + 4 new error codes; `contract-catalog-parity-contract` green |
| #S-14 | done | this README update + `discovery-smoke.sh` E2E |

**Scope fences (S-batch):** brute-force cosine only; no ANN / HNSW / IVF. BM25 parameters pinned; no learned ranker. Service+tool descriptors are the sole semantic signal; graph-aware metadata (W.8/W.9) stays fenced. C-level Semantic-DNS surface is UNCHANGED; the semantic-query path is a PHP overlay added by #S-12, preserving the existing keyword API.

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

### Semantic discovery smoke (S-batch)

```bash
MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1 \
demo/model-inference/scripts/discovery-smoke.sh
```

Exercises the keyword path against an empty registry first (never requires an
embedding worker), then — when an embedding model fixture is available —
probes `/api/discover` (semantic + hybrid), `/api/tools/discover`,
`/api/tools/pick` (including the `no_semantic_match` fail-closed path), and
`/api/telemetry/discovery/recent`. Without docker compose it runs the
offline contract suite and exits 0.

---

## Prompting a tiny model for reliable chat memory

SmolLM2-135M is the chat fixture this demo ships with. Naïvely wired — single
`prompt` field per request, default sampling, no system turn — it is
effectively useless for multi-turn conversation: every reply collapses into
one of two failure modes, and neither is a bug in King or llama.cpp.

**Failure mode A — training-data echo.** With no system prompt and temperature
0.7, the model snaps to a generic "friendly assistant" snippet baked into its
training data regardless of the user's question. One observed case: five
different food/memory questions all returned the same Croatia/beaches/colors
paragraph. The model isn't stuck; it's picking the highest-probability
"neutral" output and that output is very stable across unrelated prompts.

**Failure mode B — previous-reply echo.** Even with a system prompt, once the
assistant produces a short confident answer like `"My name is Julius."`, the
next turn's most likely continuation — to the model — is the exact same
string, regardless of the new question. *Name recall* succeeds; every
follow-up ("Where do I live?", "What is my job?") returns `"My name is
Julius."` verbatim. This is small-model mode collapse: the model uses the
immediately-preceding assistant turn as a template and copies it.

**Three cheap levers fix both.** Verified end-to-end against this backend
with a 4-fact Playwright stress test (name, city, job, food — 4/4 recalled):

1. **A strong system prompt, anchored to the *latest* question.**
   The demo ships with:
   > *"You are a concise factual assistant. Read the full conversation
   > history. Answer ONLY the LATEST user question. Do not repeat or quote
   > your previous reply. Each answer must directly address the most recent
   > question and use the specific fact it asks about…"*

   The phrase "do not repeat or quote your previous reply" is what breaks
   failure-mode B in combination with the penalties below. Dropping it
   brings the echo behaviour back.

2. **Repetition penalties.** Plumbed in sampling (#T-3):
   `frequency_penalty = 0.8`, `presence_penalty = 0.6`. OpenAI-compatible
   values that llama.cpp's sampler pushes the model away from tokens it
   just emitted, and away from any token already present in the output. 0.8
   is aggressive enough to be felt; lower values (0.3) don't reliably stop
   the Julius-echo on a 135M model.

3. **Temperature 0.2, history cap 8 turns.** Low temperature keeps answers
   focused when the prompt is well-specified; the tight history cap keeps
   the WS payload small and fits the SmolLM2 2048-token context window.
   Both are surface defaults the user can override in the `/ui` control
   bar.

**What stays broken — model capacity.** Even with all three levers, some
things do not work on 135M:

- *Multi-fact extraction from a single sentence.* "Remember my name is
  Julius, I live in Berlin, I work as an engineer" followed by "Where do I
  live?" often still picks the wrong fact. Splitting the facts across
  separate turns restores reliability.
- *Chain reasoning over recalled facts.* "You know my city — is there a
  river there?" needs world-knowledge integration 135M params cannot
  support.
- *Consistent refusal of off-topic continuations.* The model sometimes
  hallucinates a follow-up sentence after a correct answer (e.g. "My name
  is Julius, which means 'I am' in German"). The fact is right; the
  embellishment is fabricated. Tightening `max_tokens` to ~8 for recall
  probes is the practical mitigation.

These are **model-size limits, not demo limits.** Swapping SmolLM2-135M for
a 1B+ GGUF via `POST /api/models` (Qwen2.5-1.5B-Instruct, Llama-3.2-1B,
Phi-3-mini-3.8B) removes them without any further tuning.

**Where the levers live in code:**

- System prompt + defaults: `public/chat.html` (`state.systemPrompt`, the
  temperature / freq_pen / pres_pen input defaults).
- Sampling validator: `domain/inference/inference_request.php`
  (`frequency_penalty`, `presence_penalty` as optional sampling fields).
- Plumb-through to llama.cpp: `domain/inference/inference_session.php`
  (HTTP path) and `domain/inference/inference_stream.php` (WS path) — both
  include the penalties in the `/v1/chat/completions` body only when
  non-zero so pre-T-3 fixtures see the same payload as before.

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
- **Hybrid retrieval** (BM25/TF-IDF + vector fusion) is proved for *discovery*
  in S-batch (`/api/discover?mode=hybrid`, pinned BM25 k1=1.2, b=0.75). For
  *document* retrieval (`/api/retrieve`, `/api/rag`) it remains fenced —
  retrieval stays semantic-only until a dedicated R-follow-up leaf lands.
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
documentation/dev/model-inference.md             # this file

demo/model-inference/
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
    service-descriptor.contract.json            # service descriptor + embedding input (#S-1)
    tool-descriptor.contract.json               # tool descriptor + mcp_target (#S-7)
  scripts/
    smoke.sh                                    # 9-phase compose end-to-end (#M-17)
    rag-smoke.sh                                # 10-phase RAG pipeline smoke (#R-15)
    discovery-smoke.sh                          # S-batch discovery smoke (#S-14)
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
      module_discover.php                       # /api/discover + /api/tools/{discover,pick} (#S-4, S-9, S-10)
      module_inference.php                      # POST /api/infer + GET /api/transcripts/:id
      module_realtime.php                       # GET /ws + WS streaming
      module_telemetry.php                      # /api/telemetry/{inference,rag,discovery}/recent
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
      discovery/service_descriptor.php          # service descriptor validator (#S-1)
      discovery/service_embedding_store.php     # svec-{hex} store + service_embeddings (#S-2)
      discovery/service_embedding_upsert.php    # validate → embed → persist (#S-3)
      discovery/semantic_discover.php           # brute-force cosine over services (#S-5)
      discovery/hybrid_discover.php             # BM25 + cosine fusion with alpha (#S-6)
      discovery/tool_descriptor.php             # tool descriptor validator + tokenizer (#S-7)
      discovery/tool_descriptor_store.php       # tvec-{hex} + tool_embeddings + upsert (#S-7, S-8)
      discovery/tool_discover.php               # semantic + hybrid tool ranking (#S-9)
      discovery/mcp_pick.php                    # top mcp_target + no_semantic_match fail-closed (#S-10)
      discovery/dns_semantic_query.php          # DNS ∩ embedding ranking overlay (#S-12)
      routing/inference_routing.php             # Semantic-DNS routing helper (#M-14)
      telemetry/inference_metrics.php           # inference metrics ring (#M-12)
      telemetry/rag_metrics.php                 # RAG metrics ring (#R-12)
      telemetry/discovery_metrics.php           # discovery metrics ring (#S-11)
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
- `BACKLOG.md` — open model-placement, distributed inference, and fine-tuning work.
- `documentation/project-assessment.md` — what is verified now (post-merge sweep, not
  here).
- `READYNESS_TRACKER.md` — long-form closure tracker including V / W / X /
  Y / Z / AA sections this demo starts unfencing.
- `demo/video-chat/` — structural convention this demo mirrors (module
  dispatcher, contract catalog, `*-contract.sh` tests, compose smoke).
- `demo/userland/flow-php/src/McpServiceDiscovery.php` —
  `McpServiceResolution` failover shape reused by `inference_routing.php`.
