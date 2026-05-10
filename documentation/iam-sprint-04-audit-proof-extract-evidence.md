# IAM Sprint 04 Audit Proof Extraction Evidence

Date: 2026-05-10

Scope: IAM Call Access audit proof extraction only. Background, Gossip, SFU,
MediaSecurity, and BTGF areas were not touched. Source proof worktrees were read
only; no branch or worktree was deleted, reset, rebased, or merged.

Base checked: local `prod-kingrt-do-not-push-to-github` at
`ef626e2592bf3a535fffd7c981771bb88d2626ff`.

## Source Branches Inspected

| Branch | Head | Subject |
| --- | --- | --- |
| `local/iam-e2e-audit-alias-followup-proof-3` | `9c80b10189c63c82cadd0c53bdc7d2554bc8e652` | Prove IAM audit event alias contract follow-up |
| `local/iam-e2e-audit-event-compat-proof-3` | `daf6277d64b8caf554b03185e0b831d12afc5194` | Pin IAM host verification audit event aliases |

Both source branches contain the same audit-focused proof set:

```text
demo/video-chat/backend-king-php/domain/audit/audit_events.php
demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.php
demo/video-chat/backend-king-php/tests/audit-call-access-events-contract.sh
demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php
demo/video-chat/backend-king-php/tests/audit-call-access-privacy-minimization-contract.php
demo/video-chat/backend-king-php/tests/audit-call-access-privacy-minimization-contract.sh
demo/video-chat/frontend-vue/tests/contract/iam-call-access-audit-events-contract.mjs
```

## Current Coverage Verified

Current Sprint 03 prod already carries the stable audit compatibility and
redaction subset:

```text
demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs
demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs
demo/video-chat/backend-king-php/tests/audit-call-access-membership-contract.php
demo/video-chat/backend-king-php/tests/call-access-strong-mismatch-privacy-contract.php
demo/video-chat/backend-king-php/tests/call-access-session-fixation-contract.php
```

That coverage pins:

- Legacy/current audit event aliases for link-open, call-scoped continuation,
  membership removal, denied/admitted access, and role-change names.
- Canonicalization on audit write and filtered audit read.
- Artifact redaction for bearer tokens, cookies, raw join/access URLs, UUIDs,
  private call titles, host/participant email data, SDP, and ICE candidates.
- Strong mismatch denial redaction for wrong-user, wrong-host, unverified-host,
  session-fixation, context-switch, and binding-mismatch paths.

The focused current checks were run and passed:

```text
node demo/video-chat/frontend-vue/tests/contract/call-access-audit-event-compatibility-contract.mjs
node demo/video-chat/frontend-vue/tests/contract/call-access-audit-redaction-contract.mjs
node demo/video-chat/frontend-vue/tests/contract/call-access-strong-mismatch-audit-redaction-contract.mjs
```

## Unique Source Value Not Ported

The `proof-3` branches also contain broader audit-event lifecycle proof value
that is not present in current prod:

- Backend contract `audit-call-access-events-contract.php`.
- Frontend guard `iam-call-access-audit-events-contract.mjs`.
- Live fixture helper `frontend-vue/tests/e2e/helpers/iamCallAccessLiveFixtures.js`.
- Audit helper/event assertions for call creation, invitation creation,
  temporary account creation, account comparison, host-name verification
  success/failure aliases, account-update confirmation, participant
  join/leave/rejoin/kick, owner transfer, strong mismatch denial, invitation
  invalidation, and membership removal.

Those files were not ported wholesale because the source branch diffs include
hundreds of unrelated stale Sprint files and current prod does not contain the
backend helper/event surface required by that broader contract. A direct port of
the source contract would fail on missing current-prod files and would reintroduce
stale branch assumptions outside this focused extraction.

## Extraction Decision

No code proof was added in this pass. The current alias/redaction compatibility
subset is superseded by existing stable contracts and was validated green. The
broader lifecycle audit-event proof is unique but not safely extractable without
first adding the missing current-prod backend audit event helpers and live
fixture support as a dedicated implementation task.

Recommended follow-up: create a narrow IAM audit lifecycle issue that implements
the current-prod backend helpers and a fresh focused contract for the broader
event list, rather than merging the stale `proof-3` branches.
