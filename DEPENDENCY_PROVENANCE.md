# King Dependency Provenance

This document is the pinned dependency provenance record for build and release
inputs that must stay deterministic.

The CI gate `infra/scripts/check-dependency-provenance-doc.sh` verifies that
the values in this file match the lock files under `infra/scripts/`.

## Canonical Toolchain Pins

| Component | Pinned value | Lock source |
| --- | --- | --- |
| Canonical PHP for baseline CI | `8.5` | `infra/scripts/toolchain.lock` |
| Rust toolchain for build/release | `1.86.0` | `infra/scripts/toolchain.lock` |

## QUIC Bootstrap Provenance Pins

| Component | Pinned value | Lock source |
| --- | --- | --- |
| Lsquic repository | `https://github.com/cloudflare/lsquic.git` | `infra/scripts/lsquic-bootstrap.lock` |
| Lsquic commit | `b30f9e76c32332aa35377dcb00f556626d47a841` | `infra/scripts/lsquic-bootstrap.lock` |
| BoringSSL commit | `f1c75347daa2ea81a941e953f2263e0a4d970c8d` | `infra/scripts/lsquic-bootstrap.lock` |
| Wirefilter commit | `6621924baf36f8ba7f603433dbe6f857ad3d5589` | `infra/scripts/lsquic-bootstrap.lock` |

## Enforcement Surface

- `infra/scripts/toolchain-lock.sh` exposes and verifies canonical PHP/Rust pins.
- `infra/scripts/check-lsquic-bootstrap.sh` verifies lsquic/boringssl/wirefilter
  lock provenance before build work.
- `infra/scripts/check-dependency-provenance-doc.sh` hard-fails when this
  document diverges from the lock sources.
