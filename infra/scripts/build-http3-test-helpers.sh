#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/build-http3-test-helpers.sh [build|--verify-plan|--print-plan|--clean]

Builds repo-owned C HTTP/3 test helper binaries into an ignored cache directory.
The script is intentionally separate from product builds and writes no generated
sources or binaries into tracked trees.

Environment variables:
  KING_HTTP3_TEST_HELPER_SOURCE_DIR   C helper source directory.
                                      Default: extension/tests/http3_peer_helpers
  KING_HTTP3_TEST_HELPER_OUTPUT_DIR   Build/output directory.
                                      Default: .cache/king/http3-test-helpers
  CC                                  C compiler. Default: cc
  KING_LSQUIC_CFLAGS                  Optional LSQUIC include flags.
  KING_BORINGSSL_CFLAGS               Optional BoringSSL include flags.
  KING_HTTP3_TEST_HELPER_CFLAGS       Optional extra C flags.
  KING_LSQUIC_LIBS                    Optional LSQUIC linker flags.
  KING_BORINGSSL_LIBS                 Optional BoringSSL linker flags.
  KING_HTTP3_TEST_HELPER_LIBS         Optional extra linker flags.
USAGE
}

MODE="${1:-build}"
case "${MODE}" in
    build|--verify-plan|--print-plan|--clean)
        ;;
    --help|-h)
        usage
        exit 0
        ;;
    *)
        echo "Unknown mode: ${MODE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${ROOT_DIR}/infra/scripts/lsquic-bootstrap.lock"
BOOTSTRAP_SCRIPT="${ROOT_DIR}/infra/scripts/bootstrap-lsquic.sh"
SOURCE_DIR="${KING_HTTP3_TEST_HELPER_SOURCE_DIR:-${ROOT_DIR}/extension/tests/http3_peer_helpers}"
OUTPUT_DIR="${KING_HTTP3_TEST_HELPER_OUTPUT_DIR:-${ROOT_DIR}/.cache/king/http3-test-helpers}"
BUILD_DIR="${OUTPUT_DIR}/build"
BIN_DIR="${OUTPUT_DIR}/bin"
MANIFEST="${OUTPUT_DIR}/manifest.sha256"
CC_BIN="${CC:-cc}"

helpers=(
    "king-http3-abort-client:abort_client.c"
    "king-http3-delayed-body-client:delayed_body_client.c"
    "king-http3-failure-peer:failure_peer.c"
    "king-http3-multi-peer:multi_peer.c"
    "king-http3-ticket-server:ticket_server.c"
)

require_file() {
    if [[ ! -f "$1" ]]; then
        echo "Missing required file: $1" >&2
        exit 1
    fi
}

require_tool() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required tool '$1' is not installed." >&2
        exit 1
    fi
}

sha256_file() {
    local file="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "${file}" | awk '{print $1}'
        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "${file}" | awk '{print $1}'
        return
    fi

    echo "sha256sum or shasum is required." >&2
    exit 1
}

resolve_source_epoch() {
    if [[ -n "${SOURCE_DATE_EPOCH:-}" ]]; then
        printf '%s\n' "${SOURCE_DATE_EPOCH}"
        return
    fi

    if command -v git >/dev/null 2>&1 && git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        git -C "${ROOT_DIR}" log -1 --format=%ct -- "infra/scripts/lsquic-bootstrap.lock" "infra/scripts/build-http3-test-helpers.sh" 2>/dev/null || printf '0\n'
        return
    fi

    printf '0\n'
}

split_flags() {
    local raw="$1"
    local -n out_ref="$2"

    if [[ -n "${raw}" ]]; then
        # King build flag env vars are path-style tokens; paths with spaces are intentionally unsupported here.
        read -r -a out_ref <<< "${raw}"
    fi
}

compiler_accepts() {
    local flag="$1"
    local tmp_dir="$2"
    local probe="${tmp_dir}/probe.c"

    printf 'int main(void){return 0;}\n' > "${probe}"
    "${CC_BIN}" "${flag}" "${probe}" -o "${tmp_dir}/probe" >/dev/null 2>&1
}

linker_accepts() {
    local flag="$1"
    local tmp_dir="$2"
    local probe="${tmp_dir}/probe-link.c"

    printf 'int main(void){return 0;}\n' > "${probe}"
    "${CC_BIN}" "${probe}" "${flag}" -o "${tmp_dir}/probe-link" >/dev/null 2>&1
}

require_ignored_output_dir() {
    case "${OUTPUT_DIR}" in
        "${ROOT_DIR}/.cache/"*|"${ROOT_DIR}/.cache")
            ;;
        *)
            echo "HTTP/3 helper output must stay under ${ROOT_DIR}/.cache so build leftovers cannot be committed: ${OUTPUT_DIR}" >&2
            exit 1
            ;;
    esac
}

verify_plan() {
    require_file "${LOCK_FILE}"
    require_file "${BOOTSTRAP_SCRIPT}"
    "${BOOTSTRAP_SCRIPT}" --verify-lock >/dev/null
    require_ignored_output_dir
}

print_plan() {
    verify_plan
    printf 'source_dir=%s\n' "${SOURCE_DIR}"
    printf 'output_dir=%s\n' "${OUTPUT_DIR}"
    printf 'manifest=%s\n' "${MANIFEST}"
    for entry in "${helpers[@]}"; do
        printf 'helper=%s source=%s\n' "${entry%%:*}" "${entry##*:}"
    done
}

clean_outputs() {
    require_ignored_output_dir
    rm -rf "${OUTPUT_DIR}"
}

if [[ "${MODE}" == "--clean" ]]; then
    clean_outputs
    exit 0
fi

if [[ "${MODE}" == "--verify-plan" ]]; then
    verify_plan
    echo "HTTP/3 test helper build plan is deterministic and cache-scoped."
    exit 0
fi

if [[ "${MODE}" == "--print-plan" ]]; then
    print_plan
    exit 0
fi

verify_plan
require_tool "${CC_BIN}"

if [[ ! -d "${SOURCE_DIR}" ]]; then
    echo "Missing HTTP/3 C helper source directory: ${SOURCE_DIR}" >&2
    exit 1
fi

mkdir -p "${BUILD_DIR}" "${BIN_DIR}"
TMP_DIR="$(mktemp -d "${OUTPUT_DIR}/tmp.XXXXXX")"
trap 'rm -rf "${TMP_DIR}"' EXIT

export LC_ALL=C
export TZ=UTC
export SOURCE_DATE_EPOCH="$(resolve_source_epoch)"

cflags=(
    -std=c11
    -O2
    -g0
    -fno-ident
    "-ffile-prefix-map=${ROOT_DIR}=."
    "-fdebug-prefix-map=${ROOT_DIR}=."
    -Wdate-time
    -Werror=date-time
    -Wall
    -Wextra
)
ldflags=()

if compiler_accepts "-frandom-seed=king-http3-test-helpers" "${TMP_DIR}"; then
    cflags+=("-frandom-seed=king-http3-test-helpers")
fi

if compiler_accepts "-fno-record-gcc-switches" "${TMP_DIR}"; then
    cflags+=("-fno-record-gcc-switches")
fi

if linker_accepts "-Wl,--build-id=none" "${TMP_DIR}"; then
    ldflags+=("-Wl,--build-id=none")
fi

extra_cflags=()
extra_ldflags=()
split_flags "${KING_LSQUIC_CFLAGS:-}" extra_cflags
split_flags "${KING_BORINGSSL_CFLAGS:-}" extra_cflags
split_flags "${KING_HTTP3_TEST_HELPER_CFLAGS:-}" extra_cflags
split_flags "${KING_LSQUIC_LIBS:-}" extra_ldflags
split_flags "${KING_BORINGSSL_LIBS:-}" extra_ldflags
split_flags "${KING_HTTP3_TEST_HELPER_LIBS:-}" extra_ldflags

manifest_tmp="${TMP_DIR}/manifest.sha256"
: > "${manifest_tmp}"

for entry in "${helpers[@]}"; do
    helper_name="${entry%%:*}"
    source_name="${entry##*:}"
    source_path="${SOURCE_DIR}/${source_name}"
    object_path="${BUILD_DIR}/${source_name%.c}.o"
    binary_path="${BIN_DIR}/${helper_name}"
    tmp_object="${TMP_DIR}/${helper_name}.o"
    tmp_binary="${TMP_DIR}/${helper_name}"

    if [[ ! -f "${source_path}" ]]; then
        echo "Missing HTTP/3 C helper source: ${source_path}" >&2
        exit 1
    fi

    "${CC_BIN}" "${cflags[@]}" "${extra_cflags[@]}" -c "${source_path}" -o "${tmp_object}"
    "${CC_BIN}" "${tmp_object}" "${ldflags[@]}" "${extra_ldflags[@]}" -o "${tmp_binary}"
    install -m 0644 "${tmp_object}" "${object_path}"
    install -m 0755 "${tmp_binary}" "${binary_path}"
    printf '%s  %s\n' "$(sha256_file "${binary_path}")" "bin/${helper_name}" >> "${manifest_tmp}"
done

LC_ALL=C sort -k2,2 "${manifest_tmp}" > "${MANIFEST}.tmp"
mv "${MANIFEST}.tmp" "${MANIFEST}"

echo "Built HTTP/3 test helpers into ${BIN_DIR}"
