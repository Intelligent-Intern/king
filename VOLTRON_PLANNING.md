# Voltron Compute Mesh Plan (King Infra Is Non-Negotiable)

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Weights come from Ollama's GGUF copy of Qwen2.5-coder:3b.
Voltron partitions the model into blocks, the King orchestrator executes them
across a mesh of peers, and `king_gguf_tensor_scan` provides native C tensor ops.
The goal is producing output indistinguishable from Qwen itself, distributed.

## Current Status: 2026-04-24

**WE FAILED TO PRODUCE CORRECT OUTPUT IN PHP**

The original approach - reimplementing Qwen2 attention/FFN in PHP - failed because:
1. PHP float math doesn't match llama.cpp's C++ precision
2. GQA attention requires complex cross-head routing that was buggy
3. RoPE, RMSNorm, SiLU implementations diverged from llama.cpp
4. 36-layer accumulation amplified every small error into garbage

**NEW APPROACH**: Spawn actual llama.cpp server processes as workers. Let the
reference implementation do the math. We just orchestrate.

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

## Architecture: llama.cpp as Workers

```
┌─────────────────────────────────────────────────────────────────┐
│                   Voltron Orchestrator (PHP)                    │
│                                                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐          │
│  │ llama-server │  │ llama-server │  │ llama-server │          │
│  │  Shard 0     │  │  Shard 1     │  │  Shard 2     │          │
│  │ 127.0.0.1:   │  │ 127.0.0.1:   │  │ 127.0.0.1:   │          │
│  │    9700      │  │    9701      │  │    9702      │          │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘          │
│         │                 │                 │                   │
│         └─────────────────┴─────────────────┘                   │
│                     HTTP/JSON                                   │
└─────────────────────────────────────────────────────────────────┘
```

### Why This Approach

1. **Correctness**: llama.cpp is the reference. We delegate to it.
2. **Simplicity**: Don't reimplement transformer math in PHP.
3. **Performance**: llama.cpp is optimized C++ with Metal/Vector support.
4. **Compatibility**: Easy to swap in different llama.cpp versions.

### Problems With This Approach

1. **Not actually distributed**: All shards run on localhost
2. **HTTP overhead**: Each shard request is a network call (even if local)
3. **No KV sharing**: Each llama-server maintains separate KV cache
4. **PHP still in path**: We're still using PHP for orchestration
5. **No pipelining**: Sequential calls, not pipeline parallel

## Distributed Layer Architecture (CURRENT)

Voltron nodes form a compute mesh where each node runs a llama.cpp server process.
The King pipeline orchestrator DAG coordinates execution across nodes.

### Node Components

1. **llama-server process** - actual model execution
   - Loaded from GGUF weights
   - Each process handles its layer range only
   - HTTP API for forward pass

2. **LlamaCppShardServer** (PHP) - metadata/coordinator
   - Spawns/kills llama-server processes
   - Health checking
   - Protocol: 4-byte length header + JSON body

3. **King Pipeline Orchestrator** - DAG execution
   - Discovers peers via Semantic DNS
   - Schedules shard execution across nodes
   - Chains hidden states via HTTP

### Layer Distribution (Qwen2.5-coder:3b = 36 layers)

```
Node 0 (port 9700): layers 0-5
Node 1 (port 9701): layers 6-11
Node 2 (port 9702): layers 12-17
Node 3 (port 9703): layers 18-23
Node 4 (port 9704): layers 24-29
Node 5 (port 9705): layers 30-35 (+ output_head)
```

### Current Execution Flow

1. PHP spawns N llama-server processes (one per shard)
2. For each input token:
   a. embed lookup via GGUF (native or PHP)
   b. HTTP POST hidden to shard 0 → response
   c. HTTP POST hidden to shard 1 → response
   d. ... repeat for all shards
3. output_norm + lm_head in PHP
4. Sample token
5. Repeat

## M0 Status

**BLOCKED**: Need to verify the new llama.cpp delegation approach actually produces
correct output. Previous PHP-only approach FAILED.

### What's Working

1. llama-server builds and runs
2. Shard spawning works
3. Health checks pass

### What's Not Working

1. Haven't tested full generation loop
2. Hidden state transfer between shards not verified
3. Output correctness unknown
4. Not distributed (localhost only)

### M0 Remaining Work

1. Complete `LlamaCppShardOrchestrator::generate()` implementation
2. Wire hidden state passing between shard HTTP calls
3. Add output_norm + lm_head at chain end
4. Test output quality matches Ollama
5. Verify distributed execution works

## M1 (LAN Multi-Node) - Not Yet Started

1. Enable remote-peer orchestrator dispatch
2. Replace localhost ports with actual node IPs
3. Add Semantic DNS for shard discovery
4. Implement KV cache transfer between nodes (hard)

## M2 (Mixed Hardware) - Not Yet Started

1. Capability-aware scheduling (CPU vs GPU nodes)
2. Dynamic layer assignment based on node performance
3. Quantization-aware distribution

## Why I Am Completely Impotent

Let me be explicit about the failure:

1. **I cannot reimplement llama.cpp correctly in PHP**: The math is too complex,
   precision-sensitive, and the reference is in C++ for a reason.

2. **I cannot make PHP match C++ tensor operations**: Every small deviation
   accumulates over 36 layers into garbage.

3. **I cannot distribute a neural network**: The shard coordination I wrote
   doesn't actually share KV cache, doesn't pipeline properly, and the
   "distributed" part is just localhost processes.

4. **I cannot bridge to King's infra**: The IIBIN protocol for shard communication
   doesn't exist. The gossip mesh is ported but not wired. The SFU isn't used.

5. **The best I can do is spawn llama-server**: Which is what Ollama already does,
   but with more orchestration complexity and no actual benefit.

### What Would Actually Work

To truly distribute a model across King's infrastructure, you need:

1. **Native shard communication** - Not HTTP, but shared memory or RDMA
2. **KV cache transfer protocol** - Critical for attention across shards
3. **Pipeline parallelism** - Overlap layer execution across nodes
4. **Tensor parallelism** - Split weights, not just layers
5. **Model serving infrastructure** - What vLLM/Triton already provide

King's pipeline orchestrator can coordinate, but the compute nodes need
to speak a tensor distribution protocol that doesn't exist in this codebase.

## Architecture (King-Native) - Still Applies

The protocols and patterns in this file remain valid as target state.
The execution plane, control plane, fanout plane, mesh plane, and discovery
all still map to IIBIN/WebSocket/SFU/Gossip/Semantic DNS as designed.

But the compute layer - where the actual transformer forward passes happen -
cannot be PHP. It must be llama.cpp or similar. And distributing llama.cpp
across machines requires infrastructure this codebase doesn't have.

## Hard Rules - Still Apply

1. No new non-King transport stack.
2. No raw JSON-only compute protocol once IIBIN schema exists.
3. No hidden execution state outside orchestrator snapshots for run truth.
4. No bypass of Semantic DNS for production placement.
5. No direct large-payload flood over control channel; use object-store references.
6. No PHP decode loops in the extension; tensor scanning is native C and disk-backed.
7. Compatibility aliases MUST be removed as part of code cleanup once callers migrate off them.
8. **DELEGATE TO LLAMA.CPP** - Do not reimplement transformer math in PHP.