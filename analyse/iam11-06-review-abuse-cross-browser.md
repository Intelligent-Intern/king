# IAM11-06 Review-Abuse Cross-Browser Proof

Stand: 2026-05-10

## Source Branch

- Branch: `local/iam-e2e-review-abuse-cross-browser-proof-3`
- Worktree:
  `/home/jochen/projects/king.site/worktrees/iam-e2e-review-abuse-cross-browser-proof-3`
- Observed HEAD: `0e02e60542734f5221b1bd85fee7154e002e5077`
- Observed status: clean

The source tip only changes `SPRINT.md`. It documents this cross-browser value:
account A joins through one isolated browser context, account B opens the same
personalized link from another browser context, B does not receive a
call-scoped session, private linked-account/link details stay out of the
response, and B's browser session remains isolated.

## Current Integration Proof

No source E2E import is needed. The current integration already proves the
stable runtime contract through:

- `demo/video-chat/frontend-vue/tests/contract/call-access-duplicate-abuse-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/iam9-13-duplicate-abuse-devices-contract.mjs`
- `demo/video-chat/frontend-vue/tests/contract/iam-call-access-ci-wire-contract.mjs`

Pinned behavior:

- the proof keeps a parallel browser-context duplicate-abuse test;
- both contexts send their own verified user/session payloads;
- one browser receives an accepted session and the other receives a 409
  `call_access_conflict`;
- the rejected browser keeps its existing session and does not receive the
  accepted or rejected call-access token;
- the conflict denial does not leak foreign linked-call, account, session, or
  token data;
- IAM gate metadata still wires the duplicate-abuse contract into the current
  call-access contract set.

The older source wording mentions `duplicate_personalized_link` /
`manual_review_required`. That manual-review UI state is not the current narrow
runtime contract for IAM11-06; the current contract is the deterministic,
privacy-preserving cross-browser conflict boundary above.

## Verification

Run from `demo/video-chat/frontend-vue`:

```bash
node tests/contract/call-access-duplicate-abuse-contract.mjs
node tests/contract/iam9-13-duplicate-abuse-devices-contract.mjs
node tests/contract/iam-call-access-ci-wire-contract.mjs
npm run test:contract:iam-local-run-docs
```

Results in this worktree:

- `call-access-duplicate-abuse-contract.mjs`: PASS
- `iam9-13-duplicate-abuse-devices-contract.mjs`: PASS
- `iam-call-access-ci-wire-contract.mjs`: PASS
- `npm run test:contract:iam-local-run-docs`: PASS

Skipped by design: no Playwright/E2E import from the historical source branch.

Known unrelated result: `iam-review-abuse-extraction-contract.mjs` currently
fails because a parked/source-only identity-mismatch backend contract is now
present in the integration tree. That stale extraction-classification assertion
does not invalidate the narrower IAM11-06 cross-browser duplicate-abuse proof
above.

## Closure

IAM11-06 can be closed as proven by current contracts. No Background, Gossip,
SFU, DNS, Certbot, deploy, branch deletion, or push action was performed.
