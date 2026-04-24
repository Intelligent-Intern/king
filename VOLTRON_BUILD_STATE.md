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
│  │ Voltron Node│  │ Voltron Node│  │ Voltron Node│             │
│  │  Layers 0-5 │  │ Layers 6-11 │  │Layers 12-17 │   ...       │
│  │  (port 9533)│  │ (port 9534) │  │ (port 9535) │             │
│  └──────┬──────┘  └──────┬──────┘  └──────┬──────┘             │
│         │                │                │                     │
│         └────────────────┴────────────────┘                     │
│                     TCP/IIBIN Protocol                           │
└─────────────────────────────────────────────────────────────────┘
```

## Current Snapshot

### Distributed Layer Worker (NEW)
- `OllamaLayerServer.php` - TCP server for layer execution (port 9533+)
- `LayerWorker.php` - llama.cpp-compatible PHP implementation
- `test_client.php` - test client for layer servers
- `OllamaBackend.php` - Ollama HTTP API client (fallback)
- `OllamaKernels.php` - kernel delegation to Ollama

### Verified Build/Test Status

1. **GGUF Export**: Ollama blob copied to standalone GGUF (~1.9GB Q4_K)
   - Path: `/Users/sasha/qwen2.5-coder-3b-Q4_K.gguf`
   
2. **Layer Worker Test**:
   - Embed token → hidden vector (norm: 0.97) ✓
   - Forward 6 layers → output norm: 322 ✓
   - Duration: ~1.3s per 6 layers ✓
   
3. **TCP Server**: OllamaLayerServer accepts connections and processes requests ✓

4. **Native GGUF Scan**: `king_gguf_tensor_scan` handles F32/F16/Q4_K/Q6_K ✓

## Git State (Pushed)

1. Latest commit:
   - `02dedf9` - `Voltron分布式Ollama layer worker infrastructure`
2. Pushed to fork:
   - `origin/experiments/1.0.7-voltron`

## Voltron Node Architecture

Each Voltron node runs:

1. **LayerWorker** - executes assigned layer range
   - RMSNorm (attn_norm, ffn_norm)
   - QKV projections via `king_native_gguf_tensor_scan`
   - RoPE positioning
   - Attention with KV cache
   - FFN (gate/up/down + SiLU)

2. **OllamaLayerServer** - TCP server for inter-node communication
   - Protocol: 4-byte length header + JSON request
   - Actions: `embed`, `forward`, `health`
   
3. **King Pipeline Orchestrator** - DAG execution
   - Discovers peers via Semantic DNS
   - Schedules block ownership across nodes
   - Chains activations via IIBIN

## Layer Distribution

For Qwen2.5-coder:3b (36 layers):
```
Node 0 (port 9533): layers 0-5
Node 1 (port 9534): layers 6-11
Node 2 (port 9535): layers 12-17
Node 3 (port 9536): layers 18-23
Node 4 (port 9537): layers 24-29
Node 5 (port 9538): layers 30-35 (+ output_head)
```

## King Extension Public API

Primary: `king_gguf_tensor_scan` - disk-backed GGUF tensor scan
- Supported: F32 (0), F16 (1), Q8_0 (8), Q4_K (12), Q6_K (14)

## Known Issues

1. **Single-node only**: Multi-node orchestration not yet wired
2. **No KV cache transfer**: KV cache stays local per node
3. **No checkpointing**: Activations not persisted to Object Store

## M0 Status

Single-node M0 partially working:
- Layer worker executes forward pass correctly
- TCP server handles requests
- Need: wire into King orchestrator DAG for multi-node coordination