# IAM12-02 Stale Proof Text

Date: 2026-05-10

Scope:
- IAM/call-access proof text only.
- No runtime implementation changes.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Finding

`analyse/iam11-08-foreign-personalized-mismatch-current-proof.md` still
described the correct-host foreign personalized-link flow as open. That was
stale after IAM11 added:

- `CallAccessJoinFooter.vue`;
- `callAccessPersonalizedMismatch.ts`;
- the correct-host decline/update-confirm-email branch in
  `tests/e2e/call-access-join.spec.js`;
- contract assertions in
  `tests/contract/call-access-strong-mismatch-privacy-contract.mjs`.

## Change

The IAM11-08 proof note now records IAM11-08 as closed in the current
integration branch. It distinguishes the historical source branch from the
focused current extraction and points to the current browser and contract proof
instead of saying the correct-host flow remains open.

`documentation/iam-sprint-05-cross-org-extraction.md` also now marks the
foreign personalized mismatch row as historical IAM5-08 scope and points to the
current IAM11-08 correct-host decline/update-confirm-email proof. It still keeps
the remaining positive cross-org join values as separate source evidence.

Adjacent review-abuse, duplicate-review, and email-confirmation extraction docs
and contracts still contain older absent/source-only assertions. They are larger
contract updates and stay queued under IAM12-10 through IAM12-14 instead of
being mixed into this proof-text cleanup.

## Verification

Focused text checks:

```bash
rg -n "IAM11-08.*open|correct-host.*remains open|missing correct-host browser journey" analyse
```

Focused contract checks:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/call-access-strong-mismatch-privacy-contract.mjs
node tests/contract/call-access-route-guard-ui-contract.mjs
```

Diff hygiene:

```bash
git diff --check -- SPRINT.md analyse/iam11-08-foreign-personalized-mismatch-current-proof.md analyse/IAM12-02-stale-proof-text.md
```
