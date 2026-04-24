# Voltron Build State

Last updated: 2026-04-24 (America/Chicago)
Branch: `experiments/1.0.7-voltron`

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Voltron nodes form a compute mesh where each node
runs a subset of model layers. The King orchestrator DAG coordinates execution across
nodes, and `king_gguf_tensor_scan` provides native C tensor ops.

## Architecture: Voltron as DAG Orchestrator Nodes

Each Voltron node is a **King pipeline orchestrator** that:
1. Executes model layer blocks via DAG scheduling
2. Communicates with peer nodes via TCP (IIBIN wire protocol)
3. Uses Semantic DNS for node discovery
4. Stores activations/checkpoints in Object Store

```
┌─────────────────────────────────────────────────────────────────┐
│                        King Orchestrator                         │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐             │
│  │  llama.cpp  │  │  llama.cpp  │  │  llama.cpp  │             │
│  │  Shard 0    │  │  Shard 1    │  │  Shard 2    │   ...       │
│  │  Layers 0-5 │  │ Layers 6-11 │  │Layers 12-17 │             │
│  │  (port 9700)│  │ (port 9701) │  │ (port 9702) │             │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘             │
│         │                │                │                     │
│         └────────────────┴────────────────┘                     │
│                     TCP/JSON Protocol                            │
└─────────────────────────────────────────────────────────────────┘
```

## Current Snapshot

### Distributed Llama.cpp Shards (NEW 2026-04-24)

**APPROACH CHANGED**: Instead of PHP LayerWorker (which couldn't match llama.cpp correctness),
we now spawn actual llama.cpp server instances as workers. Each server handles a layer range.

- `LlamaCppShardServer.php` - PHP wrapper around llama-server processes (ports 9700+)
- `LlamaCppShardOrchestrator.php` - Orchestrates shard spawning and health checking
- `run_sharded_inference.php` - Demo script

### Why PHP LayerWorker Failed

The PHP implementation of Qwen2 attention and FFN couldn't match llama.cpp output:
- RMSNorm, RoPE, attention math diverged from C++ implementation
- GQA (8 heads query, 2 heads KV) required correct cross-head attention
- Float precision accumulation caused drift across 36 layers
- Output was garbage tokens instead of coherent text

**Solution**: Delegate to actual llama.cpp binaries instead of reimplementing in PHP.

### Verified Build/Test Status

1. **llama-server builds**: `/tmp/llama.cpp/build/bin/llama-server` ✓

2. **Shard spawning**:
   - 6 shards spawn on ports 9700-9705
   - Each serves layers 0-5, 6-11, 12-17, 18-23, 24-29, 30-35
   - Health checks pass ✓

3. **Direct inference test**:
   - `curl localhost:9700/infill` works ✓

4. **Native GGUF scan**: `king_gguf_tensor_scan` still available for embed lookups ✓

## Git State

1. Latest commit: cleanup + new shard orchestration code
2. Pushed to: `origin/experiments/1.0.7-voltron`

## Voltron Node Architecture (CURRENT)

Each Voltron node now runs:

1. **llama.cpp server process** - actual model execution
   - Each process loads full GGUF but only processes assigned layers
   - Communicates via HTTP/TCP on assigned port
   - Actions: `embed`, `forward`, `generate`, `health`

2. **LlamaCppShardServer** (PHP) - wrapper/metadata server
   - Provides shard coordination info
   - Health checking
   - Protocol: 4-byte length header + JSON request

3. **King Pipeline Orchestrator** - DAG execution
   - Chains requests across shard ports
   - Sample tokens from final hidden state

## Layer Distribution

For Qwen2.5-coder:3b (36 layers):
```
Node 0 (port 9700): layers 0-5
Node 1 (port 9701): layers 6-11
Node 2 (port 9702): layers 12-17
Node 3 (port 9703): layers 18-23
Node 4 (port 9704): layers 24-29
Node 5 (port 9705): layers 30-35
```

## Issues and Limitations

1. **NOT ACTUALLY DISTRIBUTED YET**: Shards run locally, not across network nodes
2. **No KV cache transfer**: Each llama-server maintains own KV cache, no sharing
3. **No cross-shard communication**: Hidden states passed through PHP orchestrator
4. **PHP bottleneck**: Still using PHP for layer chaining, not native IIBIN

## M0 Status

**REGRESSED**: We went from "PHP LayerWorker somewhat working" to "delegating to llama.cpp"
but haven't yet wired the distributed path to produce correct output.

What's working:
- llama-server binary runs
- Shards spawn and health-check pass

What's broken:
- Haven't tested full generation loop through shards
- No IIBIN protocol for shard communication
- No real multi-node deployment

## Next Steps Required

1. Wire PHP orchestrator to call each shard via HTTP
2. Pass hidden states between shards correctly  
3. Implement output_norm + lm_head at end of chain
4. Test full generation produces readable output
5. Then: add remote-peer dispatch for actual multi-node