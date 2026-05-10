# IAM Sprint 05 Seed/Cache/Run Docs Extraction

Date: 2026-05-10

Worker: IAM5-17

Branch: `agent/iam-s5-17-seed-cache-run-docs`

Worktree:
`/home/jochen/projects/king.site/worktrees/iam-s5-17-seed-cache-run-docs`

Base branch: `prod-kingrt-do-not-push-to-github` at `17c851ac`.

## Verdict

IAM5-17 is a focused extraction, not a merge lane. The current IAM gate already
has the stronger direct proof inventory in `test:contract:iam-call-access` and
the focused browser proof in `test:e2e:call-access`. The only still-useful value
from the IAM5-17 family is:

- seed data hygiene as a focused static guard for deterministic matrix
  uniqueness and references;
- asset-cache busting as an adjacent existing contract to keep running, not as
  IAM package wiring;
- local run commands recorded here instead of broad runbook churn;
- live-proof environment audit value classified as already covered by the
  existing read-only production diagnostics contract, outside the IAM gate.

No Background, Gossip, SFU, MediaSecurity, BTGF, deploy scripts, `SPRINT.md`,
package scripts, CI wiring, source runtime files, or broad runbooks were edited.

## Source Evidence

Branch-wide diffs in this family are broad historical integration shapes. They
rewrite top-level planning files, package/CI wiring, Call App files, deploy
state, and parked media areas. Those branches are source evidence only; they
must not be merged wholesale into the current integration branch.

| Source branch | Head | Useful value | IAM5-17 handling |
| --- | --- | --- | --- |
| `codex/iam-e2e-asset-cache-busting-contract-20260509` | `5101367b` | Fixes drift in `asset-cache-busting-contract.mjs`. | Superseded by the current focused `asset-cache-busting-contract.mjs`, which already covers build asset version injection, deferred call-workspace reload, websocket asset-version probes, runtime endpoint exposure, and stale-client disconnect helpers. No asset/cache code copied. |
| `local/iam-e2e-local-run-docs-proof-20260509` | `f956c91b` | Adds local IAM E2E proof docs and a local-run-docs contract. | Not copied. It edits `SPRINT.md`, package scripts, and new `documentation/dev/...` runbook content. It also documents `test:ci:iam-call-access:*`, `iam-call-access-ci-gate.sh`, and `call-access-e2e-suite.mjs`, but those helpers are absent from the current Sprint 05 base. The useful local commands are recorded below against the current package scripts. |
| `local/iam-seed-data-hygiene-20260509` | `bb4331ef` | Adds deterministic seed matrix uniqueness checks and fixes duplicate historical IDs. | Useful and extracted as a new focused static contract against the current matrix. No matrix data edit was needed because current prod already has unique tenant, user, call, access-link, and scenario identifiers. |
| `codex/iam-seed-data-hygiene-20260509` | `595b2ebd` | Merge wrapper around cross-org and seed-hygiene proof history. | Superseded by current IAM5-08 cross-org extraction plus the new IAM5-17 seed-hygiene guard. No wrapper merge copied. |
| `codex/iam-e2e-live-proof-env-audit-20260509` | `7ea9757a` | Branch name points at live-proof env audit, but the tip is a broad duplicate-review abuse merge. | Not imported into IAM. The current env-audit value is covered by `prod-debug-observability-contract.mjs`, which proves read-only diagnostics, inert `.env.local` parsing, an allowlist, redaction, sanitized remote env use, and no production mutation. That contract is adjacent production diagnostics, not IAM gate wiring. |
| `codex/iam-e2e-deploy-readiness-20260509` / `codex/iam-e2e-script-helper-docs-cleanup-20260509` / `codex/iam-next-5-candidate-rank-20260509` | `5771a3b6` | Historical deploy-readiness and script-helper package-suite refactor merge. | Superseded for IAM5-17. The branch family is broad and overlaps IAM5-03 package-suite classification; no package helpers or deploy docs were copied. |
| `iam-e2e-deploy-readiness-rescan-codex-20260509` / `codex/iam-e2e-cleanup-report-20260509` | `7743e21f` | Historical cleanup/report wrapper around asset-cache and lobby state merges. | Superseded by focused IAM5-05/IAM5-06 extraction and the current asset-cache contract. No cleanup report copied. |
| `codex/iam-sprint-proof-audit-20260509` | `bf1186a2` | Sprint proof audit wrapper. | Superseded by current Sprint 05 extraction docs and contracts; no active checklist or tracker state copied. |
| `codex/iam-sprint-arendt-proof-checkboxes-20260509` | `e9a55048` | Conservative sprint proof checkbox formatting. | Superseded and outside write scope because `SPRINT.md` is forbidden. |

## Current Gate

The current IAM package gate remains the stronger direct gate:

```bash
cd demo/video-chat/frontend-vue
npm run test:contract:iam-call-access
```

It keeps focused Sprint 03/04/05 static contracts, redaction/artifact proof,
seed matrix foundation proof, direct-join/cross-org/terminal/admission/lobby
proofs, and the backend SQLite/Docker wrappers. IAM5-17 does not replace it
with the old branch suite helper.

The focused browser proof remains:

```bash
cd demo/video-chat/frontend-vue
npm run test:e2e:call-access -- --reporter=list
```

The package script keeps `PLAYWRIGHT_IAM_CALL_ACCESS_ARTIFACTS=1` and
`--workers=1`, so retained failure artifacts are deterministic and live backend
access-link state is not raced by parallel workers.

For compose-shaped local proof, use the existing smoke path:

```bash
VIDEOCHAT_SMOKE_COMPOSE_ONLY=1 \
VIDEOCHAT_SMOKE_REQUIRE_COMPOSE=1 \
bash demo/video-chat/scripts/smoke.sh
```

If local host PHP lacks `pdo_sqlite`, treat host SQLite skips as an environment
blocker, not as backend proof. Use the Docker/compose proof path for SQLite
coverage.

## Extracted Contract

Added
`demo/video-chat/frontend-vue/tests/contract/iam-s5-17-seed-cache-run-docs-contract.mjs`.

The contract:

- documents the IAM5-17 branch verdicts above;
- preserves the current explicit IAM proof gate and focused browser command;
- asserts deterministic seed matrix uniqueness for keys, IDs, emails, room IDs,
  access-link join paths, and scenario keys;
- checks seed references between scenarios, links, calls, users, tenants, and
  guest lists;
- confirms the current asset-cache proof stays in the dedicated
  `test:contract:asset-cache-busting` contract;
- confirms live-proof env audit value remains covered by
  `prod-debug-observability-contract.mjs` instead of importing broad production
  diagnostics into the IAM gate;
- records local proof commands in this extraction note without adding a new
  `documentation/dev/...` runbook.

## Proof Commands

Run from this worktree:

```bash
cd demo/video-chat/frontend-vue
node tests/contract/iam-s5-17-seed-cache-run-docs-contract.mjs
node tests/contract/iam-call-access-e2e-foundation-contract.mjs
node tests/contract/call-access-direct-join-rights-contract.mjs
node tests/contract/asset-cache-busting-contract.mjs
node tests/contract/prod-debug-observability-contract.mjs
cd ../../..
git diff --check
```
