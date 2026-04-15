# King Model Inference Demo (Active Build-Out Track)

This directory is the active build-out of a first-class **inference-serving**
role on top of the King native runtime. It maps to tracker sections **V**
(AI/SLM Platform) and **Z** (Inference Serving) in `READYNESS_TRACKER.md`, both
of which currently sit in the PLANNING / FUTURE WORK block. Nothing in those
sections is verified yet. This demo is the first honest slice that will tick
the first V/Z boxes only after its contract tests land green on `main`.

## What This Demo Is

A two-node model-inference gateway that:

- hardware-profiles each node (real CPU/RAM/GPU probes — no faked VRAM)
- loads GGUF quantized weights from the King object store
- spawns `llama.cpp server` as a King-owned subordinate process
- serves inference over an HTTP non-streaming surface (`POST /api/infer`) and
  a parallel WebSocket streaming surface carrying typed IIBIN token frames
- advertises itself through Semantic-DNS as service type `king.inference.v1`
- supports capability-based routing and deterministic failover between nodes
- persists every transcript to the object store so conversations survive
  restart

The procedural and OO surfaces live over the same native King kernels. The
demo does not ship its own inference engine — King owns hardware profile,
model selection, artifact storage, streaming transport, routing, and failover,
while `llama.cpp` is the execution engine behind King's native contract.

## What This Demo Is Not

Every item in this list is explicitly **target-shape, not verified** in the
current sprint. A capability claim on any of these is deferred to a future
sprint whose contract test proves it end to end.

- **MoE multi-node expert routing** (tracker V.5).
- **Sharded / distributed inference across nodes** — this demo proves
  capability routing and failover between independent inference nodes. It does
  **not** prove sharded execution across nodes. Tracker V.4's "distributed
  execution" bullet stays fenced.
- **Fine-tuning pipelines** (entire tracker section Y). Out of scope.
- **RAG / embeddings / retrieval** (tracker sections W and X). Out of scope.
  The state-machine pattern from `demo/userland/flow-php/src/RagOrchestrationReference.php`
  is reused for its lifecycle shape only, not its retrieval semantics.
- **External provider wrappers** (tracker section AA). OpenAI, Anthropic,
  Bedrock, and larger model families live under `packages/` as explicit
  extensions, not inside this demo.
- **Cross-region GPU placement.** Routing criteria in this sprint are
  local-capability-first.
- **GPU CI matrix** beyond CPU `llama.cpp`. Apple Metal paths run on dev
  machines; no GPU-required tracker box ticks on dev-only proof.
- **Prompt-loss-free mid-stream failover.** The failover leaf proves "the next
  request routes elsewhere," not "in-flight generation migrates."
- **Context-window engineering / prompt caching.**
- **Admission control / rate limiting / quota.** Future `packages/inference-policy`
  extension.
- **Frontend client.** The wire contract is exercised by PHP-driver tests only
  in this sprint. A UI demo follows once the wire contract stabilizes.

## Sprint Plan

This demo lands on branch `feature/model-inference`. The sprint batch is
tracked in `ISSUES.md` under "M-batch: Model Inference" as `#M-1` through
`#M-18`:

| Phase | Leaves | Shippable outcome |
|-------|--------|-------------------|
| Day 1 | M-1, M-2, M-3 | Server boots, deterministic module order, catalog fixture published. |
| Day 2 | M-4, M-5 | Hardware profile endpoint + object-store-backed model registry with SHA-256 round-trip. |
| Day 3 | M-6, M-7 | Model-fit selector + real `llama.cpp` subordinate worker (tiny GGUF fixture, no mock). |
| Day 4 | M-8, M-9 | Typed inference-request envelope + IIBIN binary token-frame wire contract. |
| Day 5 | M-10, M-11, M-12 | HTTP and WS streaming inference against a real GGUF plus inference telemetry. |
| Day 6 | M-13, M-14 | Semantic-DNS self-registration + capability-based routing. |
| Day 7 | M-15, M-16 | Two-node failover + transcript persistence. |
| Day 8 | M-17, M-18 | Compose end-to-end smoke + honest README/fences + ISSUES update. |

Per `EPIC.md` discipline: tracker boxes (`READYNESS_TRACKER.md`,
`PROJECT_ASSESSMENT.md`) are **not** ticked from this branch. A separate
post-merge verification sweep ticks V/Z bullets only for leaves whose contract
test is green on `main`.

## Layout

```
demo/model-inference/
  README.md                       # this file
  contracts/v1/                   # versioned wire + envelope contracts (grows per leaf)
  backend-king-php/               # the King PHP backend
  scripts/                        # smoke + failover runners (added at M-15, M-17)
  docker-compose.v1.yml           # added at M-17 (two-node)
```

## Contracts

The canonical API + WebSocket catalog is
`contracts/v1/api-ws-contract.catalog.json`. Every endpoint and every WS event
the backend exposes must be listed there; parity is enforced by a contract
test (added at `#M-3`) that fails CI shard-1 on drift.

Typed wire contracts (one JSON file per wire shape, with header-offset table
and sample vectors for binary frames) follow the same discipline as
`demo/video-chat/contracts/v1/wlvc-frame.contract.json`.

## Related

- `EPIC.md` — stable charter + non-negotiables (no-capability-claim-without-proof,
  no-simulated-as-real, no-contract-shrink).
- `ISSUES.md` — active execution queue including the M-batch.
- `PROJECT_ASSESSMENT.md` — what is verified now (sweep after merge, not here).
- `READYNESS_TRACKER.md` — long-form closure tracker including V / W / X / Y /
  Z / AA sections that this demo starts unfencing.
- `demo/video-chat/` — structural convention this demo mirrors (module
  dispatcher, contract catalog, `*-contract.sh` tests, compose smoke).
- `demo/userland/flow-php/src/RagOrchestrationReference.php` — lifecycle-event
  state-machine pattern reused here for inference runs.
