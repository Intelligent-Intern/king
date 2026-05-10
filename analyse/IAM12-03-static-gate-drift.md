# IAM12-03 Static Gate Drift

Date: 2026-05-10

Scope:
- IAM/call-access static gate documentation drift only.
- No runtime implementation changes.
- No production deploy.
- No push, DNS changes, certbot calls, or remote mutation.
- No Background, Gossip, SFU, MediaSecurity, BTGF, or VCAP implementation work.

## Finding

`npm run test:ci:iam-call-access:static` failed in
`iam9-06-call-app-entitlement-revocation-contract.mjs` because
`documentation/iam7-08-call-app-entitlement-revocation.md` no longer contained
the exact proof boundary text required by the contract.

The contract requires the extraction note to state that the Call App entitlement
revocation proof does not depend on edits to:

```text
SPRINT.md
BACKLOG.md
READYNESS_TRACKER.md
```

## Change

The IAM7-08 extraction note now uses the exact planning-file boundary text while
keeping the current runtime proof unchanged.

## Verification

Focused contract:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/iam9-06-call-app-entitlement-revocation-contract.mjs
```

Result:

```text
[iam9-06-call-app-entitlement-revocation-contract] PASS
```

Full static IAM gate:

```bash
cd demo/video-chat/frontend-vue
npm run test:ci:iam-call-access:static
```

Result:

```text
[iam-call-access-ci-gate] PASS: mode=static
```
