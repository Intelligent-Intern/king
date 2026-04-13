# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It carries the currently active executable batch, including leaves already
> closed inside that batch.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE` before starting, replenishing, or reshaping any executable batch
- keep the active batch visible here until it is exhausted; mark closed leaves as `[x]` instead of deleting them mid-batch
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, touched comments/docblocks, and tests in the same change; handbook docs and `READYNESS_TRACKER.md` may be deferred only when the current batch explicitly says so by user request
- when a leaf closes, also verify the affected runtime with the strongest relevant tests/harnesses available before committing
- when a leaf closes, make exactly one commit for that checkbox; do not batch multiple checkbox closures into one commit
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next executable batch or enables continuous batch execution
- when the current batch is exhausted, stop and wait instead of refilling it automatically unless continuous batch execution is explicitly enabled
- complete one checkbox per commit while an active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope
- before opening, updating, or marking a PR ready, clear all outstanding GitHub AI findings for this repo at `https://github.com/Intelligent-Intern/king/security/quality/ai-findings`

## Per-Issue Closure Checklist

- update the runtime/backend code needed for the leaf
- update any touched comments, docblocks, headers, and contract wording so code and prose stay aligned
- add or tighten tests that prove the leaf on the strongest honest runtime path available
- update repo docs affected by the leaf, unless the current batch explicitly defers handbook closeout to the end
- update `PROJECT_ASSESSMENT.md`
- update `READYNESS_TRACKER.md`, unless the current batch explicitly defers tracker closeout to the end
- run the strongest relevant verification available for that leaf before committing
- make exactly one commit for the checkbox
- before any PR refresh or release-candidate handoff, re-check `https://github.com/Intelligent-Intern/king/security/quality/ai-findings` and fix every outstanding finding on the branch

## Batch Mode

- The user is advancing the current batch manually with `w`.
- Close exactly one checkbox, make exactly one commit, and then wait for the next `w`.
- Do not auto-refill from `READYNESS_TRACKER.md`; only replenish when the user explicitly requests the next batch.
- Keep `ISSUES.md` aligned with the active release branch and commit roadmap reshapes explicitly.

## Current Next Leaf

- Batch `V1` is active on `develop/v1.0.6-beta` (video-call replatform to King PHP backend + Vue frontend).
- Start with `V1 #1`, close exactly one checkbox per `w`, and keep one commit per closed checkbox.

## Active Executable Items

### V0. Release Blocker Security Remediation (4er Batch)

- [x] `#1 Produce a deterministic CVE inventory for Docker/runtime images (CVE-2025-45582, CVE-2024-56433, CVE-2024-2236).`
  done when: CI or local reproducible scan output maps each CVE to exact affected image/package/version and records fixed-target versions.
- [x] `#2 Apply highest-priority dependency/base-image updates to remove CVE-2025-45582 and CVE-2024-56433 from release images.`
  done when: the affected Dockerfiles/workflows are updated, builds stay green, and rescans show both CVEs no longer present.
- [x] `#3 Resolve CVE-2024-2236 for release gate (fix, replace component, or documented non-exploitable path with explicit control).`
  done when: release CI has an enforceable gate for this CVE and the branch contains either a real remediation or a justified, tested fail-closed mitigation.
- [x] `#4 Switch demo/video-chat IIBIN usage to the published npm package (@intelligentintern/iibin) from node_modules.`
  done when: frontend imports resolve from `@intelligentintern/iibin`, local duplicate protocol sources are removed from app usage paths, and build/tests stay green.

### U2. Video Call Productization (30er Batch)

Design guardrails for this batch:
- no glassmorphism, no opacity-heavy overlays, no decorative noisy borders
- visual language: clean enterprise blend (IBM Carbon x Fiori x Microsoft style)
- responsive first: mobile, tablet, and desktop must all remain usable

- [x] `#1 Build a canonical workspace shell layout (rail + stage + context) with deterministic breakpoints.`
  done when: the video-chat app uses one responsive shell architecture with explicit breakpoints and no legacy stress-panel fragmentation.
- [x] `#2 Introduce one shared UI token layer for color, spacing, border, radius, and elevation.`
  done when: components consume design tokens from a single source and remove ad-hoc inline visual constants.
- [x] `#3 Normalize typography and control sizing to a consistent enterprise baseline.`
  done when: inputs, buttons, headers, and body text follow one coherent scale and alignment contract across views.
- [x] `#4 Add reduced-motion-safe slide transitions for stage view switching.`
  done when: chat/call transitions animate cleanly by default and disable motion under `prefers-reduced-motion`.
- [x] `#5 Implement login entry with persisted local session identity.`
  done when: a user must sign in with display name before workspace access and session identity survives reload.
- [x] `#6 Add explicit sign-out lifecycle with full connection and call cleanup.`
  done when: sign-out reliably tears down websocket/media state and returns to unauthenticated entry.
- [x] `#7 Enforce authenticated workspace gating in the UI flow.`
  done when: room/chat/call surfaces are not reachable before successful sign-in state.
- [x] `#8 Implement room directory fetch with stable ordering and member counters.`
  done when: room list comes from backend API, displays deterministic ordering, and reflects live member counts.
- [x] `#9 Implement room creation flow with backend roundtrip and optimistic UI refresh.`
  done when: create-room submits to backend, resolves conflicts, and updates the active room list without page reload.
- [x] `#10 Implement room switching with state reset boundaries.`
  done when: switching rooms updates active context and resets room-scoped typing/call state safely.
- [x] `#11 Implement invite-code generation for active room.`
  done when: active room can produce an invite code via API and display it in context panel.
- [x] `#12 Implement invite-code redeem/join flow.`
  done when: valid invite code resolves target room and joins/switches user to that room.
- [x] `#13 Implement copy-invite action with graceful clipboard fallback handling.`
  done when: invite copy works in secure contexts and fails silently/cleanly otherwise.
- [x] `#14 Add room participant roster backed by live room snapshots.`
  done when: participant list is sourced from server snapshots and updates in near-real-time.
- [x] `#15 Implement multi-user chat fanout contract end-to-end.`
  done when: chat messages from one user are delivered to all peers in room, not echoed locally only.
- [x] `#16 Add typing indicator start/stop signaling with debounce discipline.`
  done when: typing state is room-scoped, excludes self-display, and auto-clears after bounded idle window.
- [x] `#17 Add bounded chat composer constraints (length and empty rejection).`
  done when: composer enforces max length and rejects empty/whitespace payloads before transport.
- [x] `#18 Add deterministic chat timestamp rendering with stable locale-safe formatting.`
  done when: messages render consistent timestamp formatting across clients.
- [x] `#19 Implement pre-call local media preview as first-class join gate.`
  done when: users can preview camera feed before joining call and permission failures are handled explicitly.
- [x] `#20 Implement call join/leave signaling lifecycle at room scope.`
  done when: joining/leaving call updates local and remote participant call presence reliably.
- [x] `#21 Introduce peer-connection manager keyed by remote user id.`
  done when: each remote participant has an isolated RTCPeerConnection lifecycle with clean map ownership.
- [x] `#22 Implement targeted offer/answer signaling path per peer.`
  done when: offers and answers are routed to intended peer ids and support multi-peer room negotiation.
- [x] `#23 Implement targeted ICE candidate forwarding per peer.`
  done when: ICE candidates route to correct remote peer and are applied safely on receiving side.
- [x] `#24 Bind remote tracks to dynamic call tiles with safe attach/detach.`
  done when: remote streams appear/disappear with participant lifecycle and no stale tile remnants.
- [x] `#25 Implement mic toggle via track state without renegotiation churn.`
  done when: microphone enable/disable flips local track state and propagates expected call behavior.
- [x] `#26 Implement camera toggle via track state without call teardown.`
  done when: camera enable/disable flips local video track state while preserving active peer connections.
- [x] `#27 Implement full call teardown on room-switch/sign-out/unmount boundaries.`
  done when: all peer connections/media tracks close deterministically on boundary transitions.
- [x] `#28 Add websocket reconnect with bounded backoff and room resync.`
  done when: connection loss triggers bounded reconnect attempts and restores room/session state on recovery.
- [x] `#29 Add local demo backend contract for room/invite/presence/chat/call signaling.`
  done when: `dev-backend.mjs` exposes health/API/ws flows that satisfy current frontend contracts.
- [x] `#30 Add verification and docs closure for the new video-call stack.`
  done when: build passes, smoke checks are documented, and README startup/runtime boundaries are updated honestly.

### V1. Video Call Replatform (King PHP + Vue, 30er Batch)

Contract guardrails for this batch:
- legacy runtime is frozen as historical reference; no new feature work lands there
- mock feature parity has highest priority and is the acceptance baseline
- no Node-only signaling backend on the new path; realtime flows must run through King PHP
- no contract shrinking to get green CI faster; implement missing backend/runtime work instead

- [x] `#1 Create runtime layout for the new stack under demo/video-chat (frontend-vue + backend-king-php) and mark legacy as read-only reference.`
  done when: both new directories exist with runnable entrypoints and startup docs state the new stack as the active development path.
- [x] `#2 Remove active development routing to legacy stack and make the new stack the single documented dev target.`
  done when: local/dev docs and run commands point to the new stack only, while legacy is explicitly labeled historical reference.
- [x] `#3 Scaffold Vue 3 + Vite frontend shell with route map for login, admin overview, admin user management, admin calls CRUD, user dashboard, and call workspace.`
  done when: routes resolve, shared app shell renders, and route guards are wired for authenticated/role-aware navigation.
- [x] `#4 Scaffold King PHP backend bootstrap for HTTP + WebSocket video-chat services with one reproducible local start command.`
  done when: backend process starts with King extension loaded, exposes bound addresses in logs, and shutdown is clean.
- [x] `#5 Add docker compose for the new stack (frontend-vue + backend-king-php + sqlite volume) without removing existing demo compose paths.`
  done when: `docker compose up` starts both services for the new stack, data persists in a mounted sqlite volume, and docs list ports.
- [x] `#6 Implement backend health/version endpoint for the new King PHP video-chat backend.`
  done when: endpoint returns runtime health plus app/version metadata and frontend preflight can consume it.
- [x] `#7 Implement sqlite migration/bootstrap layer for users, roles, sessions, rooms, calls, invite codes, and participant membership tables.`
  done when: clean database init and migration upgrade are deterministic and idempotent across restarts.
- [x] `#8 Implement login endpoint with deterministic demo credential mapping and hashed password verification boundary.`
  done when: login validates credentials against persisted users, returns session envelope, and rejects invalid attempts with stable error schema.
- [x] `#9 Implement session issuance/validation middleware for REST and WebSocket handshake paths.`
  done when: authenticated endpoints require valid session state, websocket connect rejects invalid/expired sessions, and tests cover both transports.
- [x] `#10 Implement logout/session-revoke endpoint with immediate effect across active websocket connections.`
  done when: logout invalidates session server-side and forces connected realtime channels for that session to close.
- [x] `#11 Implement backend RBAC policy middleware (admin, moderator, user) across all new video-chat API surfaces.`
  done when: forbidden actions fail closed with typed errors and role-allowed paths are covered by tests.
- [x] `#12 Implement admin user list endpoint with search, deterministic sort, and pagination contract matching UI needs.`
  done when: endpoint supports query + page + page_size, returns stable totals, and frontend pagination binds correctly.
- [x] `#13 Implement admin user create/update/deactivate endpoints with server-side validation and uniqueness guarantees.`
  done when: CRUD operations are persisted safely, invalid payloads fail with typed validation errors, and duplicate email conflicts are explicit.
- [x] `#14 Implement profile/settings endpoint for display name, avatar reference, time format, and theme preferences.`
  done when: settings persist per user and frontend reload restores exact saved preferences.
- [x] `#15 Implement avatar upload endpoint for new stack with type/size validation and safe storage path handling.`
  done when: valid image uploads persist and invalid/unsafe uploads are rejected fail-closed.
- [x] `#16 Implement calls list endpoint with filters for my-calls/all-calls, search by title, status filtering, and pagination.`
  done when: response matches table requirements with stable totals and deterministic ordering.
- [x] `#17 Implement create-call endpoint supporting title, start/end times, internal participant ids, and external invitee rows.`
  done when: call creation persists all participant mappings and returns a single normalized call payload.
- [x] `#18 Implement edit-call endpoint that updates schedule/participants without triggering global invite resend by default.`
  done when: edit updates persisted call fields only and invite-send remains an explicit separate action.
- [x] `#19 Implement cancel-call endpoint with persisted cancellation reason/message payload boundary for downstream notification workflows.`
  done when: cancellation state transitions are explicit, cancellation payload is stored, and cancelled calls are excluded from active joins.
- [x] `#20 Implement invite-code generation endpoint with UUID-backed codes and deterministic expiry policy.`
  done when: generated codes are unique, bound to call/room context, and expiration is enforced server-side.
- [x] `#21 Implement invite-code redeem endpoint that resolves destination room/call and returns role-safe join context.`
  done when: valid code redeems once-per-policy, invalid/expired codes fail cleanly, and join context is typed.
- [x] `#22 Implement websocket gateway channel for authenticated room presence snapshots and join/leave events.`
  done when: room membership snapshots stream to clients and reconnect path resynchronizes active room state.
- [x] `#23 Implement websocket chat channel with room-scoped fanout, server timestamps, and bounded message validation.`
  done when: chat messages are broadcast to room peers, payload constraints are enforced server-side, and timestamp format is stable.
- [x] `#24 Implement websocket typing indicator channel with debounce/expiry semantics and no self-echo.`
  done when: typing start/stop events are room-scoped, expire automatically, and never render to the sender.
- [x] `#25 Implement websocket signaling channel for call/offer, call/answer, call/ice, and call/hangup routed by target user id and room membership.`
  done when: signaling is delivered only to authorized peers in room context and invalid targets are rejected.
- [x] `#26 Implement lobby queue state and moderator actions (allow/remove/allow-all) on the backend with role checks.`
  done when: queued users and moderator actions update state atomically and frontend receives corresponding realtime snapshots.
- [x] `#27 Bind Vue auth/session store to backend login/logout/session-check and enforce route-level RBAC guards.`
  done when: unauthorized routes are blocked, role mismatch redirects are deterministic, and session recovery works on refresh.
- [x] `#28 Bind Vue admin calls/calendar views to new backend contracts including create/schedule/edit/cancel and invite popover flows.`
  done when: admin CRUD UI uses backend data only, pagination/search are fully live, and modal flows match mock behavior.
- [x] `#29 Bind Vue call workspace (users/lobby/chat tabs, lists, pagination, control bar, invite/join) to new King websocket/REST contracts.`
  done when: workspace state is server-driven, sidebar/tab interactions remain responsive, and key mock interactions are preserved.
- [x] `#30 Add end-to-end parity + smoke verification for new stack and document honest runtime boundaries (active new path vs historical reference).`
  done when: automated checks cover login, room join, chat, invite redeem, call signaling bootstrap, and docs clearly state scope/limitations.

### V1. Mock-Parity Extension (Imported from Video Planning, Backend First)

Execution guardrails for this extension:
- backend/runtime contracts land before frontend binding work
- no fallback to legacy/node paths for “temporary green” behavior
- every leaf closes with strongest relevant test proof for King runtime contracts
- clean code boundaries are mandatory (no mixed-responsibility modules)

#### Track B1. Backend Core Completion (King-PHP Priority)

- [x] `#31 Refactor backend into explicit modules (auth/session/rbac/users/calls/invites/realtime) and remove mixed-responsibility handler structure.`
  done when: backend entrypoint wires focused modules only, each module has one responsibility, and route/realtime registration is deterministic.
- [x] `#32 Add canonical API/WS contract catalog for new stack and keep server/client schema alignment under versioned fixtures.`
  done when: one source-of-truth contract file exists, runtime payloads validate against it, and drift checks fail fast in CI.
- [x] `#33 Implement session refresh/rotation endpoint with replay-safe token replacement semantics.`
  done when: refresh rotates token state safely, invalidates replaced token policy as designed, and response contract stays stable.
- [x] `#34 Implement `/api/auth/logout` session revoke endpoint and persist revocation metadata.`
  done when: logout revokes current token deterministically and returns stable success/error schema.
- [x] `#35 Enforce immediate websocket session revocation behavior for revoked/expired sessions.`
  done when: revoked/expired token sessions are disconnected quickly and cannot continue room/chat/call actions.
- [x] `#36 Implement backend RBAC middleware with explicit permission matrix for admin/moderator/user actions.`
  done when: restricted actions fail closed with typed forbidden responses and role-allowed actions remain functional.
- [ ] `#37 Implement admin user list contract with deterministic search, sorting, pagination, and total counters.`
  done when: list endpoint supports query/page/page_size/order consistently and frontend receives stable totals.
- [ ] `#38 Implement admin user create endpoint with validation and duplicate-email conflict handling.`
  done when: valid users are persisted with role defaults, invalid payloads fail typed validation, and duplicate emails return explicit conflict.
- [ ] `#39 Implement admin user update endpoint for role/status/profile fields with RBAC enforcement.`
  done when: updates persist safely, forbidden field mutations fail closed, and audit-relevant fields are normalized.
- [ ] `#40 Implement admin user deactivate/reactivate endpoint with session invalidation policy for disabled accounts.`
  done when: deactivated users lose active session validity and cannot access protected REST/WS flows.
- [ ] `#41 Implement profile/settings endpoint for current user (display name, avatar ref, theme, time format).`
  done when: profile changes persist and session-check payload reflects updated preferences.
- [ ] `#42 Implement avatar upload endpoint with type/size validation and safe storage semantics.`
  done when: valid images persist, unsafe payloads are rejected fail-closed, and stored path references are deterministic.
- [ ] `#43 Implement calls list endpoint with owner-bound filter rules and deterministic paging contract.`
  done when: admin/user list semantics are role-safe, totals remain stable, and ordering is deterministic.
- [ ] `#44 Implement create-call endpoint with internal participant ids and external invitee rows under one transaction boundary.`
  done when: call and participant rows persist atomically and response returns one normalized call payload.
- [ ] `#45 Implement edit-call endpoint with participant diff updates and no implicit global invite resend.`
  done when: edits only mutate targeted schedule/participant state and resend remains explicit separate action.
- [ ] `#46 Implement cancel-call endpoint with persisted cancellation reason/message payload for notification workflows.`
  done when: cancelled call state is explicit, cancellation payload is queryable, and cancelled calls are excluded from active joins.
- [ ] `#47 Implement invite-code generation endpoint with UUID-based codes, expiry, and redemption policy constraints.`
  done when: generated codes are unique, policy-bound, and not exposed outside explicit invite responses.
- [ ] `#48 Implement invite redeem/join endpoint with expiry, usage-limit, and context resolution enforcement.`
  done when: redeem flow resolves target call/room safely and invalid/expired/reused codes fail with typed errors.

#### Track B2. Realtime Completion (King WebSocket + IIBIN)

- [ ] `#49 Implement authenticated websocket gateway with strict handshake validation and structured close reasons.`
  done when: invalid/expired/missing auth is rejected deterministically and valid sessions enter authenticated channel lifecycle.
- [ ] `#50 Implement room presence snapshot and join/leave stream from server-authoritative membership state.`
  done when: clients receive consistent snapshots plus deltas and reconnect restores current room presence without phantom users.
- [ ] `#51 Implement room-scoped chat fanout with server timestamps, payload bounds, and delivery ack ids.`
  done when: chat broadcast is authoritative, malformed payloads are rejected, and message ids are stable for frontend dedupe.
- [ ] `#52 Implement typing indicator channel with debounce windows, expiry semantics, and no self-echo.`
  done when: typing state auto-expires and remains scoped to active room participants only.
- [ ] `#53 Implement signaling routing (`offer`/`answer`/`ice`/`hangup`) with membership + target authorization checks.`
  done when: signaling reaches only valid room peers and invalid targets fail closed without leaking peer state.
- [ ] `#54 Implement lobby queue runtime state with moderator actions (`allow`, `remove`, `allow_all`) and role checks.`
  done when: queue mutations are atomic, unauthorized actions fail closed, and clients receive consistent queue snapshots.
- [ ] `#55 Implement reaction event stream with server-side throttling and deterministic payload boundaries.`
  done when: reaction bursts are bounded and stream remains responsive under controlled load.
- [ ] `#56 Implement reconnect state machine across REST + WS contracts (`online`, `retrying`, `blocked`, `expired`).`
  done when: reconnect outcomes are explicit, session expiry is surfaced, and clients recover state without silent divergence.

#### Track B3. Frontend Mock-Parity Binding (Vue)

- [ ] `#57 Bind auth/session store to backend login/logout/refresh/session-check contracts and remove local identity fallback paths.`
  done when: session source is backend-authoritative and route guards rely on validated session payloads only.
- [ ] `#58 Enforce route-level RBAC guards and role-aware navigation rendering for admin/user/moderator surfaces.`
  done when: forbidden routes redirect deterministically and hidden actions are not reachable via direct navigation.
- [ ] `#59 Bind admin overview metrics/widgets to backend endpoints and remove hardcoded demo counters.`
  done when: displayed values come from live API contracts and loading/error states are explicit.
- [ ] `#60 Bind admin user CRUD table to backend list/create/update/deactivate contracts with live pagination/search.`
  done when: all user-table actions are server-driven and row state reflects authoritative backend responses.
- [ ] `#61 Bind admin calls CRUD list and action bar (`new`, `schedule`, `edit`, `cancel`) to backend contracts with modal workflows.`
  done when: list/actions are fully live and modal flows map 1:1 to contract operations.
- [ ] `#62 Integrate calendar scheduling flow in admin and user views with backend create/edit semantics.`
  done when: calendar interactions persist calls and reflect updates consistently across list and calendar views.
- [ ] `#63 Bind invite popover/copy/join flows to backend invite endpoints and remove table-level invite code exposure.`
  done when: invite details are shown only in explicit popover/modal flows and copy/join behavior is contract-driven.
- [ ] `#64 Bind call workspace sidebars/tabs (`users`, `lobby`, `chat`) to realtime snapshots and server-driven pagination/search.`
  done when: tab content reflects live backend state and no tab relies on local fake data.
- [ ] `#65 Bind workspace moderation actions (pin/mod/remove/allow) to RBAC-authorized backend operations with row-level feedback.`
  done when: action availability matches role policy and success/error states are explicit per row.
- [ ] `#66 Bind control bar actions (raise hand, reactions, mic/cam/screen, hangup) to backend/realtime contracts with deterministic state transitions.`
  done when: control state no longer drifts from backend/realtime state and recovery after reconnect is consistent.
- [ ] `#67 Implement settings modal parity flows for avatar crop/upload, theme persistence, and time-format application.`
  done when: settings persist backend-side and apply globally across tables/calendar/modals/workspace timestamps.
- [ ] `#68 Implement admin branding settings parity flow (logo crop/upload) with admin-only policy boundaries.`
  done when: branding writes are restricted to admin and visual updates are applied predictably across relevant UI surfaces.

#### Track B4. Test, Hardening, Release Proof

- [ ] `#69 Add backend integration tests for auth/session/rbac/calls/invites/realtime negative+positive matrices on King runtime paths.`
  done when: test suite covers success and fail-closed boundaries across REST and websocket channels.
- [ ] `#70 Add end-to-end mock-parity suite (admin flow + user flow + two-user call/chat/invite/reconnect journey).`
  done when: automated journey tests pass reproducibly and map directly to mock acceptance matrix.
- [ ] `#71 Add compose-level smoke gates for new stack (`frontend-vue` + `backend-king-php` + sqlite) including migration and auth sanity checks.`
  done when: CI smoke proves stack boots, migrates, authenticates, and serves runtime/session endpoints deterministically.
- [ ] `#72 Document final new-stack runtime boundaries and deprecate remaining legacy assumptions in video-chat docs.`
  done when: docs state active path honestly, list remaining gaps explicitly, and remove contradictory legacy-first wording.

## Notes

- Closed batches (`Q`, `R`, `S`, `T1`, `T2`) stay tracked in `PROJECT_ASSESSMENT.md`.
- This file now contains only the active executable queue for the next batch.
- If a task is not listed here, it is not the current repo-local execution item.
