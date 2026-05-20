# Epic: KingRT Video Call Live Proof

Status:
- Active from 2026-05-11 on local branch `kingrt/prod-ready`.
- Do not push. Deploy only after focused verification and explicit user approval.
- The active release goal is one server/head-authored media plan that proves
  visible call video in two browsers for 30 continuous minutes.
- Alex build-mesh is the implementation reference lane; port it station by
  station with source anchors and evidence.

## Product Target

- [ ] A participant joins through auth and lobby admission.
- [ ] Every admitted client publishes the capabilities needed for
  `media_session_plan.v1`.
- [ ] The server/head owns the expected participant set, connect cycle and one
  authoritative call media plan.
- [ ] The plan ladder is explicit: Gossip `720p30`, Gossip `360p30`, Gossip
  `360p5`, orchestrator-selected SFU `720p30`, then orchestrator-selected SFU
  `320p30`.
- [ ] Clients apply only the selected plan, including codec path, resolution,
  FPS, keyframe cadence and transport.
- [ ] Gossip egress and receiver render evidence prove live media, not just
  created peer/canvas objects.
- [ ] The UI shows one clear connect status or actionable error; transport acks,
  reconnect countdowns and retry notices stay out of visible banners.
- [ ] Active call pages do not contain the previous 2-minute reload behavior.

## Out Of Scope

- [ ] Keep ad-hoc SFU fallback out of the active path; SFU is allowed only when
  selected by the server/head orchestrator after Gossip render failure.
- [ ] Keep client health gates out of media-success decisions.
- [ ] Keep MediaSecurity sender-key gates and recovery out of the active send
  path.
- [ ] Keep Background Replacement, background-tab media policy and BTGF work out
  of this sprint.
- [ ] Keep automatic quality experiments, profile rescue, regression harnesses
  and hidden repair loops out of the active path.
- [ ] Keep strict 720p-only acceptance gates out of this sprint; prove the full
  selected ladder instead.
- [ ] Keep STT feature expansion out of this sprint.
- [ ] Keep DNS, certbot, remote branch pushes and GitHub publication out of this
  sprint.

## Sprint Loop

- [ ] `SPRINT.md` contains exactly one active checkbox list.
- [ ] `BACKLOG.md` holds parked or future work only.
- [ ] Each sprint keeps server-authored media state stronger than local-only
  browser guesses.
- [ ] Deploy gates require focused verification, grouped failures and explicit
  user approval.
- [ ] Completion evidence goes to commits, contracts, diagnostics and concise
  readiness notes.

## Active Lanes

- [ ] Alex build-mesh station map, source anchors and station-by-station proof.
- [ ] Backend media plan, connect-cycle authority and authoritative room
  snapshot.
- [ ] Gossip topology, egress accounting and receiver render proof.
- [ ] Publisher codec/profile/keyframe cadence and first-frame budget proof.
- [ ] Frontend call-video status with no transport-ack banners.
- [ ] Focused local contracts, post-deploy diagnostics and 30-minute browser
  live proof.

## Readiness Definition

- [ ] The build-mesh port has a source anchor and local proof for every station.
- [ ] Two normal call participants see deterministic video from one browser in
  the other for 30 continuous minutes.
- [ ] A late participant receives the next usable keyframe/delta flow.
- [ ] Frame send counters and receive counters prove live media, not just setup.
- [ ] Focus changes and UI clicks do not reconnect or reload the call.
- [ ] SFU, MediaSecurity gates, background policy, client health gates,
  auto-quality experiments and reload loops are not active stream-control
  dependencies.
- [ ] Diagnostics show capability input, selected plan, Gossip readiness, egress
  result, receiver frame counts and explicit stuck reasons.
