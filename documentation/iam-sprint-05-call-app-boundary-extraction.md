# IAM Sprint 05 Call App Boundary Extraction

Date: 2026-05-10

Scope: IAM5-15 extraction of Call App IAM boundary proof value for participant
entitlement revocation, launch-token reconnect validation, and Whiteboard
organization install. Background, Gossip, SFU, MediaSecurity, BTGF, deploy
scripts, and Call App UI feature work were not touched. `SPRINT.md` was not
edited. Source branches were inspected as evidence only; no source branch was
deleted, reset, rebased, merged, or edited.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`17c851ace650903f17b8b02776028d0d01a9b783`.

## Source Branches Inspected

| Branch | Head | Useful boundary value | Decision |
| --- | --- | --- | --- |
| `local/iam-e2e-call-app-entitlement-revocation` | `dd21579f5ce62985febbd7629b67aa0063409eb8` | Adds Call App grant revocation proof: user and guest grant lookup, active launch-token retirement, reconnect audit metadata, CRDT fail-closed denial, permission-action gating, and iframe/runtime denial handling. Also carries source-only launch-token entitlement revalidation. | Current value is preserved by existing grant revocation, CRDT, launch-token, and Call App lifecycle contracts. Source-only entitlement revalidation remains follow-up evidence. |
| `local/iam-e2e-call-app-launch-token-reconnect` | `a833db6f94883f04fa36cc91ec704bd051e705a0` | Adds source launch-token reconnect validation for active organization installation, active/unexpired entitlement, healthy catalog state, token staleness after installation/entitlement/catalog updates, session reactivation, and grant changes after token issue. | Not ported in this proof-only lane because it requires backend runtime edits. Documented as the strongest follow-up contract. |
| `local/iam-e2e-whiteboard-org-install-final` | `4367d573f2074f27720d91fd4ef60ad03a06a7f1` | Adds production Whiteboard organization-install proof command and static contract for Marketplace order, organization installation, real call availability, iframe reachability, and cleanup. | Current org-install boundary is preserved by existing backend/browser contracts. Production proof command is source-only because deploy scripts, package wiring, and sprint edits are outside IAM5-15 scope. |
| `local/iam-e2e-disabled-user-session-revocation` | `5a3fb5c8af08fb08ab3310df8fcce4c19156740b` | Adjacent disabled-user and primary-session revocation evidence. | Current base already documents and tests realtime session revocation separately. No Call App UI or session-auth code was imported. |

## Current Extracted Contract

The durable Call App IAM boundary in current integration is:

- Call App sessions, launch-token minting, launch-token validation, CRDT
  bootstrap/replay/append, and presence delivery must re-enter backend IAM
  checks instead of trusting iframe, sidebar, or cached participant state.
- A participant whose Call App grant is denied must lose active launch tokens;
  reconnect validation for those retired tokens must fail closed.
- A denied participant may receive only status launch capability and must not
  receive CRDT read/replay/append capability, private CRDT bootstrap, or private
  CRDT replay.
- User and guest grant lookup must use one subject-aware grant resolution path
  and carry explicit read/write/delete permission actions into the sandbox.
- The iframe must never receive the primary session token, raw user ids,
  `Authorization` material, or local storage credentials.
- Whiteboard organization install must flow through Marketplace order and
  installation endpoints, produce an enabled organization installation backed
  by an active entitlement, appear through call-scoped availability, and then
  attach through backend Call App session creation.

Current maintained proof files:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-callapp-revocation-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-app-permission-revocation-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-app-iframe-launch-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-app-marketplace-to-call-journey-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-app-whiteboard-install-browser-proof-contract.mjs
demo/video-chat/backend-king-php/tests/call-app-session-lifecycle-contract.sh
demo/video-chat/backend-king-php/tests/call-app-marketplace-entitlement-contract.sh
```

Those contracts bind the current runtime to the Call App IAM boundary without
importing broad source branch UI work.

## Entitlement And Grant Revocation

Current base preserves the grant-revocation half of the source value:

- `videochat_call_app_launch_subject_grant_state` and
  `videochat_call_app_launch_guest_grant_state` keep user and guest reconnect
  lookup on the same backend grant table and default-policy fallback.
- `videochat_call_app_retire_launch_tokens_for_grant` revokes active launch
  tokens for the changed subject when a grant becomes denied or permission
  actions change.
- grant mutation audit payloads include `retired_launch_tokens` and
  `reconnect_policy`.
- `videochat_call_app_crdt_requires_allowed_grant` rejects revoked participants
  before private CRDT state is returned or mutated.
- the parent CRDT bridge forwards `participant_grant_denied` to the sandbox,
  and Whiteboard clears polling/editing after the runtime denial.

The current proof is static plus backend lifecycle coverage in
`call-app-permission-revocation-contract.mjs` and
`call-app-session-lifecycle-contract.sh`. It does not rely on Call App UI
feature changes.

## Launch-Token Reconnect Validation

Current base preserves the maintained launch-token reconnect floor:

- launch tokens are short-lived, stored by hash, and validated by
  `/api/call-app-sessions/{session_id}/launch-token/validate`;
- validation rejects missing, revoked, expired, inactive-session, and
  participant-not-in-call tokens;
- minting and validation both re-check current call admission through
  `videochat_call_app_grant_subject_in_call`;
- the parent iframe bridge mints tokens through the backend endpoint and posts
  only sanitized cloneable launch payloads to the opaque sandbox origin;
- lifecycle proof shows a denied participant active token is revoked and that
  revoked token fails reconnect validation.

### Source-only follow-up: Launch-token entitlement and stale-token revalidation

The strongest source branch also adds
`videochat_call_app_launch_session_availability`, which revalidates enabled
organization installation, active and unexpired entitlement, healthy catalog
state, and token staleness after installation, entitlement, catalog, session,
or grant changes. Current base does not yet contain
`videochat_call_app_launch_session_availability`, nor the
`token_stale_after_entitlement_change`,
`token_stale_after_session_reactivation`, and
`token_stale_after_grant_change` proof messages from the source branch.

That is preserved here as backend follow-up evidence. The IAM5-15 extraction
does not weaken the desired contract to "revoked_at and expires_at only"; it
records that the current maintained floor is token revocation plus current call
admission, while the stronger entitlement/session-staleness reconnect proof
still needs focused backend extraction.

## Whiteboard Organization Install

Current base preserves the organization-install boundary in maintained backend
and browser contracts:

- Marketplace catalog starts with Whiteboard not installed for the
  organization.
- regular users cannot order Call Apps for the organization.
- client tenant overrides fail and do not create entitlement rows.
- installation without entitlement fails.
- Marketplace order creates one active organization entitlement.
- Marketplace installation creates one enabled organization installation with
  explicit `default_app_policy`.
- post-install call availability returns Whiteboard from the organization
  installation and active entitlement.
- browser proof starts from a user-visible "Install for organization" action,
  posts to Marketplace order and installation endpoints, verifies availability
  before attach, creates a backend Call App session, mutates participant grants,
  proves token-retirement payloads, runs the real Whiteboard iframe, and proves
  grant changes do not reload the iframe.
- browser proof rejects manual DB/storage shortcuts by contract.

### Source-only follow-up: Production org-install command

`local/iam-e2e-whiteboard-org-install-final` adds
`demo/video-chat/scripts/prod-whiteboard-org-install-proof.sh` and
`call-app-whiteboard-production-org-install-contract.mjs`. That source proof
pins the production service roots, exercises Marketplace order/install against
production endpoints, verifies active entitlement and explicit organization
default policy, creates a real call, verifies Whiteboard call availability,
checks iframe reachability, and cleans up only the temporary call.

That production command was not ported because IAM5-15 write scope excludes
deploy scripts, package script wiring, and `SPRINT.md`. The value is retained
as follow-up evidence instead of being downgraded to local-only install proof.

## Extraction Decision

Safe extraction performed in this branch:

- documented the Call App participant-grant revocation value against current
  maintained launch-token, CRDT, iframe, and lifecycle contracts;
- documented launch-token reconnect validation against current token
  revocation, hash/expiry/session checks, call-admission recheck, and iframe
  no-primary-token contracts;
- preserved the stronger source entitlement/session-staleness launch-token
  revalidation as backend follow-up evidence;
- documented Whiteboard organization install against current Marketplace,
  availability, sidebar attach, grant mutation, and real iframe browser proof;
- preserved the production org-install command as follow-up evidence because
  deploy script and package wiring edits are outside scope;
- added one narrow IAM5-15 static extraction contract.

No product code, package scripts, shared CI wiring, Call App UI feature files,
deploy scripts, `SPRINT.md`, or `BACKLOG.md` were edited. No broad source
branch was imported.
