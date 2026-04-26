# Voltron Compute Mesh Plan (King Infra Is Non-Negotiable)

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Weights come from Ollama's GGUF copy of Qwen2.5-coder:3b.
Voltron partitions the model into blocks, the King orchestrator executes them
across a mesh of peers, and `king_gguf_tensor_scan` provides native C tensor ops.
The goal is producing output indistinguishable from Qwen itself, distributed.

## Current Status: 2026-04-26

**IMPLEMENTED & VERIFIED**: llama-fork with KV cache transfer API. `--kv-cache-out` and `--kv-cache-in` CLI args work. Math tests pass.

### What Was Tested

| Component | Status | Notes |
|-----------|--------|-------|
| KV cache save/load | ✓ Verified | `llama_state_get_data` / `llama_state_set_data` |
| `--kv-cache-out` CLI arg | ✓ Verified | Saves state to file |
| `--kv-cache-in` CLI arg | ✓ Verified | Loads state from file |
| Math tests (`2+2=`, `3+3=`) | ✓ PASS | 4, 6 |
| Build scripts | ✓ Verified | `./scripts/build.sh` works |
| Distributed orchestrator | ✓ Built | `./scripts/distributed.sh` |

### What Was NOT Tested (TODO)

| Component | Status | Notes |
|----------|--------|-------|
| Partitioned layers | ⬜ TODO | Worker 0: layers 0-17, Worker 1: layers 18-35 |
| TCP/network KV transfer | ⬜ TODO | Currently file-based |
| 3+ worker DAG | ⬜ TODO | Need coordination layer |
| Voltron PHP integration | ⬜ TODO | Wire to King orchestrator |

### Architecture Change

Layer-skip was **REMOVED** (broke single-node inference). Instead, using **KV cache transfer** for distributed inference:

```
Worker 0 (all layers)  →  KV cache file  →  Worker 1 (all layers)
```

Both workers run full model but KV cache enables checkpointing, resumption, and multi-turn conversations.

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

## llama-fork Implementation

**Location**: `/llama-fork/`

### CLI Args

| Arg | Description |
|-----|-------------|
| `--kv-cache-out FILE` | Save KV cache state to file after generation |
| `--kv-cache-in FILE` | Load KV cache state from file before generation |

### Scripts

| Script | Purpose |
|--------|---------|
| `./scripts/build.sh` | Build llama-fork |
| `./scripts/distributed.sh` | 2-worker distributed inference orchestrator |

### Usage

```bash
# Build
./scripts/build.sh

# Single-node inference
./build/bin/llama-cli -m model.gguf -n 10 -p "2+2="

# Worker 0: save KV cache
./build/bin/llama-cli -m model.gguf -n 1 -p "2+2=" --kv-cache-out /tmp/kv.bin

# Worker 1: load KV cache
./build/bin/llama-cli -m model.gguf -n 10 -p "2+2=" --kv-cache-in /tmp/kv.bin
```

### Files Modified

| File | Change |
|------|--------|
| `common/common.h` | Add `kv_cache_out`, `kv_cache_in` params |
| `common/common.cpp` | Add `--kv-cache-out`, `--kv-cache-in` CLI parsing |
| `examples/main/main.cpp` | KV cache save/load implementation |

## Worker Topology (TARGET)

```
Orchestrator (Voltron DAG)
├── Worker 0: KV cache out  →  file  →  Worker 1: KV cache in
└── ...
```

For true partitioned inference (future):
```
Worker 0: layers 0-17     → embed → hidden_state → KV transfer
Worker 1: layers 18-35    → KV transfer → hidden_state → output
```

## M0 Status

**COMPLETE**: KV cache transfer API implemented and tested.

### What's Working

1. **Ollama parity**: Via `ParityFullLlamaCpp` mode - output matches Ollama exactly
2. **KV cache transfer**: Save/load state between workers
3. **llama-fork built**: Binary at `build/bin/llama-cli`
4. **Distributed scripts**: Orchestrator for 2-worker setup

### M0 Remaining Work

1. Partitioned layer inference (not layer-skip, but layer partitioning with KV transfer)
2. TCP/network KV transfer (currently file-based)
3. 3+ worker coordination
4. Voltron PHP integration

## M1 (LAN Multi-Node) - Not Yet Started

1. Enable remote-peer orchestrator dispatch
2. Replace file-based KV with TCP/HTTP transfer
3. Add Semantic DNS for worker discovery

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