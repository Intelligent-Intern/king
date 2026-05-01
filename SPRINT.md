# King Active Issues

Purpose:
- This file contains the active sprint issues for the current branch only.
- Detailed history, parked ideas, and overflow items belong in `BACKLOG.md`.
- Completion evidence belongs in `READYNESS_TRACKER.md`.

Sprint rule:
- This sprint must close the architecture gap between the current protected SFU
  app-frame relay and a smooth video-call media path.
- Do not weaken media-security, room/admission, binary envelope, diagnostics, or
  automatic quality contracts to get temporary throughput.
- Do not grow `CallWorkspaceView.vue`; new call-runtime behavior belongs in
  focused helpers/modules.
- Video quality stays automatic. No user-facing quality selector.
- Debugging must be backend-routed and structured enough to identify the first
  over-budget stage without browser-console screenshots.

## Sprint: Video Call Real Media Path Architecture

Sprint branch:
- `sprint/video-call-real-media-path-architecture`

PR target:
- `development/1.0.7-beta`

Production symptom:
- Video quality and smoothness regress under protected SFU load: repeated hard
  reconnects, blocky frames, and slow frame turnover even after local profile
  and thumbnail fixes.
- The current hot path still behaves like application message relay:
  browser-encoded frames enter WebSocket/TCP, pass through King PHP relay,
  bounded SQLite/live relay replay, browser-side decode, and canvas render.

Technical target:
- Make the sprint explicit: the correct target is a real media plane shape,
  not another round of queue-threshold tuning.
- Until the dedicated media plane lands, make every remaining WebSocket/SFU
  fallback buffer bounded, age-biased, observable, and incapable of runaway
  memory/disk growth.
- Add a deployment-quality diagnostics surface that tells us whether pressure
  starts at capture, encode, browser send, King receive, broker/fanout, receiver
  decode, or render.

## Active Issues

1. [x] `[sfu-bounded-age-biased-frame-buffer]` Make the SFU broker buffer bounded by age, rows, and bytes.

   Scope:
   - Extract frame-buffer ownership out of the oversized SFU store into a
     focused helper.
   - Keep the short-lived SQLite broker buffer, but enforce row and room-byte
     bounds on every insert.
   - Evict age-biased: stale frames first, then oldest frames before newer
     frames, with a small freshness grace so the newest live frame is protected
     whenever possible.
   - Emit backend diagnostics with evicted rows, bytes, before/after pressure,
     oldest age, and max bounds.

   Done when:
   - `sfu_frames` cannot grow beyond the per-room row or byte budget between
     cleanup intervals.
   - Eviction is deterministic and age-biased, not random and not only
     opportunistic cleanup.
   - Contracts prove the helper, byte cap, eviction policy, and diagnostics.

   Report:
   - Implemented in this WIP branch.

2. [x] `[real-media-plane-contract]` Define the target media plane that replaces WebSocket whole-frame transport.

   Scope:
   - Add a contract/doc for the production media path:
     `MediaStreamTrack -> encoder -> packet/datagram media transport -> SFU
     packet/layer forwarder -> jitter buffer/keyframe/layer recovery -> native
     renderer`.
   - Keep app-level protected media metadata and room/admission controls.
   - Make WebSocket an SFU control/signaling path, not the long-term video data
     plane.
   - Decide the implementation route: King RTP/SRTP/RTCP, WebTransport/QUIC
     datagrams, or a King-native media datagram primitive, with fallback rules.

   Done when:
   - Contracts fail if the active sprint tries to bless WebSocket/TCP
     `bufferedAmount` as the final video congestion-control layer.
   - The doc names required media-plane features: packet pacing, jitter buffer,
     keyframe request, NACK/PLI or equivalent, layer routing, receiver feedback,
     and per-subscriber quality choice.

   Report:
   - Added `documentation/dev/video-chat/real-media-plane-architecture.md` as
     the target media-plane contract.
   - Pinned WebSocket/TCP whole-frame relay as fallback/control-compatible only,
     not the final video data plane.
   - Added contract coverage so the sprint must keep packet/datagram pacing,
     jitter buffering, keyframe/NACK/PLI recovery, per-subscriber layer routing,
     backend diagnostics, and SQLite/live-relay fallback boundaries explicit.

3. [x] `[sfu-control-data-plane-split]` Split SFU control messages from media payload transport.

   Scope:
   - Keep `/sfu` WebSocket for auth, join, publish, subscribe, layer preference,
     diagnostics, and recovery controls.
   - Introduce an explicit media payload interface behind the client and backend
     so the data plane can move off WebSocket without touching UI/runtime code.
   - Preserve current binary envelope compatibility while making it a fallback
     transport, not the architecture target.

   Done when:
   - Client code has a media transport abstraction with WebSocket fallback and a
     real-media-plane implementation seam.
   - Backend route code separates control handling from payload fanout.
   - Diagnostics identify `control_transport` and `media_transport` separately.

   Report:
   - Added a frontend `SfuWebSocketFallbackMediaTransport` abstraction so binary
     frame send no longer calls the socket directly from the SFU client hot path.
   - Added explicit `websocket_sfu_control` and
     `websocket_binary_media_fallback` identifiers in client and backend
     diagnostics.
   - Backend welcome/frame metadata now marks WebSocket binary media as
     `fallback_until_real_media_plane`, preserving room/admission control while
     separating it from the future media data plane.

4. [x] `[packet-layer-sfu-forwarder]` Replace whole-frame fanout with packet/layer forwarding semantics.

   Scope:
   - Model primary/thumbnail/fullscreen layers as independently routable media
     streams.
   - Forward per-subscriber layers without forcing publisher global downshift.
   - Add keyframe request and layer-switch control messages that do not hard
     restart the SFU socket.
   - Keep slow-subscriber isolation and room/admission security.

   Done when:
   - A frozen receiver requests keyframe/layer recovery before any hard
     reconnect.
   - Fullscreen subscriber quality is isolated from mini/grid subscribers.
   - Backend diagnostics show per-subscriber media layer and recovery actions.

   Report:
   - Added `sfu/media-recovery-request` on the SFU control plane so a receiver
     can request publisher-side keyframe/layer recovery without restarting the
     media socket.
   - King routes recovery control directly when publisher and receiver are in
     the same worker, and falls back to a bounded SQLite broker table for
     cross-worker publisher delivery.
   - Publisher clients consume `sfu/publisher-recovery-request` and route
     `force_full_keyframe` into the existing WLVC full-frame keyframe path, so
     normal freezes now have a targeted recovery path before reconnect.

5. [x] `[native-render-and-jitter-buffer]` Stop treating canvas repaint as the primary receiver media runtime.

   Scope:
   - Move receiver recovery toward jitter-buffered frame ordering and native
     playback/render where available.
   - Keep canvas only for effects/compositing or fallback rendering.
   - Make render diagnostics report decode delay, dropped stale frames, frame
     ordering gaps, and final displayed frame cadence.

   Done when:
   - Reconnect is no longer the primary response to normal media jitter.
   - Receiver can smooth short gaps without resetting publisher/subscriber state.
   - Online probes verify moving video cadence, not just non-black pixels.

   Report:
   - Added a bounded receiver jitter buffer for small sequence gaps: up to 8
     frames, 90 ms hold window, and a maximum reorder gap of 3 frames.
   - The decoder now holds slightly future deltas before continuity drop,
     drains them once missing frames arrive, and only releases to normal
     keyframe recovery after the hold window expires.
   - Diagnostics now report `sfu_receiver_jitter_buffer_hold`,
     `sfu_receiver_jitter_buffer_drain`, and
     `sfu_receiver_jitter_buffer_release` so receiver jitter can be separated
     from encoder/network pressure.

6. [x] `[end-to-end-media-pressure-observability]` Add full-path performance logging and gates.

   Scope:
   - Preserve correlation by `frame_sequence`, publisher, track, layer, profile,
     and transport generation.
   - Emit backend-routed samples for capture, encode, queue, send, King receive,
     broker/fanout, subscriber send, receiver decode, and render.
   - Keep console clean: browser warnings become structured diagnostics where
     the app can catch them.

   Done when:
   - Production smoke can report where pressure starts.
   - Critical pressure logs include the first over-budget stage, not only the
     final symptom.
   - The report per issue has enough evidence to compare quality/performance
     before and after deploy.

   Report:
   - Added `sfu_end_to_end_v1` performance payloads to sampled publisher-send
     diagnostics with capture, encode, payload, queue, browser buffer, King
     latency, fanout, and subscriber-send fields.
   - Added `first_over_budget_stage` resolution for source readback, encoded
     payload, outbound queue age, browser send buffer, and subscriber send
     pressure.
   - Receiver render samples now use the same report schema and mark
     `receiver_render` as the first pressure stage when render latency crosses
     the existing receiver lag threshold.

## Execution Order

1. Close `[sfu-bounded-age-biased-frame-buffer]` first because it prevents
   fallback buffer runaway while the media plane is rebuilt.
2. Close `[real-media-plane-contract]` before further tuning so the sprint cannot
   drift into threshold-only fixes.
3. Implement the control/data split and packet/layer forwarder.
4. Replace receiver recovery/render assumptions.
5. Deploy only when the branch is complete enough to prove smooth video cadence
   and clean backend diagnostics.

## Sprint: Free-for-all Invite Lobby and Logout Landing

Sprint branch:
- `sprint/free-for-all-invite-lobby-logout-landing`

PR target:
- `development/1.0.7-beta`

Current finding:
- The backend already has the correct public invite shape for free-for-all
  calls: `POST /api/calls/{call_id}/access-link` creates an `open` access link
  and returns `join_path` as `/join/{access_id}`.
- `/join/:accessId` is already a public frontend route. It resolves
  `/api/call-access/{access_id}/join`, creates a call-access guest session via
  `/api/call-access/{access_id}/session`, then waits in the lobby/admission
  flow instead of showing the normal login screen.
- The practical gap is UI and settings: the admin enter-call controller can
  generate/copy this link, but the in-call owner sidebar did not expose it where
  the owner already manages live call settings.
- Logout always drops the local session and routes to `/login` or the fixed
  `/call-goodbye` page. No configurable post-logout landing URL exists in user
  settings yet.

Technical target:
- Make the free-for-all invite URL visible and copyable where the call owner
  naturally manages a live call: the left call sidebar between Background Blur
  and Call settings.
- Keep invite-only personal links separate from free-for-all open links.
- Ensure guests entering through `/join/{access_id}` never hit the login mask
  before the lobby/admission step.
- Add a validated, configurable post-logout landing page in settings and use it
  after guest/call logout without introducing an open-redirect issue.

## Active Issues: Free-for-all Invite Lobby and Logout Landing

1. [x] `[free-for-all-public-link-surface]` Surface the open invite link in call management.

   Scope:
   - Add a visible invite-link field plus copy action to the in-call left
     sidebar for call owners when `access_mode=free_for_all`.
   - Reuse `POST /api/calls/{call_id}/access-link` with `link_kind=open`.
   - Show the resulting `/join/{access_id}` URL, and keep the copy action
     separate from the normal "Join call" action.
   - Keep the layout stable on narrow/mobile sidebars with a single input plus
     icon button row.

   Done when:
   - A free-for-all call owner/admin can copy a public `/join/{access_id}` link
     without opening DevTools or manually calling the API.
   - Invite-only calls do not expose an open-link copy action.

   Report:
   - Added the owner-only free-for-all invite block in the call left sidebar
     between Background Blur and Call settings.
   - The block generates/reuses the backend open access link, renders a readonly
     URL input, and provides clipboard copy with a textarea fallback.
   - Invite-only calls and non-owners do not render the open invite surface.

2. [x] `[call-access-open-link-contracts]` Harden backend contracts for open links.

   Scope:
   - Prove `free_for_all` creates or reuses exactly one open link per call.
   - Prove `invite_only` rejects `link_kind=open`.
   - Prove `free_for_all` rejects personal link generation.
   - Prove public `GET /api/call-access/{access_id}/join` returns enough context
     for the lobby without authenticated session headers.

   Done when:
   - Backend contracts fail if the public link flow regresses into a login-only
     or personal-invite-only flow.

   Report:
   - Extended the call-access session contract so open guest sessions inherit
     the owner configured logout landing URL while still authenticating as
     `account_type=guest`.
   - Existing call-update contracts continue to pin open-link requirements:
     free-for-all defaults to `open`, rejects personal links, and invite-only
     rejects `open`.
   - Public `/api/call-access/{access_id}/session` remains the no-login guest
     session issuer for `/join/{access_id}`.

3. [x] `[guest-lobby-direct-entry]` Keep `/join/{access_id}` as the canonical no-login lobby entry.

   Scope:
   - Ensure router guards keep `/join/:accessId` public.
   - Ensure open-link guest sessions are issued with `account_type=guest` and
     cannot inherit a stale logged-in account session.
   - Ensure the guest is queued in the room/call lobby and can be admitted or
     removed by owner, moderator, or admin.
   - Ensure admission state survives refresh without bouncing to `/login`.

   Done when:
   - A fresh browser profile can open the copied link, enter a guest name, wait
     in the lobby, and enter the call after approval.

   Report:
   - Preserved the existing public `/join/:accessId` route and public
     call-access session endpoint.
   - Open-link guest sessions still create a guest user, bind the call-access
     session, and enter the waiting-room/admission path before the call room.
   - The copied sidebar URL points at the frontend `/join/{access_id}` route,
     not at the login-only workspace.

4. [x] `[settings-post-logout-landing-url]` Add a configurable logout landing URL setting.

   Scope:
   - Add a persisted setting for the post-logout landing page.
   - Expose it through `GET/PATCH /api/user/settings`.
   - Validate it as a safe same-origin path or explicitly allowlisted absolute
     HTTPS URL; reject protocol-relative URLs and arbitrary external redirects.
   - Define a default fallback that preserves the current behavior when unset.

   Done when:
   - Settings API contracts cover save, fetch, reset/default, and invalid URL
     rejection.

   Report:
   - Added `post_logout_landing_url` to user settings and sessions with a
     dedicated migration.
   - Exposed the field through `GET/PATCH /api/user/settings`, login/session
     snapshots, cached auth snapshots, and logout payloads.
   - Validation only accepts an empty default or a safe same-origin path; full
     external and protocol-relative redirects are rejected.

5. [x] `[settings-ui-logout-landing]` Add the setting to the frontend settings modal.

   Scope:
   - Add a focused settings field for the logout landing page.
   - Show validation errors returned by `/api/user/settings`.
   - Provide a reset-to-default control.
   - Keep existing profile/theme/regional settings behavior unchanged.

   Done when:
   - A user can configure the landing page without editing environment variables
     or backend config.

   Report:
   - Added a Session tab in the settings modal with a landing-page path input
     and reset-to-default action.
   - Frontend validation mirrors the backend same-origin path rule before
     sending the settings PATCH.
   - Mobile settings layout collapses the field/action row to avoid sidebar or
     modal overflow.

6. [x] `[call-logout-redirect-runtime]` Route call logout to the configured landing page.

   Scope:
   - Make `logoutSession` or the caller return/resolve the configured landing
     target before local session state is cleared.
   - Apply the redirect after guest call exit and normal sidebar logout.
   - Keep `/call-goodbye` as a safe default/fallback for guest call exits until
     a custom target is configured.
   - Do not redirect authenticated non-guest users into guest-only routes.

   Done when:
   - Leaving a call as a free-for-all guest lands on the configured page.
   - Normal logout still clears backend and frontend session state fail-closed.

   Report:
   - `logoutSession()` now captures the configured landing target before local
     session state is cleared and also honors the backend logout payload.
   - Sidebar logout and the guest goodbye flow redirect to the configured safe
     path, falling back to `/login` when unset.
   - Free-for-all open guest sessions store the owner landing URL on the session
     so the guest can leave to the owner-selected page without using an external
     redirect.

7. [ ] `[free-for-all-lobby-e2e]` Add end-to-end coverage for the full flow.

   Scope:
   - Cover call creation/update with `access_mode=free_for_all`.
   - Copy the open link from UI.
   - Open `/join/{access_id}` in a fresh unauthenticated context.
   - Queue in lobby, admit from owner/admin UI, enter call, leave call, and land
     on the configured logout page.

   Done when:
   - Playwright or focused contract tests prove the complete user-visible flow.

## Execution Order: Free-for-all Invite Lobby and Logout Landing

1. Close `[call-access-open-link-contracts]` first so the existing backend
   behavior is pinned before UI changes.
2. Implement `[free-for-all-public-link-surface]` and
   `[guest-lobby-direct-entry]` together because the copied URL must be proven
   against the real lobby path.
3. Add the settings persistence/API before frontend settings UI.
4. Wire logout redirect runtime after the setting exists.
5. Finish with full-flow E2E coverage.
