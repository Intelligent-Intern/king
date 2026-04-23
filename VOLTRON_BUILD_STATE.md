# Delphi Build State

Last updated: 2026-04-23 (America/Chicago)
Branch: `experiments/1.0.7-voltron`

## Current Snapshot

1. DAG-shaped orchestrator submission support is implemented via submission-time normalization:
   - accepts top-level `steps` graph form
   - honors per-step `id` + `deps`
   - validates duplicates, unknown deps, self-deps, and cycles
   - normalizes to deterministic topological step order before persistence/execution
2. New DAG contract test is present:
   - `extension/tests/707-orchestrator-dag-topological-scheduling-contract.phpt`
3. Delphi M0 scaffold is present:
   - `demo/userland/voltron/src/M0Scaffold.php`
   - `demo/userland/voltron/README.md`
4. Orchestrator docs include graph submission semantics and the explicit scope caveat (control-plane normalization, not fine-grained parallel runtime).
5. Voltron model-agnostic partitioner is implemented:
   - `demo/userland/voltron/src/ModelConfig.php` - model block schema definitions for Gemma2B, Gemma7B
   - `demo/userland/voltron/src/ModelPartitioner.php` - partition any model into DAG of blocks
   - `demo/userland/voltron/src/VoltronConnector.php` - wire to King infra (orchestrator + semantic DNS)
   - `demo/userland/voltron/contracts/700-voltron-model-partitioner-contract.php` - contract tests

## Verified Build/Test Status

1. Release extension build succeeded:
   - command: `cd extension && ../infra/scripts/build-profile.sh release`
   - output artifact: `extension/modules/king.so`
2. Targeted PHPT checks passed:
   - `tests/707-orchestrator-dag-topological-scheduling-contract.phpt`
   - `tests/594-orchestrator-userland-terminal-state-visibility-contract.phpt`
3. `php -l demo/userland/voltron/src/M0Scaffold.php` passed.
4. PHP lint passed for all new Voltron files:
   - `demo/userland/voltron/src/ModelConfig.php`
   - `demo/userland/voltron/src/ModelPartitioner.php`
   - `demo/userland/voltron/src/VoltronConnector.php`
5. Voltron contract test passed (7/7):
   - ModelConfig::gemma2B() is valid
   - ModelConfig::gemma7B() is valid
   - Gemma2B block schema has no cycles
   - ModelPartitioner::partition produces DAG
   - ModelPartitioner respects memory constraints
   - Partition respects block dependencies
   - VoltronConnector builds valid pipeline

## Git State (Most Recent Work)

1. Commit:
   - `ca16a3a` - `Add DAG submission normalization and Delphi M0 scaffold`
2. New commits (to be staged):
   - Voltron model-agnostic partitioner implementation
   - ModelConfig with Gemma2B/Gemma7B block schemas
   - ModelPartitioner for DAG-based block partitioning
   - VoltronConnector to King infra
   - Contract tests
3. Pushed to fork:
   - `origin/experiments/1.0.7-voltron`
4. Push policy for this branch:
   - do not push to `upstream`
   - push only to fork remote (`origin`, `sashakolpakov`) unless explicitly overridden by the user.

## Voltron Architecture (Voltron-style Model Parallelism)

The implementation follows the "dismember" pattern from VOLTRON_PLANNING.md:

1. **ModelConfig** defines model block schemas (model-agnostic):
   - embed, attention, ffn, output_head block types
   - layer ranges, memory requirements, dependencies
   - built-in configs for Gemma2B, Gemma7B

2. **ModelPartitioner** partitions model into block DAG:
   - topological sort of blocks
   - node capability matching (memory, role capabilities)
   - generates orchestrator steps with correct deps

3. **VoltronConnector** wires to King infra:
   - registers node via Semantic DNS
   - discovers cluster nodes
   - builds pipeline from node capabilities
   - registers tool handlers with orchestrator

4. **Execution flow**:
   ```
   input → embed → attention → ffn → attention → ffn → ... → output_head
   ```
   Each block runs as separate orchestrator step with artifact refs passed between them.

## Non-Negotiable Stack (Used)

- IIBIN: artifact refs for tensor payloads
- Semantic DNS: node capability registration/discovery
- Pipeline Orchestrator: step execution with DAG dependencies
- Object Store: checkpoint/activation staging (integrated, not yet fully wired)

