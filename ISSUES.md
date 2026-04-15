# King Issues

> Status: 2026-04-14
> Focus: SFU-first media path + WLVC (Wavelet + Kalman) implemented in PHP backend

This tracker is now aligned to the requested direction:
- SFU (server-forwarded media), not mesh P2P as the primary architecture
- codec pipeline (wavelet + Kalman) implemented in PHP backend logic, not only frontend prototype logic

## Non-negotiable direction

- active backend path stays `King/PHP` (no Node fallback as target architecture)
- primary call topology is **SFU**, not browser mesh P2P
- WLVC codec path (wavelet + Kalman stages) must be implemented in backend PHP code and exposed through stable APIs
- no contract shrink to speed up CI; build the stronger path
- prove behavior with wire/runtime tests, not only mock UI flows

## Done in current branch

- [x] Canonical WLVC wire contract is versioned in-repo (`demo/video-chat/contracts/v1/wlvc-frame.contract.json`).
- [x] WLVC encode/decode contract tests exist for backend/frontend wire-envelope parity.

## Known prototype work (feature branch, not yet integrated here)

- [ ] RTP/DTLS/SRTP C slice from `feature/sfu-and-wasm-codec-for-video-demo` is not yet merged into this branch.
- [ ] SFU prototype from that branch is metadata/signaling oriented and still not the final server-side media-forwarding implementation required for v1.

## Open / To implement (priority order)

- [ ] `#1` Import and integrate the RTP C runtime slice into this branch (`extension/include/rtp.h`, `extension/src/media/rtp.c`, `extension/src/php_king.c`, `extension/config.m4`, stubs).
  Done when: extension builds cleanly with the RTP surface enabled and PHP stub parity reflects the exported API.

- [ ] `#2` Implement server-side SFU media forwarding in King runtime as the primary call topology.
  Done when: media forwarding is server-authoritative via SFU path and multi-party calls do not depend on mesh P2P as primary transport.

- [ ] `#3` Implement wavelet stage in PHP backend codec pipeline.
  Done when: wavelet transform runs in PHP backend path and is used in live media packets, not only local frontend loopback.

- [ ] `#4` Implement Kalman prediction/filter stage in PHP backend codec pipeline.
  Done when: Kalman stage is active in PHP encode/decode flow with deterministic behavior and test coverage.

- [ ] `#5` Bind end-to-end WLVC media path over King/PHP SFU pipeline.
  Done when: sender payload is encoded as WLVC, routed through SFU, and decoded remotely without local fake encode->decode loopback.

- [ ] `#6` Add codec negotiation + fallback policy (WLVC <-> standard WebRTC codec).
  Done when: mixed-capability clients connect reliably and fallback occurs deterministically with explicit telemetry.

- [ ] `#7` Complete PHP runtime hardening for RTP/DTLS/SRTP/SFU lifecycle.
  Done when: no resource leaks, no zombie peers, deterministic cleanup/reconnect/rekey behavior, and fail-closed error mapping.

- [ ] `#8` Add security and abuse protection for media/signaling channels.
  Done when: rate limits, room-membership authorization, replay/invalid-frame rejection, and clear close reasons are testably enforced.

- [ ] `#9` Lock performance budget and telemetry (CPU, RTT, join time, bitrate, frame drop, packet loss, SFU fanout cost).
  Done when: reproducible benchmarks and SLO targets are documented and exercised in CI/smoke.

- [ ] `#10` Close test matrix for SFU+WLVC runtime path.
  Done when: unit + PHPT + E2E multi-user + negative + fuzz coverage stay green under load and mixed client capabilities.

- [ ] `#11` Repo hygiene for codec track.
  Done when: no build/db/log artifacts are committed as product code and `.gitignore`/CI enforce clean artifact boundaries.

## Next step

- [ ] Start with `#1` (import RTP C slice), then `#2` (true SFU forwarding), then `#3/#4` (wavelet+Kalman in runtime).

## M-batch: Model Inference (branch `feature/model-inference`)

> Parallel track, does not block or replace the SFU/WLVC batch above. Maps to
> tracker sections `V` (AI/SLM Platform) and `Z` (Inference Serving). Tracker
> boxes in `READYNESS_TRACKER.md` and `PROJECT_ASSESSMENT.md` are **not** ticked
> from this branch; a post-merge verification sweep ticks V/Z bullets only for
> leaves whose contract test is green on `main`. See
> `demo/model-inference/README.md` for the sprint-day cadence.

Non-negotiable direction for this batch:

- backend engine is **llama.cpp server** (GGUF, CPU + Metal). King owns
  hardware profile, model-fit selection, artifact storage, streaming
  transport, routing, and failover; llama.cpp is the execution engine behind
  King's native contract, not a proxy identity.
- client transport is **WebSocket + IIBIN typed binary token frames**, with a
  parallel HTTP `POST /api/infer` non-streaming surface over the same native
  kernel (parallel surfaces, not wrappers).
- out of scope: RAG (`W`, `X`), fine-tune (`Y`), external providers (`AA`),
  MoE routing (`V.5`), sharded inference, GPU CI matrix, mid-stream handoff.
  These are explicitly fenced in `demo/model-inference/README.md`.

### Done in current branch

- [x] `#M-1` Demo skeleton + `server.php` + `http/router.php` + `run-dev.sh` +
  `Dockerfile`; extension-load gate; `GET /health` and `GET /api/runtime`
  return deterministic runtime envelopes; `GET /api/bootstrap` and
  `GET /api/version` return stable envelopes.
- [x] `#M-2` Router module-order function
  (`model_inference_dispatch_route_module_order()`) returns the currently
  deployed module list; grows per leaf.
- [x] `#M-3` `demo/model-inference/contracts/v1/api-ws-contract.catalog.json`
  fixture published listing current endpoints + target-shape planned surfaces.
- [x] `#M-1/#M-2 tests` Dispatcher-level contract tests landed at
  `backend-king-php/tests/runtime-bootstrap-contract.{sh,php}` and
  `backend-king-php/tests/router-module-order-contract.{sh,php}`: assert
  `/health`, `/api/runtime`, `/api/bootstrap`, `/`, `/api/version`, and
  preflight envelopes; assert the exact `model_inference_dispatch_route_module_order()`
  list (currently `['runtime']`) and fail-closed `not_implemented` on every
  target-shape path that has not yet landed its module.
- [x] `#M-3 closure` `backend-king-php/tests/contract-catalog-parity-contract.{sh,php}`
  asserts 1:1 between the live `api.*` / `ws.*` catalog entries and the
  actually-served routes of the dispatcher, refuses target-shape paths leaking
  into the live section, and locks the currently emitted error-code set.
- [x] `#M-4` Runtime hardware profile kernel landed at
  `backend-king-php/domain/profile/hardware_profile.php` +
  `backend-king-php/http/module_profile.php`. Real platform-aware probes:
  darwin uses `/usr/sbin/sysctl` for `hw.logicalcpu` / `hw.physicalcpu` /
  `hw.memsize` / `hw.pagesize` / `machdep.cpu.brand_string`, `/usr/bin/vm_stat`
  for free-page accounting, and `/usr/sbin/system_profiler SPDisplaysDataType`
  for Metal + VRAM detection with arm64 fallback; linux parses
  `/proc/cpuinfo` + `/proc/meminfo`, `getconf PAGESIZE`, and probes GPUs
  through `nvidia-smi --query-gpu=memory.total,memory.free` with MiB→bytes
  conversion and `rocminfo` HSA-agent parsing. `vram_total_bytes` /
  `vram_free_bytes` stay 0 when the probe cannot read a value (no fabrication).
  The contract in `contracts/v1/node-profile.contract.json` is pinned and
  `tests/node-profile-contract.{sh,php}` asserts envelope shape, honesty
  invariants (`present=false ⇒ kind=none ∧ vram=0`; free ≤ total; physical ≤
  logical), dispatcher parity for `GET /api/node/profile`, and
  method_not_allowed for non-GET. `model_inference_dispatch_route_module_order()`
  grows to `['runtime', 'profile']` and catalog `api.node_profile` moves from
  `planned_surfaces_target_shape` into the live section (parity test
  updated accordingly).

### Open / To implement (priority order)

- [ ] `#M-5` Object-store-backed model registry — SQLite index + GGUF blobs
  via `king_object_store_put_from_stream` (resumable); publishes
  `contracts/v1/model-registry-entry.contract.json`. Bit-identical SHA-256
  round-trip required. Maps to `V.7`, `V.9`.

- [ ] `#M-6` Pure model-fit selector — given profile + registry, picks largest
  fitting GGUF preferring higher quantization. Maps to `V.2`, `V.3`, `Z.3`.

- [ ] `#M-7` `LlamaCppWorker` lifecycle — spawn / health / drain / stop
  `llama.cpp server` as a King-owned subordinate with ephemeral loopback port.
  Real tiny GGUF fixture (≤150 MB Q4_0 via LFS or CI-fetch with checksum;
  **no mock mode**). Maps to `Z.1`, `Z.4`, `V.1`.

- [ ] `#M-8` `contracts/v1/inference-request.contract.json` + typed validation
  on `POST /api/infer` and WS `infer.start`; canonical error codes. Maps to
  `V.10`, `Z.10`.

- [ ] `#M-9` `contracts/v1/token-frame.contract.json` — IIBIN binary frame
  (magic `KITF`, 24-byte header + payload); encode/decode via `king_proto_*`;
  committed hex sample vectors. Maps to `V.10`, `Z.4`, `Z.10`.

- [ ] `#M-10` `POST /api/infer` non-streaming surface — real prompt → real
  completion + telemetry; parallel surface to WS. Maps to `Z.1`, `Z.4`, `V.1`.

- [ ] `#M-11` WS streaming via `king_server_upgrade_to_websocket` +
  `king_websocket_send` emitting `#M-9` token frames; concatenation equals
  `POST /api/infer` result for same seed. Maps to `Z.4`, `Z.5`, `V.1`.

- [ ] `#M-12` Inference telemetry — TTFT, tokens/s, VRAM budget/observed,
  prompt/completion counts; `GET /api/telemetry/inference/recent`. Maps to
  `Z.9`.

- [ ] `#M-13` Semantic-DNS self-registration as `king.inference.v1` on ready;
  deregister on drain; bounded `heartbeat_after_ready` retry (never `sleep`).
  Maps to `V.4`, `Z.6`, `V.10`.

- [ ] `#M-14` `InferenceRouting` helper —
  `king_semantic_dns_get_optimal_route` with criteria
  `{model_name, quantization, min_free_vram_bytes}` → ordered primary +
  failover list (reuses `McpServiceResolution` shape from
  `demo/userland/flow-php/src/McpServiceDiscovery.php`). Maps to `V.4`, `Z.6`,
  `Z.7`.

- [ ] `#M-15` Deterministic two-node failover — compose spawns A + B,
  prompt-1 hits primary, primary drains, prompt-2 routes to secondary
  without reconfig. Explicit fence: **no mid-stream handoff claim**. Maps to
  `Z.8`.

- [ ] `#M-16` Transcript persistence to object-store keyed
  `inference/transcripts/{yyyy}/{mm}/{dd}/{request_id}.json`; survives
  restart. Maps to `V.9`.

- [ ] `#M-17` `scripts/smoke.sh` — two-node compose end-to-end; real
  streaming chat turn + transcript + failover; gated on
  `MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1`. Maps to `Z.10`, `V.10`.

- [ ] `#M-18` Honest README pass + target-shape fences + this ISSUES section
  review. Tracker boxes remain unticked; post-merge sweep ticks V/Z bullets
  against `main`.

### Next step (M-batch)

- [ ] Continue with `#M-5` (object-store-backed model registry): SQLite
  index migration for model entries, GGUF artifact upload via
  `king_object_store_put_from_stream` (resumable), publish
  `demo/model-inference/contracts/v1/model-registry-entry.contract.json`,
  add `backend-king-php/domain/registry/model_registry.php` +
  `backend-king-php/http/module_registry.php`, extend
  `model_inference_dispatch_route_module_order()` to
  `['runtime', 'profile', 'registry']`, move `models_list` and
  `models_create` from `planned_surfaces_target_shape` into live
  `catalog.api`, update the router-module-order + catalog-parity tests,
  and add a dedicated
  `tests/model-registry-contract.{sh,php}` proving a bit-identical SHA-256
  round-trip through the object store.
