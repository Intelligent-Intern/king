# Experiment Intake Provenance

Purpose:
- Keep contributor credit visible while selected experiment-branch work is ported into the current release branch.
- Avoid importing experiment artifacts, generated files, local machine paths, or weaker contracts just to preserve history.

Rules:
- Prefer `git cherry-pick -x` when a source commit can be ported without weakening current contracts.
- If a manual port is required, include the source commit hash in the commit body.
- Keep the original Git author visible when the port is materially based on a source commit.
- If the recorded author identity is later clarified, add a valid `Co-authored-by` trailer in the port commit.

## Q-13 IIBIN/Proto Batch And Varint Sources

Source range:
- `origin/experiments/v1.0.6-beta` through `4e58bef`, available locally through the experiment ancestry used for this sprint.

Recorded source commits:
- `3267785485ad61706170f9122f7af5997cc42202` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: optimize varint encode with branchless algorithm`
- `a669b0964382e23eb316125132f59ff86cd42c71` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: optimize varint decode with ARM64 unrolling`
- `e16af6f7e02f1826c11554dd68c49964bc7a7cd2` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: consolidate float/double to shared header`
- `c9f6cf63986d770b72405ca1a494aaccc6f9a67e` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: add batch encode to amortize PHP<->C boundary`
- `2914b0316e6138ec8a442d27b85b7d25e701ac22` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `perf: add batch decode to amortize PHP<->C boundary`
- `b6507fcc83a89d4b4770cce021efd0efbb8c81f9` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `bench: add batch encode/decode benchmarks`
- `8e0a539b837cd0e397b58528329c95f44c98e5cc` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `bench: update benchmarks with batch operations`
- `79df7a971ff10fe1d7a9bef64e0be63a4e9d2758` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `fixed king_proto_encode_varint; now batch processing`

Porting notes:
- Port code only after validating it against the current IIBIN/proto contracts.
- Do not carry generated benchmark results into the repo.
- Do not add public API surfaces until arginfo, stubs, function tables, docs, and PHPT coverage match.

Port status:
- `3267785485ad61706170f9122f7af5997cc42202` and `a669b0964382e23eb316125132f59ff86cd42c71` were reviewed for the varint port. The encode patch from `3267785` is not cherry-picked because its small multi-byte cases write non-canonical continuation bytes. The current port keeps the source context, ports the bounded/unrolled encode intent manually, and adds uint64 overflow-safe decode behavior without architecture-specific unaligned reads.
- The ARM64-specific varint decode unrolling from `a669b0964382e23eb316125132f59ff86cd42c71` remains out of the production path for now. The current production helper is architecture-neutral C with compiler-assisted length calculation where available. A future ARM64 helper needs a dedicated guard, benchmark, sanitizer coverage, and parity PHPT before it is enabled.
- `e16af6f7e02f1826c11554dd68c49964bc7a7cd2` was ported for float/double bit conversion consolidation: encode/decode now use the shared `iibin_internal.h` helpers instead of local duplicate helpers.
- `c9f6cf63986d770b72405ca1a494aaccc6f9a67e` and `2914b0316e6138ec8a442d27b85b7d25e701ac22` were reviewed for the public batch API. The stable public surface is ported as `king_proto_encode_batch()` and `king_proto_decode_batch()` plus `King\IIBIN::encodeBatch()` and `King\IIBIN::decodeBatch()`; it delegates to internal `king_iibin_encode_batch()` / `king_iibin_decode_batch()` helpers, pre-sizes output arrays, fails the whole batch on the first invalid record, and adds batch-index context while preserving the original lower-level exception as `previous`.
- `b6507fcc83a89d4b4770cce021efd0efbb8c81f9` and `8e0a539b837cd0e397b58528329c95f44c98e5cc` were reviewed for benchmark coverage. The standalone experiment script was not copied verbatim because the current tree has a canonical benchmark runner, budgets, docs, and result-hygiene rules. The useful intent is ported as clean source-only benchmark cases for batch encode/decode and varint-vs-Elias-omega comparison, with no generated result snapshots committed.

## Q-14 GossipMesh/SFU Research Sources

Source range:
- `origin/experiments/v1.0.6-beta` through `4e58bef`, represented locally during this sprint by the fetched experiment ancestry and the `sash-temp/develop-v1.0.6-beta` remote-tracking ref.

Recorded source commits:
- `d92dfddd09710f80c2599bab4dbb5f59c3f34f1c` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `save state commit`
- `dca5e9815eaf90900d8bda2de7b9850f969f48e2` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `main changes described in /documentation/README.md, protobuf.md, gossipmesh.md`
- `b338a87e505a0ed40eb32bacc47d099581d5e029` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `main changes described in /documentation/README.md, protobuf.md, gossipmesh.md`
- `9f7f544ba3dbc8159ca57335ae819d978b904406` - Alice-and-Bob `<sasha@MacBook-Pro-2.local>` - `bring IIBIN to gossip_mesh`

Relevant experiment paths:
- `extension/src/gossip_mesh/gossip_mesh.c`
- `extension/src/gossip_mesh/gossip_mesh.h`
- `extension/src/gossip_mesh/gossip_mesh.php`
- `extension/src/gossip_mesh/gossip_mesh_client.js`
- `extension/src/gossip_mesh/sfu_signaling.php`
- `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js`
- `documentation/gossipmesh.md`
- `extension/tests/999-gossipmesh-test.phpt`

Porting notes:
- Do not blindly import the experiment directory. The production path must keep current King runtime contracts, especially explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state.
- Prefer `git cherry-pick -x` only when a source commit can be applied without artifacts or weaker behavior. Otherwise, make a manual port and include the relevant source commit hash in the commit body.
- Keep the recorded author identity visible. If the contributor's real public identity is clarified, add a valid `Co-authored-by` trailer to material port commits instead of rewriting this provenance history.
- Do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks from these commits.
- Treat direct P2P/DataChannel behavior as research until it is re-specified under current backend-authoritative SFU, room, admission, and payload-protection contracts.

Port status:
- Contributor credit and source paths are recorded.
- A compatible server-authoritative runtime slice has been ported as `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`. Raw experiment transport, browser authority, process-local room state, generated artifacts, and debug scaffolding remain excluded.

Production API surface decision:
- The raw experiment surface is not the production King API. Do not expose the global PHP `GossipMesh` class, raw C `gossip_mesh_t` pointers, browser `GossipMeshClient` topology control, direct peer IP/port neighbor mutation, or process-local room ownership as stable API.
- The production surface is a server-authoritative topology and routing planner. Clients may receive assigned topology and media/signaling instructions, but they must not create call state, admission state, room identity, or trust decisions.
- The C layer may provide internal helpers for topology planning, duplicate-window tracking, TTL/fanout selection, relay candidate selection, and stats collection after those helpers have contract tests. The C layer must not expose raw mutable structs to PHP.
- The public PHP extension surface, if Q-14 proceeds to implementation, is a namespaced/static `King\GossipMesh` facade plus procedural `king_gossip_mesh_*` mirrors. Candidate stable operations are topology planning, membership delta application, envelope routing, duplicate suppression, relay fallback selection, and stats export.
- Public PHP calls must accept and return bounded arrays or typed King objects, not sockets, WebRTC objects, raw peers, or callbacks. WebSocket/SFU workers own transport side effects.
- Wire payloads must use a versioned IIBIN envelope once implemented. A JSON/debug envelope may exist only as test scaffolding until the production envelope is contract-tested.
- The video-chat demo may consume the production surface only through the existing backend-authoritative room/call/admission gateway. `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` remains research until it is folded into the current SFU client without weakening room binding, admission, logging, or security behavior.

SFU signaling admission review:
- `extension/src/gossip_mesh/sfu_signaling.php` cannot replace the active video-chat `/sfu` gateway. It creates rooms from arbitrary client input, derives `peer_id` from `spl_object_id($websocket)`, stores rooms and peers in process arrays, accepts client-provided `userId`/role-style state, and does not validate `call_id`, call-access session binding, `call_participants.invite_state`, `joined_at`/`left_at`, owner/moderator/admin authority, or DB-backed admission before room entry.
- The active production baseline is `videochat_handle_sfu_routes()` plus `videochat_realtime_user_has_sfu_room_admission()`: `/sfu` requires a valid WebSocket handshake, session auth, RBAC, a bound `room_id`, optional `call_id`, current room membership or persistent admission, and fail-closed `sfu_room_admission_required` behavior when the user has not been admitted.
- Active SFU frame handling already rejects room mismatch through `videochat_sfu_decode_client_frame()` and preserves protected media frame constraints. GossipMesh signaling must not reintroduce plaintext fallback, client-invented room changes, or cross-room peer discovery.
- Reusable ideas from the experiment signaling are limited to server-side bootstrap-peer selection, neighbor-exchange snapshots, relay-candidate selection, relay-fallback metadata, churn cleanup cadence, and max-peer bounds. Those ideas must run after the current admission gate and must read/write topology state through the server-authoritative call/room/SFU store.
- A future GossipMesh integration may add topology hints to admitted participants, but it must keep `call_id`/`room_id` as the binding key, preserve DB-backed participant state, and route all SFU/control messages through authorized backend events rather than process-local peer maps.

Reusable versus experiment-only split:
- Reusable topology ideas: bounded neighbor count, bootstrap-peer sampling, duplicate suppression keyed by publisher plus sequence, TTL/fanout limiting, deterministic forward selection for a frame, neighbor-health statistics, relay-candidate ranking, relay fallback metadata, churn cleanup cadence, and topology stats export.
- Reusable signaling ideas: admitted-participant topology snapshots, targeted offer/answer/ICE command shapes, neighbor-exchange deltas, relay request/assignment metadata, peer-left deltas, and request-new-peers commands. These must be emitted only by the backend after session, room, call, participant, and admission checks pass.
- Reusable envelope ideas: the optional IIBIN-style binary transport direction from `9f7f544` is compatible only as a versioned, backend-validated envelope. JSON compatibility, direct decoder construction in browser hot paths, and console-warning fallback are not production semantics.
- Experiment-only behavior: direct browser-to-browser media or frame transport, browser-owned peer IDs, browser-selected topology, browser-triggered room joins, unbounded public STUN defaults, client-side relay authority, process-local peer maps, random peer-connect probability as control policy, raw `console.*` debug paths, and any fallback that silently downgrades protected payloads to JSON/plaintext.
- Experiment-only docs: `documentation/gossipmesh.md` describes research architecture and must not be published as product documentation until the implementation is server-authoritative, admission-bound, protected-envelope-aware, and contract-tested.
- Port rule: implementation commits may port a reusable idea only together with a contract proving the corresponding experiment-only behavior is absent from the active path.

Direct P2P transport policy:
- Direct P2P/DataChannel transport from the experiment branch remains research only. It is not an active runtime path and must not be surfaced as production documentation, default config, deployment wiring, or UI capability.
- A future P2P runtime path has to be re-specified as `webrtc_native` under the current backend-authoritative model before implementation: server-issued peer identity, server-issued topology, explicit `call_id` and `room_id` binding, persisted participant/admission state, owner/moderator/admin authority checks, session revocation handling, and room-scoped event routing.
- Browser peers may never invent their own call, room, peer, relay, or neighbor authority. Browser-side WebRTC objects may execute an already authorized route, but policy decisions and participant visibility come from the backend.
- P2P media/control frames must use the existing protected-media contracts when policy requires protection: `call_id`, `room_id`, `participant_set_hash`, `runtime_path`, media suite, epoch, sender key id, and downgrade behavior must remain bound to the frame/header and key transcript.
- Transport security from DTLS/SRTP, WSS, or WebRTC DataChannel is not enough to claim protected media. Without the app-level protected media envelope and downgrade tests, the only honest state is `transport_only`.
- Any future P2P implementation must prove cross-room isolation, admission revocation, participant-set churn rekey, replay/duplicate handling, relay fallback authorization, and no plaintext fallback in required mode before it can leave research status.

Transport protection decision:
- Transport-level protection from WebRTC DataChannel, DTLS, SRTP, WSS, or TLS is not sufficient for intended media/control payloads that carry codec frames, audio/video units, sender keys, participant state, topology control, room policy, or relay instructions.
- Transport-level protection may only support the honest `transport_only` state and hop-by-hop transport confidentiality. It does not provide application-level participant binding, replay binding, epoch binding, sender-key binding, downgrade proof, relay visibility limits, or stable authorization semantics across SFU, relay, gossip, and storage paths.
- App-level protected envelopes are required whenever room policy is `required`, whenever payloads cross SFU, relay, or gossip peers, whenever payloads can be recorded, stored, or forwarded, or whenever UI, telemetry, API, or docs claim protected media or E2EE.
- Required envelope claims must remain bound to `call_id`, `room_id`, `participant_set_hash`, `runtime_path`, `kex_suite`, `media_suite`, `epoch`, `sender_key_id`, `sequence`, AAD length, and ciphertext length as pinned by `king-video-chat-protected-media-frame`.
- Plaintext or JSON fallback is allowed only in `transport_only` under `preferred` or `disabled` policy where UI and telemetry expose `transport_only`; it is forbidden in `required` mode and forbidden when a `protected_frame` field is present.
- Any GossipMesh or P2P port must use `king-video-chat-protected-media-transport-envelope` or an equivalent versioned IIBIN envelope. The SFU or gossip layer may inspect bounded public metadata only and must never see raw media keys, shared secrets, or plaintext media.

Compatible runtime port:
- The compatible runtime port is `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`.
- It ports the experiment ideas for bounded topology planning, TTL estimation, duplicate suppression, deterministic forward target selection, relay candidate ranking, and stats-safe member normalization.
- It deliberately does not port the experiment global `GossipMesh` class, C `gossip_mesh_t` surface, browser `GossipMeshClient`, `sfu_signaling.php`, direct P2P transport, process-local rooms, raw sockets, ICE/STUN/TURN defaults, JSON/plaintext fallback, or debug console behavior.
- The port accepts only server-provided admitted members and returns bounded arrays with `call_id`, `room_id`, `runtime_path`, `envelope_contract`, topology, relay candidates, and rejected-member counts.
- Artifact exclusions remain mandatory: `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, and submodule gitlinks must not be imported.

Frontend client integration decision:
- `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` is not ported as a standalone browser runtime.
- The experiment browser client is replaced by the current `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` integration point. Any compatible future GossipMesh client behavior must be folded into that existing SFU client after the backend publishes server-authoritative topology and routing contracts.
- Browser code may consume server-issued topology snapshots, relay hints, and forward instructions only after the current `/sfu` admission gate has bound `session`, `call_id`, `room_id`, participant state, and protected-media policy.
- Browser code must not generate peer identity, create room state, select topology, open direct DataChannels, pick relay authority, add public STUN/TURN defaults, or downgrade protected frames to JSON/plaintext.
- A future folded SFU-client extension must preserve existing backend-origin failover, `room_id` and `call_id` query binding, `protected_frame` carriage, `protection_mode` honesty, and current `sfu/frame` parsing semantics.
- Direct P2P/WebRTC-native behavior remains research until it is specified as a separate backend-authoritative `webrtc_native` contract with admission, revocation, rekey, relay, and downgrade tests.

Runtime contract-test coverage:
- `realtime-gossipmesh-runtime-contract.php` covers admitted-member filtering, rejected-member accounting, deterministic topology, bounded neighbor fanout, relay candidate ranking, duplicate suppression, TTL expiry, protected-envelope validation, route planning, relay fallback, and failure cases for plaintext data, missing envelope contract, duplicate frames, unknown publishers, and unavailable relays.
- `725-gossipmesh-runtime-coverage-contract.phpt` pins that the runtime exposes only backend-owned helpers for protected-envelope routing and that the coverage remains wired into `SPRINT.md` and `READYNESS_TRACKER.md`.

Production documentation:
- `documentation/gossipmesh.md` is now allowed because the production runtime contract exists and is covered by tests.
- The doc describes only the current backend-authoritative `wlvc_sfu` topology/routing helper, protected-envelope requirement, failure behavior, inactive experiment behaviors, frontend integration boundary, and provenance rules.
- It must not reintroduce the experiment documentation as product semantics.

SFU constraint preservation:
- The active `/sfu` gateway remains the only production entry point for SFU media signaling. The experiment `sfu_signaling.php` stays rejected as a replacement.
- It binds every SFU socket to a validated `room_id` and optional normalized `call_id` before WebSocket upgrade.
- Admission is current room membership or DB-backed admission through `videochat_realtime_user_has_sfu_room_admission()`.
- Durable SFU admission comes from current room presence plus the database-backed `calls`, `rooms`, and `call_participants` state, including participant role and invite state.
- Process-local `$sfuClients` and `$sfuRooms` are live socket indexes only after admission. They are not durable room identity, call identity, participant state, or admission state.
- Client SFU frames are decoded against the already-bound room through `videochat_sfu_decode_client_frame($msgJson, $roomId)`.
- The experiment may add topology hints after admission, but it must not create room identity, call identity, participant state, or admission state from client input.

Weakening behavior rejection:
- Rejected experiment behavior is forbidden in active `/sfu` and GossipMesh paths unless it is re-specified under the current backend-authoritative contract and covered by tests.
- The active path must not accept client-created room, call, peer, participant, admission, relay, or topology authority.
- It must not accept direct P2P media forwarding, process-local admission authority, JSON/plaintext downgrade for protected frames, unbounded public STUN/TURN defaults, raw sockets/network endpoints, or debug-generated control behavior.
- Reusable topology ideas may enter only as server-issued hints after session auth, RBAC, room/call binding, participant/admission checks, and protected-envelope validation.
- Any future native WebRTC or P2P mode must be a separate backend-authoritative runtime contract with revocation, participant churn, rekey, relay authorization, downgrade, and cross-room-isolation tests.

Q-14 disposition:
- GossipMesh is accepted only as the tested `wlvc_sfu` runtime helper in `demo/video-chat/backend-king-php/domain/realtime/realtime_gossipmesh.php`.
- The accepted capability is bounded topology planning, admitted-member filtering, protected-envelope routing, duplicate suppression, TTL/fanout limiting, relay candidate ranking, and relay fallback planning.
- The active proof is `demo/video-chat/backend-king-php/tests/realtime-gossipmesh-runtime-contract.php` plus PHPT guards `723` through `729`.
- Raw experiment API, C structs, standalone browser client, `sfu_signaling.php`, direct P2P transport, process-local authority, plaintext downgrade, generated artifacts, and debug scaffolding are rejected for the current runtime.
- Future WebRTC-native or P2P work must be opened as a separate backend-authoritative runtime contract instead of weakening this disposition.

Video-chat SFU compatibility disposition:
- The active video-chat SFU remains compatible with current room, admission, and security contracts after Q-14.
- Compatibility is proven by the existing `/sfu` gateway order: handshake validation, websocket session auth, RBAC, room/call binding, current room membership or DB-backed admission, then WebSocket upgrade.
- SFU message handling keeps bound-room decoding, protected-frame downgrade rejection, cross-room isolation, and room-scoped broker persistence.
- GossipMesh may only provide post-admission topology/routing hints and does not replace the video-chat SFU gateway.

## Q-15 WLVC/WASM/Kalman Experiment Diff Audit

Audited boundary: `4e58bef77420c03df379f2fe159a694c4d40493a`.

Compared paths: `codec-test.html`, `codec-test.md`, `src/lib/wasm/**`, `src/lib/wavelet/**`, `src/lib/kalman/**`, and `mediaRuntime*`.

Comparison outcome:
- The C++/WASM codec sources and generated `wlvc.*` assets are unchanged from the audited experiment boundary for this checkbox.
- The current TypeScript WASM wrapper is stronger than the experiment boundary because it keeps the WASM MIME cache-buster, uses `debugWarn`, and recreates stale encoder/decoder bindings after Emscripten class-mismatch errors.
- The current wavelet decoder is stronger because it bounds the V-channel payload slice by the declared byte count and rejects payload-length mismatch.
- The current Kalman filter is stronger because it multiplies the Kalman gain by `SInv`, computes process-noise `dt4` locally, and removes stale module-level `dt2`/`dt3`/`dt4` constants.
- The current codec test page keeps the experiment live-camera test intent but fixes the V-channel slice and removes unused Kalman result storage.
- `codec-test.md` and `src/lib/wavelet/README.md` were removed from the active frontend tree and replaced by canonical docs under `documentation/dev/`.
- `mediaRuntimeCapabilities.js` and `mediaRuntimeTelemetry.js` are present in the audited experiment boundary and remain in the current frontend.
- The duplicate legacy `demo/video-chat/frontend/src/lib/**` experiment tree is not reintroduced; the active tree is `demo/video-chat/frontend-vue/**`.

WASM MIME/cache-buster decision:
- Keep the current production-safe handling for this sprint leaf.
- `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts` imports the bundled `wlvc.js`, resolves `wlvc.wasm` through Emscripten `locateFile`, and appends `?v=application-wasm-20260421` through `WASM_MIME_CACHE_BUSTER`.
- `demo/video-chat/edge/edge.php` serves `.wasm` as `application/wasm`; the cache-buster only invalidates stale cached responses after MIME fixes and is not a MIME workaround by itself.
- The audited experiment boundary has no better production-safe replacement for this handling. A future replacement must provide an immutable asset fingerprint plus correct `application/wasm` serving and equivalent contract coverage.

Debug-log abstraction decision:
- Keep the current production-safe debug abstraction in active codec hotpaths.
- Active TypeScript codec, WASM wrapper, wavelet transform, wavelet processor, and Kalman processor files must use `debugLog`/`debugWarn` from `demo/video-chat/frontend-vue/src/support/debugLogs.js`.
- Direct `console.*` calls are forbidden in active `src/lib/wasm`, `src/lib/wavelet`, and `src/lib/kalman` JavaScript/TypeScript hotpath files, except generated Emscripten glue `wlvc.js`.
- `codec-test.html` remains a standalone manual diagnostic page and may keep browser-console diagnostics; it is not the production media hotpath.

WASM binding-mismatch recovery decision:
- Keep the current production-safe Emscripten binding-mismatch recovery in `demo/video-chat/frontend-vue/src/lib/wasm/wasm-codec.ts`.
- The wrapper recognizes stale class-handle errors by checking `Expected null or instance of` plus the target class name.
- On encoder mismatch, the wrapper deletes the stale encoder if possible, recreates it from the cached module reference with current width, height, quality, and key-frame interval, then retries the encode exactly through the recreated encoder.
- On decoder mismatch, the wrapper deletes the stale decoder if possible, recreates it from the cached module reference with current width, height, and quality, then retries the decode exactly through the recreated decoder.
- Non-binding errors still fail closed by rethrowing the original error; this recovery is not a broad catch-all fallback.

SFU compatibility decision:
- Keep the current `demo/video-chat/frontend-vue/src/lib/sfu/sfuClient.ts` and backend `/sfu` compatibility behavior for this sprint leaf.
- The client resolves SFU websocket candidates through `resolveBackendSfuOriginCandidates()`, connects through `buildWebSocketUrl(origin, '/sfu', query)`, and records the working origin with `setBackendSfuOrigin(...)`.
- The client binds `room`, `room_id`, and validated `call_id` query parameters before opening `/sfu`, sends outbound SFU commands with snake_case fields, and remains compatible with camelCase or snake_case server events.
- The backend `/sfu` gateway validates handshake, websocket auth, RBAC, room binding, `call_id`/`callId`, and room admission before `king_server_upgrade_to_websocket(...)`.
- Client SFU command frames are decoded against the already-bound room, accepting legacy `room`/`roomId` only when they match and failing closed with `sfu_room_mismatch` on cross-room commands.

Remaining Q-15 leaves decide which other current stronger behavior must stay pinned and whether any remaining experiment diff should be ported.
