# King: Model Inference as a Service on the Agentic Web

Podcast preparation resource -- architectural overview and narrative guide.

This is a standalone document. It assumes no prior exposure to the King
repository and builds from thesis through proof to honest assessment. Each
section maps to a natural podcast segment.

Status: April 2026. Based on the closed M-batch sprint (18 leaves, 17
contract tests) on `feature/model-inference` and the planned R-batch RAG
pipeline sprint.

---

## 1. The Thesis -- Why a PHP Native Runtime for AI Infrastructure

PHP runs more than three-quarters of the web. WordPress alone accounts for
over 40% of all websites. Drupal, Laravel, Symfony, Magento, and hundreds of
internal platforms push that number well past 75%. That is not legacy
liability. It is the largest continuous deployment surface in computing.

Now look at what it takes to serve a single inference request today. A typical
stack involves a model server (vLLM, TGI, Ollama), a reverse proxy (nginx,
Envoy), a load balancer, a service mesh for discovery, a vector database for
retrieval, an orchestrator for multi-step workflows, a metrics collector, a
model registry, and an object store for checkpoints. Each of these is a
separate binary, a separate config surface, a separate failure domain, a
separate upgrade path. The PHP application that originally handled the user
request is reduced to a thin HTTP client that fires a prompt at one of those
sidecars and waits.

King inverts that architecture.

King is a C-level PHP extension -- not a framework, not a userland library --
that gives PHP direct, native access to the infrastructure primitives that
inference and agent workloads need. It compiles against quiche and BoringSSL
for QUIC and TLS, exposes HTTP/1, HTTP/2, and HTTP/3 client and server paths
with explicit session and stream ownership, implements a binary wire format
(IIBIN), a multi-backend object store, service discovery (Semantic DNS), a
model context protocol (MCP), a pipeline orchestrator, telemetry export
(OTLP), and autoscaling hooks -- all from one shared native kernel.

The result is that a single PHP process can boot a model inference server,
register itself with a discovery service, probe its own hardware, select a
model that fits the available GPU and RAM, spawn a local inference engine,
stream tokens to clients over binary WebSocket frames, persist transcripts in
a durable store, record telemetry, failover to a sibling node when it goes
down, and participate in a capacity scaling loop -- without a single sidecar
process.

That is what the model-inference demo proves. Not as a prototype or a
planning exercise, but as running code backed by 17 contract tests, committed
binary sample vectors, and two-node failover smoke.

### Why inference is the natural proof

Inference is simultaneously transport-heavy (token streaming at 50-100+
tokens per second), state-heavy (multi-gigabyte GGUF artifacts, transcripts,
model metadata), discovery-heavy (which node has GPU capacity for a 7B
model?), and control-heavy (spawn workers, health-poll, drain, rekey, reap).
A workload that touches all four of King's runtime planes is the hardest
thing to fake and the most convincing thing to demonstrate.

### The agentic web context

The "agentic web" refers to the emerging paradigm where AI agents
autonomously discover services, negotiate capabilities, move artifacts, and
orchestrate multi-step workflows across distributed infrastructure. An agent
does not call one endpoint and stop. It discovers an embedding service,
uploads a document, waits for chunking, queries a retrieval index, injects
context into an inference prompt, streams the completion back to a user, and
records the entire pipeline as one observable trace.

Consider what an agent needs from infrastructure:

- **Discovery**: which inference node has GPU capacity for my model? Which
  embedding service supports my dimensionality? The agent cannot hardcode
  addresses in a world where nodes appear, drain, and disappear.
- **Artifact transport**: model weights are multi-gigabyte files. Training
  checkpoints, document corpora, and embedding vectors need to move between
  nodes with integrity guarantees. This is not small JSON payloads.
- **Orchestration with failure identity**: when step 3 of a 5-step pipeline
  fails, the agent needs to know whether it was a validation error (fix the
  input), a timeout (retry with a longer budget), a missing handler (route
  to a different node), or a transport failure (the peer is down). Generic
  "error" is not actionable.
- **Observability as a control input**: telemetry is not just for dashboards.
  It feeds autoscaling decisions, routing preferences, and agent retry logic.
  A spike in TTFT on node-a should automatically shift traffic to node-b
  before an operator notices.
- **Binary efficiency**: agents that stream tokens, embeddings, or structured
  tool results at high frequency cannot afford JSON serialization overhead
  on every message.

Traditional PHP infrastructure offers none of these natively. Traditional
AI infrastructure offers them as separate services. King offers them as one
runtime.

King is built for that world. The model-inference demo is the first concrete
proof.

---

## 2. The Four Runtime Planes -- Mapped to Agent Needs

King does not collapse all runtime behavior into one generic event loop. It
separates the system into four planes, each designed for a distinct category
of work. This separation matters because it prevents a token stream from
stalling a model download, a scaling decision from blocking a pipeline step,
or an orchestrator checkpoint from racing a WebSocket fanout.

### Realtime Plane -- WebSocket + IIBIN

The realtime plane handles long-lived bidirectional channels. For inference,
this is where token streaming lives. A client opens a WebSocket, sends an
`infer.start` text frame with a typed request envelope, and receives
token-by-token responses as IIBIN binary frames with 24-byte fixed headers.
No JSON parsing on the hot path. No SSE polling. Agents that need live
streamed completions operate here.

### Media and Transport Plane -- QUIC / HTTP/3 / TLS

The transport plane handles session ownership, stream lifecycle, connection
reuse, TLS tickets, cancellation, and timeout semantics. For inference, this
is the non-streaming HTTP path (`POST /api/infer`) and the model artifact
download path. For agents coordinating across multiple services, QUIC
provides independent streams without head-of-line blocking -- one slow
inference request does not stall a fast status check on the same connection.
Session resumption via TLS tickets reduces handshake overhead to near-zero on
warm connections.

### Control Plane -- MCP + Pipeline Orchestrator

The control plane handles structured inter-agent communication and durable
multi-step workflows. MCP (Model Context Protocol) is King's native protocol
for request/upload/download between peers with explicit transfer identity,
deadline propagation, and cancellation. The pipeline orchestrator manages
multi-step runs across local, file-worker, and remote-peer execution
backends with explicit failure classification per step: validation, runtime,
timeout, backend, missing handler, or cancelled. Agents that compose
"embed then retrieve then infer" as one durable pipeline operate here.

### State and Fleet Plane -- Object Store, Semantic DNS, Autoscaling

The state plane handles durable artifacts and fleet-level behavior. The
object store persists GGUF model weights, inference transcripts, embedding
vectors, and pipeline checkpoints across local, distributed, and cloud
backends with SHA-256 integrity, byte-range reads, and resumable uploads.
Semantic DNS enables live service discovery -- inference nodes register
themselves with GPU capabilities and load metrics, and agents route work to
the best-fit node without hardcoded addresses. Autoscaling closes the loop:
telemetry signals feed a controller that provisions or drains nodes, which
register or deregister via Semantic DNS, which agents discover on their next
routing decision.

---

## 3. The Model-Inference Demo -- A Concrete Proof

The demo lives under `demo/model-inference/` in the King repository. It is
not a sketch or a target-shape document. It is running code that serves real
inference from real model weights against real hardware probes.

### Architecture at a glance

One PHP file (`server.php`) boots the King extension, initializes a SQLite
database and the King Object Store, registers the node with Semantic DNS,
and enters a blocking accept loop via `king_http1_server_listen_once()`. Each
request dispatches through a deterministic router to one of ten focused
modules: runtime, profile, registry, embedding, ingest, inference, realtime,
telemetry, routing, and ui. Domain logic lives in dedicated directories
(profile, registry, inference, routing, telemetry). Infrastructure support
(database, object store, token frame codec, llama.cpp worker management) is
separated from business rules.

The inference engine is llama.cpp, pinned at release b8802. King does not
reimplement a transformer runtime. It owns every surface around the engine:
hardware profiling, model selection, artifact storage, worker lifecycle,
request validation, token streaming, transcript persistence, service
discovery, routing, and telemetry. llama.cpp is the execution engine behind
King's native contract, not a proxy identity.

### Hardware profiling -- real probes, never fabricated

Every inference node exposes `GET /api/node/profile`, which returns a live
hardware snapshot: CPU core counts and brand, total and available RAM, GPU
presence and kind (Metal, CUDA, ROCm, or none), device count, and VRAM
totals. On macOS, the probes use `sysctl` and `system_profiler`. On Linux,
they parse `/proc/cpuinfo`, `/proc/meminfo`, and call `nvidia-smi` or
`rocminfo`.

The honesty rule is strict: if a probe cannot read a value, it reports zero.
VRAM is never fabricated. Physical core count falls back to logical when the
platform does not distinguish them. This matters because model-fit selection
downstream trusts these numbers. A fabricated 16 GB VRAM report would cause
the selector to load a model that cannot actually run.

### Model registry and artifact persistence

Models are registered via `POST /api/models` with a raw GGUF body and
metadata headers (`X-Model-Name`, `X-Model-Quantization`, etc.). The binary
streams through `king_object_store_put_from_stream()` -- no full in-memory
copy -- and lands in the King Object Store under a flat key. SHA-256 is
computed from the actual persisted bytes, never trusted from the client.

The registry supports eight quantization levels (Q2_K through F16) and
enforces a unique constraint on `(model_name, quantization)`. On boot, the
server can auto-seed from local GGUF fixtures, registering nine SmolLM2-135M
variants idempotently.

### Deterministic model-fit selection

`model_fit_selector.php` is a pure function. It takes a hardware profile and
a list of registry entries, applies a filter chain, and returns a structured
result: winner, candidates that passed, rejected entries with reasons, and
the rules that were applied.

The filter chain rejects models that exceed available RAM, require a GPU when
none is present, require VRAM when the probe cannot read it (instead of
treating unreadable VRAM as an arbitrary budget), or use an unsupported
quantization. Survivors are sorted by parameter count descending, then
quantization precision descending, then model ID ascending for determinism.

Agents can inspect the rejection reasons. A model rejected for
`vram_budget_exceeded` tells the agent to look for a smaller quantization. A
model rejected for `gpu_required_but_none_present` tells the agent to route
to a GPU-equipped node.

### LlamaCppWorker lifecycle

The `LlamaCppWorker` class manages one llama.cpp server subprocess. It has
five states: stopped, starting, ready, draining, and error. `start()` spawns
the process via `proc_open()` with explicit `LD_LIBRARY_PATH`, stdout/stderr
redirect, and model path. `waitForReady()` polls `/health` through King's
native HTTP/1 client until the worker returns 200 or the timeout expires.
`drain()` sends SIGTERM, waits for graceful shutdown, then sends SIGKILL
after the deadline.

There is no mock mode. If the llama.cpp binary or the GGUF file is missing,
the worker throws. State is reconciled on every call by checking the
subprocess status, so an unexpected crash flips the state to error without
silent drift.

The inference session enforces a one-active-worker policy. If a request
targets a different model than the currently loaded one, the old worker
drains before the new one starts.

### Dual inference paths

The demo exposes two parallel transport surfaces over the same inference
engine:

`POST /api/infer` is the HTTP non-streaming path. The request envelope
carries a model selector, prompt, sampling parameters, and `stream: false`.
The response is a JSON object with the full completion text, token counts,
timing (TTFT, duration), stop reason, and worker diagnostics.

`GET /ws` is the WebSocket streaming path. The client upgrades to WebSocket,
sends an `infer.start` text frame with the same envelope shape but
`stream: true`, and receives binary IIBIN TokenFrame responses -- one delta
frame per token batch, one end frame with the timing summary. Both paths
share the same worker, the same model, the same metrics ring, and the same
transcript store.

Transport cross-checks are enforced: `stream: true` on the HTTP endpoint is
rejected with `invalid_request_envelope`. `stream: false` on the WebSocket
endpoint is rejected the same way. The validator is a pure function shared by
both modules.

### Transcript persistence and telemetry

Every successful inference is persisted to the King Object Store under a
flat key (`transcript-{yyyymmdd}-{request_id}`) and retrievable via
`GET /api/transcripts/{request_id}`. The transcript captures the full
request envelope, model metadata, completion text, timing, and a
server-owned timestamp.

A bounded-FIFO metrics ring (configurable capacity, default 100) records
per-request telemetry: request ID, transport (HTTP or WS), model, token
counts, TTFT, duration, tokens per second, VRAM snapshot, and GPU kind.
Failed inferences do not record, preventing partial data from corrupting
averages. The ring is exposed via `GET /api/telemetry/inference/recent`.

---

## 4. IIBIN TokenFrame -- The Wire Protocol Story

The TokenFrame is a fixed-layout binary framing protocol for server-to-client
token streaming. It replaces the SSE-over-JSON pattern that most inference
servers use with a compact, versioned, schema-pinned binary format.

The header is exactly 24 bytes, big-endian:

| Offset | Field | Size | Type | Purpose |
|--------|-------|------|------|---------|
| 0 | magic | 4 | u32 | `KITF` (0x4B495446) -- reject before any other validation if wrong |
| 4 | version | 1 | u8 | Pinned at 1; unknown versions are rejected, never silently parsed |
| 5 | frame_type | 1 | u8 | delta (0), end (1), error (2) |
| 6 | flags | 1 | u8 | bit 0: final in burst, bit 1: UTF-8 boundary safe |
| 7 | reserved1 | 1 | u8 | Must be 0; non-zero on decode is a forward-compatibility trap |
| 8 | sequence | 4 | u32 | Monotonically increasing within one request stream |
| 12 | request_id_crc32 | 4 | u32 | Fast de-mux key (CRC32 of the ASCII request ID) |
| 16 | token_count | 2 | u16 | Tokens packed into this frame (batching is allowed) |
| 18 | payload_length | 4 | u32 | Exact byte extent of the payload that follows |
| 22 | reserved2 | 2 | u16 | Must be 0; same forward-compatibility rule |

A delta frame carrying the UTF-8 token "hello" with two tokens is 29 bytes
total: 24 bytes of header, 5 bytes of payload. The equivalent SSE/JSON
representation -- `data: {"token":"hello","sequence":1,"request_id":"req_abc"}\n\n`
-- is easily 80 bytes before HTTP chunked-transfer framing. At 100 tokens
per second, that difference compounds to roughly 5 KB/s saved per concurrent
stream.

The contract JSON includes three committed sample vectors with exact hex
encodings. The contract test re-encodes each vector and asserts bit-identical
output. Round-trip decode is tested for every header field. Every rejection
path (bad magic, unsupported version, unknown frame type, non-zero reserved
fields, truncated frames, overlong frames) has explicit coverage. This is not
a specification that exists only on paper.

The reserved fields are a deliberate forward-compatibility trap. Any future
version that repurposes those bytes must bump the version number first.
Decoders that encounter non-zero reserved bytes on version 1 reject the
frame outright. This prevents silent misinterpretation when old clients
encounter new frames.

For agents, binary framing means that token-level streaming is not just
faster -- it is structurally different. The client does not parse a text
stream looking for JSON boundaries. It reads a fixed 24-byte header, knows
exactly how many payload bytes follow, and can decode immediately. On
constrained devices or high-concurrency edge nodes, that predictability
matters.

---

## 5. Semantic DNS + Routing -- Agents Discovering Infrastructure

When an inference node boots, it registers itself with King's Semantic DNS as
a `king.inference.v1` service. The registration carries live attributes
extracted from the hardware profile: GPU kind, VRAM total and free, supported
quantizations, streaming capability, and the node's health endpoint URL.

A bounded-retry heartbeat loop (up to 5 attempts, 100ms between tries)
confirms that the node appears in the discovered topology. On shutdown, the
node deregisters itself -- marking unhealthy before process exit so stale
entries do not linger.

Routing is handled by a dedicated domain module (`inference_routing.php`)
that wraps two Semantic DNS primitives:
`king_semantic_dns_discover_service()` returns all nodes matching a service
type and optional criteria (model name, quantization, minimum free VRAM).
`king_semantic_dns_get_optimal_route()` applies affinity rules if a load
balancer is configured.

The routing module normalizes discovered nodes, ranks them (healthy before
degraded before draining, then by current load percent ascending, then by
service ID for deterministic tiebreak), and returns a structured resolution:
primary, failover list, full candidate list, and timestamp.

The two-node failover proof uses a Docker Compose file that spawns node-a
and node-b on separate ports with isolated data volumes. The smoke script
sends a prompt to node-a, stops node-a, verifies it is unreachable, then
sends a second prompt to node-b. Node-b serves the request without
reconfiguration. The transcript is retrievable from node-b. The routing
diagnostic endpoint on node-b shows the updated candidate list.

The explicit scope fence is important: this proves next-request failover, not
mid-stream migration. If node-a dies during an active inference, the
in-flight generation is lost. The next request routes elsewhere. This fence
is stated in the demo README and in the smoke script comments. Claiming
otherwise would be dishonest.

For agents, this means dynamic service discovery without hardcoded addresses,
without an external service mesh, and without a separate discovery sidecar.
An agent that needs a GPU-equipped node for a 7B model queries Semantic DNS,
receives a ranked candidate list, sends its request to the primary, and falls
back to the next candidate if the primary is unreachable.

---

## 6. The Autoscaling Feedback Loop

King's autoscaling subsystem closes the loop between observed load and
available capacity. The cycle works like this:

Telemetry signals (CPU utilization, memory pressure, active connections,
requests per second, response latency, queue depth) feed the autoscaling
controller. The controller evaluates the signals against configured
thresholds and cooldown windows. If it decides to scale up, it provisions a
new node through the configured provider (Hetzner is the verified backend),
waits for the node to bootstrap, register, and reach ready state, then
confirms it appears in Semantic DNS. If it decides to scale down, it drains
the target node (marking it draining in DNS, waiting for in-flight work to
complete, then deleting the provider resource).

Node lifecycle is explicit: provisioned, registered, ready, draining,
deleted. If a node fails to bootstrap or reach readiness, the controller
rolls it back instead of leaving a stale entry in the topology.

Decision visibility is a first-class concern. Every scaling decision records
a structured explanation: which signals triggered it, which thresholds were
crossed, which cooldown windows blocked action, and what the outcome was
(hold, scale up, cooldown blocked, scale down). Operators and agents can
query this state to understand not just what happened, but why.

For agents, this means capacity is not a static number configured at deploy
time. It is a live, observable, feedback-driven surface. An agent that
observes increasing latency on its inference requests does not need to
manually provision new nodes. The autoscaling controller is already watching
the same signals and acting on them.

---

## 7. The R-Batch RAG Pipeline -- Next Evolution

The next sprint (R-batch, 16 leaves, branch `feature/rag-pipeline`) extends
the model-inference demo with embedding generation, document ingestion, text
chunking, vector storage, retrieval, and an end-to-end RAG pipeline.

The embedding engine is the same pinned llama.cpp binary, running in
`--embedding` mode with a dedicated GGUF embedding model. A separate
`EmbeddingSession` manages this worker independently from the inference
worker, so the two do not contend for the same subprocess.

The pipeline composes in-process on the same server:

1. **Ingest**: `POST /api/documents` accepts plain text, stores it in the
   King Object Store, and returns a document ID with SHA-256 integrity.
2. **Chunk**: A text chunker splits the document into fixed-size segments
   with configurable overlap, persisted with metadata in SQLite.
3. **Embed**: Each chunk is embedded via the llama.cpp embedding worker.
   Vectors are stored in the Object Store under flat keys.
4. **Retrieve**: `POST /api/retrieve` embeds the query, scans stored vectors
   via brute-force cosine similarity, and returns ranked chunks with scores.
5. **RAG**: `POST /api/rag` composes the full pipeline -- retrieve context
   chunks, inject them into the inference prompt, and return a grounded
   completion.

The honest fences are explicit: vector search is brute-force cosine
similarity over loaded vectors. There is no HNSW index, no IVF, no
approximate nearest neighbor claim. The document format is plain text only --
no PDF, HTML, or Markdown parsing. The corpus size is demo-scale (under 10K
vectors). Concurrent RAG execution is not supported. These fences are listed
in the README and enforced by the sprint's non-negotiable direction.

For agents, the R-batch proves that King's primitives (object store, worker
lifecycle, telemetry, Semantic DNS) compose into a real RAG pipeline without
external vector databases, embedding services, or orchestration sidecars.
The same PHP process that serves inference also ingests documents, generates
embeddings, and retrieves context.

---

## 8. What King Is and What King Is Not

### What King is

King is a proof that PHP can own the infrastructure layer for AI workloads.

The model-inference demo is not a mockup. It runs real inference from real
GGUF model weights on real hardware. The hardware profile reports real CPU
cores, real available RAM, real GPU VRAM -- or honestly reports zero when
the probe fails. The model-fit selector applies real constraints. The worker
lifecycle manages a real subprocess with real signals. The token streaming
uses a real binary wire format with committed sample vectors. The two-node
failover runs in a real Docker Compose stack. Every claim is backed by a
contract test that asserts specific behavior, not just "it doesn't crash."

The engineering discipline is the thesis in action. The demo README carries
an explicit list of scope fences -- things King does not claim. The contract
catalog is parity-checked against the live code. Target-shape surfaces that
are not yet implemented are kept in a separate section and fail-closed if
accessed. There is no behavior presented as distributed that is actually
local-only.

### What King is not

King is not a production LLM platform. SmolLM2-135M is a 135-million
parameter model useful for proving the architecture. It is not a replacement
for GPT-4 or Claude.

King is not a replacement for dedicated inference engines. It wraps
llama.cpp; it does not reimplement attention, KV caching, or batch
scheduling. The value is in the surfaces around the engine, not in competing
with the engine itself.

King is not a distributed inference shard orchestrator. The failover proof
routes the next request to another node. It does not migrate in-flight
generation. Tensor parallelism, pipeline parallelism, and expert routing
across hosts are explicitly out of scope.

King is not a concurrent WebSocket server -- yet. The current handler blocks
the HTTP listen loop during inference. Concurrent sessions are a future
hardening leaf.

King is not a fleet-wide model placement optimizer. Per-node fit selection
is proved and deterministic. Fleet-wide placement across many nodes is
fenced.

### The cultural gap

The PHP community is deeply accustomed to the request-response model:
short-lived processes, frameworks that bootstrap per request, ORMs that
manage database connections automatically. King asks PHP developers to think
about session lifecycle, transport ownership, binary wire formats, process
supervision, GPU probing, worker drain semantics, and topology-aware routing.

That is not an impossible transition -- Go and Node developers deal with
these concerns daily -- but it is a real cultural shift for the median PHP
developer. No documentation can eliminate that gap entirely. The path forward
runs through framework integration: Laravel and Symfony bridges that abstract
the lower-level primitives while preserving the architectural benefits.

Without those bridges, King's audience is capped at operations-minded PHP
teams that are already comfortable with systems thinking. With them, King
could change what PHP means in AI infrastructure.

---

## 9. Conversation Starters

These questions are designed for a podcast host to pick from. Each one opens
a different facet of the architecture.

- "You chose PHP for AI infrastructure. Walk me through the moment that idea
  stopped sounding crazy."

- "The TokenFrame header is 24 bytes. Why not just use Server-Sent Events
  and JSON like everyone else?"

- "Your README has an explicit list of things King does not do. Most projects
  bury their limitations. Why lead with the fences?"

- "If an AI agent needs to discover which node has GPU capacity for a 7B
  model, what actually happens at the Semantic DNS level?"

- "What does PHP gain from King that it cannot get from a Go or Rust sidecar
  sitting next to it?"

- "The RAG pipeline uses brute-force cosine similarity. You call that an
  honest fence. Where does that fence lead next?"

- "What does the autoscaling feedback loop look like when it decides not to
  scale?"

- "You have 17 contract tests that assert specific wire-level behavior. What
  breaks in the project culture if you ship a feature without one?"
