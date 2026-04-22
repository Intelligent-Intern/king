# King Active Issues

Purpose:
- This file contains only the active sprint extraction from `BACKLOG.md`.
- The complete open backlog is in `BACKLOG.md`.
- Completion notes go to `READYNESS_TRACKER.md`.

Active GitHub issue:
- #146 Batch 1: Video-Call Demo Live Readiness (`1.0.7-beta`)

Rules:
- Keep active work small enough for clean commits and bisectable reviews.
- Do not mix ownership lanes unless the backlog item explicitly requires coordination.
- Do not weaken King v1 contracts to close a task faster.
- Preserve contributor credit when porting experiment-branch work.

## Batch 1: Video-Call Demo Live Readiness (`1.0.7-beta`)

### #Q-16 Video-Chat Media Fanout And Participant Rendering

Goal:
- Every participant in one call room sees and hears the other admitted participants, with stable roster and layout state.

Checklist:
- [x] Verify the joined user is admitted into the owner's existing call room instead of creating or resolving a separate room/session.
- [x] Fix SFU publish/subscribe fanout so remote audio and video tracks are delivered across browser sessions.
- [x] Ensure remote participants render in mini-video slots unless pinned/promoted to the main stage.
- [x] Ensure participant roster entries are derived from stable server-authoritative presence and do not jitter on polling/reconnect ticks.
- [x] Keep admin rights equivalent to call-owner rights inside the call.
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
- [x] Hide the lobby tab from plain invited users unless they are explicitly promoted to moderator or are the call owner/admin.
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
