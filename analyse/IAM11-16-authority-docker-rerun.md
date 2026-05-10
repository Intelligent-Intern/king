# IAM11-16 Authority Docker Rerun

Date: 2026-05-10

Scope:
- Analysis note only.
- No runtime or test files edited.
- No Background, Gossip, SFU, MediaSecurity, or BTGF files touched.
- No push performed.

## Result

The IAM11-16 backend authority Docker contracts are green in this workspace after
the follow-up fixes.

Commands run from the repository root:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-owner-transfer-contract.php
```

Result:

```text
[call-owner-transfer-contract] PASS
```

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/call-access-cross-org-contract.php
```

Result:

```text
[call-access-cross-org-contract] PASS
```

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/system-admin-call-rights-contract.php
```

Result:

```text
[system-admin-call-rights-contract] PASS
```

```bash
docker run --rm -v "$PWD":/workspace -w /workspace php:8.4-cli-trixie php demo/video-chat/backend-king-php/tests/org-admin-call-rights-contract.php
```

Result:

```text
[org-admin-call-rights-contract] PASS
```

## Analysis

The two blockers recorded in `analyse/IAM11-16-admin-edge-policy-proof.md` are
not reproduced by this Docker rerun:

- `system-admin-call-rights-contract.php` no longer fails at participant
  management for a foreign-tenant call.
- `org-admin-call-rights-contract.php` no longer fails at realtime context call
  resolution.

This pass establishes a focused local green signal for the IAM11-16 authority
contracts. It does not mark broader IAM completion; the wider final gate still
depends on the other backend, frontend, build, and deploy-gate checks listed in
`analyse/IAM11-20-final-sprint-proof-checklist.md`.
