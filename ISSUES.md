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
- [x] `#M-8` Typed inference-request envelope landed at
  `contracts/v1/inference-request.contract.json` +
  `backend-king-php/domain/inference/inference_request.php`. Pins the
  envelope consumed by `POST /api/infer` (#M-10) and WS `infer.start`
  (#M-11) so both transports share one validator and one canonical
  rejection-code set. Validator is pure (no I/O, no DB, no kernel
  calls): `model_inference_validate_infer_request($payload,
  ['transport' => 'http'|'ws'])` returns the normalized envelope or
  throws `InferenceRequestValidationError` whose machine-readable
  payload (`errorCode` / `field` / `reason` / `observed`) projects
  cleanly into the typed error envelope via `toDetails()`. Enforced
  rules: `session_id` required 1..128 chars matching
  `[A-Za-z0-9_.:-]+`; `model_selector` required with `model_name`,
  `quantization` ∈ `{Q2_K..F16}`, optional `prefer_local` default
  `true`; `prompt` required 1..131072 chars; optional `system`
  string ≤32768 chars (empty normalizes to `null`); `sampling`
  required with `temperature` ∈ `[0.0, 2.0]`, `top_p` ∈ `[0.0, 1.0]`,
  `top_k` ∈ `[0, 1024]`, `max_tokens` ∈ `[1, 8192]`, optional `seed`
  ∈ `[0, 4294967295]`; `stream` required bool, with transport
  cross-check (HTTP refuses `stream=true`, WS refuses `stream=false`).
  Unknown top-level keys, unknown keys inside `model_selector` /
  `sampling`, and wrong types at every slot are all fail-closed.
  Number normalization is explicit: integers promoted to floats in
  float slots, integral floats accepted in int slots, non-integral
  floats rejected.
  `tests/inference-request-envelope-contract.{sh,php}` asserts 33
  rules: happy-path HTTP + WS normalization, every missing-required
  field, every out-of-range numeric, every wrong-type rejection,
  transport cross-checks, unknown-key rejection at every level, and
  catalog error-code parity. Maps to tracker V.10 and Z.10 (still
  unticked pending post-merge sweep). No dispatcher surface yet; the
  envelope feeds `#M-10` HTTP and `#M-11` WS.
- [x] `#M-7` `LlamaCppWorker` lifecycle landed at
  `backend-king-php/support/llama_cpp_worker.php`. Real subordinate process:
  `start()` validates a real GGUF path, `proc_open`s the pinned
  `llama.cpp server` binary with `-m … --host 127.0.0.1 --port … -c …
  -n … --no-webui`, pins `LD_LIBRARY_PATH` to the bundle directory, sends
  stdout+stderr to a configurable log path, and transitions state
  `stopped → starting` on success. `health()` probes
  `http://127.0.0.1:port/health` through `king_http1_request_send`
  (dogfoods the King HTTP/1 client; falls back to a bounded PHP stream
  probe only when the extension is absent) and promotes the worker to
  `ready` on the first 200 response. `waitForReady()` is bounded, fails
  closed on unexpected exit, and never caches a stale snapshot.
  `drain()` sends SIGTERM, then SIGKILL after the deadline, and always
  ends with `proc_close()` + `state=stopped`; `stop()` is an alias with
  a 2 s budget; `__destruct()` drains any still-running child.
  `state()` reconciles `proc_get_status` on every call so an unplanned
  exit flips to `error` without silent drift. `diagnostics()` emits the
  full snapshot consumed later by `/api/worker` in `#M-10`.
  No mock mode — if the binary or GGUF is missing, the class throws.
  `tests/llama-cpp-worker-contract.{sh,php}` spawns a real `llama.cpp
  server` against the pinned SmolLM2-135M-Instruct-Q4_K_S fixture,
  asserts `/health` returns `{"status":"ok"}`, exercises diagnostics,
  drains + reaps (confirmed via `posix_kill(pid, 0) === false`),
  re-starts on a fresh ephemeral port, rejects double-start with a
  `RuntimeException`, rejects missing GGUF, and refuses construction
  with a missing binary. The `.sh` wrapper SKIPs cleanly when the
  llama.cpp runtime or fixture is not installed; CI and the dev
  container run it as a hard gate.
  `scripts/install-llama-runtime.sh` is the repeatable installer:
  downloads the pinned llama.cpp `b8802` Ubuntu (arm64 or x64) release
  archive, verifies committed SHA-256
  (`64ab9e2b…0eda` / `6be3f247…c3f`) before extraction, and fetches the
  SmolLM2-135M-Instruct-Q4_K_S GGUF (102 039 904 bytes, SHA-256
  `a8654d8e…22a0`) into `backend-king-php/.local/fixtures/`. The
  `Dockerfile` runs the installer at image build time unless
  `MODEL_INFERENCE_SKIP_LLAMA_INSTALL=1`. `.gitignore` excludes the
  `.local/` staging directory so the 100+ MiB runtime + fixture never
  enter the repo. Maps to tracker Z.1, Z.4, V.1 (still unticked pending
  post-merge sweep). `/api/worker` diagnostics endpoint is deferred to
  `#M-10` where a persistent worker handle exists to report on — the
  dispatcher still returns 404 `not_implemented` for `/api/worker`
  today, and `planned_surfaces_target_shape.api.worker_status` remains
  its catalog home until then.
- [x] `#M-6` Pure model-fit selector landed at
  `backend-king-php/domain/registry/model_fit_selector.php` as a pure
  function (no I/O, no DB, no kernel calls). Signature:
  `model_inference_select_model_fit(array $profile, array $registry, array $options = []): array` →
  `{winner, candidates, rejected, rules_applied}`. Filter sequence (all
  fail-closed and traced in `rejected[].reason`): `model_name_filter`,
  `quantization_filter`, `context_length_below_minimum`,
  `ram_budget_exceeded`, `quantization_not_supported`,
  `gpu_required_but_none_present`, `vram_unreadable_cpu_fallback_requires_zero_vram`
  (honours the `#M-4` rule that unreadable VRAM MUST NOT be treated as an
  arbitrary budget), `vram_budget_exceeded`. Deterministic ordering:
  parameter_count DESC → quantization precision DESC
  (`F16>Q8_0>Q6_K>Q5_K>Q4_K>Q4_0>Q3_K>Q2_K`, unknown tags rank 0) →
  `model_id` ASC; the third tiebreak guarantees reproducibility on
  identical inputs. Supports `options.model_name`, `options.quantization`,
  and `options.min_context_tokens`. `tests/model-fit-selector-contract.{sh,php}`
  covers the CPU-only darwin (Metal present, VRAM unreadable → CPU
  fallback) tiebreak, CUDA 24 GiB picks the largest 13B, CUDA 4 GiB forces
  7B Q4_K over VRAM-hungry 7B Q8_0 and 13B, 2 GiB edge gets only
  TinyLlama Q4_0, Q8_0-stripped quantization support filters out the
  Q8_0 TinyLlama with `quantization_not_supported`, a 1 GiB host has
  zero candidates and five rejections, `model_name` + `min_context_tokens`
  filters work, and input-order independence + quantization-rank sanity
  are asserted explicitly. No dispatcher surface yet — the selector feeds
  `#M-7` worker lifecycle. Maps to tracker V.2, V.3, Z.3 (still unticked
  pending post-merge sweep).
- [x] `#M-5` Object-store-backed model registry landed at
  `backend-king-php/domain/registry/model_registry.php` +
  `backend-king-php/http/module_registry.php` +
  `backend-king-php/support/object_store.php`. Schema migration #2 adds a
  flat-key `models` table with a `UNIQUE(model_name, quantization)` index and
  a matching `object_store_key` unique constraint; server bootstrap calls
  `model_inference_object_store_init()` against a `local_fs` primary rooted
  under `$MODEL_INFERENCE_KING_OBJECT_STORE_ROOT` (default `${DB}/object-store`).
  GGUF artifacts stream through `king_object_store_put_from_stream` under
  flat `mdl-<16hex>` object ids (King rejects slash-bearing ids; resumable
  sessions require a cloud primary, so this demo uses one-shot streamed
  writes — explicitly fenced in the contract). Registry rows trust King's
  `integrity_sha256` as the authoritative checksum; registry code never
  accepts a client-supplied checksum.
  HTTP surface: `GET /api/models` lists deterministic-order rows,
  `POST /api/models` (raw body + `X-Model-*` headers) validates metadata,
  rejects duplicates with `409 model_registry_conflict`, rejects empty body
  with `400 invalid_request_envelope`, and returns the full envelope with
  `byte_length` + `sha256_hex` observed from King itself; `GET /api/models/{id}`
  returns the single envelope or `404 model_not_found`;
  `DELETE /api/models/{id}` removes the artifact from King first (fails
  closed if King delete errors) and then the row. Model id, object-store
  key, and registry row are atomic — a failed artifact write leaves no
  orphan row.
  Catalog `api.models_list / models_create / model_get / model_delete` move
  from `planned_surfaces_target_shape` into the live section; the parity
  test rejects any future drift. `model_inference_dispatch_route_module_order()`
  grows to `['runtime', 'profile', 'registry']`. The
  `king.security_allow_config_override=1` ini is wired into `run-dev.sh` and
  `Dockerfile` so the userland `king_object_store_init()` call is permitted.
  `tests/model-registry-contract.{sh,php}` boots king inside the dev
  container, round-trips a 131 072 byte payload through the registry +
  dispatcher, asserts bit-identical SHA-256 parity on direct `king_object_store_get()`
  readback, lists/gets/deletes the row, and verifies catalog error-code
  parity. Maps to tracker V.7, V.9 (still unticked pending post-merge sweep).
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

- [ ] Continue with `#M-9` (IIBIN token-frame wire contract): publish
  `demo/model-inference/contracts/v1/token-frame.contract.json` with the
  24-byte big-endian header (magic `KITF` u32, version u8, frame_type
  u8 enum `delta=0|end=1|error=2`, flags u8, reserved u8, sequence u32,
  `request_id_crc32` u32, `token_count` u16, `payload_length` u32,
  reserved u16) plus payload shapes per frame_type and committed hex
  sample vectors. Add
  `backend-king-php/support/token_frame.php` with encode/decode helpers
  built on `king_proto_define_schema` / `king_proto_encode` /
  `king_proto_decode`; add
  `tests/token-frame-wire-contract.{sh,php}` proving bit-identical
  round-trip of the sample vectors and rejecting unknown `frame_type`
  / version / oversized payloads. No dispatcher surface yet; the codec
  is consumed by `#M-11` WS streaming.
