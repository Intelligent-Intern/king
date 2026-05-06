# Videochat Connection Upgrade Sprint

Date: 2026-05-06

Scope:
- Video-call join, SFU publish/relay, signaling, media-security, and gossip mesh
  reliability.
- Keep this separate from `SPRINT.md`, because that sprint explicitly excludes
  video-call runtime work.

Goal:
- Users can join free-for-all and access-link calls without being stuck in
  `connecting`.
- Published video frames are relayed through SFU and, where available, assisted
  by gossip data lanes.
- Security-key setup, signaling, reconnects, and call participant state remain
  consistent across deploys, page reloads, and multi-worker routing.

## Online Findings, 2026-05-06

Call investigated:
- `8414e41a-ee26-4885-b0f1-420746dbee70`
- `access_mode=free_for_all`
- Owner user `1`

Observed production symptoms:
- `67x sfu_frame_sqlite_buffer_insert_failed`
- `67x sfu_frame_live_relay_publish_failed`
- `10x media_security_sender_key_not_ready`
- `5x sfu_publish_waiting_for_media_security`
- `9x signaling_publish_failed`
- Several clients showed `remote_peer_count=0` while they believed they were
  connected or rekeying.

Participant state anomaly:
- User `5` joined at `06:17:18` and was marked left at `06:17:19`.
- User `27` joined and was marked left in the same second.
- Client diagnostics still showed pages trying to publish/rekey after durable
  participant state had already moved to left.

Concrete SFU room-key defect:
- Presence uses tenant-scoped room keys like `tenant:1:room:<uuid>`.
- SFU frame buffer and live relay normalize their input as a plain room id and
  reject colon-containing tenant room keys.
- This explains the `invalid_room_or_publisher` buffer failures while the
  visible call room id and publisher id looked valid.

Gossip mesh status:
- Gossip mesh is the data-channel topology/repair lane, not the primary SFU.
- No direct gossip publish-failure burst was visible in the sampled logs.
- It cannot compensate while participant membership, signaling targets, or
  media-security sender keys are unstable.

## Sprint Items

### 1. Tenant-safe room identity contract

Problem:
- Runtime mixes external room ids and tenant-scoped presence room keys.
- SFU broker, frame buffer, live relay, and signaling do not all accept the same
  room identity shape.

Work:
- Define explicit `external_room_id` and `tenant_room_key` fields in the SFU and
  signaling contracts.
- Store and route tenant-aware state with `tenant_room_key`.
- Store public/API-facing room references with `external_room_id`.
- Remove accidental re-normalization of already-scoped room keys.

Acceptance:
- Tenant-scoped calls no longer emit `invalid_room_or_publisher`.
- SFU broker stores publishers, tracks, and frames for tenant-scoped calls.
- Unit tests cover plain room ids and `tenant:<id>:room:<uuid>` room keys.
- Cross-tenant tests prove no room leakage is introduced.

### 2. Join-state and reconnect reconciliation

Problem:
- Users were marked as left within zero to one second while clients still
  attempted to publish, receive, or rekey.
- This can make free-for-all links look joined locally but unauthorized or empty
  to signaling/SFU paths.

Work:
- Add a reconnect grace window before durable `left_at` is finalized.
- Reconcile WebSocket presence, SFU publisher presence, and
  `call_participants` before admission decisions.
- Track disconnect reason separately from durable call leave.
- Add diagnostics for `join_state_race_detected`.

Acceptance:
- Reloading or reconnecting does not immediately remove an active participant.
- Free-for-all call links can join without permission-denied regressions.
- Backend tests cover page reload, deploy disconnect, and duplicate session
  reconnect.

### 3. Media-security sender-key readiness

Problem:
- Clients reported `media_security_sender_key_not_ready` and publishers waited
  for media security while participants and remote peers were unstable.
- This blocks publish and causes video not to be shared even when sockets are
  open.

Work:
- Make sender-key epochs deterministic per call, publisher, receiver set, and
  membership generation.
- Add explicit ACK/NACK telemetry for key distribution.
- Recompute keys after grace-window membership changes, not after transient
  presence flicker.
- Fail open only to the strongest permitted transport mode for that call, never
  by silently weakening call security.

Acceptance:
- No indefinite `sfu_publish_waiting_for_media_security` state.
- Diagnostics show current epoch, receiver-set hash, missing receivers, and
  recovery action.
- Tests cover join, leave, reconnect, and two-user protected publish.

### 4. Signaling target routing and broker fallback

Problem:
- `signaling_publish_failed` appeared alongside remote peer count zero.
- Tenant-aware presence lookup and broker fallback can disagree with durable
  participant state when `left_at` races or room keys mismatch.

Work:
- Use `tenant_room_key` for in-memory presence rooms.
- Use `external_room_id` for API payloads and database call references.
- Make broker fallback validate against reconciled active membership instead of
  stale immediate-left rows.
- Add structured failure reasons for missing room, missing target, stale
  session, and broker publish failure.

Acceptance:
- Direct same-worker signaling and broker cross-worker signaling both deliver
  topology/key messages.
- Failed signaling logs include actionable reason and target identity.
- Tests cover same worker, cross worker, target reconnect, and target left.

### 5. Gossip mesh observability and fallback behavior

Problem:
- Gossip mesh health is currently hard to distinguish from SFU health.
- It depends on stable membership, signaling, and security, so it silently loses
  usefulness when those upstream layers degrade.

Work:
- Emit topology diagnostics: assigned neighbors, connected neighbors, data-lane
  open state, repair requests, and fallback reason.
- Add a call-level debug endpoint summarizing SFU, signaling, security, and
  gossip state for one call.
- Ensure publisher pipeline explicitly falls back to SFU-only when gossip has no
  safe path.
- Add browser smoke coverage for two participants with gossip enabled and
  disabled.

Acceptance:
- A live call can answer: who is connected, who can receive, who has keys, who
  has gossip neighbors, and where frames are flowing.
- Gossip failures no longer look like generic `connecting` or missing video.
- SFU-only mode remains reliable when gossip topology is empty.

## Verification Plan

- Add focused unit tests for room-key normalization and tenant isolation.
- Add backend integration tests for free-for-all join, reconnect grace, and
  cross-worker signaling.
- Add frontend diagnostics assertions for key readiness and gossip state.
- Add a two-browser Playwright smoke test that verifies local publish, remote
  receive, reconnect, and no indefinite `connecting` state.
- Validate production with one canary call before broader rollout.
