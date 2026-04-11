# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It carries the currently active executable batch, including leaves already
> closed inside that batch.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE` before starting, replenishing, or reshaping any `20`-issue batch
- keep the active batch visible here until it is exhausted; mark closed leaves as `[x]` instead of deleting them mid-batch
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, touched comments/docblocks, and tests in the same change; handbook docs and `READYNESS_TRACKER.md` may be deferred only when the current batch explicitly says so by user request
- when a leaf closes, also verify the affected runtime with the strongest relevant tests/harnesses available before committing
- when a leaf closes, make exactly one commit for that checkbox; do not batch multiple checkbox closures into one commit
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch or enables continuous batch execution
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

- Batch `V0` is active on `develop/v1.0.6-beta` (release-blocker security fixes first).
- Start with `V0 #1`, close exactly one checkbox per `w`, and keep one commit per closed checkbox.

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

After V0 closes, resume `U2` from `#1`.

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
- [ ] `#23 Implement targeted ICE candidate forwarding per peer.`
  done when: ICE candidates route to correct remote peer and are applied safely on receiving side.
- [ ] `#24 Bind remote tracks to dynamic call tiles with safe attach/detach.`
  done when: remote streams appear/disappear with participant lifecycle and no stale tile remnants.
- [ ] `#25 Implement mic toggle via track state without renegotiation churn.`
  done when: microphone enable/disable flips local track state and propagates expected call behavior.
- [ ] `#26 Implement camera toggle via track state without call teardown.`
  done when: camera enable/disable flips local video track state while preserving active peer connections.
- [ ] `#27 Implement full call teardown on room-switch/sign-out/unmount boundaries.`
  done when: all peer connections/media tracks close deterministically on boundary transitions.
- [ ] `#28 Add websocket reconnect with bounded backoff and room resync.`
  done when: connection loss triggers bounded reconnect attempts and restores room/session state on recovery.
- [ ] `#29 Add local demo backend contract for room/invite/presence/chat/call signaling.`
  done when: `dev-backend.mjs` exposes health/API/ws flows that satisfy current frontend contracts.
- [ ] `#30 Add verification and docs closure for the new video-call stack.`
  done when: build passes, smoke checks are documented, and README startup/runtime boundaries are updated honestly.

## Notes

- Closed batches (`Q`, `R`, `S`, `T1`, `T2`) stay tracked in `PROJECT_ASSESSMENT.md`.
- This file now contains only the active executable queue for the next batch.
- If a task is not listed here, it is not the current repo-local execution item.
