#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd "${BACKEND_ROOT}/../../.." && pwd)"
DOCKER_BIN="${DOCKER_BIN:-docker}"
DOCKER_IMAGE="${IAM3_15_PHP_IMAGE:-php:8.4-cli-trixie}"

DEFAULT_CONTRACTS=(
  "call-access-cross-org-contract.php"
  "call-access-stale-organization-role-contract.php"
)

if [[ -n "${IAM3_15_CONTRACTS:-}" ]]; then
  read -r -a CONTRACTS <<<"${IAM3_15_CONTRACTS}"
else
  CONTRACTS=("${DEFAULT_CONTRACTS[@]}")
fi

for contract in "${CONTRACTS[@]}"; do
  case "${contract}" in
    call-access-cross-org-contract.php|call-access-stale-organization-role-contract.php)
      ;;
    *)
      echo "[call-access-cross-org-stale-role-docker-proof] FAIL: unsupported contract ${contract}" >&2
      exit 1
      ;;
  esac
done

if ! command -v "${DOCKER_BIN}" >/dev/null 2>&1; then
  echo "[call-access-cross-org-stale-role-docker-proof] FAIL: ${DOCKER_BIN} is unavailable" >&2
  exit 1
fi

echo "[call-access-cross-org-stale-role-docker-proof] Container runtime: ${DOCKER_IMAGE}"
"${DOCKER_BIN}" run --rm \
  -v "${REPO_ROOT}:/workspace:ro" \
  -w /workspace/demo/video-chat/backend-king-php \
  -e IAM3_15_CONTRACTS="${CONTRACTS[*]}" \
  "${DOCKER_IMAGE}" \
  bash -lc '
    set -euo pipefail

    ensure_pdo_sqlite() {
      if php -m | grep -qi "^pdo_sqlite$"; then
        return
      fi
      if ! command -v docker-php-ext-install >/dev/null 2>&1; then
        echo "[call-access-cross-org-stale-role-docker-proof] FAIL: container PHP lacks pdo_sqlite and docker-php-ext-install is unavailable" >&2
        exit 1
      fi

      export DEBIAN_FRONTEND=noninteractive
      apt-get update >/tmp/iam3-15-apt-update.log
      apt-get install -y --no-install-recommends libsqlite3-dev >/tmp/iam3-15-apt-install.log
      docker-php-ext-install pdo_sqlite >/tmp/iam3-15-pdo-sqlite-build.log
    }

    ensure_pdo_sqlite
    echo "[call-access-cross-org-stale-role-docker-proof] PHP runtime: $(php -r "echo PHP_VERSION;")"
    php -m | grep -i "^pdo_sqlite$"

    for contract in ${IAM3_15_CONTRACTS}; do
      case "${contract}" in
        call-access-cross-org-contract.php|call-access-stale-organization-role-contract.php)
          ;;
        *)
          echo "[call-access-cross-org-stale-role-docker-proof] FAIL: unsupported contract ${contract}" >&2
          exit 1
          ;;
      esac
      echo "[call-access-cross-org-stale-role-docker-proof] ${contract}"
      php -l "tests/${contract}" >/dev/null
      php "tests/${contract}"
    done
  '

echo "[call-access-cross-org-stale-role-docker-proof] PASS"
