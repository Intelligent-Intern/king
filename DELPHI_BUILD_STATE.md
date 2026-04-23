# Delphi Build State

Last updated: 2026-04-22 (America/Chicago)
Branch: `experiments/1.0.7-delphi`

## Current Snapshot

1. DAG-shaped orchestrator submission support is implemented via submission-time normalization:
   - accepts top-level `steps` graph form
   - honors per-step `id` + `deps`
   - validates duplicates, unknown deps, self-deps, and cycles
   - normalizes to deterministic topological step order before persistence/execution
2. New DAG contract test is present:
   - `extension/tests/707-orchestrator-dag-topological-scheduling-contract.phpt`
3. Delphi M0 scaffold is present:
   - `demo/userland/delphi/src/M0Scaffold.php`
   - `demo/userland/delphi/README.md`
4. Orchestrator docs include graph submission semantics and the explicit scope caveat (control-plane normalization, not fine-grained parallel runtime).

## Verified Build/Test Status

1. Release extension build succeeded:
   - command: `cd extension && ../infra/scripts/build-profile.sh release`
   - output artifact: `extension/modules/king.so`
2. Targeted PHPT checks passed:
   - `tests/707-orchestrator-dag-topological-scheduling-contract.phpt`
   - `tests/594-orchestrator-userland-terminal-state-visibility-contract.phpt`
3. `php -l demo/userland/delphi/src/M0Scaffold.php` passed.

## Git State (Most Recent Work)

1. Commit:
   - `ca16a3a` - `Add DAG submission normalization and Delphi M0 scaffold`
2. Pushed to fork:
   - `origin/experiments/1.0.7-delphi`
3. Push policy for this branch:
   - do not push to `upstream`
   - push only to fork remote (`origin`, `sashakolpakov`) unless explicitly overridden by the user.

