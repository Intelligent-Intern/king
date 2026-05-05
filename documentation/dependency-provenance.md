# King Dependency Provenance

This document is the pinned dependency provenance record for build and release
inputs that must stay deterministic.

The CI gate `infra/scripts/check-dependency-provenance-doc.sh` verifies that
the values in this file match the lock files under `infra/scripts/`. The active
HTTP/3 replacement provenance below is the active LSQUIC/BoringSSL-based
QUIC/HTTP3 stack. Legacy Quiche bootstrap locks are no longer provenance inputs.

## Canonical Toolchain Pins

| Component | Pinned value | Lock source |
| --- | --- | --- |
| Canonical PHP for baseline CI | `8.5` | `infra/scripts/toolchain.lock` |
| Rust toolchain for build/release | `1.86.0` | `infra/scripts/toolchain.lock` |

## HTTP/3 Replacement Stack Provenance Pins

The Git commit is the authoritative source pin. The source archive URL and
SHA-256 checksum document the bootstrap payload to verify before extracting it.
LSQUIC GitHub archives do not inline submodule payloads, so its submodules are
pinned and hashed as separate rows.

| Component | Source | Pin | Commit | Archive SHA-256 | Bytes |
| --- | --- | --- | --- | --- | --- |
| LSQUIC | `https://github.com/litespeedtech/lsquic/archive/refs/tags/v4.6.1.tar.gz` | `v4.6.1` | `c1ca7980107b1495298c93ab54e798fa050c3c7b` | `dde62d4458238fd7d3ec6c69da46cd291f8b59b45c2e932b6c2f64f74abfef14` | `1951563` |
| BoringSSL | `https://github.com/google/boringssl/archive/refs/tags/0.20260413.0.tar.gz` | `0.20260413.0` | `e1acfa3193d44166ce77df74c5285afea983fc63` | `3560f7dd3f08e16b9f84d877a5be21ec62071564783009571af5fcc6fad734d2` | `70098624` |
| LSQUIC submodule `src/liblsquic/ls-qpack` | `https://github.com/litespeedtech/ls-qpack/archive/1a27f87ece031f9e2fbfb29d5b3ef0a72e0a6bbb.tar.gz` | LSQUIC gitlink | `1a27f87ece031f9e2fbfb29d5b3ef0a72e0a6bbb` | `9187f00cb85885a48eeeafc72e67a54bb850d52ec108be587c11f5f180303057` | `709885` |
| LSQUIC submodule `src/lshpack` | `https://github.com/litespeedtech/ls-hpack/archive/8905c024b6d052f083a3d11d0a169b3c2735c8a1.tar.gz` | LSQUIC gitlink | `8905c024b6d052f083a3d11d0a169b3c2735c8a1` | `07d8bf901bb1b15543f38eabd23938519e1210eebadb52f3d651d6ef130ef973` | `952726` |

## Enforcement Surface

- `infra/scripts/toolchain-lock.sh` exposes and verifies canonical PHP/Rust pins.
- `infra/scripts/check-lsquic-bootstrap.sh` validates LSQUIC/BoringSSL archive
  pins, checksums, byte sizes, HTTPS source URLs, and documentation drift
  without network access.
- `infra/scripts/bootstrap-lsquic.sh` may try multiple deterministic transport
  URLs for the same pinned archive when a provider endpoint is temporarily
  unhealthy: optional `KING_LSQUIC_ARCHIVE_MIRROR_BASE`, the lockfile URL, and
  the equivalent `codeload.github.com` archive URL for GitHub sources. Every
  candidate still goes through the same SHA-256 and byte-size verification
  before extraction or cache admission.
- `infra/scripts/check-dependency-provenance-doc.sh` hard-fails when this
  document diverges from the lock sources or reintroduces Quiche provenance
  into the active HTTP/3 replacement section.
