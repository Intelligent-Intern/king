# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It carries the currently active executable batch, including leaves already
> closed inside that batch.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE` before starting, replenishing, or reshaping any `20`-issue batch
- keep the active batch visible here until it is exhausted; mark closed leaves as `[x]` instead of deleting them mid-batch
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, touched comments/docblocks, and tests in the same change; handbook docs and `READYNESS_TRACKER.md` may be deferred only when the current batch explicitly says so by user request
- when a leaf closes, also verify the affected runtime with the strongest relevant tests/harnesses available before committing
- when a leaf closes, make exactly one commit for that checkbox; do not batch multiple checkbox closures into one commit
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch or enables continuous batch execution
- when the current batch is exhausted, stop and wait instead of refilling it automatically unless continuous batch execution is explicitly enabled
- complete one checkbox per commit while an active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope
- before opening, updating, or marking a PR ready, clear all outstanding GitHub AI findings for this repo at `https://github.com/Intelligent-Intern/king/security/quality/ai-findings`

## Per-Issue Closure Checklist

- update the runtime/backend code needed for the leaf
- update any touched comments, docblocks, headers, and contract wording so code and prose stay aligned
- add or tighten tests that prove the leaf on the strongest honest runtime path available
- update repo docs affected by the leaf, unless the current batch explicitly defers handbook closeout to the end
- update `PROJECT_ASSESSMENT.md`
- update `READYNESS_TRACKER.md`, unless the current batch explicitly defers tracker closeout to the end
- run the strongest relevant verification available for that leaf before committing
- make exactly one commit for the checkbox
- before any PR refresh or release-candidate handoff, re-check `https://github.com/Intelligent-Intern/king/security/quality/ai-findings` and fix every outstanding finding on the branch

## Batch Mode

- The user is advancing the current batch manually with `w`.
- Close exactly one checkbox, make exactly one commit, and then wait for the next `w`.
- For the current visible batch, defer repo docs and `READYNESS_TRACKER.md` updates until every visible checkbox is closed, then do the closeout sweep once before pushing `develop/v1.0.4-beta` and opening the PR.
- After the PR is open, each further `w` means wait instead of auto-refilling from `READYNESS_TRACKER.md`.

## Current Next Leaf

- `#5` from batch `T1` on `develop/v1.0.4-beta`.

## Active Executable Items

### T1. CI Determinism and No-Manual-Test Hardening (9er Batch)

This batch is intentionally repo-local and CI-verifiable only: no manual provider validation, no manual runtime probes, no external hand-testing gates.

- [x] `#1 Pin and freeze build/release toolchain versions in one canonical source used by scripts and CI.`
  done when: release and CI builds fail on toolchain drift and no ambient host version silently changes outputs.
- [x] `#2 Enforce pinned QUIC/bootstrap dependency provenance before any build starts.`
  done when: CI hard-fails if quiche/boringssl/wirefilter lock provenance differs from tracked pins.
- [x] `#3 Remove remaining non-deterministic Cargo/Git resolution paths from release packaging.`
  done when: release scripts only resolve locked refs and never fall back to branch-based or host-state-dependent resolution.
- [x] `#4 Add reproducible release-archive verification as a required CI gate.`
  done when: package jobs run deterministic rebuild checks and fail if same-commit artifacts are not byte-identical.
- [ ] `#5 Expand transport-facing untrusted-input negative PHPT matrix.`
  done when: malformed/oversized/protocol-invalid transport payloads are covered with stable fail-closed assertions.
- [ ] `#6 Expand object-store negative PHPT matrix for traversal/injection/corrupt-manifest inputs.`
  done when: unsafe path and snapshot-import edge cases are covered and proven to fail closed.
- [ ] `#7 Emit deterministic regression diagnostics artifacts on PHPT failures across all shards/jobs.`
  done when: failure uploads always include structured summaries plus the relevant `.diff/.exp/.log/.out` payloads.
- [ ] `#8 Add flaky-test detection pass for canonical PHPT failures.`
  done when: CI reruns failing subsets and reports flaky-vs-deterministic classification in artifacts.
- [ ] `#9 Add CI truthfulness gates for public contracts.`
  done when: stub/runtime parity and public-claim checks are enforced so unsupported caveat text cannot silently regress.

## Notes

- The active batch is now `T1` on `develop/v1.0.4-beta`.
- Batch `S` remains fully closed (`#1`, `#3`-`#19`) and stays recorded in `PROJECT_ASSESSMENT.md`.
- The `Q` and `R` blocks are fully completed and recorded in `PROJECT_ASSESSMENT.md`.
- If a task is not listed here, it is not the current repo-local execution item.
