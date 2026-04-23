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
- Contributor credit and source paths are recorded. No GossipMesh/SFU production code has been ported yet under Q-14.

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
