# Voltron Build State

Last updated: 2026-04-24 (America/Chicago)
Branch: `experiments/1.0.7-voltron`

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone** on King's
native infrastructure. Voltron nodes form a compute mesh where each node runs a subset of model layers.
The King orchestrator DAG coordinates execution across nodes, and `king_gguf_tensor_scan`
provides native C tensor ops.

## Architecture: Layer Worker RPC via llama.cpp Fork

```
┌─────────────────────────────────────────────────────────────────────┐
│                     Voltron Orchestrator                             │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │                   DAG Execution Graph                         │  │
│  │  embed → layer_worker(0-11) → layer_worker(12-23) → head    │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                                │                                     │
│         ┌──────────────────────┼──────────────────────┐        │
│         │                       TCP                       │        │
│    ┌────┴────┐           ┌─────┴─────┐           ┌──────┴─────┐  │
│    │Worker 0 │           │Worker 1   │           │Worker N    │  │
│    │Layers 0-│           │Layers 12-  │           │Final +head │  │
│    │   11   │           │   23      │           │           │  │
│    │:9700   │           │:9701     │           │:970N      │  │
│    └────────┘           └───────────┘           └───────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

## New Design: llama.cpp Fork with Layer Worker RPC

**2026-04-24**: Forked llama.cpp to add layer-range execution for distributed inference.

### Key Files

- `/Users/sasha/king/llama-fork/` - fork repository
- `/Users/sasha/king/llama-fork/DESIGN_layer_worker.md` - full design

### Implementation Path

1. **Add CLI args**: `--layer-start`, `--layer-end` to `common_params`
2. **Modify layer loop**: `src/models/llama.cpp:31` - skip/early-exit based on range
3. **New endpoint**: `POST /v1/worker/layer` for RPC execution
4. **Hidden state transfer**: Base64-encoded float arrays between workers

### Files to Modify

| File | Change |
|------|--------|
| `common/common.h` | Add `layer_start`, `layer_end` fields |
| `src/models/llama.cpp` | Modify layer loop bounds |
| `tools/server/server-http.cpp` | Add `/v1/worker/layer` endpoint |

### RPC Protocol

**Request:**
```json
{
  "layer_start": 0,
  "layer_end": 11,
  "hidden_state": "base64_encodedFloatArray",
  "position": 5,
  "kv_cache": "base64_encoded_or_null",
  "n_tokens": 1
}
```

**Response:**
```json
{
  "hidden_state": "base64_encodedFloatArray",
  "kv_cache": "base64_encoded",
  "n_tokens": 1
}
```

### Layer Loop Modification

In `src/models/llama.cpp:31`:
```cpp
for (int il = 0; il < n_layer; ++il) {
    if (il < params.layer_start) continue;        // skip early layers
    if (params.layer_end >= 0 && il > params.layer_end) break;
    // ... attention + ffn for this layer
}
// Only compute output_norm + lm_head if layer_end == n_layer - 1
```

## Current Status

### Working (Pre-Fork)

1. **Ollama parity**: Via `ParityFullLlamaCpp` execution mode
   - `VoltronExecutionMode.php` controls mode
   - `OllamaBackend.php` calls local Ollama via shell_exec
   - Output tokens match Ollama exactly ✓

2. **PHP LayerWorker deprecated**: Couldn't match llama.cpp output
   - RMSNorm, RoPE, attention math diverged
   - GQA cross-head attention broken
   - Float precision drift across 36 layers

### In Progress (Fork Implementation)

1. **llama.cpp fork**: Analyzing codebase for layer-range modification
   - Layer loop at `src/models/llama.cpp:31`
   - Each layer: attention (QKV + rope + attention) → ffn (gate/up/down)
   - Output: `t_embd` (hidden state) → `t_logits` (vocab probs)

2. **Design complete**: Ready to implement
   - CLI args + layer loop modification identified
   - HTTP endpoint design finalized

### Blocked

- Need to implement fork changes
- Not yet built/tested

## Git State

1. Latest commit: Added Ollama parity mode via VoltronExecutionMode
2. New submodule: `/Users/sasha/king/llama-fork/` - forked llama.cpp
3. Design doc: `/Users/sasha/king/llama-fork/DESIGN_layer_worker.md`

## Worker Topology (TARGET)

```
Orchestrator (Voltron DAG)
├── Worker 0: layers 0-11     → embed → attention → ffn → hidden_state
├── Worker 1: layers 12-23   → attention → ffn → hidden_state
├── Worker 2: layers 24-35   → attention → ffn → hidden_state
└── Worker N: final layers    → output_norm + lm_head → logits → sample
```

## Next Steps

1. **Implement fork**: Add layer_start/layer_end to CLI args
2. **Modify layer loop**: Skip/early-exit based on range
3. **Add RPC endpoint**: POST /v1/worker/layer
4. **Build**: `cmake -DLLAMA_BUILD_SERVER=ON`
5. **Test**: Verify partial layer computation
6. **Integrate**: Wire to Voltron DAG orchestrator

## Build Command (Future)

```bash
cd llama-fork && mkdir -p build && cd build
cmake .. -DLLAMA_BUILD_SERVER=ON
make -j$(nproc)
```

Then run worker:
```bash
./build/bin/llama-server --layer-start 0 --layer-end 11 --model model.gguf
```