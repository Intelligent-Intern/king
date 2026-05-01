# HTTP/3 Regression Evidence

This document records the Batch 3 / `#Q-11` HTTP/3 release gate.

## Active Stack

- Product HTTP/3 uses LSQUIC plus BoringSSL.
- Legacy Quiche binaries, Cargo workspaces, and Quiche loader fallbacks are not part of the active product path.
- Do not reintroduce Quiche artifacts just to rerun old local comparisons. Historical Quiche comparisons must use archived release artifacts outside this repository tree.

## Release Gate

The release gate is defined by:

- `extension/tests/http3_release_regression_matrix.inc`
- `extension/tests/706-http3-release-regression-matrix-contract.phpt`
- `infra/scripts/check-ci-http3-contract-suites.rb`
- the canonical PHPT shards in `.github/workflows/ci.yml`

The matrix maps the sprint checklist to pinned PHPT evidence. It fails if a required HTTP/3 behavior loses its test proof or if the CI suite stops carrying the relevant client/server contracts.

## Checklist Mapping

| Sprint item | Required evidence |
| --- | --- |
| Client one-shot request/response | `190-http3-request-send-roundtrip`, `338-http3-one-shot-churn-isolation` |
| OO `Http3Client` exception matrix | `531-oo-http3-client-public-exception-mapping-contract`, `536-oo-http3-client-error-mapping-matrix-contract` |
| Server one-shot listener | `384-http3-server-listen-on-wire-runtime`, `680-server-http3-lsquic-behavior-contract`, `681-server-http3-lsquic-lifecycle-contract` |
| Session tickets and 0-RTT | `380-http3-session-ticket-reuse-contract`, `503-http3-early-data-session-ticket-contract`, `535-http3-quic-zero-rtt-acceptance-and-fallback-contract` |
| Stream lifecycle, reset, stop-sending, cancel, timeout | `527`, `528`, `529`, `530` HTTP/3 QUIC lifecycle contracts |
| Packet loss, retransmit, congestion, flow control, soak | `487`, `488`, `504`, `533`, `534`, `645` HTTP/3 stress and recovery contracts |
| WebSocket-over-HTTP3 slices | `542-server-websocket-http3-local-honesty`, `682-server-websocket-http3-onwire-honesty-contract` |

## Performance Baseline

The previous Quiche state is preserved as a release baseline through the behavior and stress workloads that mattered for the old stack:

- multi-stream backpressure and fairness under one QUIC session;
- sustained staggered load and repeated mixed-load soak;
- packet loss with visible retransmit counters;
- constrained-link congestion-control coverage for `cubic` and `bbr`;
- flow-control exhaustion and recovery;
- partial-failure recovery rounds.

Those workloads are now pinned to LSQUIC via the release matrix above. The acceptable baseline for this release is no regression below the preserved Quiche-era contract level. Numeric raw benchmark snapshots are host-specific and are not used as shared release truth. CI/release performance gates should use committed budgets, as described in `documentation/dev/benchmarks.md`, without requiring a Rust, Cargo, or Quiche runtime.

## Local Verification

Static and contract-level verification:

```bash
ruby infra/scripts/check-ci-http3-contract-suites.rb
cd extension && php run-tests.php -q tests/706-http3-release-regression-matrix-contract.phpt
```

Full on-wire LSQUIC PHPT verification requires a PHP build with `KING_HTTP3_BACKEND_LSQUIC` and `KING_LSQUIC_LIBRARY` pointing at the packaged LSQUIC runtime. On machines without that runtime, the on-wire PHPTs skip honestly instead of using the old Quiche path.
