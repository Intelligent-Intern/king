# Contributing to King

This document defines how work lands in the King repository.

It is not a product description.
It is not the strategic program map.
It is not the active backlog.

Use the repository docs like this:

- `README.md`
  Permanent target-system description.
- `EPIC.md`
  Stable charter and exit criteria.
- `PROJECT_ASSESSMENT.md`
  Verified current implementation state.
- `ISSUES.md`
  Single moving roadmap and execution queue.
- `READYNESS_TRACKER.md`
  Long-form completion tracker and closure reference.
- `CONTRIBUTE.md`
  Contribution and workflow rules.
- `stubs/king.php`
  Public PHP signature surface.

Raw or personal checklists are inputs, not source-of-truth repo planning.
The tracked `READYNESS_TRACKER.md` is the only repo-owned long-form checklist,
and it is still not the active backlog. If open work matters, distill it into
`ISSUES.md` as a small verifiable leaf instead of duplicating it across
multiple docs.

## Working Standard

King is a native systems project, not a demo app.

Every change is expected to preserve these rules:

- one runtime kernel, multiple API surfaces
- explicit ownership and teardown
- no capability claims without code and verification
- no documentation drift between what exists and what is claimed
- no convenience shortcut that weakens transport, lifecycle, or validation semantics

If a change lowers clarity, weakens lifecycle guarantees, or widens the surface
without a real backend behind it, it is the wrong change.

## Non-Negotiable V1 Rule

King v1 is not an MVP, a demo slice, or a "good enough for now" release.
Treat it as the long-lived contract.

That means:

- do not narrow, fence off, or remove an important capability just because the
  robust implementation is slower or harder to finish
- do not turn a shared, remote, persistent, or stronger runtime contract into a
  local or reduced one as an engineering shortcut
- do not use documentation edits as a substitute for backend work when the
  intended contract is already part of the product direction
- if code and intent diverge, the default action is to build the missing
  synchronization, persistence, recovery, protocol, or safety work that makes
  the stronger contract correct

Any true contract reduction is a product decision and requires explicit user
approval. It is not a normal cleanup tool for making CI, tests, or docs easier.

## Canonical Build Path

The canonical extension workflow is inside `extension/`.
Do not treat ad-hoc local commands or legacy wrappers as the source of truth.
The GitHub Actions baseline in `.github/workflows/ci.yml` is expected to run
the same workflow, not a separate approximation.

Build:

```bash
cd extension
./scripts/build-extension.sh
```

Test:

```bash
cd extension
./scripts/test-extension.sh
```

Audit the active build surface:

```bash
cd extension
./scripts/audit-runtime-surface.sh
```

Run the repo-local static checks:

```bash
cd extension
./scripts/static-checks.sh
```

Run the canonical fuzz, stress, and edge-case subset:

```bash
cd extension
./scripts/fuzz-runtime.sh
```

Check the public PHP stubs against the live runtime:

```bash
cd extension
./scripts/check-stub-parity.sh
```

Build the canonical release package:

```bash
cd extension
./scripts/package-release.sh
```

Verify that the release package is reproducible:

```bash
cd extension
./scripts/package-release.sh --verify-reproducible
```

Verify a generated release archive after extraction:

```bash
cd extension
./scripts/verify-release-package.sh --archive ../dist/king-*.tar.gz
```

Run the clean-host package install matrix across supported PHP binaries:

```bash
cd extension
./scripts/install-package-matrix.sh --archive ../dist/king-*.tar.gz --php-bins php
```

Build one package per supported PHP/API combination and run the host install
smoke against the matching runtime for that archive.

Run the previous-release to current-release upgrade compatibility gate:

```bash
cd extension
./scripts/check-release-upgrade.sh --from-ref HEAD^
```

This packages the previous git ref, verifies both archives, installs them into
the same prefix one after the other, and runs the packaged smoke before and
after the upgrade.

Run the current-release to previous-release downgrade compatibility gate:

```bash
cd extension
./scripts/check-release-downgrade.sh --from-ref HEAD^
```

This uses the same package pair and prefix model, but proves the reverse
install order explicitly instead of assuming downgrade symmetry.

Run the persisted-state migration compatibility gate:

```bash
cd extension
./scripts/check-persistence-migration.sh --from-ref HEAD^
```

This writes representative persisted state with the previous package and then
verifies that the current package can rehydrate that state correctly.

Run the old/new configuration-state compatibility matrix:

```bash
cd extension
./scripts/check-config-compatibility-matrix.sh
```

This proves that the packaged runtime still accepts the representative legacy
flat override aliases, the current namespaced override form, and the inherited
system INI snapshot without state drift between those paths.

Run the published-container smoke matrix across supported runtime images:

```bash
cd extension
./scripts/container-smoke-matrix.sh --php-versions 8.1,8.2,8.3,8.4,8.5
```

Run the final repo-local go-live readiness gate:

```bash
cd extension
./scripts/go-live-readiness.sh
```

Benchmark the canonical runtime paths:

```bash
./benchmarks/run-canonical.sh
```

Write a local baseline and compare against it:

```bash
./benchmarks/run-canonical.sh --write-baseline benchmarks/results/local-baseline.json
./benchmarks/run-canonical.sh --baseline benchmarks/results/local-baseline.json
```

If you touch the PHP stub surface, also run:

```bash
cd extension
./scripts/check-stub-parity.sh
```

Explicit local build profiles are available through:

```bash
cd extension
./scripts/build-profile.sh release
./scripts/build-profile.sh debug
./scripts/build-profile.sh asan
./scripts/build-profile.sh ubsan
```

These profile builds own the canonical QUIC bootstrap path.
They bootstrap the pinned `quiche` checkout recorded in
`extension/scripts/quiche-bootstrap.lock` and are expected to fail closed if
that pinned dependency set cannot be rehydrated exactly.
Do not reintroduce ad hoc local clones, branch-based dependency rewrites, or
unlocked cargo fallbacks around them.

Smoke the staged profile artifact after each non-release build:

```bash
cd extension
./scripts/smoke-profile.sh debug
./scripts/smoke-profile.sh asan
./scripts/smoke-profile.sh ubsan
```

Run the long-duration sanitizer soak gates against the staged artifacts:

```bash
cd extension
./scripts/soak-runtime.sh asan --iterations 5 --artifacts-dir ../soak-artifacts/asan
./scripts/soak-runtime.sh ubsan --iterations 5 --artifacts-dir ../soak-artifacts/ubsan
./scripts/soak-runtime.sh leak --iterations 3 --artifacts-dir ../soak-artifacts/leak
```

Each soak retains `summary.txt`, the exact PHPT subset, per-iteration logs, and
failure diagnostics under the selected artifacts directory. CI uses the same
entrypoint and uploads those retained artifacts when a soak gate fails.

If CI drifts from these commands, fix the workflow to match the scripts instead
of introducing another build entry point.
Do not revive `infra/scripts/benchmark.sh`; the canonical benchmark entrypoint
is `benchmarks/run-canonical.sh`, and `make benchmark` is expected to delegate
to it directly. The same rule now applies to static checks and profile builds:
the canonical repo-local paths are `extension/scripts/static-checks.sh`,
`extension/scripts/build-profile.sh`, `extension/scripts/smoke-profile.sh`,
`extension/scripts/fuzz-runtime.sh`,
`extension/scripts/check-stub-parity.sh`,
`extension/scripts/package-release.sh`,
`extension/scripts/install-package-matrix.sh`,
`extension/scripts/container-smoke-matrix.sh`,
`extension/scripts/soak-runtime.sh`,
`extension/scripts/verify-release-package.sh`, and
`extension/scripts/go-live-readiness.sh`.

## Workflow

### 1. Start from the right document

Before changing code, align the change against the right file:

- product meaning or permanent system description: `README.md`
- stable charter or exit criteria: `EPIC.md`
- current verified implementation state: `PROJECT_ASSESSMENT.md`
- active roadmap and execution queue: `ISSUES.md`
- long-form completion tracker: `READYNESS_TRACKER.md`
- actual code and tests: `extension/` and `stubs/`

### 2. Change the kernel first

When a feature has both procedural and OO entry points, the native kernel comes
first. Wrappers, aliases, arginfo, and docs move after the kernel is real.

### 2a. Keep headers where they belong

Project-owned C headers belong under `extension/include/`.
Do not park new runtime headers under `extension/` or `extension/src/` just
because the local compiler can find them.

The only normal exception is generated `extension/config.h`.
If a change adds or moves headers, update the build and packaging paths in the
same change so `extension/include/` stays the canonical include root.

### 3. Move docs with reality

If runtime behavior changes, update the relevant repo-local docs in the same
change. Do not leave README, EPIC, PROJECT_ASSESSMENT, ISSUES, stubs, and
tests describing different systems.

### 4. Verify before claiming done

A feature or fix is only done when:

- the code builds
- affected tests pass
- changed public surface is reflected in stubs and docs
- no stale claim remains in repo-local documentation

## Documentation Rules

### README

`README.md` is intentionally stable.
Do not use it as:

- a changelog
- a migration diary
- an issue tracker
- a current-state verification dump

If information is volatile, it belongs somewhere else.

### EPIC

`EPIC.md` is the stable charter layer.
Update it only when:

- the program charter changes
- the release-level exit criteria change
- the product boundary changes

### ISSUES

`ISSUES.md` is the single moving roadmap and active queue.
This is where current open fronts, prioritized leaves, and execution sequencing belong.

### PROJECT_ASSESSMENT

`PROJECT_ASSESSMENT.md` is the current-state document.
This is where verified implementation reach, current limits, and the last known
green baseline belong.

### READYNESS_TRACKER

`READYNESS_TRACKER.md` is the long-form closure tracker.
It can keep broad completion criteria and checked-vs-open slices, but it does
not replace `ISSUES.md` as the narrow execution queue.

## Code and Review Expectations

### Runtime work

Prefer:

- explicit native state
- narrow helper functions
- stable validation and error contracts
- shared kernels for procedural and OO surfaces

Avoid:

- duplicate logic across entry points
- fake parity where one surface is real and the other is a stub
- hidden global state
- undocumented ownership transfer

### Review bar

A change is not high-quality just because it compiles.
The review bar is:

- technically coherent
- lifecycle-safe
- policy-consistent
- test-backed
- documentation-backed

## Tests

PHPTs are the primary verification surface for extension behavior.

When changing runtime behavior:

- add or update focused PHPTs
- preserve negative-path coverage
- keep direct, dispatcher, and OO contracts aligned where relevant

Prefer small targeted tests over broad vague ones.

## Generated Artifacts

Do not treat generated build outputs as source.

Examples of generated or local-only noise include:

- `extension/.libs/`
- `extension/autom4te.cache/`
- `extension/modules/*.so`
- `extension/src/**/*.dep`
- `extension/src/**/*.lo`
- PHPT side artifacts such as `.diff`, `.exp`, `.log`, `.out`, `.php`, `.sh`

If a change requires a generated file to be committed, that should be explicit
and justified, not accidental.

## Archived Sources

`extension/src_bak/` is archived reference material, not the active runtime.

Do not:

- wire new behavior to `src_bak`
- treat archived files as active implementation
- describe archived code as if it were part of the build

Ports only count when the active build compiles and tests the new path.

## Commit Discipline

Keep commits coherent.
One commit should represent one understandable unit of change.

Good commit scopes:

- one runtime slice
- one documentation reframing
- one test matrix expansion
- one policy cleanup

Bad commit scopes:

- unrelated code plus unrelated docs plus opportunistic cleanup
- generated noise mixed with source edits
- speculative API surface with no backend

## When in Doubt

Use the stricter rule:

- prefer explicitness over convenience
- prefer fewer claims over overstated claims
- prefer real runtime over wrapper inflation
- prefer smaller, verifiable leaves over broad vague changes
