# King Backlog

Purpose:
- `BACKLOG.md` is parked and future work only.
- `SPRINT.md` is the only active top-priority checklist.
- Historical detail stays in git history, archived docs, contracts and focused
  readiness notes.
- If an item becomes release-critical, move it into `SPRINT.md` and remove it
  from this file.

Rules:
- Do not duplicate active sprint items here.
- Do not keep completed sprint transcripts here.
- Do not weaken the strongest correct King v1 contract to simplify cleanup.
- Keep root docs minimal and checkbox-based.

## Parked Legacy Mechanisms

- [ ] STT subsystem expansion is parked. Reopen only concrete STT defects; the
  completed STT04 implementation detail stays in git history and contracts.
- [ ] Ad-hoc SFU fallback, SFU-specific deploy blockers and SFU repair loops
  are parked. SFU may only be used when selected by the active sprint's
  server/head orchestrator.
- [ ] MediaSecurity sender-key gates, participant-set recovery and security
  rewrites are parked outside the active call-video proof.
- [ ] Strict 720p-only acceptance gates are parked. The active sprint starts
  with 720p30 but proves the full orchestrated quality ladder instead of using
  720p as a release blocker.
- [ ] Client health gates are parked. Media success must come from
  server-authored plan, egress and receiver render evidence.
- [ ] Automatic quality rescue, regression harnesses and hidden repair loops are
  parked unless rebuilt as explicit server-plan behavior.
- [ ] Background-tab media policy is parked as a hidden call-video behavior.
- [ ] Retry-countdown banners, reconnect chatter and green transport-ack notices
  remain out of the visible call UI; diagnostics may keep redacted evidence.

## Future Video Call Work

- [ ] Add late-join and screenshare acceptance after the active two-participant
  video proof is stable.
- [ ] Extend `media_session_plan.v1` with durable participant media-state history
  if diagnostics need cross-session audit.
- [ ] Split domain/admin/ops deploy gates from media-transport-specific gates so
  parked transports do not block unrelated release checks.
- [ ] Convert stale SFU/Gossip/Background/regression tests into capability,
  plan-selection and render-evidence contracts after the active sprint proves
  the new orchestrator.
- [ ] Revisit topology observability and binary media envelope compaction after
  the current release path is stable.
- [ ] Add multi-participant scaling proof for 10 participants after the
  two-browser live proof and right-sidebar layout are stable.

## Call App Future Work

- [ ] Keep Call App package roots canonical at `demo/call-apps/<app-key>/`.
- [ ] Keep `demo/video-chat/frontend-vue/src/domain/realtime/callApps` as
  host/bridge/shell code, not app source.
- [ ] Treat `demo/video-chat/frontend-vue/dist/call-app` as build output only.
- [ ] Plan any `text-document` to `word` rename as a real migration with aliases,
  redirects or data migration.
- [ ] Reconcile Call App entitlement revocation and launch-token reconnect proof
  with the current package boundaries.
- [ ] Preserve Whiteboard, Planning Image, Presentation and Spreadsheet follow-up
  defects as concrete future tickets, not old sprint transcripts.

## Future IAM And Product Work

- [ ] Reconcile any IAM work not selected in the active sprint as focused future
  batches with one problem statement and evidence target each.
- [ ] Restore any remaining call-access aggregate gate cleanup only when it has a
  current problem statement and evidence.
- [ ] Move Calendar tabs out of Video Call Management into the top-level Calendar
  route.
- [ ] Finish mobile booking flow cleanup with day strip, slot list and
  confirmation step.
- [ ] Continue Settings/Profile, Theme Editor, Localization/Admin and
  Calendar/Booking extraction under file-size guards.
- [ ] Keep AI/SLM/Fine-Tuning and MarketView as future product work outside the
  active call-video sprint.

## Cleanup Notes

- [ ] Do not restore archived root markdown trackers as active planning sources.
- [ ] Do not duplicate `SPRINT.md` checklists in this file.
- [ ] Reintroduce removed historical items only with a current problem statement,
  owner and evidence.
