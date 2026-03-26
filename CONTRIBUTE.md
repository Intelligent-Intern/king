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

Smoke the staged profile artifact after each non-release build:

```bash
cd extension
./scripts/smoke-profile.sh debug
./scripts/smoke-profile.sh asan
./scripts/smoke-profile.sh ubsan
```

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
