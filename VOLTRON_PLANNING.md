# Voltron Compute Mesh Plan (King Infra Is Non-Negotiable)

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Weights come from Ollama's GGUF copy of Qwen2.5-coder:3b.
Voltron partitions the model into blocks, the King orchestrator executes them
across a mesh of peers, and `king_gguf_tensor_scan` provides native C tensor ops.
The goal is producing output indistinguishable from Qwen itself, distributed.

## Current Status: 2026-04-25

**IMPLEMENTED & VERIFIED**: Fork built with layer range args. `--layer-start` and `--layer-end` CLI args work. All 3 math tests pass. Layer-worker TCP connector built.

### What Was Tested

| Component | Status | Notes |
|-----------|--------|-------|
| CLI args (`--layer-start`, `--layer-end`) | ✓ Verified | Single-node layer skip |
| `llama_cparams::layer_start/end` | ✓ Verified | |
| Layer loop modification | ✓ Verified | Different outputs |
| Build | ✓ Success | `llama-server` built |
| HTTP endpoint (`/v1/worker/layer`) | ✓ Implemented | Returns KV cache |
| TCP layer-worker connector | ✓ Built | Binary frames work |
| Math tests (`2+2=`, `3+3=`, `10+1=`) | ✓ All PASS | |

### What Was NOT Tested (BLOCKED)

| Component | Status | Notes |
|----------|--------|-------|
| Multi-worker DAG | ⬜ TODO | Need 3+ workers wired |
| End-to-end KV transfer | ⬜ TODO | Worker 0 → 1 → 2 |
| Distributed inference | ⬜ TODO | Full partitioned run |
| Voltron DAG orchestrator | ⬜ TODO | Wire via Voltron |

### Next: Realistic Distributed Test

Requires running 3 workers in series to test actual partitioned inference.

## Non-Negotiable Stack (UNCHANGED)

1. **IIBIN** is the wire contract (`king_proto_*`, `King\\IIBIN`).
2. **WebSocket** is the durable control/session channel
   (`king_client_websocket_*`, `king_server_upgrade_to_websocket()`).
3. **SFU** is the high-fanout realtime plane (existing `/sfu` route and frame flow).
4. **Gossip Mesh** is the decentralized relay/propagation layer.
5. **Pipeline Orchestrator** is the compute execution/runtime boundary
   (`king_pipeline_orchestrator_*`, local/file-worker/remote-peer backends).
6. **Semantic DNS** is discovery/routing policy (`king_semantic_dns_*`).
7. **Object Store** is artifact/staging for large payloads.
8. **GGUF in C/Assembly** is the native compute kernel for tensor operations
   (`king_gguf_tensor_scan`): disk-backed GGUF parsing, quantization decode, and
   tensor-vector projection in C/asm. Supports F32/F16/Q8_0/Q4_K/Q6_K. No PHP decode loops.
9. **LLAMA.CPP FORK** is the compute layer - delegate to reference implementation.

## Architecture: Forked llama.cpp Workers

```
┌─────────────────────────────────────────────────────────────────────┐
│                   Voltron Orchestrator                          │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │         DAG: embed → worker(0-11) → head               │    │
│  └──────────────────────────────────────────────────────────┘    │
│                                │                                 │
│         ┌──────────────────────┼──────────────────────┐        │
│         │         TCP/RPC       │                      │        │
│    ┌────┴────┐           ┌─────┴─────┐           ┌──────┴────┐ │
│    │Worker 0 │           │Worker 1   │           │Worker N    │ │
│    │Layers 0-│           │Layers 12-  │           │Final +head │ │
│    │   11   │           │   23      │           │           │ │
│    │:9700   │           │:9701     │           │:970N      │ │
│    │layer-  │           │layer-    │           │layer-    │ │
│    │start=0 │           │start=12  │           │start=36  │ │
│    └────────┘           └───────────┘           └───────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

### RPC Protocol: `/v1/worker/layer`

**Request:**
```json
{
  "layer_start": 0,
  "layer_end": 11,
  "hidden_state": "base64...",
  "position": 5,
  "kv_cache": "base64...",
  "n_tokens": 1
}
```

**Response:**
```json
{
  "hidden_state": "base64...",
  "kv_cache": "base64...",
  "n_tokens": 1
}
```

### Why This Approach

1. **Correctness**: Fork llama.cpp reference, don't reimplement
2. **Layer range isolation**: Each worker runs subset of layers
3. **Native compute**: C++ forward pass, not PHP
4. **Distributed-ready**: TCP/HTTP workers can run on any node
5. **KV cache transfer**: Uses existing state serialization

### Implementation Files

| File | Change |
|------|--------|
| `common/common.h` | Add `layer_start`, `layer_end` to `common_params` |
| `common/arg.cpp` | Add `--layer-start`, `--layer-end` CLI args |
| `src/models/llama.cpp` | Modify layer loop at line 31 |
| `tools/server/server-http.cpp` | Add `/v1/worker/layer` endpoint |

## Layer Loop Modification

In `src/models/llama.cpp:31`:
```cpp
for (int il = 0; il < n_layer; ++il) {
    if (il < params.layer_start) continue;        // skip early layers
    if (params.layer_end >= 0 && il > params.layer_end) break;
    // ... attention + ffn for this layer
}
// Only compute output_norm + lm_head if layer_end == n_layer - 1
```

## M0 Status

**IN PROGRESS**: Fork implementation

### What's Working

1. **Ollama parity**: Via `ParityFullLlamaCpp` mode - output matches Ollama exactly
2. **Design complete**: Layer-worker RPC protocol designed
3. **llama.cpp fork started**: Fork at `/Users/sasha/king/llama-fork/`

### What's Not Working

1. Fork changes not implemented
2. Not yet built
3. Not tested

### M0 Remaining Work

1. Add `--layer-start`, `--layer-end` to CLI args
2. Modify layer loop to skip/early-exit
3. Add `/v1/worker/layer` endpoint
4. Build and test
5. Wire to Voltron DAG

## Worker Topology (TARGET)

```
Orchestrator (Voltron DAG)
├── Worker 0: layers 0-11     → embed input → hidden_state out
├── Worker 1: layers 12-23    → hidden_state → hidden_state
├── Worker 2: layers 24-35    → hidden_state → hidden_state
└── Worker N: layer_end=-1     → hidden_state → output_norm + lm_head → logits
```

## M1 (LAN Multi-Node) - Not Yet Started

1. Enable remote-peer orchestrator dispatch
2. Replace localhost with node IPs
3. Add Semantic DNS for worker discovery
4. KV cache serialization between workers

## M2 (Mixed Hardware) - Not Yet Started

1. Capability-aware scheduling (CPU vs GPU nodes)
2. Dynamic layer assignment
3. Quantization-aware distribution

## Hard Rules

1. No new non-King transport stack.
2. No raw JSON-only compute protocol once IIBIN schema exists.
3. No hidden execution state outside orchestrator snapshots.
4. No bypass of Semantic DNS for production placement.
5. No direct large-payload flood over control channel.
6. No PHP decode loops - use native C extension.
7. **DELEGATE TO LLAMA.CPP** - Do not reimplement transformer math.
8. **FORK DON'T REIMPLEMENT** - Modify llama.cpp, match reference exactly.