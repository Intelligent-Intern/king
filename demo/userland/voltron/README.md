# Voltron - Distributed Model Partitioning via King Infra

Partition and run LLM models across the King compute mesh.

## Quick Start

```bash
# From repo root:
php -d extension=extension/modules/king.so demo/userland/voltron/voltron.php "What is 2+2?"
php -d extension=extension/modules/king.so demo/userland/voltron/voltron.php "Explain quantum computing" --dag

# Remote-peer controller mode (requires a remote peer server):
php -d extension=extension/modules/king.so \
  -d king.orchestrator_execution_backend=remote_peer \
  -d king.orchestrator_remote_host=127.0.0.1 \
  -d king.orchestrator_remote_port=9444 \
  demo/userland/voltron/voltron.php "Explain AI" --backend=remote_peer --dag

# Explicit scheduler peers:
php -d extension=extension/modules/king.so demo/userland/voltron/voltron.php \
  "Explain AI" --dag --peers=peer-a,peer-b

# Or from the voltron directory:
cd demo/userland/voltron
php -d extension=../../../extension/modules/king.so voltron.php "What is 2+2?"
php -d extension=../../../extension/modules/king.so voltron.php "Explain quantum computing" --dag
```

## Usage

```
voltron.php "your question" [model] [--dag] [--backend=local|remote_peer] [--trace-id=id] [--peers=peer-a,peer-b]
voltron.php "?" --dag
```

## Examples

```bash
php -d extension=.../king.so voltron.php "What is 15 + 27?"
php -d extension=.../king.so voltron.php "Write a Python hello world"
php -d extension=.../king.so voltron.php "What is the capital of France?" --dag
```

## The DAG

The `--dag` flag shows the computational hotpath:

```
═══ Computational DAG (Hotpath) ═══
   0 │ embed                ← (root)
   1 │ attention_1          ← embed
   2 │ ffn_1                ← attention_1
   3 │ attention_2          ← ffn_1
   4 │ ffn_2                ← attention_2
   5 │ attention_3          ← ffn_2
   6 │ ffn_3                ← attention_3
   7 │ attention_4          ← ffn_3
   8 │ ffn_4                ← attention_4
   9 │ output_head          ← ffn_4
═══ Execution Order ═══
  → voltron.execute_block.embed
  → ... (10 blocks in sequence)
```

Each block runs as a King orchestrator step with dependency tracking.

## Two Peers (Same Machine)

Run Voltron with two peer processes (`peer-a`, `peer-b`) and one controller submission:

```bash
# From repo root:
php demo/userland/voltron/two-client-demo.php "Explain AI"
```

What this does:
- starts `peer-b` server
- starts `peer-a` server with downstream forwarding to `peer-b`
- controller submits one `remote_peer` run to `peer-a`
- scheduler assigns block owners (`peer-a` first half, `peer-b` second half)
- each step writes artifact refs through object-store primitives and includes IIBIN artifact-ref encoding metadata
- prints controller output plus both peer captures

Manual split run (real terminals):

1. Terminal A (Peer A):
```bash
php -d king.security_allow_config_override=1 -d extension=extension/modules/king.so demo/userland/voltron/remote_peer_server.php \
  /tmp/voltron-peer-a-capture.json 9444 127.0.0.1 demo/userland/voltron/remote_peer_bootstrap.php \
  peer-a 127.0.0.1 9445
```

2. Terminal B (Peer B):
```bash
php -d king.security_allow_config_override=1 -d extension=extension/modules/king.so demo/userland/voltron/remote_peer_server.php \
  /tmp/voltron-peer-b-capture.json 9445 127.0.0.1 demo/userland/voltron/remote_peer_bootstrap.php \
  peer-b
```

3. Controller submit:
```bash
php -d extension=extension/modules/king.so \
  -d king.orchestrator_execution_backend=remote_peer \
  -d king.orchestrator_remote_host=127.0.0.1 \
  -d king.orchestrator_remote_port=9444 \
  demo/userland/voltron/voltron.php "Explain AI" --backend=remote_peer --dag --peers=peer-a,peer-b
```

If object-store config overrides are locked down in your environment, add `-d king.security_allow_config_override=1` to peer processes.

## Batch Smoke Questions + Log Dump

Run a mixed prompt set (general + coding) and write full runner output to a log file:

```bash
# From repo root:
php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php

# With DAG output and custom log file:
php -d extension=extension/modules/king.so demo/userland/voltron/smoke_questions.php \
  --dag --log=demo/userland/voltron/logs/voltron-smoke.log
```

Default question set:
- General: explain AI, geography fact, time-management tips
- Coding: Python palindrome function, binary-search Big-O + JS snippet, SQL top customers query

The script exits non-zero if any prompt run fails.

## Contract Smoke Test

```bash
php -d extension=extension/modules/king.so demo/userland/voltron/contracts/710-voltron-runner-smoke-contract.php
```

## Architecture

- **ModelConfig**: block schemas (Qwen2.5: 10 blocks)
- **ModelPartitioner**: partitions model → DAG of steps
- **VoltronScheduler**: discovers peers and assigns step ownership for orchestrator execution
- **VoltronHandlers**: shared King userland handler contract for local and remote-peer execution
- **VoltronRunner**: executes DAG via King orchestrator and emits the final demo response from the `output_head` step

## Build Status

- Partition: 10 blocks + emit_final = 11 steps ✓
- Dependencies: topological order ✓
- Scheduler: peer discovery + block ownership assignment for orchestrator ✓
- Orchestrator: DAG execution via King (remote_peer) ✓
- Exchange: object-store artifact refs + IIBIN artifact ref metadata in peer trace ✓
- Response: generated by the `output_head` handler and returned through `voltron.emit_final` ✓
