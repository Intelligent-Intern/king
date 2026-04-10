#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/check-release-upgrade.sh --from-ref REF [--current-archive PATH] [--php-bin BIN] [--artifacts-dir DIR] [--direction upgrade|downgrade]

Builds a packaged King release archive from a previous git ref, packages the
current tree if needed, verifies both archives, installs them sequentially into
the same prefix, and runs the packaged smoke test before and after the upgrade.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"

FROM_REF=""
CURRENT_ARCHIVE=""
PHP_BIN="${PHP_BIN:-php}"
ARTIFACTS_DIR=""
SCRATCH_DIR=""
PREVIOUS_WORKTREE=""
BASELINE_REF=""
DIRECTION="upgrade"

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
    if [[ -n "${PREVIOUS_WORKTREE}" ]] && [[ -d "${PREVIOUS_WORKTREE}" ]]; then
        git -C "${ROOT_DIR}" worktree remove --force "${PREVIOUS_WORKTREE}" >/dev/null 2>&1 || true
    fi

    if [[ -n "${SCRATCH_DIR}" && -d "${SCRATCH_DIR}" ]]; then
        rm -rf "${SCRATCH_DIR}"
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
        --direction)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --direction." >&2
                exit 1
            fi
            DIRECTION="$2"
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

case "${DIRECTION}" in
    upgrade|downgrade)
        ;;
    *)
        echo "Unsupported --direction value: ${DIRECTION}" >&2
        exit 1
        ;;
esac

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
INSTALL_ROOT="${ARTIFACTS_DIR}/upgrade-prefix"
mkdir -p "${PREVIOUS_BUILD_DIR}" "${CURRENT_BUILD_DIR}" "${INSTALL_ROOT}"

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
        return 1
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
        return 1
    fi

    resolve_existing_path "${archive_path}" || return 1
}

verify_archive() {
    local archive_path="$1"
    local log_path="$2"
    local allow_missing_provenance="${3:-0}"
    local -a verify_args

    verify_args=(./infra/scripts/verify-release-package.sh --archive "${archive_path}")
    if [[ "${allow_missing_provenance}" == "1" ]]; then
        verify_args+=(--allow-missing-provenance)
    fi

    (
        cd "${ROOT_DIR}"
        PHP_BIN="${PHP_BIN}" "${verify_args[@]}"
    ) 2>&1 | tee "${log_path}"
}

install_archive_to_prefix() {
    local archive_path="$1"
    local prefix="$2"
    local log_path="$3"

    rm -rf "${prefix}"
    mkdir -p "${prefix}"

    (
        tar -xzf "${archive_path}" -C "${prefix}" --strip-components=1
        PHP_BIN="${PHP_BIN}" "${prefix}/bin/smoke.sh"
    ) 2>&1 | tee "${log_path}"
}

prepare_previous_archive() {
    local candidate_ref="$1"
    local package_log=""
    local next_candidate=""
    local attempt=0

    while [[ -n "${candidate_ref}" ]]; do
        package_log="${PREVIOUS_BUILD_DIR}/package-$(git -C "${ROOT_DIR}" rev-parse --short "${candidate_ref}").log"

        printf 'Preparing previous release archive from %s\n' "${candidate_ref}"
        PREVIOUS_WORKTREE="$(mktemp -d)"
        rm -rf "${PREVIOUS_WORKTREE}"
        git -C "${ROOT_DIR}" worktree add --detach "${PREVIOUS_WORKTREE}" "${candidate_ref}" >/dev/null
        git -C "${PREVIOUS_WORKTREE}" submodule update --init --recursive >/dev/null

        if PREVIOUS_ARCHIVE="$(package_tree "${PREVIOUS_WORKTREE}" "${PREVIOUS_BUILD_DIR}/dist" "${package_log}")"; then
            BASELINE_REF="${candidate_ref}"
            return 0
        fi

        echo "Failed to package from ${candidate_ref}. Last 40 log lines from ${package_log}:" >&2
        if [[ -f "${package_log}" ]]; then
            tail -n 40 "${package_log}" >&2
        fi

        next_candidate="$(git -C "${ROOT_DIR}" rev-parse "${candidate_ref}^" 2>/dev/null || true)"
        if [[ -z "${next_candidate}" ]]; then
            break
        fi

        echo "Packaging baseline ${candidate_ref} failed. Trying parent ${next_candidate} (attempt $((attempt + 1)))." >&2
        attempt=$((attempt + 1))

        rm -rf "${PREVIOUS_WORKTREE}"
        PREVIOUS_WORKTREE=""
        candidate_ref="${next_candidate}"
    done

    echo "Failed to build a package from ${FROM_REF} or any first-parent ancestor." >&2
    exit 1
}

prepare_previous_archive "${FROM_REF}"

if [[ -z "${CURRENT_ARCHIVE}" ]]; then
    printf 'Preparing current release archive from working tree\n'
    CURRENT_ARCHIVE="$(package_tree "${ROOT_DIR}" "${CURRENT_BUILD_DIR}/dist" "${CURRENT_BUILD_DIR}/package.log")"
fi

printf 'Verifying previous archive\n'
verify_archive "${PREVIOUS_ARCHIVE}" "${PREVIOUS_BUILD_DIR}/verify.log" "1"

printf 'Verifying current archive\n'
verify_archive "${CURRENT_ARCHIVE}" "${CURRENT_BUILD_DIR}/verify.log" "0"

if [[ "${DIRECTION}" == "upgrade" ]]; then
    printf 'Installing previous archive into upgrade prefix\n'
    install_archive_to_prefix "${PREVIOUS_ARCHIVE}" "${INSTALL_ROOT}" "${PREVIOUS_BUILD_DIR}/install-smoke.log"

    printf 'Installing current archive into the same upgrade prefix\n'
    install_archive_to_prefix "${CURRENT_ARCHIVE}" "${INSTALL_ROOT}" "${CURRENT_BUILD_DIR}/install-smoke.log"
else
    printf 'Installing current archive into downgrade prefix\n'
    install_archive_to_prefix "${CURRENT_ARCHIVE}" "${INSTALL_ROOT}" "${CURRENT_BUILD_DIR}/install-smoke.log"

    printf 'Installing previous archive into the same downgrade prefix\n'
    install_archive_to_prefix "${PREVIOUS_ARCHIVE}" "${INSTALL_ROOT}" "${PREVIOUS_BUILD_DIR}/install-smoke.log"
fi

cat > "${ARTIFACTS_DIR}/summary.txt" <<EOF
release_${DIRECTION}_compatibility=ok
previous_ref=${BASELINE_REF}
previous_archive=${PREVIOUS_ARCHIVE}
current_archive=${CURRENT_ARCHIVE}
php_bin=${PHP_BIN}
install_root=${INSTALL_ROOT}
EOF

printf 'Release %s compatibility ok\n' "${DIRECTION}"
