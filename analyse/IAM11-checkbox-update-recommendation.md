# IAM11 Checkbox Update Recommendation After Integration

Date: 2026-05-10

Scope:
- Analyse recommendation only.
- Do not edit `SPRINT.md` from this pass.
- No push.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.

Sources:
- `SPRINT.md`
- `analyse/IAM11-13-parallel-account-tabs-proof.md`
- `analyse/IAM11-14-lobby-concurrency-closure.md`
- `analyse/IAM11-15-owner-transfer-lifecycle-proof.md`
- `analyse/IAM11-16-admin-edge-policy-proof.md`
- `analyse/IAM11-17-call-access-edge-proof.md`
- `analyse/IAM11-18-branch-hygiene-proof.md`
- `analyse/IAM11-19-local-deploy-gate.md`

## Command Gate

Recommended local integration gate before applying the checkbox updates:

```bash
demo/video-chat/scripts/local-deploy-gate.sh
```

That gate covers the core backend IAM/call-access contracts, package/deploy
syntax checks, frontend IAM contract gate, release-gate package contract, and
frontend production build. Because host PHP may skip SQLite-backed contracts
when `pdo_sqlite` is unavailable, the backend proof commands below should pass
under a PHP runtime with `pdo_sqlite` when they are used as closure evidence:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie \
  php demo/video-chat/backend-king-php/tests/call-owner-transfer-lifecycle-contract.php

docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie \
  php demo/video-chat/backend-king-php/tests/iam11-17-call-access-edge-proof-contract.php
```

Focused frontend/E2E commands that should also pass for the browser-facing
checkboxes:

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:iam-call-access
npm run test:e2e:call-access
npm run test:e2e:lobby-concurrency
npm run test:e2e:release-gate
npm run build
```

## Recommended Checkbox Updates

### IAM11-13 Parallel Account Tabs

After `npm run test:contract:iam-call-access` and
`npm run test:e2e:call-access` pass, these `SPRINT.md` items can be checked:

- Security and Manipulation Cases:
  - `Parallel tabs with different accounts cause no incorrect merge`
- Duplicate Personalized Link / Abuse Detection:
  - `Concurrent use of same personalized link by two accounts is detected`
  - `Race condition on parallel link open creates no inconsistent assignment`

Rationale: `IAM11-13` proves two isolated browser contexts open the same
personalized link concurrently, keep bearer/session state isolated, send their
own verified context to session issuance, and resolve as one accepted session
plus one conflict without foreign data or session merge.

Do not mark review-flag/audit-review duplicate-abuse items from this proof
alone; the note proves concurrency isolation and conflict behavior, not review
workflow creation.

### IAM11-14 Lobby Concurrency

After `npm run test:e2e:lobby-concurrency` passes, and after the backend
`realtime-lobby-concurrency-contract` executes with `pdo_sqlite` rather than
skipping, these `SPRINT.md` items can be checked:

- Lobby and Admission:
  - `Lobby status updates correctly`
  - `Participant is removed from lobby after admission`
- Test Group: Lobby:
  - `e2e_lobby_010_concurrent_admission_idempotent`
  - `e2e_lobby_011_concurrent_admit_reject_deterministic`
  - `e2e_lobby_012_lobby_state_updates_correctly`

Rationale: `IAM11-14` records a passing focused Playwright rerun for duplicate
queue snapshots, admitted-plus-stale-queue state, duplicate participant rows,
stale lobby controls, and reject-empty state. Backend closure still needs a
non-skipped SQLite-backed race run in the integration gate.

### IAM11-15 Owner-Transfer Lifecycle

After the owner-transfer lifecycle contract passes under PHP with `pdo_sqlite`,
these `SPRINT.md` items can be checked:

- Call Creation and Owner Rights:
  - `Owner rights can be transferred to another user`
  - `If organization role User transfers owner rights, old owner loses call-admin rights`
  - `New owner receives owner rights`
  - `New owner receives admin rights in call`
- Test Group: Call Creation and Ownership:
  - `e2e_owner_006_normal_user_transfers_owner_and_loses_admin_rights`
  - `e2e_owner_008_new_owner_receives_owner_and_admin_rights`
  - `e2e_owner_009_exactly_one_current_owner_after_transfer`

Rationale: `IAM11-15` proves normal-owner transfer to another internal
participant, exactly one owner row, new-owner call update authority,
new-owner lobby moderation, old non-admin owner denial for administration and
owner-transfer, and immutable cancelled/ended/deleted call behavior.

Do not mark organization-admin owner-transfer retention items from this proof;
that remains IAM11-16 and is not closed.

### IAM11-16 Admin Edge Policy

Do not check additional `SPRINT.md` items from `IAM11-16` yet.

Rationale: the analyse note explicitly records that current backend authority
does not expose the org-admin owner-transfer policy, and local Docker proof
still failed `system-admin-call-rights-contract.php` and
`org-admin-call-rights-contract.php` at the documented assertions. The already
checked system-admin and org-admin boundary items can remain checked only if the
existing contracts pass in the final integration gate; the open org-admin
owner-transfer items should remain open.

Specifically keep these open:

- Organization Admin:
  - `Organization admin rights remain after owner transfer`
  - `Organization admin can transfer owner rights if allowed`
  - `Organization admin keeps admin rights when transferring ownership`
- Test Group: Call Creation and Ownership:
  - `e2e_owner_007_org_admin_transfers_owner_and_keeps_admin_rights`
- End-to-End User Journeys:
  - `e2e_journey_017_org_admin_owner_transfer_keeps_admin`

### IAM11-17 Call-Access Edge Proof

After the IAM11-17 backend proof contract passes under PHP with `pdo_sqlite`,
these `SPRINT.md` items can be checked:

- Join Permissions:
  - `Deleted / disabled user cannot join`
- Rejoin, Leave, Kick:
  - `Kicked temporary user cannot directly rejoin`
  - `Kicked temporary user lands back in lobby or is blocked`
  - `Kick state overrides previous admission`
  - `Kick state is stored server-side`
  - `Kick state is scoped to affected call if intended`
  - `Kick state is scoped to affected user / temporary account`
- Anonymous Join Link: User Not Logged In:
  - `If anonymous user was kicked, rejoin requires approval or is blocked`
- Call Rescheduling:
  - `Personalized invite link from old time is invalidated after reschedule if required`
  - `New personalized invite link is issued after reschedule if required`
  - `Old temporary guest account is deleted, invalidated, or migrated according to product rule`
  - `Guest using old link after reschedule cannot join stale call state`
  - `Guest using new link after reschedule can join according to current permissions`
  - `Stale links do not join users into wrong call instance`
- Test Group: Direct Join Permissions:
  - `e2e_join_008_disabled_user_cannot_join`
- Test Group: Rejoin and Kick:
  - `e2e_rejoin_004_kicked_temp_user_cannot_direct_rejoin`
  - `e2e_rejoin_005_kick_overrides_previous_admission`
- Test Group: Call Rescheduling:
  - `e2e_reschedule_004_old_personalized_link_invalidated`
  - `e2e_reschedule_005_new_personalized_link_works`
  - `e2e_reschedule_006_old_temp_guest_handled_by_product_rule`
  - `e2e_reschedule_007_old_link_cannot_join_stale_call`
- End-to-End User Journeys:
  - `e2e_journey_018_temp_user_kicked_cannot_rejoin_directly`
  - `e2e_journey_021_rescheduled_call_old_link_invalid_new_link_valid`

Rationale: `IAM11-17` proves temporary guest kick removes prior admission and
requires renewed approval, disabled registered users have active call-access
sessions stamped `revoked_at` and rejected as `revoked_session`, and reschedule
invalidates old links/sessions/temp guests while a newly issued link binds to
the current call.

Do not mark broad lobby, deletion, ended-call, audit, anonymous-link-after-
reschedule, or frontend old-link UX items from this backend-only proof.

### IAM11-18 Branch Hygiene

No `SPRINT.md` checkbox should be marked as product/test closure from
`IAM11-18`. The note is operational hygiene evidence only.

Recommended status text, if a sprint note is added later: no branch/worktree was
deleted; only branches that are ancestors of `iam-e2e-integration` and
clean/no-worktree are safe-to-consider cleanup candidates; dirty and non-
ancestor branches remain manual.

### IAM11-19 Local Deploy Gate

After `demo/video-chat/scripts/local-deploy-gate.sh` passes in the integration
environment, this `SPRINT.md` Definition of Done item can be checked:

- `Documentation explains how to run tests locally`

Rationale: `IAM11-19` records a local gate command and focused equivalent
commands for backend IAM/call-access contracts, frontend IAM contracts,
release-gate package contract, and production build. It also keeps online smoke
separate behind explicit opt-in and does not deploy, mutate DNS, or request
certificates.

Do not mark broad coverage, CI-only, or production-online items from this local
gate alone:

- `Security manipulation cases are covered`
- `CI job runs E2E suite automatically`
- `CI starts king containers for multi-participant tests`
- `CI collects traces, screenshots, videos, and logs on failure`
- production deploy or online smoke closure

## Summary

Recommended to check after the command gates pass:

- IAM11-13: parallel-tab/session-isolation and concurrent same-link race items.
- IAM11-14: lobby concurrency/status UI items, once backend SQLite race proof is
  non-skipped.
- IAM11-15: normal-user owner-transfer lifecycle and new-owner authority items.
- IAM11-17: disabled-user revocation, kicked temporary rejoin blocking, and
  stale personalized-link reschedule safety items.
- IAM11-19: local run documentation, once the local deploy gate passes.

Recommended to keep open:

- IAM11-16 org-admin owner-transfer retention/transfer policy.
- Review-flag/audit-review duplicate-link workflow items.
- Broad deletion/ended-call/join-path coverage not directly proved by these
  notes.
- Broad security-manipulation Definition of Done closure.
- CI automation, production deploy, and online smoke items not covered by the
  local gate.
