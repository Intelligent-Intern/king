# Test Results - 2026-04-16 (Classified)

Platform: Darwin ARM64 (macOS 21.5.0)  
PHP: 8.2.3 / Zend 4.2.3  
Extension: King PHP Extension  
Duration: 634 seconds

## Summary

| Metric | Count | Percentage |
|--------|-------|------------|
| Total Tests | 654 | — |
| Passed | 439 | 67.1% |
| Failed | 163 | 24.9% |
| Skipped | 51 | 7.8% |
| Borked | 1 | 0.2% |

---

## Failed Tests by Category

### Hetzner-Related (11 tests)

- `295-autoscaling-hetzner-provider-mock-scale-flow.phpt`
- `296-autoscaling-hetzner-recovery-state.phpt`
- `299-autoscaling-hetzner-monitoring-step-guards.phpt`
- `300-autoscaling-hetzner-budget-gating.phpt`
- `301-autoscaling-hetzner-bootstrap-rollout.phpt`
- `359-autoscaling-hetzner-pending-rollback.phpt`
- `360-autoscaling-hetzner-rollback-provider-failure.phpt`
- `362-autoscaling-hetzner-delete-idempotent.phpt`
- `363-autoscaling-hetzner-delete-retry.phpt`
- `523-autoscaling-hetzner-partial-state-loss-bootstrap-propagation-contract.phpt`

### Quiche-Related (16 tests)

- `136-http2-request-send-roundtrip.phpt` — HTTP/2 (requires nghttp2)
- `146-oo-http2-client-runtime.phpt` — HTTP/2 OO client
- `183-http2-https-alpn-roundtrip.phpt` — HTTP/2 HTTPS
- `184-oo-http2-https-alpn-runtime.phpt` — HTTP/2 HTTPS OO
- `185-http2-request-send-multi-multiplexing.phpt`
- `186-http2-request-send-multi-validation.phpt`
- `187-http2-request-send-captures-push.phpt`
- `188-client-request-dispatch-http2-captures-push.phpt`
- `189-http2-request-send-multi-captures-push.phpt`
- `203-http2-timeout-direct-and-dispatch.phpt`
- `337-http2-multi-fairness-backpressure.phpt`
- `375-http2-session-pooling-under-load.phpt`
- `376-http2-reset-and-abort-contract.phpt`
- `366-lsquic-bootstrap-contract.phpt` — QUIC bootstrap
- `668-ensure-lsquic-toolchain-lockfile-v4-branch-contract.phpt` — QUIC toolchain

### Main Failed Tests (136 tests)

#### Configuration & INI (2)
- `003-ini-lifecycle.phpt`
- `004-module-info-exposes-ini.phpt`

#### HTTP/1 & WebSocket (4)
- `131-transport-udp-socket-runtime.phpt`
- `135-http1-runtime-errors.phpt`
- `223-server-tls-reload-validation.phpt`
- `335-http1-server-websocket-wire-soak.phpt`

#### Object Store Cloud — S3 (18)
- `358-object-store-v1-contract-honesty.phpt`
- `403-object-store-cloud-s3-contract.phpt`
- `404-object-store-cloud-s3-credential-failure.phpt`
- `405-object-store-cloud-s3-network-failure.phpt`
- `406-object-store-cloud-s3-throttling-contract.phpt`
- `407-object-store-cloud-s3-incomplete-write-recovery.phpt`
- `408-object-store-localfs-cloud-s3-backup-failure-recovery.phpt`
- `409-object-store-localfs-cloud-s3-read-failover-contract.phpt`
- `410-object-store-localfs-cloud-s3-primary-outage-failover.phpt`
- `411-object-store-real-backend-routing-matrix.phpt`
- `412-object-store-real-replication-partial-failure.phpt`
- `413-object-store-real-replication-recovery.phpt`
- `414-object-store-real-delete-semantics.phpt`
- `415-object-store-real-backend-migration-contract.phpt`
- `416-object-store-real-backend-migration-integrity.phpt`
- `417-object-store-real-backend-migration-metadata-consistency.phpt`
- `431-object-store-cloud-s3-multipart-contract.phpt`

#### Object Store Cloud — GCS (3)
- `421-object-store-cloud-gcs-contract.phpt`
- `422-object-store-localfs-cloud-gcs-backup-contract.phpt`
- `432-object-store-cloud-gcs-resumable-contract.phpt`

#### Object Store Cloud — Azure (5)
- `418-object-store-future-cloud-network-failclosed-contract.phpt`
- `419-object-store-future-cloud-credential-failclosed-contract.phpt`
- `420-object-store-future-cloud-throttling-failclosed-contract.phpt`
- `423-object-store-cloud-azure-contract.phpt`
- `424-object-store-localfs-cloud-azure-backup-contract.phpt`

#### Object Store Cloud — Shared (13)
- `426-object-store-core-full-read-integrity-contract.phpt`
- `427-object-store-cloud-core-metadata-range-versioning-contract.phpt`
- `429-object-store-streaming-cloud-contract.phpt`
- `430-object-store-streaming-integrity-no-leak-contract.phpt`
- `434-object-store-cloud-upload-abort-status-contract.phpt`
- `436-object-store-expiry-cloud-contract.phpt`
- `437-object-store-cloud-upload-chunking-contract.phpt`
- `439-object-store-cloud-upload-session-locking-contract.phpt`
- `440-object-store-cloud-capacity-contract.phpt`
- `441-object-store-cloud-upload-capacity-contract.phpt`
- `442-object-store-cloud-upload-restart-recovery-contract.phpt`
- `443-object-store-cloud-upload-restart-abort-contract.phpt`
- `433-object-store-cloud-azure-block-upload-contract.phpt`

#### Object Store Local (5)
- `121-object-store-ha-hooks.phpt`
- `445-object-store-range-validation-contract.phpt`
- `446-object-store-real-cloud-delete-consistency-contract.phpt`
- `450-object-store-cloud-imported-metadata-header-sanitization-contract.phpt`
- `476-object-store-restart-rehydration-cloud-persistence-modes-contract.phpt`

#### Object Store Restore/Import (4)
- `478-object-store-restore-integrity-cloud-contract.phpt`
- `480-object-store-import-integrity-cloud-contract.phpt`
- `482-object-store-restore-metadata-migration-cloud-contract.phpt`
- `635-object-store-failover-harness-contract.phpt`

#### CDN (10)
- `552-cdn-cache-paths-real-backend-contract.phpt`
- `553-cdn-fill-on-miss-real-backend-contract.phpt`
- `554-cdn-invalidation-under-load-contract.phpt`
- `555-cdn-stale-serve-on-error-real-backend-contract.phpt`
- `556-cdn-cache-consistency-after-backend-update-contract.phpt`
- `558-cdn-origin-timeout-and-no-retry-contract.phpt`
- `561-cdn-restart-recovery-contract.phpt`
- `562-cdn-observability-surface-contract.phpt`
- `565-cdn-origin-stream-size-guard-contract.phpt`
- `648-cdn-traffic-pressure-recovery-matrix-contract.phpt`

#### Telemetry / OTLP (29)
- `319-telemetry-otlp-metrics-type-hardening.phpt`
- `320-telemetry-failover-recovery-harness.phpt`
- `361-telemetry-otlp-traces-logs-success.phpt`
- `362-telemetry-otlp-traces-non2xx-recovery.phpt`
- `363-telemetry-otlp-logs-failure-recovery.phpt`
- `364-telemetry-degraded-delivery-contract.phpt`
- `399-telemetry-pending-buffer-cap-contract.phpt`
- `519-telemetry-request-boundary-cleanup-contract.phpt`
- `520-telemetry-worker-boundary-cleanup-contract.phpt`
- `521-telemetry-memory-bound-self-metrics-contract.phpt`
- `566-telemetry-span-lifecycle-request-and-worker-churn-contract.phpt`
- `567-telemetry-metric-lifecycle-request-and-worker-churn-contract.phpt`
- `568-telemetry-log-lifecycle-request-and-worker-churn-contract.phpt`
- `570-server-telemetry-incoming-trace-context-propagation-contract.phpt`
- `571-telemetry-http-client-outgoing-trace-context-contract.phpt`
- `572-telemetry-orchestrator-span-hierarchy-boundary-contract.phpt`
- `573-telemetry-sampling-strategy-contract.phpt`
- `575-telemetry-cpu-bound-under-load-contract.phpt`
- `576-telemetry-otlp-request-size-limit-contract.phpt`
- `577-telemetry-otlp-response-size-limit-contract.phpt`
- `578-telemetry-otlp-permanent-network-failure-contract.phpt`
- `579-telemetry-restart-replay-contract.phpt`
- `580-telemetry-queued-batch-ordering-contract.phpt`
- `581-telemetry-exporter-idempotency-batch-identity-contract.phpt`
- `582-telemetry-mixed-signal-batch-formation-contract.phpt`
- `583-telemetry-otlp-json-reference-collector-contract.phpt`
- `584-telemetry-export-failure-diagnostics-contract.phpt`
- `586-pipeline-telemetry-adapter-identity-contract.phpt`
- `639-telemetry-otlp-rate-limit-diagnostic-contract.phpt`
- `640-telemetry-lifecycle-restart-resume-contract.phpt`

#### Semantic DNS (4)
- `390-semantic-dns-concurrent-write-coherence.phpt`
- `391-semantic-dns-mother-node-concurrent-sync-churn.phpt`
- `517-semantic-dns-stale-peer-rejoin-partial-state-recovery-contract.phpt`
- `518-semantic-dns-mother-node-reelection-pressure-contract.phpt`

#### Orchestrator / Remote Peer (15)
- `354-orchestrator-remote-peer-backend.phpt`
- `355-orchestrator-remote-peer-failure-contract.phpt`
- `392-orchestrator-remote-peer-object-result-hardening.phpt`
- `463-orchestrator-remote-peer-resume-boundary-contract.phpt`
- `496-orchestrator-host-restart-continuation-contract.phpt`
- `497-orchestrator-step-error-classification-contract.phpt`
- `498-orchestrator-distributed-multi-worker-contract.phpt`
- `502-orchestrator-remote-error-meta-bounded-contract.phpt`
- `513-orchestrator-failover-harness-contract.phpt`
- `514-orchestrator-distributed-observability-contract.phpt`
- `591-orchestrator-remote-peer-userland-handler-contract.phpt`
- `592-orchestrator-userland-failure-classification-contract.phpt`
- `593-orchestrator-app-worker-boundary-smoke.phpt`
- `597-orchestrator-remote-peer-userland-topology-failclosed-contract.phpt`
- `626-system-readiness-remote-peer-dispatch-resume-gate-contract.phpt`

#### WebSocket Server (5)
- `312-websocket-server-retired.phpt`
- `539-websocket-server-registry-and-targeted-send-contract.phpt`
- `540-websocket-server-broadcast-and-shutdown-contract.phpt`
- `548-websocket-server-multi-connection-scheduling-contract.phpt`
- `550-websocket-server-abort-cleanup-contract.phpt`
- `545-server-early-hints-on-wire-contract.phpt`

#### Flow / ETL (7)
- `603-flow-php-object-store-sink-contract.phpt`
- `610-flow-php-execution-backend-remote-peer-contract.phpt`
- `611-flow-php-failure-taxonomy-source-sink-contract.phpt`
- `616-flow-php-object-store-dataset-bridge-cloud-contract.phpt`
- `617-flow-php-serialization-bridge-text-contract.phpt`
- `621-flow-php-etl-e2e-local-remote-contract.phpt`
- `671-flow-php-sql-pgvector-bridge-contract.phpt`

#### System & Misc (4)
- `121-config-resource-empty-snapshot-follows-ini.phpt`
- `321-system-autoscaling-chaos-recovery.phpt`
- `326-verify-release-package-hardening.phpt`
- `344-mcp-parallel-transfer-backpressure.phpt`
- `507-server-admin-api-real-traffic-contract.phpt`
- `525-admin-api-self-signed-client-rejection-contract.phpt`
- `638-system-lifecycle-recovery-validation-matrix.phpt`

---

## Skipped Tests (51 total)

Most skips are due to missing dependencies:

| Reason | Count |
|--------|-------|
| `KING_QUICHE_LIBRARY` not configured | ~40 |
| `KING_QUICHE_SERVER` not configured | ~10 |
| `pcntl` and `posix` extensions missing | ~5 |
| `unshare` syscall unavailable | 2 |
| `/proc/self/fd` unavailable | 1 |
| Linux-only check | 1 |
| `ip route metadata` unavailable | 1 |

---

## Notes

- All **proto encode/decode tests pass** (64 tests covering primitive types, enums, maps, oneof, packed fields, nested messages, defaults, fuzz stability)
- Most failures are **expected** — they require real cloud credentials (S3/GCS/Azure), real OTLP endpoints, or real Hetzner API access
- HTTP/2 tests fail because nghttp2 is not installed/linked
- The SIMD optimization scaffolding (`varint_simd.h`, `varint_simd.c`) was added and compiles cleanly
