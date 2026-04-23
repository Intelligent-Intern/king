# King Benchmarks

This directory contains the canonical local benchmark harness for the active
King runtime surface.

Use it through the wrapper:

```bash
./benchmarks/run-canonical.sh
```

The wrapper loads the built extension and enables the local config override
policy needed by the measured runtime slices. It does not require a Rust,
Cargo, or Quiche runtime environment.

## Covered Cases

- `session`
  `king_connect()`, `king_get_stats()`, `king_poll()`, `king_cancel_stream()`, `king_close()`
- `proto`
  `king_proto_define_schema()` once, then repeated `king_proto_encode()` / `king_proto_decode()`
- `proto_batch`
  `king_proto_define_schema()` once, then repeated `king_proto_encode_batch()` /
  `king_proto_decode_batch()` over a fixed 32-record set
- `proto_varint`
  schema-defined `uint64` encode/decode over boundary values that exercise the
  active King varint wire helper
- `proto_omega`
  a source-only PHP Elias omega encode/decode control workload over the same
  boundary values as `proto_varint`, so varint-vs-omega performance can be
  compared without adding generated benchmark results to the repo
- `object_store`
  `king_object_store_init()` once, then repeated put/get/cache/delete cycles
  on a benchmark temp root that prefers tmpfs when available
- `semantic_dns`
  one real `king_semantic_dns_init()` / `king_semantic_dns_start_server()`
  bootstrap per sample in local routing mode, then repeated
  `king_semantic_dns_register_service()`, discovery, route, and topology reads
  in steady state

## Baselines

Write a local baseline:

```bash
./benchmarks/run-canonical.sh --write-baseline benchmarks/results/local-baseline.json
```

Compare against an earlier baseline:

```bash
./benchmarks/run-canonical.sh --baseline benchmarks/results/local-baseline.json
```

By default the compare mode allows up to `1.35x` slowdown in `ns/iteration`
before exiting non-zero. Override that with `--max-slowdown=<ratio>`.
Very low `--iterations` values are useful for smoke checks, but they are too
noisy for meaningful non-regression gates; use the defaults or higher counts
when writing and comparing baselines.

For CI or release gating, enforce explicit per-case budgets:

```bash
./benchmarks/run-canonical.sh \
  --iterations=5000 \
  --warmup=500 \
  --samples=3 \
  --budget-file=benchmarks/budgets/canonical-ci.json
```

The committed budget file stores a conservative `max_ns_per_iteration` ceiling
per canonical case so CI can fail on real regressions without depending on a
host-specific raw baseline snapshot.

When a hosted runner is noisy, `--samples=<n>` runs each case multiple times
and keeps the median sample for the actual budget/baseline comparison.

## Useful Flags

- `--case=session,proto`
- `--case=proto_batch`
- `--case=proto_varint,proto_omega`
- `--iterations=500`
- `--warmup=25`
- `--baseline=<path>`
- `--budget-file=<path>`
- `--samples=<n>`
- `--write-baseline=<path>`
- `--max-slowdown=1.20`
- `--json`

`benchmarks/results/` is ignored so local baselines and measurement snapshots do
not dirty the repository.
