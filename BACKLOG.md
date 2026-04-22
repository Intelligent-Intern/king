# King Backlog

Purpose:
- This is the source of truth for open work.
- GitHub Project: https://github.com/orgs/Intelligent-Intern/projects/1
- Work is split into five release batches so multiple people can work in parallel with clear ownership boundaries.
- `READYNESS_TRACKER.md` is the completion log only.
- `SPRINT.md` is only the active sprint extraction from this backlog, not the long-term backlog.

Rules:
- Do not weaken the King v1 contract to close a backlog item faster.
- Completed work is removed from `SPRINT.md`, checked off or removed here, and summarized in `READYNESS_TRACKER.md`.
- Contributor credits are preserved. Matching experiment commits should be cherry-picked where possible; manual ports must carry source-branch context and `Co-authored-by` once the author is identified.
- Current stronger production contracts beat experiment-branch behavior where they conflict.
- No generated test results, build trees, libtool/phpize churn, local lockfiles, `.DS_Store`, debug scratch files, or orphaned submodule gitlinks are valid output.
- No path may be labeled secure, encrypted, E2EE, or post-quantum unless implementation, contracts, negative tests, and runtime/UI state prove the claim.

## Release Batch Map

| Batch | GitHub Issue | Target Version | Owner Lane | Primary Scope | Parallel Notes |
| --- | --- | --- | --- | --- | --- |
| 1 | `#146` | `1.0.7-beta` | Video-call demo | Live call correctness, lobby, chat, responsive UI, deploy/admin ops, frontend cleanup | Owns `demo/video-chat/**`; coordinate with Batch 2 only around media-security labels and protected media mode. |
| 2 | `#147` | `1.0.7-beta` | E2E + encryption | Media E2EE contracts, implementation, protected envelopes, KEX/PQ posture, end-to-end acceptance gates | Owns security contracts/tests and media protection; coordinate with Batch 1 on UI state and media pipeline touch points. |
| 3 | `#148` | `1.0.7-beta` | Core runtime + experiment intake | LSQUIC regression, migration cleanup, IIBIN/proto batch, GossipMesh, WLVC/WASM/Kalman audit | Owns `extension/**`, `infra/**`, `documentation/**`, `benchmarks/**`; avoid video UI churn unless a codec/SFU integration contract requires it. |
| 4 | `#149` | `1.0.7-beta` | AI / SLM / fine-tuning | Model placement, distributed inference, prompt/cache checkpoints, fine-tuning, model extensions | Owns model-inference and AI platform surfaces; should not touch video-call demo or MarketView except shared runtime contracts. |
| 5 | `#150` | `v1.0.12-beta+` | Future work | MarketView trading demo and later product explorations | Keep at the bottom; do not start until Batches 1-4 are stable or explicitly reprioritized. |

## Batch 1: Video-Call Demo Live Readiness (`1.0.7-beta`)

### #Q-16 Video-Chat Media Fanout And Participant Rendering

Goal:
- Every participant in one call room sees and hears the other admitted participants, with stable roster and layout state.

Checklist:
- [ ] Verify the joined user is admitted into the owner's existing call room instead of creating or resolving a separate room/session.
- [ ] Fix SFU publish/subscribe fanout so remote audio and video tracks are delivered across browser sessions.
- [ ] Ensure remote participants render in mini-video slots unless pinned/promoted to the main stage.
- [ ] Ensure participant roster entries are derived from stable server-authoritative presence and do not jitter on polling/reconnect ticks.
- [ ] Keep admin rights equivalent to call-owner rights inside the call.
- [ ] Add a two-browser Playwright journey that proves admin plus user see each other, hear/receive media signals, and share the same participant list.

Done:
- [ ] Admin and user in the same call both see local and remote media plus the same roster without flicker.

### #Q-17 Video-Chat Lobby, Admission, And Role Boundary

Goal:
- Invited users pass through the join modal gate, owners/admins/moderators see pending users, and plain users never see moderator-only lobby UI.

Checklist:
- [ ] Keep invited users on the existing join modal until `Join call` moves their participant state to `pending`.
- [ ] Reset `pending` back to `invited` when the modal closes or the websocket/session disappears before approval.
- [ ] Show the host notification and lobby badge/list for pending users to call owner, admins, and moderators.
- [ ] Admit users only after an authorized owner/admin/moderator grants access, then redirect the waiting browser into the same call.
- [ ] Hide the lobby tab from plain invited users unless they are explicitly promoted to moderator or are the call owner/admin.
- [ ] Add role-boundary tests for owner, admin, moderator, invited user, and removed participant.

Done:
- [ ] The admission flow is gate-first, room-stable, and role-correct.

### #Q-18 Video-Chat Chat, Archive, Emoji, And Attachment Release Readiness

Goal:
- In-call chat works during the call and archived chat/files are readable afterwards through standard responsive modal surfaces.

Checklist:
- [ ] Fix disabled send-button paths for text and emoji messages in the call chat.
- [ ] Show unread chat badge and first-message chat notification for other participants.
- [ ] Keep emoji reactions/chat emoji delivery visible to all call participants.
- [ ] Keep inline message limits and oversized-paste-to-attachment behavior intact.
- [ ] Keep allowed attachment types and object-store ACL/download boundaries intact.
- [ ] Rebuild the post-call chat/files modal with the shared modal style and responsive layout.
- [ ] Add Playwright coverage for text send, emoji send, unread badge, attachment upload/download, and read-only archive modal.

Done:
- [ ] Chat is usable live, notifies other participants, and the archive modal matches product modal standards on desktop and mobile.

### #Q-19 Video-Chat Admin Operations And Production Deploy Readiness

Goal:
- Production deploy and operations views expose real, safe, backend-driven state instead of placeholders or oversharing.

Checklist:
- [ ] Replace static operations data such as sample running calls with backend/live data.
- [ ] Correct live call and participant counts from current call/session/SFU state.
- [ ] Keep public health responses safe for production and hide schema/user/internal runtime detail unless authorized.
- [ ] Keep deployment configuration in `.env.local` and make deploy wizard reruns idempotent for known-host, cert, DNS, and compose/service state.
- [ ] Verify HTTPS redirect, certificate renewal hooks, API, websocket, and SFU endpoints with scripted `curl`/websocket smoke checks.
- [ ] Investigate and eliminate runaway `/app/edge.php` CPU spin under production routing.
- [ ] Keep Hetzner-specific discovery behind provider abstractions so Kubernetes or other providers can be added later.

Done:
- [ ] A fresh production deploy is repeatable and the admin operations page reports real safe state.

### #Q-20 Video-Chat Responsive Call Management And Workspace UI Parity

Goal:
- Desktop, tablet, and mobile call management use the same product flows and the same established visual system.

Checklist:
- [ ] Mobile user call creation/editing can add internal participants.
- [ ] Remove the obsolete `Room name` field from create/edit call modal flows.
- [ ] Keep mini-video layout portrait-oriented and available on tablet/mobile with above/below-main toggle controls.
- [ ] Move activity strategy controls into the left sidebar call-settings area using the existing select/control styling.
- [ ] Remove ad-hoc overlay, border, background, and color treatments that diverge from the current design system.
- [ ] Ensure call settings width aligns with neighboring sidebar controls.
- [ ] Add responsive Playwright coverage for mobile call creation with participants, mini-video toggle, and call-settings strategy selection.

Done:
- [ ] Mobile and desktop call-management flows are feature-equivalent and visually consistent.

### #Q-21 Video-Chat Frontend Refactor And Shared UI Components

Goal:
- Reduce recurring UI drift and file-size pressure without changing behavior.

Checklist:
- [ ] Split oversized frontend files toward the current target of maximum 750 LOC per source file.
- [ ] Extract shared modal shell, header/title blocks, action bars, buttons, tables, pagination, empty states, and form controls where product behavior is already equivalent.
- [ ] Split frontend state into focused stores for auth, calls, participants, chat, presence, and settings.
- [ ] Keep existing visual standards instead of introducing one-off colors, borders, or modal variants.
- [ ] Add focused component/store tests or Playwright smoke coverage around extracted shared surfaces.
- [ ] Keep refactor commits small enough that regressions can be bisected.

Done:
- [ ] Shared UI primitives reduce duplicate modal/table/header/action code while existing flows still pass.

### #VC-TEST-1 Frontend Gherkin Feature Coverage Intake

Source:
- Imported from GitHub issues `#131` through `#139` before replacing one-issue-per-view tracking with one issue per release batch.

Goal:
- Convert the old view-by-view Gherkin issue set into real Playwright/Cucumber-compatible frontend coverage without scattering planning across GitHub issues.

Checklist:
- [ ] Core UI primitives expose stable `data-testid` selectors and accessible state for buttons, form controls, modals, sidebars, tabs, tables, pagination, empty/loading states, media preview, and call controls.
- [ ] Login feature covers valid login, validation errors, backend rejection, authenticated redirects, and tablet/mobile orientation changes.
- [ ] Guest call-join feature covers access-link resolution, display-name requirements, invited-guest identity, media-preview failure, invalid/expired/forbidden links, and orientation changes.
- [ ] Guest exit feature covers guest-only exit confirmation, no authenticated workspace controls, no media preview, no websocket reconnect UI, and orientation changes.
- [ ] Admin overview feature covers dashboard/calendar switching, calendar call compose, non-admin redirect, and orientation state preservation.
- [ ] User management feature covers admin-only user table, search/pagination, create/edit user modal, email/avatar/status flows, non-admin redirect, and orientation state preservation.
- [ ] Admin video-calls feature covers list/calendar switching, create/edit with registered and external participants, enter-call media preview, preview failures, cancel/delete confirmation, non-admin redirect, and orientation state preservation.
- [ ] User dashboard feature covers user call list/calendar, invite redemption, invite errors, enter-call media preview, owner create/edit flow, role redirects, and orientation state preservation.
- [ ] Video room feature covers local/remote media surfaces, call controls, chat send/receive, lobby admission, non-moderator role boundaries, owner settings edit, media toggles, reconnect/expired auth states, orientation continuity, and hangup routing.
- [ ] Feature files use English Gherkin, role/breakpoint tags, and `data-testid` selectors only; no CSS class or visible-text selectors.
- [ ] View-level features do not retest shared Core UI primitive internals.

Done:
- [ ] The imported GitHub Gherkin scope is covered by feature files and executable frontend tests, with stable selectors and responsive/orientation coverage.

## Batch 2: E2E, Encryption, And Security Claims (`1.0.7-beta`)

### #Q-22 Video-Chat E2EE Threat Model, Contracts, And Runtime Honesty

Goal:
- Define the real media-security contract for video-chat so transport security, media-E2EE state, capability policy, and failure behavior are explicit and testable.

Checklist:
- [ ] Publish `demo/video-chat/contracts/v1/e2ee-session.contract.json`.
- [ ] Publish `demo/video-chat/contracts/v1/protected-media-frame.contract.json`.
- [ ] Pin participant key state, epoch semantics, sender key id, receiver expectations, replay inputs, error codes, and rekey transitions.
- [ ] Explicitly distinguish `transport_only`, `protected_not_ready`, `media_e2ee_active`, `blocked_capability`, `rekeying`, and `decrypt_error`.
- [ ] Define one deterministic capability negotiation policy for `required | preferred | disabled`.
- [ ] Define one shared security model across native WebRTC and WLVC/SFU paths.
- [ ] Add negative tests for unsupported capability, mixed rooms, invalid control state, downgrade attempts, and malformed protected frames.
- [ ] Make README, runtime notes, UI state, and telemetry wording match the contract exactly.
- [ ] Remove any “E2EE” wording from paths that are only DTLS/TLS protected.

Done:
- [ ] Media security claims are contract-first, runtime-honest, and testable.

### #Q-23 Video-Chat Native And SFU Media E2EE Implementation

Goal:
- Implement real media E2EE for both the native WebRTC path and the WLVC/SFU path so the server cannot decrypt protected media payloads.

Checklist:
- [ ] Implement client-side session key establishment and media epoch state.
- [ ] Keep raw media keys client-side only in normal operation.
- [ ] Implement sender-side media encryption for the native WebRTC path before remote delivery.
- [ ] Implement receiver-side decryption and integrity validation for the native WebRTC path.
- [ ] Implement sender-side media encryption for the WLVC/SFU path before `sfu/frame` transit.
- [ ] Implement receiver-side decryption and integrity validation for the WLVC/SFU path.
- [ ] Add participant join/leave/admission/removal/reconnect rekey behavior.
- [ ] Reject wrong epoch, wrong key id, replayed units, tampered payloads, and stale post-removal material.
- [ ] Add wire/packet-path verification proving the SFU forwards ciphertext and bounded public metadata only.
- [ ] Add CI coverage for native sender->receiver success, tamper rejection, and WLVC/SFU ciphertext-only transit.

Done:
- [ ] The E2EE path protects media end-to-end and the server cannot decode call content.

### #Q-24 Video-Chat Protected Media Transport Cleanup

Goal:
- Clean up the media transport layer so protected media is carried in a pinned typed/binary envelope rather than ad-hoc plaintext-oriented payload conventions.

Checklist:
- [ ] Separate codec-frame, transport-envelope, and protected-media contracts.
- [ ] Replace any ad-hoc JSON byte-array carriage for protected media with a pinned typed or binary envelope.
- [ ] Add bounded parse rules, malformed-frame rejection, and size ceilings for protected media transit.
- [ ] Ensure `/sfu` never needs raw media keys and never accepts unauthenticated plaintext in E2EE mode.
- [ ] Add contract tests for envelope parse/serialize parity and malformed-frame rejection.
- [ ] Add relay-visible-field tests so only intentionally public metadata crosses the SFU.
- [ ] Keep compatibility behavior explicit: no implicit fallback from protected envelope to plaintext media in `required` mode.

Done:
- [ ] Protected media transit is pinned, bounded, and ready for stable E2EE rollout.

### #Q-25 Video-Chat Algorithm-Agile And Hybrid Post-Quantum Key Agreement

Goal:
- Make the media-E2EE design algorithm-agile and able to support hybrid classical + post-quantum key establishment without redesigning the media-protection layer.

Checklist:
- [ ] Add a KEX abstraction independent from the protected-media frame format.
- [ ] Pin the negotiated KEX suite in capability negotiation and session state.
- [ ] Ship one production classical KEX path first on the shared abstraction.
- [ ] Add hybrid classical + PQ suite negotiation behind explicit policy.
- [ ] Bind transcript, room, participants, and selected suite into derived media epoch material.
- [ ] Add downgrade rejection across KEX suites.
- [ ] Add rejoin, reconnect, participant churn, and forced-rekey coverage under hybrid mode.
- [ ] Add telemetry that distinguishes classical vs hybrid sessions without leaking secrets.
- [ ] Document exactly what “post-quantum” means in this stack: key-establishment posture, not blanket secrecy of metadata, topology, or signaling.
- [ ] Keep post-quantum wording out of README/security claims until suite agreement, transcript binding, and downgrade tests are green.

Done:
- [ ] Media-key derivation is algorithm-agile and hybrid PQ works under the same pinned session-state contract as the classical path.
- [ ] Downgrade across KEX suites fails closed and is CI-covered.

### #E2E-1 Video-Chat End-To-End Acceptance Matrix

Goal:
- Prove the demo as a user journey, not only as isolated endpoint contracts.

Checklist:
- [ ] Add Playwright journey: owner creates call, invited user logs in from link, waits in join modal, owner admits, both see media and roster.
- [ ] Add Playwright journey: chat text, emoji, unread badge, attachment, and post-call read-only archive.
- [ ] Add Playwright journey: mobile call creation/editing with internal participant add.
- [ ] Add Playwright journey: websocket interruption, reconnect, room resync, and media/control recovery.
- [ ] Add release gate that fails when UI parity matrix or core video journeys are not covered.

Done:
- [ ] E2E journeys are deterministic enough to gate release readiness.

## Batch 3: Core Runtime, Experiment Intake, And Release Closure (`1.0.7-beta`)

### #Q-11 Full HTTP/3 Regression Against New Stack

Goal:
- Prove the new stack carries the previous HTTP/3/QUIC contract.

Checklist:
- [ ] Client one-shot request/response tests are green.
- [ ] OO `Http3Client` exception matrix is green.
- [ ] Server one-shot listener tests are green.
- [ ] Session-ticket and 0-RTT tests are green.
- [ ] Stream lifecycle, reset, stop-sending, cancel, and timeout tests are green.
- [ ] Packet loss, retransmit, congestion control, flow control, and long-duration soak are green.
- [ ] WebSocket-over-HTTP3 relevant slices are green.
- [ ] Performance baseline against the previous Quiche state is documented.

Done:
- [ ] New stack is proven at the existing contract level.
- [ ] Deviations are fixed or registered as new blocker issues.

### #Q-12 Migration Closure And Repo Cleanup

Goal:
- Close the sprint cleanly: no leftover artifacts, no half-renamed paths, no old build assumptions.

Checklist:
- [ ] Complete `rg` sweep for Quiche, Cargo, Rust-HTTP3, local paths, and stub loaders.
- [ ] `git status` contains no generated build or test artifacts.
- [ ] Docs, tests, CI, and release manifests reference the same new stack.
- [ ] Add closure note to `documentation/project-assessment.md` and `READYNESS_TRACKER.md` with test evidence.
- [ ] Split migration work into logical commits: inventory, build, client, server, tests, docs/cleanup.

Done:
- [ ] Quiche is removed from the active product path.
- [ ] HTTP/3/QUIC is fully proven on the new stack.

### #Q-13 Port Experiment IIBIN/Proto Batch And Varint Work

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.
- Relevant source commits: `3267785`, `a669b09`, `e16af6f`, `c9f6cf6`, `2914b03`, `b6507fc`, `8e0a539`, `79df7a9`.

Goal:
- Bring the useful IIBIN/proto performance and batch API work into the current tree without importing experiment artifacts or weakening existing contracts.

Checklist:
- [ ] Preserve contributor credit for the experiment work.
- [ ] Port branchless varint encode and architecture-safe decode improvements into `extension/include/iibin/iibin_internal.h`.
- [ ] Review whether ARM64-specific unrolling belongs in production or should stay behind a guarded helper.
- [ ] Consolidate float/double bit helpers without duplicating logic across encode/decode/prelude paths.
- [ ] Add `king_proto_encode_batch(schema, records[])` and `king_proto_decode_batch(schema, binary_records[], options)` as stable public API only if the contract is fully validated.
- [ ] Add internal `king_iibin_encode_batch()` and `king_iibin_decode_batch()` with bounded memory behavior and per-record error handling.
- [ ] Add or port batch encode/decode PHPT coverage under the current test numbering scheme.
- [ ] Add benchmarks for batch encode/decode and omega-vs-varint only as clean source files, not generated results.
- [ ] Add explicit failure coverage for malformed batch boundaries, truncated records, schema mismatch, oversized batches, and bounded allocation behavior.
- [ ] Update documentation for IIBIN/proto batch behavior and performance expectations.

Done:
- [ ] IIBIN batch APIs are public, documented, tested, and wired through arginfo/function-table surfaces.
- [ ] Varint performance changes are proven on supported architectures without undefined behavior.

### #Q-14 Port Experiment GossipMesh/SFU Research As King Runtime Contract

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.
- Relevant source commits: `d92dfdd`, `dca5e98`, `b338a87`, `9f7f544`.

Goal:
- Evaluate and port the useful GossipMesh/SFU pieces as a real King runtime contract, not as raw experiment code.

Checklist:
- [ ] Preserve contributor credit for the experiment work.
- [ ] Review `extension/src/gossip_mesh/*` and decide the production King API surface.
- [ ] Review `extension/src/gossip_mesh/sfu_signaling.php` against the current video-chat SFU room-binding and admission model.
- [ ] Separate reusable topology/signaling ideas from experiment-only behavior.
- [ ] Treat direct P2P transport in the experiment branch as research until it is re-specified under current backend-authoritative room/call contracts.
- [ ] Explicitly decide whether transport-level DataChannel protection is sufficient for any intended payloads or whether app-level protected envelopes are required.
- [ ] Port only compatible GossipMesh runtime pieces; do not import `.DS_Store`, `tmp_*`, debug PHPTs, generated test results, generated build churn, or submodule gitlinks.
- [ ] Decide whether `demo/video-chat/frontend-vue/src/lib/sfu/gossip_mesh_client.js` should be ported, replaced, or folded into the current SFU client.
- [ ] Add contract tests for GossipMesh message routing, membership, IIBIN envelope use, duplicate suppression, TTL handling, relay fallback, and failure behavior.
- [ ] Add `documentation/gossipmesh.md` only after the production contract matches the implementation.
- [ ] Keep the current stronger SFU constraints: explicit room/call binding, DB-backed admission, no process-local room identity, and no client-invented call state.
- [ ] Reject any experiment behavior that weakens current room/admission/security guarantees.

Done:
- [ ] GossipMesh is either rejected with documented reasons or ported as a tested King runtime capability.
- [ ] Video-chat SFU remains compatible with current room/admission/security contracts.

### #Q-15 Audit Remaining WLVC/WASM/Kalman Experiment Diffs

Source:
- `origin/experiments/v1.0.6-beta` through `4e58bef`.

Goal:
- Verify whether any remaining codec/WASM/Kalman experiment diffs should be ported, while keeping the stronger current implementation where it already improved the experiment.

Checklist:
- [ ] Compare `codec-test.html`, `codec-test.md`, `src/lib/wasm`, `src/lib/wavelet`, `src/lib/kalman`, and `mediaRuntime*` against the experiment branch.
- [ ] Keep current WASM MIME/cache-buster handling unless a better production-safe replacement exists.
- [ ] Keep current debug-log abstraction and avoid reintroducing noisy direct `console.*` paths in hot codec loops.
- [ ] Keep current WASM encoder/decoder binding-mismatch recovery unless disproven by tests.
- [ ] Keep current SFU origin, call-id, snake_case compatibility, and room-binding behavior.
- [ ] Port only verified codec correctness or performance improvements with targeted frontend tests.
- [ ] Add explicit regression checks for encode/decode parity, crash-free decode failure, runtime-path switching, and remote render continuity.
- [ ] Document the outcome in `READYNESS_TRACKER.md`.

Done:
- [ ] Remaining codec experiment diffs are either ported with tests or explicitly classified as superseded by current implementation.

## Batch 4: AI / SLM / Fine-Tuning Platform (`1.0.7-beta`)

### #AI-1 Distributed Model Placement And Inference Execution

Goal:
- Move from proven single-node/model-fit surfaces to real fleet-aware model placement and distributed inference execution.

Checklist:
- [ ] Define network-aware model placement across multiple nodes.
- [ ] Define distributed inference execution across multiple servers.
- [ ] Define future MoE-ready multi-node expert routing.
- [ ] Add placement tests that use node hardware profile, health, memory pressure, model artifact locality, and network cost.
- [ ] Add fail-closed behavior for no-fit, no-healthy-node, and stale placement state.

Done:
- [ ] Placement decisions are deterministic, explainable, and backed by real multi-node proof.

### #AI-2 Prompt, Cache, And Checkpoint Persistence

Goal:
- Persist model-facing runtime state where claimed, using King-owned object-store/runtime contracts.

Checklist:
- [ ] Define object-store-backed prompt persistence where applicable.
- [ ] Define object-store-backed response/cache persistence where applicable.
- [ ] Define checkpoint persistence for long-running AI workflows.
- [ ] Define ownership, retention, encryption, and invalidation semantics.
- [ ] Add restart/resume tests for persisted prompt/cache/checkpoint state.

Done:
- [ ] AI workflow state survives restart only where explicitly claimed and tested.

### #AI-3 Fine-Tuning And Training Data Workflows

Goal:
- Add real fine-tuning workflow contracts instead of only inference and retrieval surfaces.

Checklist:
- [ ] Define training-data extraction pipelines from stored data.
- [ ] Define dataset-building pipelines for task-specific SLMs.
- [ ] Define dataset versioning and lineage.
- [ ] Define fine-tuning workflows for small models.
- [ ] Define fine-tuning artifact storage and recovery.
- [ ] Define fine-tuned model registration and reuse.
- [ ] Define evaluation and validation surfaces for fine-tuned models.
- [ ] Finalize the public contract for fine-tuning workflows.

Done:
- [ ] A fine-tuned model can be produced, registered, evaluated, and reused under a documented King contract.

### #AI-4 Advanced Model Extensions

Goal:
- Keep advanced model work extensible without bloating the built-in SLM surface.

Checklist:
- [ ] Define advanced model capabilities as extension-based functionality.
- [ ] Define external providers as extension-based functionality.
- [ ] Define larger model families as extension-based functionality.
- [ ] Define advanced routing, fallback, and policy layers as extension-based functionality.
- [ ] Define advanced multimodal capabilities as extension-based functionality.
- [ ] Define clear public contract boundaries between the built-in AI platform and later model extensions.

Done:
- [ ] Built-in AI platform and extension-based advanced model features have a clean, tested boundary.

## Batch 5: Future Work / MarketView Trading Demo (`v1.0.12-beta+`)

### #MV-1 MarketView Product Boundary And Data Contract

Goal:
- Define the trading demo as a technical preview with paper-trading semantics only.

Checklist:
- [ ] Define explicit MarketView scope: market data plus paper trading, no real-money execution.
- [ ] Define symbol universe and normalization contract.
- [ ] Define typed market event envelope: trade, quote, orderbook delta, candle, heartbeat, status.
- [ ] Define deterministic event ordering policy per symbol and stream.
- [ ] Define bounded replay window behavior for late-joining clients.

Done:
- [ ] MarketView cannot be confused with a live brokerage/execution product.

### #MV-2 Market Feed, Aggregation, And Fanout

Goal:
- Implement the backend runtime shape for realtime market data.

Checklist:
- [ ] Implement market-feed adapter boundary with pluggable providers.
- [ ] Implement reconnecting upstream feed client with bounded backoff and health state.
- [ ] Implement per-symbol 1s and 5s OHLC aggregation from trade ticks.
- [ ] Implement top-of-book snapshot plus delta propagation contract.
- [ ] Implement backend websocket fanout for symbol-scoped subscriptions.
- [ ] Persist minimal demo state: recent candles, paper orders, positions.

Done:
- [ ] Market data flows through bounded backend contracts with replay and health behavior.

### #MV-3 MarketView Frontend UX

Goal:
- Build the responsive market dashboard.

Checklist:
- [ ] Build shell layout: symbol rail, chart stage, right detail panel.
- [ ] Implement realtime candle chart with live cursor and timeframe toggle.
- [ ] Implement trades tape with bounded in-memory window and deterministic timestamp formatting.
- [ ] Implement orderbook ladder view with depth bars and live spread indicator.
- [ ] Implement symbol switcher with preserved per-symbol UI state.
- [ ] Implement connection-state banner and recovery hints.

Done:
- [ ] MarketView frontend reads as a coherent realtime demo and works on desktop/tablet/mobile.

### #MV-4 Paper Trading Flow

Goal:
- Add paper order entry and portfolio state without claiming real execution.

Checklist:
- [ ] Define paper-order model: market/limit fields, lifecycle, fills, cancellation.
- [ ] Implement order ticket UI with validation and explicit failure reasons.
- [ ] Implement backend paper matching simulation against live/simulated ticks.
- [ ] Implement positions, unrealized/realized PnL, and trade history surfaces.
- [ ] Implement risk guardrails: max position size, notional cap, invalid quantity/price rejection.

Done:
- [ ] Paper trading is usable and explicitly non-production/non-brokerage.

### #MV-5 MarketView Packaging And Ops

Goal:
- Package MarketView as a separate future demo.

Checklist:
- [ ] Add `demo/marketview/frontend` and `demo/marketview/backend` folder split mirroring video-chat demo layout.
- [ ] Add docker compose stack for MarketView demo.
- [ ] Add optional feed-simulator sidecar.
- [ ] Add CI build smoke for MarketView frontend/backend images.
- [ ] Add README with explicit tech-preview messaging, startup commands, and known limitations.
- [ ] Add end-to-end smoke check: start stack, subscribe symbol, receive ticks/candles, place paper order.

Done:
- [ ] MarketView can be started and smoke-tested as a future technical preview.
