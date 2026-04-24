# Voltron Build State

Last updated: 2026-04-23 (America/Chicago)
Branch: `experiments/1.0.7-voltron`

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Weights come from Ollama's GGUF copy of Qwen2.5-coder:3b.
Voltron's partitioner divides the model into blocks, the King orchestrator executes them
distributed across peers, and `king_gguf_tensor_scan` provides native C tensor ops.
The goal is full Qwen-equivalent output, distributed.

## Current Snapshot

1. Voltron model-agnostic partitioner is implemented:
   - `ModelConfig.php` - Qwen2.5 block schema (model-agnostic block types)
   - `ModelPartitioner.php` - partition any model into DAG of blocks
   - `VoltronScheduler.php` - peer discovery + deterministic block ownership assignment
   - `VoltronHandlers.php` - shared King handler contract for local + remote-peer
   - `VoltronRunner.php` - executes partitioned blocks via King orchestrator
   - `remote_peer_server.php` - local remote-peer server for two-client same-machine runs
   - `two-client-demo.php` - controller + peer orchestration demo runner
   - `smoke_questions.php` - mixed general/coding batch runner with log file output
   - `contracts/700-voltron-model-partitioner-contract.php` - partitioner contract tests
   - `contracts/710-voltron-runner-smoke-contract.php` - runner smoke + provenance contract
   - `contracts/730-voltron-native-gguf-contract.php` - native GGUF scan contract

## Verified Build/Test Status

1. Voltron partitioner contract test passes (5/5)
2. Voltron runner smoke contract runs with King extension:
   - loops terminate via `decode_stop` flag propagated through orchestrator
   - source provenance is `king_voltron_handler`
3. VoltronRunner executes Qwen2.5 partitioned via King orchestrator:
   - 37 steps (36 transformer blocks + embed + output_head, DAG with topological deps)
   - Block execution: embed → attention → ffn → ... → output_head per token
   - Decode iteration loop: runs N tokens per prompt (max_tokens bounded)
4. Two-client same-machine remote-peer run passes:
   - controller backend: `remote_peer`
   - topology scope: `tcp_host_port_execution_peer`
   - scheduler assigns block ownership across `peer-a` and `peer-b`
5. Native GGUF scan contract passes (3/3):
   - `king_native_gguf_tensor_scan` available
   - F32 row projection returns expected dot products
   - top-k scan preserves row IDs in descending score order
6. Batch smoke run supports prompt suites with log dumping

## Git State (Pushed)

1. Latest commit:
   - `29fad1f` - `Rename delphi to voltron: model-agnostic partitioner implementation`
2. Pushed to fork:
   - `origin/experiments/1.0.7-voltron`
3. Push policy for this branch:
   - do not push to `upstream`
   - push only to fork remote (`origin`, `sashakolpakov`) unless explicitly overridden by the user.

## Voltron Architecture (Voltron-style Model Parallelism)

The implementation follows the "dismember" pattern from VOLTRON_PLANNING.md:

1. **ModelConfig** defines model block schemas (model-agnostic):
   - embed, attention, ffn, output_head block types
   - layer ranges, memory requirements, dependencies
   - current active config: Qwen2.5-coder 3B (36 blocks, 151936 vocab, mixed Q4_K/Q6_K)

2. **ModelPartitioner** partitions model into block DAG:
   - topological sort of blocks
   - node capability matching (memory, role capabilities)
   - generates orchestrator steps with correct deps

3. **VoltronHandlers** define shared execution handlers:
   - `voltron.execute_model_block`
   - `voltron.emit_final`
   - same handler code used by local runner and remote peer bootstrap

4. **VoltronScheduler** drives orchestrator ownership:
   - discovers peer IDs (caller hints, semantic DNS if available, deterministic fallback)
   - assigns model block step IDs across discovered peers
   - exports schedule into orchestrator run options (`voltron_schedule`) for peer runtimes to honor

5. **Execution flow**:
   ```
   input → embed → attention → ffn → attention → ffn → ... → output_head
   ```
   Each block runs as separate orchestrator step with explicit DAG dependencies and scheduled owner peer.

## King Extension Public API Policy

The King extension public surface is implemented in C and remains disk-backed. PHP
decode loops are not used. Public API functions:

1. **Primary**: `king_gguf_tensor_scan` - disk-backed GGUF tensor scan with native decode
   - Supported tensor types: F32 (0), F16 (1), Q8_0 (8), Q4_K (12), Q6_K (14)
   - Projects input vector against tensor rows; returns all scores or top-k
   - Top-k rescans once for accurate ranking
   - Used by VoltronKernels for embedding projection, attention projection, FFN projection, and logits scoring
2. **Compatibility alias**: `king_native_gguf_tensor_scan` - same implementation, for Voltron migration stability

### Alias Cleanup Rule

Any `DEPRECATED` or compatibility alias for the public surface:
- MUST be removed once Voltron migrates off it
- Replacement path: direct use of `king_gguf_tensor_scan`
- Track removal in cleanup pass, not left as permanent dead code

## Non-Negotiable Stack (Used)

- IIBIN: artifact refs for tensor payloads
- Semantic DNS: node capability registration/discovery
- Pipeline Orchestrator: step execution with DAG dependencies
- Object Store: checkpoint/activation staging (integrated, not yet fully wired)
- King Extension: native C GGUF tensor scan (`king_gguf_tensor_scan`), disk-backed, supports F32/F16/Q8_0/Q4_K/Q6_K

## Known Correctness Issues (Must Fix Before Full Functionality)

1. **Shared embedding** - Qwen models use `token_embd.weight` as BOTH embedding and lm_head (shared weight pattern). VoltronKernels resolves output tensor to `token_embd.weight` but applies `hidden_indices` sampling, which corrupts logits. Fix: use full embedding row for lm_head, no sampling.
2. **Tokenizer model-type detection** - Model tokenizer detected as "gpt2" not Qwen's vocab format. `decodeId` returns empty strings for many tokens because byte-decoder mapping doesn't match token vocabulary encoding. Fix: detect vocab encoding from token types and use correct decode path.
3. **Format prompt not applied** - `VoltronTokenizer::formatPrompt()` checks `$this->pre !== 'qwen2'` but model does not set the pre-field, so chat template (`<|im_start|>...<|im_end|>`) is never injected.

## Known Performance Issues

1. **Slow per-token decode** - ~9s/token on Qwen2.5-coder:3b with 36 blocks, 151936 vocab, mixed Q4_K/Q6_K
2. **PHP weight projection** - native scan handles F32/Q4_K/Q6_K projection correctly; remaining bottleneck is PHP overhead in orchestrator step dispatch and state management per token
3. **No SIMD or threading** - extension operates single-threaded on single core; future: batch inference, multi-threaded projection, or CUDA offload

## M0 Status

1. M0 (single-machine deterministic) is the current target
2. Gate B from VOLTRON_PLANNING.md is in scope; Gates C/D (multi-node) are not yet started
3. Gossip mesh is not yet ported from `experiments/1.0.7-gossip-mesh`