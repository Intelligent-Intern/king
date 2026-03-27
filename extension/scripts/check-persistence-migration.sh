#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/check-persistence-migration.sh --from-ref REF [--current-archive PATH] [--php-bin BIN] [--artifacts-dir DIR]

Builds a packaged King release archive from a previous git ref, packages the
current tree if needed, verifies both archives, writes representative persisted
state with the previous package, and verifies that the current package can
rehydrate it correctly.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"

FROM_REF=""
CURRENT_ARCHIVE=""
PHP_BIN="${PHP_BIN:-php}"
ARTIFACTS_DIR=""
SCRATCH_DIR=""
PREVIOUS_WORKTREE=""

SEMANTIC_DNS_STATE_DIR="/tmp/king_semantic_dns_state"
SEMANTIC_DNS_STATE_FILE="${SEMANTIC_DNS_STATE_DIR}/durable_state.bin"
SEMANTIC_DNS_STATE_DIR_EXISTED=0
SEMANTIC_DNS_BACKUP_FILE=""

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

restore_semantic_dns_state() {
    if [[ -n "${SEMANTIC_DNS_BACKUP_FILE}" && -f "${SEMANTIC_DNS_BACKUP_FILE}" ]]; then
        mkdir -p "${SEMANTIC_DNS_STATE_DIR}"
        chmod 0700 "${SEMANTIC_DNS_STATE_DIR}"
        cp "${SEMANTIC_DNS_BACKUP_FILE}" "${SEMANTIC_DNS_STATE_FILE}"
    else
        rm -f "${SEMANTIC_DNS_STATE_FILE}"
    fi

    if [[ "${SEMANTIC_DNS_STATE_DIR_EXISTED}" == "0" ]]; then
        rmdir "${SEMANTIC_DNS_STATE_DIR}" >/dev/null 2>&1 || true
    fi
}

cleanup() {
    restore_semantic_dns_state

    if [[ -n "${PREVIOUS_WORKTREE}" ]] && [[ -d "${PREVIOUS_WORKTREE}" ]]; then
        git -C "${ROOT_DIR}" worktree remove --force "${PREVIOUS_WORKTREE}" >/dev/null 2>&1 || true
    fi

    if [[ -n "${SCRATCH_DIR}" && -d "${SCRATCH_DIR}" ]]; then
        rm -rf "${SCRATCH_DIR}"
    fi

    if [[ -n "${SEMANTIC_DNS_BACKUP_FILE}" ]]; then
        rm -f "${SEMANTIC_DNS_BACKUP_FILE}" >/dev/null 2>&1 || true
    fi
}

trap cleanup EXIT

while [[ $# -gt 0 ]]; do
    case "$1" in
        --from-ref)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --from-ref." >&2
                exit 1
            fi
            FROM_REF="$2"
            shift 2
            ;;
        --current-archive)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --current-archive." >&2
                exit 1
            fi
            CURRENT_ARCHIVE="$2"
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

if [[ -z "${FROM_REF}" ]]; then
    echo "The --from-ref option is required." >&2
    usage >&2
    exit 1
fi

if ! command -v "${PHP_BIN}" >/dev/null 2>&1; then
    echo "Missing requested PHP binary: ${PHP_BIN}" >&2
    exit 1
fi

if ! git -C "${ROOT_DIR}" rev-parse --verify "${FROM_REF}^{commit}" >/dev/null 2>&1; then
    echo "Could not resolve previous ref: ${FROM_REF}" >&2
    exit 1
fi

if [[ -n "${CURRENT_ARCHIVE}" ]]; then
    CURRENT_ARCHIVE="$(resolve_existing_path "${CURRENT_ARCHIVE}")" || {
        echo "Missing current archive: ${CURRENT_ARCHIVE}" >&2
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

PREVIOUS_BUILD_DIR="${ARTIFACTS_DIR}/previous"
CURRENT_BUILD_DIR="${ARTIFACTS_DIR}/current"
PREVIOUS_PREFIX="${ARTIFACTS_DIR}/previous-prefix"
CURRENT_PREFIX="${ARTIFACTS_DIR}/current-prefix"
OBJECT_STORE_ROOT="${ARTIFACTS_DIR}/persisted-object-store"
ORCHESTRATOR_STATE_PATH="${ARTIFACTS_DIR}/orchestrator-state.bin"
mkdir -p "${PREVIOUS_BUILD_DIR}" "${CURRENT_BUILD_DIR}"

if [[ -d "${SEMANTIC_DNS_STATE_DIR}" ]]; then
    SEMANTIC_DNS_STATE_DIR_EXISTED=1
fi
if [[ -f "${SEMANTIC_DNS_STATE_FILE}" && ! -L "${SEMANTIC_DNS_STATE_FILE}" ]]; then
    SEMANTIC_DNS_BACKUP_FILE="$(mktemp)"
    cp "${SEMANTIC_DNS_STATE_FILE}" "${SEMANTIC_DNS_BACKUP_FILE}"
fi
mkdir -p "${SEMANTIC_DNS_STATE_DIR}"
chmod 0700 "${SEMANTIC_DNS_STATE_DIR}"
rm -f "${SEMANTIC_DNS_STATE_FILE}"

package_tree() {
    local tree_root="$1"
    local output_dir="$2"
    local log_path="$3"
    local package_output=""
    local archive_path=""

    mkdir -p "${output_dir}"

    package_output="$(
        (
            cd "${tree_root}/extension"
            ./scripts/package-release.sh --output-dir "${output_dir}"
        ) 2>&1 | tee "${log_path}"
    )"

    archive_path="$(printf '%s\n' "${package_output}" | sed -n 's/^Package created: //p' | tail -n 1)"
    if [[ -z "${archive_path}" ]]; then
        echo "Failed to resolve package archive from ${tree_root}." >&2
        exit 1
    fi

    resolve_existing_path "${archive_path}"
}

verify_archive() {
    local archive_path="$1"
    local log_path="$2"

    (
        cd "${EXT_DIR}"
        PHP_BIN="${PHP_BIN}" ./scripts/verify-release-package.sh --archive "${archive_path}"
    ) 2>&1 | tee "${log_path}"
}

extract_archive_to_prefix() {
    local archive_path="$1"
    local prefix="$2"

    rm -rf "${prefix}"
    mkdir -p "${prefix}"
    tar -xzf "${archive_path}" -C "${prefix}" --strip-components=1
}

run_package_fixture() {
    local prefix="$1"
    local mode="$2"
    local log_path="$3"

    (
        export KING_QUICHE_LIBRARY="${prefix}/runtime/libquiche.so"
        export KING_QUICHE_SERVER="${prefix}/runtime/quiche-server"
        export LD_LIBRARY_PATH="${prefix}/runtime${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"
        export KING_PERSIST_OBJECT_STORE_ROOT="${OBJECT_STORE_ROOT}"

        "${PHP_BIN}" \
            -d "extension=${prefix}/modules/king.so" \
            -d "king.security_allow_config_override=1" \
            -d "king.orchestrator_state_path=${ORCHESTRATOR_STATE_PATH}" \
            "${SCRIPT_DIR}/runtime-persistence-migration.php" \
            "${mode}"
    ) 2>&1 | tee "${log_path}"
}

printf 'Preparing previous release archive from %s\n' "${FROM_REF}"
PREVIOUS_WORKTREE="$(mktemp -d)"
rm -rf "${PREVIOUS_WORKTREE}"
git -C "${ROOT_DIR}" worktree add --detach "${PREVIOUS_WORKTREE}" "${FROM_REF}" >/dev/null
git -C "${PREVIOUS_WORKTREE}" submodule update --init --recursive >/dev/null

PREVIOUS_ARCHIVE="$(package_tree "${PREVIOUS_WORKTREE}" "${PREVIOUS_BUILD_DIR}/dist" "${PREVIOUS_BUILD_DIR}/package.log")"

if [[ -z "${CURRENT_ARCHIVE}" ]]; then
    printf 'Preparing current release archive from working tree\n'
    CURRENT_ARCHIVE="$(package_tree "${ROOT_DIR}" "${CURRENT_BUILD_DIR}/dist" "${CURRENT_BUILD_DIR}/package.log")"
fi

printf 'Verifying previous archive\n'
verify_archive "${PREVIOUS_ARCHIVE}" "${PREVIOUS_BUILD_DIR}/verify.log"

printf 'Verifying current archive\n'
verify_archive "${CURRENT_ARCHIVE}" "${CURRENT_BUILD_DIR}/verify.log"

extract_archive_to_prefix "${PREVIOUS_ARCHIVE}" "${PREVIOUS_PREFIX}"
extract_archive_to_prefix "${CURRENT_ARCHIVE}" "${CURRENT_PREFIX}"

find "${OBJECT_STORE_ROOT}" -mindepth 1 -delete >/dev/null 2>&1 || true
mkdir -p "${OBJECT_STORE_ROOT}"
rm -f "${ORCHESTRATOR_STATE_PATH}"
rm -f "${SEMANTIC_DNS_STATE_FILE}"

printf 'Writing representative persisted state with previous package\n'
run_package_fixture "${PREVIOUS_PREFIX}" "write" "${PREVIOUS_BUILD_DIR}/migration-write.log"

printf 'Reading representative persisted state with current package\n'
run_package_fixture "${CURRENT_PREFIX}" "read" "${CURRENT_BUILD_DIR}/migration-read.log"

cat > "${ARTIFACTS_DIR}/summary.txt" <<EOF
persistence_migration=ok
previous_ref=${FROM_REF}
previous_archive=${PREVIOUS_ARCHIVE}
current_archive=${CURRENT_ARCHIVE}
php_bin=${PHP_BIN}
object_store_root=${OBJECT_STORE_ROOT}
orchestrator_state_path=${ORCHESTRATOR_STATE_PATH}
semantic_dns_state_file=${SEMANTIC_DNS_STATE_FILE}
EOF

printf 'Persistence migration compatibility ok\n'
