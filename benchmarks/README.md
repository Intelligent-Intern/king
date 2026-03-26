# King Benchmarks

This directory contains the canonical local benchmark harness for the active
King runtime surface.

Use it through the wrapper:

```bash
./benchmarks/run-canonical.sh
```

The wrapper loads the built extension, configures the same quiche runtime
environment as the canonical PHPT flow, and enables the local config override
policy needed by the measured runtime slices.

## Covered Cases

- `session`
  `king_connect()`, `king_get_stats()`, `king_poll()`, `king_cancel_stream()`, `king_close()`
- `proto`
  `king_proto_define_schema()` once, then repeated `king_proto_encode()` / `king_proto_decode()`
- `object_store`
  `king_object_store_init()` once, then repeated put/get/cache/delete cycles
- `semantic_dns`
  repeated `king_semantic_dns_init()`, `king_semantic_dns_start_server()`,
  `king_semantic_dns_register_service()`, discovery, route, and topology reads

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
  --budget-file=benchmarks/budgets/canonical-ci.json
```

The committed budget file stores a conservative `max_ns_per_iteration` ceiling
per canonical case so CI can fail on real regressions without depending on a
host-specific raw baseline snapshot.

## Useful Flags

- `--case=session,proto`
- `--iterations=500`
- `--warmup=25`
- `--baseline=<path>`
- `--budget-file=<path>`
- `--write-baseline=<path>`
- `--max-slowdown=1.20`
- `--json`

`benchmarks/results/` is ignored so local baselines and measurement snapshots do
not dirty the repository.
