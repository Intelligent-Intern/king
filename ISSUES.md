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

- [x] `#M-13` Semantic-DNS self-registration as `king.inference.v1` on ready;
  deregister on drain; bounded `heartbeat_after_ready` retry (never `sleep`).
  Maps to `V.4`, `Z.6`, `V.10`.

- [x] `#M-14` `InferenceRouting` helper —
  `king_semantic_dns_get_optimal_route` with criteria
  `{model_name, quantization, min_free_vram_bytes}` → ordered primary +
  failover list (reuses `McpServiceResolution` shape from
  `demo/userland/flow-php/src/McpServiceDiscovery.php`). `GET /api/route`
  diagnostic endpoint. Maps to `V.4`, `Z.6`, `Z.7`.

- [x] `#M-15` Deterministic two-node failover — `docker-compose.v1.yml`
  spawns node-a + node-b, prompt-1 hits primary, primary is stopped,
  prompt-2 routes to secondary without reconfig. `scripts/failover-smoke.sh`
  proves the flow. Explicit fence: **no mid-stream handoff claim**. Maps to
  `Z.8`.

- [x] `#M-16` Transcript persistence to object-store keyed by flat key
  `transcript-{yyyymmdd}-{request_id}` (King rejects slashes in object IDs);
  `GET /api/transcripts/{request_id}` retrieval endpoint. Survives restart.
  Maps to `V.9`.

- [x] `#M-17` `scripts/smoke.sh` — 9-phase smoke: syntax validation,
  offline contract tests, compose boot, runtime/profile probes, model
  registry, real inference, transcript retrieval, telemetry, routing
  diagnostic, two-node failover. Gated on
  `MODEL_INFERENCE_SMOKE_REQUIRE_COMPOSE=1`. Maps to `Z.10`, `V.10`.

- [x] `#M-18` Honest README pass + target-shape fences + ISSUES section
  review. All 18 leaves closed, 17 contract tests. Tracker boxes remain
  unticked; post-merge sweep ticks V/Z bullets against `main`.

### Status (M-batch)

All 18 leaves (`#M-1` through `#M-18`) are closed. 17 contract tests
green. The `feature/model-inference` branch is merge-ready. Post-merge
sweep ticks V/Z tracker bullets against `main`.

## R-batch: RAG Pipeline (branch `feature/rag-pipeline`)

> Parallel track, extends `demo/model-inference/` with embedding + retrieval
> surfaces. Maps to tracker sections **W** (Embeddings / Vectorization /
> Semantic Discovery, partial: W.1–W.4, W.6) and **X** (Knowledge / Retrieval,
> partial: X.1–X.3, X.7). Branched off `feature/model-inference` tip `e4aeeb7`.
> Tracker boxes NOT ticked from this branch; post-merge sweep only.

Non-negotiable direction for this batch:

- embedding engine is **llama.cpp server in `--embedding` mode** (same pinned
  binary, different GGUF, different flags). King owns the embedding contract;
  llama.cpp is the execution engine behind it.
- vector storage is **object-store brute-force cosine similarity**. Honest — no
  HNSW/IVF/ANN claim. Demo corpus sizes only.
- document format is **plain text only**. PDF/HTML/Markdown parsing fenced.
- the RAG pipeline composes embedding + retrieval + inference in-process (same
  server, no cross-service HTTP). Multi-service composition fenced.
- out of scope: hybrid retrieval (X.4), retrieval-backed MCP selection
  (X.5–X.6), graph traversal (W.8–W.9), similarity-based service resolution
  (W.5, W.7), external vector databases, large-scale indexing (>10K vectors),
  WS streaming of RAG results, multimodal embedding, concurrent RAG execution.

### Done in current branch

- [x] `#R-1` Embedding model registry — extend SQLite schema with `model_type`
  column (`chat`/`embedding`); `scripts/install-embedding-model.sh` pins a GGUF
  embedding model with committed SHA-256; autoseed on boot. Maps to `W.1`,
  `W.2`.
  - Added `model_type` column via idempotent ALTER in `registry_schema_migrate()`
  - Extended validation, create, list, and envelope functions
  - Added `model_inference_registry_list_by_type()` and `model_inference_registry_find_embedding_model()`
  - Created `scripts/install-embedding-model.sh` pinning nomic-embed-text-v1.5 Q8_0
  - Extended server autoseed for embedding model fixtures
  - Contract test: `tests/embedding-model-registry-contract.{sh,php}`

- [x] `#R-2` Embedding worker lifecycle — spawn second `LlamaCppWorker` with
  `extra_argv: ['--embedding']`; health probe on `/health`; `EmbeddingSession`
  cache (one active embedding worker, separate from inference worker). Maps to
  `W.2`.
  - Created `domain/embedding/embedding_session.php` — mirrors InferenceSession
  - Spawns worker with `extra_argv: ['--embedding']`; calls `/v1/embeddings`
  - L2 normalization on returned vectors; one-active-worker policy
  - Bootstrapped in `server.php` with shutdown drain handler
  - Contract test: `tests/embedding-worker-contract.{sh,php}`

- [x] `#R-3` `contracts/v1/embedding-request.contract.json` — typed envelope
  `{texts[], model_selector, options:{normalize, truncate}}`; validation with
  rejection codes. Maps to `W.2`, `W.4`.
  - Created `contracts/v1/embedding-request.contract.json` with full shape
  - Created `domain/embedding/embedding_request.php` — `EmbeddingRequestValidationError` + `model_inference_validate_embedding_request()`
  - 33-rule contract test: `tests/embedding-request-envelope-contract.{sh,php}`

- [x] `#R-4` `POST /api/embed` — real embedding generation via llama.cpp
  `/v1/embeddings`; returns `{embeddings[], dimensions, model, tokens_used,
  duration_ms}`. Maps to `W.1`, `W.2`.
  - Created `http/module_embed.php` — POST /api/embed endpoint
  - Router module order grew to include `embed` between registry and inference
  - Wired `$getEmbeddingSession` through router + server handler (optional param, backward-compatible)
  - Updated catalog: added `embed` surface to live API
  - Updated parity test: embed probe, shipped list, exception catch
  - Contract test: `tests/embedding-generation-contract.{sh,php}`

- [x] `#R-5` Document ingest — `POST /api/documents` accepts plain text body,
  stores in object store under flat key `doc-{16hex}`, returns `{document_id,
  byte_length, sha256_hex}`. Maps to `X.1`.
  - Created `domain/retrieval/document_store.php` — ingest, get, list, schema migration
  - Created `http/module_ingest.php` — POST /api/documents, GET /api/documents, GET /api/documents/{id}
  - Router module order grew to include `ingest` between embed and inference
  - Catalog: added `documents_list`, `documents_create`, `document_get` + error codes
  - Contract test: `tests/document-ingest-contract.{sh,php}`

- [x] `#R-6` Text chunking engine — `domain/retrieval/text_chunker.php` with
  configurable strategy (fixed-size with overlap);
  `contracts/v1/chunk-envelope.contract.json`. Maps to `X.2`.
  - Created `domain/retrieval/text_chunker.php` — `model_inference_chunk_text()` with configurable chunk_size + overlap
  - Chunk ID format: `chk-{doc_prefix_8hex}-{sequence_4digit}` (deterministic)
  - SQLite persistence: chunks table with schema migration, persist, list_by_document
  - Created `contracts/v1/chunk-envelope.contract.json`
  - 60-rule contract test: `tests/text-chunker-contract.{sh,php}`

- [x] `#R-7` Chunk persistence — embed + store chunks to object store keyed
  `chk-{doc_prefix}-{seq}`; chunk metadata in SQLite;
  `GET /api/documents/{document_id}/chunks`. Maps to `X.2`, `W.3`.
  - Auto-chunks on document ingest: `POST /api/documents` now chunks + persists
  - Chunk text stored to object store via `model_inference_chunk_store_texts()`
  - Added `GET /api/documents/{document_id}/chunks` endpoint
  - Catalog: added `document_chunks` surface
  - Contract test: `tests/chunk-persistence-contract.{sh,php}`

- [x] `#R-8` Vector store — persist embedding vectors to object store keyed
  `vec-{16hex}`; vector metadata in SQLite linking chunk_id → vector_id →
  embedding model. Maps to `W.3`.
  - Created `domain/retrieval/vector_store.php` — schema migration, store, load, list
  - Vectors stored as JSON float arrays in object store under `vec-{16hex}` keys
  - SQLite metadata: vectors table linking chunk_id → vector_id → embedding_model_id
  - `model_inference_vector_load_all()` and `_load_all_for_document()` for retrieval
  - Contract test: `tests/vector-store-contract.{sh,php}`

- [x] `#R-9` Brute-force cosine similarity — pure function
  `cosine_similarity(array $a, array $b): float`; vector search over stored
  vectors returning top-K ranked results. Maps to `W.4`.
  - Created `domain/retrieval/cosine_similarity.php` — `model_inference_cosine_similarity()` + `model_inference_vector_search()`
  - Pure functions: no database, no object store, no I/O
  - top-K ranking with min_score filtering, sorted descending by score
  - 16-rule contract test: `tests/cosine-similarity-contract.{sh,php}`

- [x] `#R-10` `POST /api/retrieve` — retrieval endpoint: embed query → scan
  vectors → return ranked chunks with scores;
  `contracts/v1/retrieval-request.contract.json`. Maps to `X.1`, `X.3`.
  - Created `domain/retrieval/retrieval_pipeline.php` — `model_inference_retrieval_search()`
  - Created `http/module_retrieve.php` — POST /api/retrieve endpoint
  - Created `contracts/v1/retrieval-request.contract.json`
  - Request validation: query, model_selector, optional document_ids/top_k/min_score
  - Router module order grew to include `retrieve` between ingest and inference
  - Catalog + parity test updated
  - Contract test: `tests/retrieval-pipeline-contract.{sh,php}`

- [x] `#R-11` `POST /api/rag` — end-to-end RAG pipeline: accept query +
  document_id → retrieve top-K context → augment prompt with context → forward
  to inference engine → return grounded completion. Maps to `X.1`.
  - Created `domain/retrieval/rag_orchestrator.php` — `model_inference_rag_execute()`, `_rag_build_prompt()`, `_validate_rag_request()`
  - Dual model_selector: separate chat + embedding model selectors
  - Prompt augmentation: context block with numbered chunks + system instruction
  - Wired as `POST /api/rag` in module_retrieve.php
  - Contract test: `tests/rag-orchestrator-contract.{sh,php}`

- [x] `#R-12` RAG telemetry — extend `InferenceMetricsRing` pattern for
  embedding + retrieval metrics (embedding_latency_ms, retrieval_latency_ms,
  chunks_scanned, vectors_scanned, context_tokens). Maps to `X.7`.
  - Created `domain/telemetry/rag_metrics.php` — `RagMetricsRing` (same bounded-FIFO pattern)
  - Tracks: embedding_ms, retrieval_ms, inference_ms, total_ms, chunks_used, vectors_scanned, tokens_in/out
  - `GET /api/telemetry/rag/recent` endpoint added to module_telemetry
  - 24-rule contract test: `tests/rag-telemetry-contract.{sh,php}`

- [x] `#R-13` Semantic-DNS: register embedding + retrieval capabilities as
  attributes on existing `king.inference.v1` service; update routing
  diagnostic. Maps to `W.6`.
  - Extended `model_inference_semantic_dns_register()` with `supports_embedding`, `supports_retrieval`, `supports_rag`, `embedding_dimensions` attributes
  - Same service type `king.inference.v1` (not a separate type)
  - Boot profile in server.php sets embedding capabilities
  - Contract test: `tests/semantic-dns-embedding-contract.{sh,php}`

- [x] `#R-14` Catalog parity update — grow `api-ws-contract.catalog.json` with
  all R-batch surfaces; update parity gate; promote relevant target-shape
  entries. Maps to `W`, `X`.
  - Catalog maintained incrementally through R-1–R-13 (no drift)
  - All R-batch surfaces in live catalog: embed, documents_list, documents_create, document_get, document_chunks, retrieve, rag, telemetry_rag_recent
  - Parity test covers all 18 live API surfaces + probes
  - Error codes: document_not_found, document_too_large added
  - Shipped list prevents R-batch surfaces from leaking into target-shape

- [x] `#R-15` `scripts/rag-smoke.sh` — end-to-end: ingest doc → verify chunks
  → embed → retrieve → RAG completion; runs in compose. Maps to `X.7`.
  - Created `scripts/rag-smoke.sh` — 10-phase end-to-end RAG smoke test
  - Phases: syntax → contract tests (R-batch + M-batch) → compose boot →
    embedding model probe → document ingest → chunk verification → embedding →
    retrieval → RAG completion → RAG telemetry
  - Graceful skip for phases 6-8 when embedding model fixture not installed
  - Runs all 23 R-batch + M-batch offline contract tests as regression gate

- [x] `#R-16` README update + target-shape fences + ISSUES section review.
  Tracker boxes remain unticked.
  - README: added R-batch "What works today" section, R-batch leaf table,
    embedding model install step, RAG smoke section, R-batch scope fences
  - Layout tree updated with all R-batch files (30 test pairs total)
  - Scope fences: 8 explicit R-batch fences (hybrid retrieval, external vector
    DBs, HNSW/IVF, PDF/HTML parsing, multimodal, large-scale, WS streaming,
    concurrent RAG)

### Next step (R-batch)

- R-batch sprint complete. All 16 leaves (#R-1 → #R-16) are closed. 30
  contract tests green. Branch `feature/rag-pipeline` is merge-ready.
  Tracker boxes remain unticked pending post-merge sweep on `main`.

## S-batch: Semantic Discovery (branch `feature/rag-pipeline`)

> Stacks directly on top of R-batch. Reuses `model_inference_embed()` +
> `model_inference_cosine_similarity()` + `model_inference_vector_search()`
> to replace keyword-only service/tool selection with vector-ranked
> discovery. Maps to tracker bullets **W.5, W.7, X.4, X.5, X.6**.
> Tracker boxes NOT ticked from this branch; post-merge sweep only.

Non-negotiable direction for this batch:

- Brute-force cosine over `service_embeddings` / `tool_embeddings`. No ANN /
  HNSW / IVF. Demo scale only (<1k services).
- BM25 parameters pinned (`k1=1.2`, `b=0.75`). No learned ranker.
- Descriptor text (name + description + capabilities + tags) is the sole
  semantic signal. No graph traversal.
- C-level Semantic-DNS surface is UNCHANGED. The semantic-query path is an
  additive PHP overlay so the existing keyword API keeps its behavior.
- `POST /api/tools/pick` fails closed with `no_semantic_match` when no tool
  scores above `min_score`. No silent default target.
- Out of scope: graph-aware metadata (W.8/W.9), retrieval-driven *document*
  hybrid (stays semantic-only in `/api/retrieve`), fleet-wide model
  placement (V.3-V.5), fine-tuning (Y), advanced extensions (AA).

### Done in current branch

- [x] `#S-1` `service-descriptor.contract.json` + validator
  (`domain/discovery/service_descriptor.php`): typed envelope
  `{service_id, service_type, name, description, capabilities[], tags[]}` +
  `model_inference_service_descriptor_embedding_text()` helper. Maps to `W.5`.
  - 40-rule test: `tests/service-descriptor-contract.{sh,php}`

- [x] `#S-2` `service_embeddings` SQLite table + `svec-{hex}` object-store
  layer (`domain/discovery/service_embedding_store.php`): schema migration,
  upsert-aware store, load_row, load_all (metadata + dense vector),
  list-by-type, delete. Maps to `W.5`, `W.7`.
  - 39-rule test: `tests/service-embedding-store-contract.{sh,php}`

- [x] `#S-3` Embedding composition layer
  (`domain/discovery/service_embedding_upsert.php`):
  `model_inference_service_embedding_upsert()` validates descriptor, assembles
  text, calls injected embedder, persists. Adapter factory converts an
  `EmbeddingSession` + worker into the callable shape. Maps to `W.5`.
  - 15-rule test: `tests/service-embedding-upsert-contract.{sh,php}`

- [x] `#S-4` `POST /api/discover` + envelope parser
  (`http/module_discover.php`). Modes: `keyword | semantic | hybrid`. Wired
  into dispatcher; `discover` added to deterministic module order. Maps to `X.5`.
  - 43-rule envelope test: `tests/discover-envelope-contract.{sh,php}`

- [x] `#S-5` Semantic scorer (`domain/discovery/semantic_discover.php`):
  brute-force cosine over `service_embeddings` with deterministic `service_id`
  tie-break. Maps to `W.7`, `X.5`.
  - 11-rule test: `tests/semantic-discover-contract.{sh,php}`

- [x] `#S-6` Hybrid scorer (`domain/discovery/hybrid_discover.php`):
  normalized BM25 (k1=1.2, b=0.75) + cosine fusion with `alpha`, min-max
  normalization before fusion, deterministic tie-break on zero scores. Maps
  to `X.4`.
  - 30-rule test: `tests/hybrid-discover-contract.{sh,php}`

- [x] `#S-7` `tool-descriptor.contract.json` + `tool_embeddings` store +
  `model_inference_validate_tool_descriptor()` with full mcp_target shape.
  Maps to `W.7`.
  - 43-rule test: `tests/tool-descriptor-contract.{sh,php}`

- [x] `#S-8` Tool embedding upsert
  (`model_inference_tool_embedding_upsert()`): same pattern as S-3 for tools,
  persists to `tvec-{hex}`. Maps to `W.7`. (Tested in `tool-descriptor-contract`.)

- [x] `#S-9` `POST /api/tools/discover` + semantic/hybrid tool scorers
  (`domain/discovery/tool_discover.php`): same shape as `/api/discover`,
  returns ranked tools with `mcp_target`. Maps to `X.6`.
  - 11-rule test: `tests/tool-discover-contract.{sh,php}`

- [x] `#S-10` `POST /api/tools/pick` + `model_inference_mcp_pick()`
  wrapper (`domain/discovery/mcp_pick.php`): fails closed with
  `McpPickNoMatchException` / `no_semantic_match` when no tool scores above
  `min_score`. Maps to `W.7`, `X.6`.
  - 5-rule test: `tests/mcp-pick-contract.{sh,php}`

- [x] `#S-11` `DiscoveryMetricsRing` + `GET /api/telemetry/discovery/recent`
  (`domain/telemetry/discovery_metrics.php`): bounded-FIFO ring with
  `embedding_ms`, `search_ms`, `total_ms`, `candidates_scanned`, `mode`,
  `alpha`, `query_length`, `service_type`, `top_k`, `min_score`. Maps to `X.5`.
  - 32-rule test: `tests/discovery-telemetry-contract.{sh,php}`

- [x] `#S-12` `dns_semantic_query.php` overlay: intersects
  `king_semantic_dns_discover_service` candidates with
  `semantic_discover` results. Fails closed (empty intersection) when a
  service is only in embeddings but not registered in DNS. Maps to `W.5`, `W.7`.
  - 10-rule test: `tests/dns-semantic-query-contract.{sh,php}`

- [x] `#S-13` Catalog parity grown by 4 live surfaces (`discover`,
  `tools_discover`, `tools_pick`, `telemetry_discovery_recent`) + 4 new
  error codes (`invalid_service_descriptor`, `invalid_tool_descriptor`,
  `embedding_worker_unavailable_discovery`, `no_semantic_match`).
  `contract-catalog-parity-contract` stays green; `router-module-order`
  updated.

- [x] `#S-14` `scripts/discovery-smoke.sh` — 10-phase end-to-end
  (syntax → contract suite → compose → embedding probe → keyword
  discovery → semantic → hybrid → tools discover → tools pick
  fail-closed → telemetry). README updated with S-batch table + scope
  fences + layout entries. ISSUES updated (this section).

### Next step (S-batch)

- S-batch sprint complete. All 14 leaves (#S-1 → #S-14) are closed. 11 new
  contract tests green; no regressions in existing 30+ tests. Branch
  `feature/rag-pipeline` carries both R-batch and S-batch and is
  merge-ready. Tracker boxes **W.5, W.7, X.4, X.5, X.6** remain unticked
  pending post-merge sweep on `main`.

## T-batch: Chat Memory & Small-Model Reliability (branch `feature/rag-pipeline`)

> Follow-on to R/S batches on the same branch. Turns the model-inference
> demo from a single-turn primitive into a working multi-turn chat against
> SmolLM2-135M, including the surface-level tuning needed to make a tiny
> model actually usable for context recall. Does NOT tick any readiness
> tracker boxes — this is demo-UX hardening, not a new capability axis.

Non-negotiable direction for this batch:

- No model swap. Everything must work on the existing 135M fixture.
- Server envelope stays backward-compatible. Pre-T-batch callers that send
  only `prompt` (no `messages[]`, no penalties) must see identical behaviour.
- Shared resolver for HTTP and WS transports so behaviour can't drift.
- Honest documentation of the failure modes and the specific knobs that fix
  them — so readers know both *what to do* and *why*.

### Done in current branch

- [x] `#T-1` Multi-turn chat memory: optional `messages[]` field on the
  inference-request envelope, plumbed through HTTP + WS paths to llama.cpp
  `/v1/chat/completions`, with transcripts persisted including messages.
  Browser UI (`public/chat.html`) now keeps an in-memory `state.history`
  of `{role, content}` turns, re-sent on every submit. Pre-T-1 clients
  that send only `prompt` are unchanged.
  - Shared resolver: `domain/inference/chat_messages.php`
  - Validator + schema: `domain/inference/inference_request.php` (adds
    `messages` as optional top-level key; roles `system|user|assistant`;
    1–64 items; content 1–32768 chars)
  - Transcript round-trip: `domain/inference/transcript_store.php`
  - Contract test: `tests/chat-memory-contract.{sh,php}` (37 rules)

- [x] `#T-2` Anti-collapse surface fix: add a default system prompt,
  lower default temperature to 0.2, and cap history at 8 turns (down
  from 32). Stops SmolLM2-135M from mode-collapsing into training-data
  snippets (the observed "Croatia/beaches/colors" loop). Single-fact
  recall (`"What is my name?"`) becomes reliable.
  - Edits: `public/chat.html` (`state.systemPrompt`, `pushHistory` cap,
    temperature default 0.7 → 0.2)
  - Live proof: name-recall across a fresh Playwright session.
  - Limitation surfaced: multi-fact follow-up questions still fail
    because the model echo-copies its previous short reply. Resolved
    in #T-3.

- [x] `#T-3` Repetition penalties + stronger system prompt: extend the
  sampling envelope with optional `frequency_penalty` and
  `presence_penalty` (OpenAI-compatible, range -2.0..2.0, default 0.0).
  Plumb both through HTTP + WS paths to `/v1/chat/completions` only when
  non-zero (keeps pre-T-3 payloads identical). UI ships defaults
  `frequency_penalty=0.8, presence_penalty=0.6`. Stronger system prompt
  explicitly forbids repeating/quoting the previous reply and anchors
  the model to the LATEST question.
  - Envelope + validator: `domain/inference/inference_request.php`
    (sampling: new optional fields with float range check)
  - Plumbing: `domain/inference/inference_session.php` (HTTP) and
    `domain/inference/inference_stream.php` (WS); both include the
    penalty fields only when `!== 0.0`
  - UI: `public/chat.html` adds two new numeric inputs and the
    refined system prompt under `state.systemPrompt`
  - Contract JSON: `contracts/v1/inference-request.contract.json`
    documents the new sampling fields
  - Live proof: 4-fact Playwright stress test (name, city, job, food)
    recalled 4/4 correctly on SmolLM2-135M-Instruct/Q4_K. Same test on
    T-2 baseline scored 1/3 (first probe correct, subsequent probes all
    returned `"My name is Julius."`).

- [x] `#T-4` Demo README learnings section: `demo/model-inference/README.md`
  gets a new "Prompting a tiny model for reliable chat memory" section
  documenting the two failure modes (training-data echo, previous-reply
  echo), the three-lever recipe that fixes them (system prompt + penalties
  + short history), the capacity limits that remain (multi-fact extraction
  in one turn, chain reasoning), and pointers to every file where the
  levers live. Positioned next to the existing scope-fences section since
  it's the same *what works / what doesn't* register.

### Next step (T-batch)

- T-batch complete. No new contract tests beyond T-1's `chat-memory-contract`
  (T-2 and T-3 edits are UI defaults + backward-compatible envelope
  extensions that don't need their own test — the existing envelope test
  already covers the optional-field rules). Offline suite: 36 pass / 4 skip
  / 1 pre-existing fail (unrelated to T-batch). No readiness tracker boxes
  move; this is honest demo-UX work on top of the shipped M/R/S capabilities.
