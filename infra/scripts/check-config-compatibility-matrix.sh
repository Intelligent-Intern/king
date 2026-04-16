#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/check-config-compatibility-matrix.sh [--archive PATH] [--php-bin BIN] [--artifacts-dir DIR]

Builds or reuses the current packaged King release archive, verifies it, and
proves three representative configuration-state paths against the same packaged
runtime:

- legacy flat userland override aliases
- current namespaced userland overrides
- system INI snapshot inheritance via king_new_config([])
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

ARCHIVE_PATH=""
PHP_BIN="${PHP_BIN:-php}"
ARTIFACTS_DIR=""
SCRATCH_DIR=""

resolve_existing_path() {
    local candidate="$1"
    local base_dir=""

    if [[ ! -e "${candidate}" ]]; then
        return 1
    fi

    base_dir="$(
        (
            cd "$(dirname "${candidate}")"
            pwd
        )
    )"

    printf '%s/%s\n' "${base_dir}" "$(basename "${candidate}")"
}

resolve_path_for_output() {
    local candidate="$1"

    mkdir -p "${candidate}"
    (
        cd "${candidate}"
        pwd
    )
}

cleanup() {
    if [[ -n "${SCRATCH_DIR}" && -d "${SCRATCH_DIR}" ]]; then
        rm -rf "${SCRATCH_DIR}"
    fi
}

trap cleanup EXIT

while [[ $# -gt 0 ]]; do
    case "$1" in
        --archive)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --archive." >&2
                exit 1
            fi
            ARCHIVE_PATH="$2"
            shift 2
            ;;
        --php-bin)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --php-bin." >&2
                exit 1
            fi
            PHP_BIN="$2"
            shift 2
            ;;
        --artifacts-dir)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --artifacts-dir." >&2
                exit 1
            fi
            ARTIFACTS_DIR="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if ! command -v "${PHP_BIN}" >/dev/null 2>&1; then
    echo "Missing requested PHP binary: ${PHP_BIN}" >&2
    exit 1
fi

if [[ -n "${ARCHIVE_PATH}" ]]; then
    ARCHIVE_PATH="$(resolve_existing_path "${ARCHIVE_PATH}")" || {
        echo "Missing archive: ${ARCHIVE_PATH}" >&2
        exit 1
    }
fi

if [[ -n "${ARTIFACTS_DIR}" ]]; then
    ARTIFACTS_DIR="$(resolve_path_for_output "${ARTIFACTS_DIR}")"
else
    SCRATCH_DIR="$(mktemp -d)"
    ARTIFACTS_DIR="${SCRATCH_DIR}/artifacts"
    mkdir -p "${ARTIFACTS_DIR}"
fi

BUILD_DIR="${ARTIFACTS_DIR}/current"
PREFIX_DIR="${ARTIFACTS_DIR}/prefix"
mkdir -p "${BUILD_DIR}"

package_tree() {
    local tree_root="$1"
    local output_dir="$2"
    local log_path="$3"
    local package_output=""
    local package_status=0
    local archive_path=""

    rm -rf "${output_dir}"
    mkdir -p "${output_dir}"

    set +e
    package_output="$(
        (
            cd "${tree_root}"
            ./infra/scripts/package-release.sh --verify-reproducible --output-dir "${output_dir}"
        ) 2>&1 | tee "${log_path}"
    )"
    package_status=$?
    set -e

    if [[ "${package_status}" -ne 0 ]]; then
        echo "Failed to package release archive from ${tree_root}." >&2
        if [[ -f "${log_path}" ]]; then
            echo "Last 40 log lines from ${log_path}:" >&2
            tail -n 40 "${log_path}" >&2
        fi
        exit "${package_status}"
    fi

    archive_path="$(
        printf '%s\n' "${package_output}" \
            | tr -d '\r' \
            | sed -n 's/^.*Package created:[[:space:]]*//p' \
            | tail -n 1
    )"

    if [[ -z "${archive_path}" ]]; then
        archive_path="$(
            find "${output_dir}" -maxdepth 1 -type f -name '*.tar.gz' -print \
                | LC_ALL=C sort \
                | tail -n 1
        )"
    fi

    if [[ -n "${archive_path}" && "${archive_path}" != /* ]]; then
        archive_path="${tree_root}/${archive_path}"
    fi

    if [[ -z "${archive_path}" ]]; then
        echo "Failed to resolve package archive from ${tree_root}." >&2
        exit 1
    fi

    resolve_existing_path "${archive_path}" || {
        echo "Resolved package archive path does not exist: ${archive_path}" >&2
        exit 1
    }
}

verify_archive() {
    local archive_path="$1"
    local log_path="$2"

    (
        cd "${ROOT_DIR}"
        PHP_BIN="${PHP_BIN}" ./infra/scripts/verify-release-package.sh --archive "${archive_path}"
    ) 2>&1 | tee "${log_path}"
}

extract_archive_to_prefix() {
    local archive_path="$1"
    local prefix="$2"

    rm -rf "${prefix}"
    mkdir -p "${prefix}"
    tar -xzf "${archive_path}" -C "${prefix}" --strip-components=1
}

run_matrix_mode() {
    local prefix="$1"
    local mode="$2"
    local output_path="$3"
    local log_path="$4"
    shift 4

    (
        export KING_LSQUIC_SHIMRARY="${prefix}/runtime/liblsquic-shim.so"
        export KING_LSQUIC_SERVER="${prefix}/runtime/lsquic"
        export LD_LIBRARY_PATH="${prefix}/runtime${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

        "${PHP_BIN}" \
            -d "extension=${prefix}/modules/king.so" \
            -d "king.security_allow_config_override=1" \
            "$@" \
            "${SCRIPT_DIR}/runtime-config-compatibility.php" \
            "${mode}" \
            > "${output_path}"
    ) 2> "${log_path}"
}

if [[ -z "${ARCHIVE_PATH}" ]]; then
    printf 'Preparing current release archive from working tree\n'
    ARCHIVE_PATH="$(package_tree "${ROOT_DIR}" "${BUILD_DIR}/dist" "${BUILD_DIR}/package.log")"
fi

printf 'Verifying current archive\n'
verify_archive "${ARCHIVE_PATH}" "${BUILD_DIR}/verify.log"

extract_archive_to_prefix "${ARCHIVE_PATH}" "${PREFIX_DIR}"

LEGACY_OUTPUT="${ARTIFACTS_DIR}/legacy-alias-overrides.json"
NAMESPACED_OUTPUT="${ARTIFACTS_DIR}/namespaced-overrides.json"
INI_OUTPUT="${ARTIFACTS_DIR}/ini-snapshot.json"

printf 'Running legacy alias override snapshot\n'
run_matrix_mode \
    "${PREFIX_DIR}" \
    "legacy_alias_overrides" \
    "${LEGACY_OUTPUT}" \
    "${ARTIFACTS_DIR}/legacy-alias-overrides.log"

printf 'Running namespaced override snapshot\n'
run_matrix_mode \
    "${PREFIX_DIR}" \
    "namespaced_overrides" \
    "${NAMESPACED_OUTPUT}" \
    "${ARTIFACTS_DIR}/namespaced-overrides.log"

printf 'Running INI snapshot inheritance check\n'
run_matrix_mode \
    "${PREFIX_DIR}" \
    "ini_snapshot" \
    "${INI_OUTPUT}" \
    "${ARTIFACTS_DIR}/ini-snapshot.log" \
    -d "king.transport_cc_algorithm=bbr" \
    -d "king.tls_verify_peer=0" \
    -d "king.http2_max_concurrent_streams=32" \
    -d "king.tcp_enable=0" \
    -d "king.storage_default_redundancy_mode=replication" \
    -d "king.cdn_cache_mode=memory" \
    -d "king.dns_mode=service_discovery" \
    -d "king.otel_service_name=king_config_matrix_ini" \
    -d "king.cluster_autoscale_provider=hetzner" \
    -d "king.cluster_autoscale_max_nodes=5" \
    -d "king.mcp_default_request_timeout_ms=41000" \
    -d "king.mcp_enable_request_caching=1" \
    -d "king.orchestrator_enable_distributed_tracing=0" \
    -d "king.geometry_default_vector_dimensions=1024" \
    -d "king.geometry_calculation_precision=float32" \
    -d "king.smartcontract_enable=1" \
    -d "king.smartcontract_dlt_provider=solana" \
    -d "king.ssh_gateway_enable=1" \
    -d "king.ssh_gateway_auth_mode=mcp_token"

if ! cmp -s "${LEGACY_OUTPUT}" "${NAMESPACED_OUTPUT}"; then
    echo "Legacy alias and namespaced config snapshots diverged." >&2
    diff -u "${LEGACY_OUTPUT}" "${NAMESPACED_OUTPUT}" || true
    exit 1
fi

cat > "${ARTIFACTS_DIR}/summary.txt" <<EOF
config_state_compatibility=ok
archive=${ARCHIVE_PATH}
php_bin=${PHP_BIN}
legacy_snapshot=${LEGACY_OUTPUT}
namespaced_snapshot=${NAMESPACED_OUTPUT}
ini_snapshot=${INI_OUTPUT}
EOF

printf 'Config-state compatibility matrix ok\n'
