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
- [x] `#M-12` Inference telemetry landed at
  `backend-king-php/domain/telemetry/inference_metrics.php` +
  `backend-king-php/http/module_telemetry.php`. Process-local
  bounded-FIFO `InferenceMetricsRing` with server-owned
  `recorded_at` stamps, FIFO eviction on overflow, newest-first
  `recent()`, and client-supplied `transport` clamped to
  `http|ws` (garbage values default to `http` rather than leaking).
  Per-entry fields: `request_id`, `session_id`, `transport`,
  `model_id`, `model_name`, `quantization`, `node_id`,
  `tokens_in`, `tokens_out`, `ttft_ms`, `duration_ms`,
  `tokens_per_second` (derived server-side from `tokens_out /
  (duration_ms/1000)`), `vram_total_bytes`, `vram_free_bytes`,
  `gpu_kind`, `recorded_at`. Failed inferences intentionally do
  NOT record (partial state would corrupt averages); transcript
  persistence is a separate contract landing at `#M-16`.
  Both `module_inference.php` (HTTP) and `module_realtime.php`
  (WS) record through the same surface via
  `model_inference_metrics_entry_from_http()` /
  `…_from_ws()` helpers so transport parity is mechanical, not
  copy-pasted. Dispatcher module order grows to
  `['runtime','profile','registry','inference','realtime','telemetry']`;
  signature gains `$getInferenceMetrics` callable between
  `$getInferenceSession` and `$wsPath`. Catalog
  `api.telemetry_recent` graduates from
  `planned_surfaces_target_shape` into the live section with a
  pinned `item_shape`. `tests/inference-telemetry-contract.{sh,php}`
  asserts (section A): capacity passthrough, empty start,
  capacity-bounded eviction (5 records into a 3-slot ring leaves
  the three newest), newest-first ordering of `recent()`,
  `tokens_per_second` derivation parity, rfc3339 `recorded_at`,
  unknown-transport → `http` normalization, `recent(n)` limit,
  `clear()`, `capacity<1` rejection. Section B with real
  SmolLM2: one HTTP `POST /api/infer` populates exactly one entry
  with `transport=http`; `GET /api/telemetry/inference/recent`
  returns `{status:ok, items, count, capacity, time}` with every
  pinned item field; one WS streamed completion through the same
  `model_inference_stream_completion()` + `…_from_ws()` helper
  the realtime module uses populates a second entry with
  `transport=ws` and the same `tokens_per_second` derivation
  rule; non-GET on the endpoint returns
  `405 method_not_allowed`. Maps to tracker Z.9 (still unticked
  pending post-merge sweep).
- [x] `#M-11` WS streaming via IIBIN token frames landed at
  `backend-king-php/domain/inference/inference_stream.php` +
  `backend-king-php/http/module_realtime.php`. First leaf that
  streams real tokens: opens a raw TCP socket to the cached
  `LlamaCppWorker`, POSTs `/completion?stream=true`, parses
  HTTP/1.1 chunked transfer + SSE framing (llama.cpp emits each
  event as one chunk carrying `data: {json}\n\n`), and for each
  non-empty `content` encodes a `#M-9` TokenFrame delta
  (`frame_type=0`, monotonic sequence, `request_id_crc32` de-mux
  key, `token_count` reflecting the real token-array length from
  the chunk — never claiming 1-frame-per-token), then a terminal
  `frame_type=end` frame carrying the real timing summary
  (`tokens_in`, `tokens_out`, `ttft_ms`, `duration_ms`). Frames are
  handed to a caller-supplied `$sendBinaryFrame` callable — in
  production it wraps `king_websocket_send($ws, $bytes, true)`; in
  the test harness it captures bytes for decode-and-assert.
  `http/module_realtime.php` owns the `GET /ws` upgrade: validates
  `Upgrade: websocket`, `Connection: Upgrade`, `Sec-WebSocket-Key`,
  `Sec-WebSocket-Version: 13` fail-closed; calls
  `king_server_upgrade_to_websocket($request['session'],
  $request['stream_id'])`; reads one `{"event":"infer.start",
  "payload":<envelope>}` text frame; validates envelope with
  `transport='ws'` (which forces `stream=true`); resolves the
  registry row; runs `#M-6` fit-check; spawns/reuses the cached
  worker; delegates to `model_inference_stream_completion()`; and
  closes with a 1000 normal-close. Transport-level failures emit a
  best-effort `frame_type=error` frame before the close.
  Dispatcher module order grows to
  `['runtime','profile','registry','inference','realtime']`.
  Catalog `ws.handshake` now pins the required headers; `ws.client_events.infer.start` is text; `ws.server_events.{infer.token, infer.end, infer.error}` are binary frames pointing at
  `token-frame.contract.json` with `frame_type_value` = `delta`,
  `end`, `error` respectively. `infer.cancel` stays in
  `planned_surfaces_target_shape` as a future WS leaf.
  `tests/infer-ws-streaming-contract.{sh,php}` seeds the SmolLM2
  fixture, runs the streaming function against a real worker, and
  asserts: ≥2 frames captured; every frame decodes cleanly through
  TokenFrame; magic/version/request_id_crc32 correct; delta payloads
  non-empty UTF-8; exactly one terminal end frame; end-body JSON has
  `tokens_in/tokens_out/ttft_ms/duration_ms` as ints ≥0 with
  `tokens_out ≥ 1`; strict monotonic sequence across the stream;
  `total_frames` summary equals captured count; and — the leaf's
  headline claim — **streamed deltas concatenated equal the
  non-streaming completion for the same seed+temp=0** (streaming
  does not drift from batching). Scope fence: the WS upgrade
  handler runs the session synchronously; concurrent WS sessions
  are a future hardening leaf (called out in the README when it
  lands). Maps to tracker V.1, Z.4, Z.5 (still unticked pending
  post-merge sweep).
- [x] `#M-10` `POST /api/infer` non-streaming landed at
  `backend-king-php/domain/inference/inference_session.php` +
  `backend-king-php/http/module_inference.php`. First leaf that
  produces real tokens end-to-end: request envelope validated via
  `#M-8`, model looked up by `(model_name, quantization)` in the
  registry, profile+registry scored through `#M-6`, GGUF streamed
  out of the King object store into a local cache on first hit,
  `LlamaCppWorker` spawned + cached per process keyed by
  `model_id` (one-active policy: a different model drains the old
  worker first), prompt POSTed to llama-server's `/completion`
  through `king_http1_request_send` (dogfoods King's native HTTP/1
  client), response normalized to
  `{text, tokens_in, tokens_out, ttft_ms, duration_ms, request_wall_ms, stop{type,word,truncated}, worker{pid,port,gguf_path}}`.
  Canonical rejection codes: `invalid_request_envelope` (400; empty
  body / invalid JSON / any `#M-8` rule), `method_not_allowed`
  (405), `model_not_found` (404), `model_fit_unavailable` (422),
  `worker_unavailable` (502/503). The dispatcher now requires
  `module_inference.php` and grows the order list to
  `['runtime','profile','registry','inference']`; server.php
  constructs the `InferenceSession` once at boot, registers a
  shutdown drain hook, and threads a `$getInferenceSession`
  callable through the dispatcher alongside `$openDatabase`.
  Catalog `api.infer_http` graduates from
  `planned_surfaces_target_shape` into the live section; error-code
  list gains `model_fit_unavailable` + `worker_unavailable`.
  `tests/infer-http-nonstreaming-contract.{sh,php}` seeds the
  SmolLM2 GGUF through the same registry domain used by the HTTP
  route, dispatches `POST /api/infer` with a real prompt, and
  asserts: 200 response, `request_id` shape
  (`req_<16hex>`), `session_id` passthrough, `model.model_id` ==
  seeded row, non-empty `completion.text`, `tokens_out >= 1`,
  `tokens_in >= 1`, `ttft_ms / duration_ms / request_wall_ms` all
  non-negative integers, `worker.port` live; back-to-back calls
  reuse the cached worker (same pid, same port); `stream=true`
  rejected with `invalid_request_envelope.details.field=stream`
  (#M-8 transport cross-check); unknown model name returns 404
  `model_not_found`; empty body / malformed JSON / GET all
  fail-closed with the right codes; draining the session spawns a
  fresh worker (new pid) on the next request. Maps to tracker V.1,
  Z.1, Z.4 (still unticked pending post-merge sweep).
- [x] `#M-9` IIBIN-style token-frame wire contract landed at
  `contracts/v1/token-frame.contract.json` +
  `backend-king-php/support/token_frame.php`. Fixed big-endian
  24-byte header + payload framing for WS token streaming introduced
  by `#M-11`. Header layout (offset : field (bytes, type)):
  `0:magic(u32="KITF"=0x4B495446), 4:version(u8=1),
  5:frame_type(u8 delta=0|end=1|error=2), 6:flags(u8 bit0=final_in_burst,
  bit1=utf8_boundary_safe), 7:reserved1(u8=0),
  8:sequence(u32 monotonic), 12:request_id_crc32(u32 fast-demux),
  16:token_count(u16), 18:payload_length(u32),
  22:reserved2(u16=0)`. Payload layouts: `delta` = raw UTF-8 token
  bytes, `end` = JSON `{tokens_in, tokens_out, ttft_ms, duration_ms}`,
  `error` = JSON `{code, message}`. `max_payload_bytes=1048576` matches
  the `king_http1` one-shot body cap. Codec built on PHP `pack/unpack`
  (fixed layout, versioned, never evolves silently) and exposes
  `TokenFrame::{encode, decode, encodeDelta, encodeEnd, encodeError,
  requestIdCrc32, assertMonotonicSequence}` plus a strict
  `TokenFrameDecodeError` with machine-readable reasons matching the
  contract's `validation.reject_on` list (`bad_magic`,
  `unsupported_version`, `unknown_frame_type`, `reserved1_nonzero`,
  `reserved2_nonzero`, `payload_length_out_of_range`,
  `truncated_or_overlong`, `sequence_not_monotonic`). Three sample
  vectors pin the on-wire bytes (`delta-hello`=29B,
  `end-summary`=88B, `error-abort`=78B) against
  `crc32("sess-demo-01")=0x248B58D1`. `tests/token-frame-wire-contract.{sh,php}`
  re-encodes each sample vector and asserts `bin2hex()` equals the
  committed hex, round-trips decode on every field, exercises every
  reject path (bad magic, bad version, bad frame_type, reserved
  nonzero, truncated, overlong, short header, oversized encode,
  invalid frame_type, sequence/token_count u-range overflow), and
  asserts the contract's `reject_on` list stays in sync with
  `TokenFrameDecodeError` reasons. 3 sample vectors + 18 rules
  asserted. Maps to tracker V.10, Z.4, Z.10 (still unticked pending
  post-merge sweep). No dispatcher surface yet; the codec is consumed
  by `#M-11` WS streaming.
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

- [ ] Continue with `#M-13` (Semantic-DNS self-registration): on
  ready, call `king_semantic_dns_register_service` with
  `service_type=king.inference.v1` carrying a slimmed node-profile
  subset (node_id, health_url, free_ram_bytes, free_vram_bytes,
  loadable model_name+quantization pairs, supports_streaming).
  Deregister on drain / shutdown. Heartbeat-after-ready is a
  bounded retry loop with no `sleep` between attempts — use
  king's own poll timing. Add
  `backend-king-php/support/semantic_dns.php` +
  `backend-king-php/domain/discovery/service_registration.php`
  + `tests/semantic-dns-inference-register-contract.{sh,php}`
  that boots a semantic-DNS runtime via `king_semantic_dns_init`,
  registers + discovers + deregisters, asserts a second process
  sees the record within 2 seconds and the record is gone on
  drain. Maps to V.4, Z.6, V.10.
