# IAM Sprint 05 Authorized Rejoin Extraction

Date: 2026-05-10

Scope: IAM5-04 extraction review for `local/iam-e2e-authorized-rejoin-main`
and the 20260509 authorized-rejoin branches. Background, Gossip, SFU,
MediaSecurity, and BTGF areas were not touched. Source branches were compared
against current `prod-kingrt-do-not-push-to-github` at `a204a4a2`.

## Source Branches

| Branch | Head | Useful authorized-rejoin value | Decision |
| --- | --- | --- | --- |
| `local/iam-e2e-authorized-rejoin-main` | `039b9a41` | Adds backend and browser proofs that an already authorized caller can leave and rejoin the same call while stale left-state is cleared. Also carries rejoin/kick and seed-matrix work. | Superseded except for the durable contract captured below. The branch diff is broad and not a narrow cherry-pick. |
| `codex/iam-e2e-authorized-rejoin-browser-proof-20260509` | `d6197c02` | Repeats the authorized browser proof and adds broader active-kick, CI, seed, and runtime work. | Superseded for IAM5-04; active-kick and CI wiring are outside this lane. |
| `codex/iam-e2e-authorized-rejoin-doc-gate-scan-20260509` | `f8164297` | Adds the same authorized backend proof plus many static gates and script edits. | Documentation/gate ideas only; package and shared CI edits are outside this lane. |
| `codex/iam-e2e-authorized-rejoin-main-integration-20260509` | `b75874be` | Integration candidate with authorized rejoin plus broad IAM branch content. | Superseded by current focused contracts. |
| `codex/iam-e2e-authorized-rejoin-main-integration-current-20260509` | `1d31357f` | Currentized integration candidate with the same authorized PHP/browser proof and extra suite wrappers. | Superseded for IAM5-04; do not import wholesale. |

## Extracted Contract

The durable proof value is:

- authorized rejoin is allowed for a currently authorized participant or admin
  path after a normal leave;
- rejoin clears stale `left_at` state for the allowed participant row;
- stale, kicked, removed, and role-invalidated paths still fail closed and do
  not regain call, lobby, moderation, or Call App access from cached state.

The current integration already contains the stronger maintained pieces:

- `demo/video-chat/backend-king-php/domain/realtime/realtime_call_context.php`
  allows direct room admission for current `allowed` or `accepted`
  participant state, lets current moderators/admins bypass the lobby, and
  clears `left_at` when `videochat_realtime_mark_call_participant_joined`
  persists a rejoin.
- `demo/video-chat/frontend-vue/tests/contract/call-access-direct-join-rights-contract.mjs`
  pins direct-join allow/deny coverage for system admin, organization admin,
  owner, registered guest-list user, normal denied user, and cross-org denied
  user through the maintained seed matrix.
- `demo/video-chat/backend-king-php/tests/call-access-decision-contract.php`
  proves current participant access survives tenant membership removal only as
  a call-scoped participant decision, while removing the participant row denies
  invite-only access.
- `demo/video-chat/backend-king-php/tests/call-access-membership-removal-contract.php`
  proves an admitted call-scoped session resolves into the bound call room
  without restoring tenant/admin powers.
- `demo/video-chat/frontend-vue/tests/contract/call-access-kicked-rejoin-denial-contract.mjs`
  proves cached sessions, copied links, queue joins, and room joins do not
  revive cancelled or declined participant rows.
- `demo/video-chat/frontend-vue/tests/contract/call-access-removed-members-contract.mjs`
  proves removed members have no active membership or direct call visibility
  and cannot observe lobby state without an issued call-scoped session.
- `demo/video-chat/frontend-vue/tests/contract/call-access-stale-role-org-switch-contract.mjs`,
  `demo/video-chat/backend-king-php/tests/call-access-stale-organization-role-contract.php`,
  and
  `demo/video-chat/frontend-vue/tests/contract/call-access-owner-transfer-temp-moderator-extract-contract.mjs`
  prove stale organization/admin/moderator state is re-read and revoked paths do
  not regain call or moderation authority on subsequent actions or rejoin.

## Added Focused Proof

`demo/video-chat/frontend-vue/tests/contract/call-access-authorized-rejoin-extract-contract.mjs`
is a small static extraction contract for IAM5-04. It does not add package or CI
wiring. It cross-checks the current runtime and existing maintained contracts so
the branch-specific proof value is visible without importing the broad source
branches.

## Non-ported Source Changes

The old authorized-rejoin branches also contain broad backend domain changes,
Playwright suites, CI script edits, seed-matrix rewrites, active-kick work,
Call App work, and sprint/planning edits. Those changes were not ported because
IAM5-04 only needs the authorized rejoin proof value, and the current product
surface already has narrower maintained proofs for the negative fail-closed
cases.
