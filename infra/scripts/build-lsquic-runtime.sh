#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/build-lsquic-runtime.sh [--verify-current|--print-env|--clean]

Builds the pinned LSQUIC/BoringSSL runtime used by King HTTP/3 wire tests and
release profiles. Sources are controlled by infra/scripts/lsquic-bootstrap.lock.

Environment variables:
  KING_LSQUIC_RUNTIME_PREFIX  Runtime install prefix.
                              Default: .cache/king/lsquic/runtime/prefix
  KING_LSQUIC_RUNTIME_BUILD_DIR
                              CMake build directory.
                              Default: .cache/king/lsquic/runtime/build
  KING_LSQUIC_SOURCE_CACHE    Source/archive cache root.
  KING_LSQUIC_SOURCE_DIR      Extracted source root.
  JOBS                        Parallel build jobs. Default: nproc
USAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

MODE="${1:-build}"
case "${MODE}" in
    build|--verify-current|--print-env|--clean)
        ;;
    *)
        echo "Unknown mode: ${MODE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
LOCK_FILE="${SCRIPT_DIR}/lsquic-bootstrap.lock"
BOOTSTRAP_SCRIPT="${SCRIPT_DIR}/bootstrap-lsquic.sh"

CACHE_DIR="${KING_LSQUIC_SOURCE_CACHE:-${ROOT_DIR}/.cache/king/lsquic}"
SOURCE_DIR="${KING_LSQUIC_SOURCE_DIR:-${CACHE_DIR}/src}"
BUILD_DIR="${KING_LSQUIC_RUNTIME_BUILD_DIR:-${CACHE_DIR}/runtime/build}"
PREFIX_DIR="${KING_LSQUIC_RUNTIME_PREFIX:-${CACHE_DIR}/runtime/prefix}"
METADATA_FILE="${PREFIX_DIR}/king-lsquic-runtime.env"

default_jobs() {
    local count=""

    if command -v nproc >/dev/null 2>&1; then
        nproc
        return
    fi

    if command -v sysctl >/dev/null 2>&1; then
        count="$(sysctl -n hw.ncpu 2>/dev/null || true)"
        if [[ "${count}" =~ ^[0-9]+$ ]] && [[ "${count}" -gt 0 ]]; then
            printf '%s\n' "${count}"
            return
        fi
    fi

    getconf _NPROCESSORS_ONLN 2>/dev/null || printf '%s\n' 1
}

host_os() {
    case "$(uname -s)" in
        Darwin)
            printf '%s\n' "darwin"
            ;;
        Linux)
            printf '%s\n' "linux"
            ;;
        *)
            uname -s | tr '[:upper:]' '[:lower:]'
            ;;
    esac
}

runtime_library_name() {
    case "$(host_os)" in
        darwin)
            printf '%s\n' "liblsquic.dylib"
            ;;
        *)
            printf '%s\n' "liblsquic.so"
            ;;
    esac
}

JOBS="${JOBS:-$(default_jobs)}"

if [[ ! -f "${LOCK_FILE}" ]]; then
    echo "Missing LSQUIC bootstrap lock file: ${LOCK_FILE}" >&2
    exit 1
fi

if [[ ! -x "${BOOTSTRAP_SCRIPT}" ]]; then
    echo "Missing executable LSQUIC bootstrap script: ${BOOTSTRAP_SCRIPT}" >&2
    exit 1
fi

# shellcheck source=/dev/null
source "${LOCK_FILE}"

require_tool() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required tool '$1' is not installed." >&2
        exit 1
    fi
}

file_sha256() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
        return
    fi

    echo "sha256sum or shasum is required." >&2
    exit 1
}

runtime_arch() {
    local os=""

    os="$(host_os)"
    case "$(uname -m)" in
        x86_64|amd64)
            printf '%s\n' "${os}-amd64"
            ;;
        aarch64|arm64)
            printf '%s\n' "${os}-arm64"
            ;;
        *)
            printf '%s-%s\n' "${os}" "$(uname -m)"
            ;;
    esac
}

expected_lock_sha256() {
    file_sha256 "${LOCK_FILE}"
}

write_metadata() {
    local library="$1"

    cat > "${METADATA_FILE}" <<EOF
KING_LSQUIC_RUNTIME_ARCH="$(runtime_arch)"
KING_LSQUIC_RUNTIME_LOCK_SHA256="$(expected_lock_sha256)"
KING_LSQUIC_RUNTIME_LIBRARY_SHA256="$(file_sha256 "${library}")"
KING_LSQUIC_COMMIT="${KING_LSQUIC_COMMIT}"
KING_LSQUIC_BORINGSSL_COMMIT="${KING_LSQUIC_BORINGSSL_COMMIT}"
KING_LSQUIC_LS_QPACK_COMMIT="${KING_LSQUIC_LS_QPACK_COMMIT}"
KING_LSQUIC_LS_HPACK_COMMIT="${KING_LSQUIC_LS_HPACK_COMMIT}"
EOF
}

metadata_value() {
    local key="$1"
    local value=""

    value="$(sed -n "s/^${key}=\"\\(.*\\)\"$/\\1/p" "${METADATA_FILE}" | head -n 1)"
    printf '%s\n' "${value}"
}

verify_runtime() {
    local library="${PREFIX_DIR}/lib/$(runtime_library_name)"
    local header="${PREFIX_DIR}/include/lsquic/lsquic.h"
    local types_header="${PREFIX_DIR}/include/lsquic/lsquic_types.h"
    local xpack_header="${PREFIX_DIR}/include/lsquic/lsxpack_header.h"
    local boringssl_header="${PREFIX_DIR}/include/boringssl/openssl/ssl.h"
    local boringssl_ssl="${PREFIX_DIR}/boringssl/lib/libssl.a"
    local boringssl_crypto="${PREFIX_DIR}/boringssl/lib/libcrypto.a"
    local symbols_file=""
    local lock_sha=""
    local arch=""
    local library_sha=""

    [[ -f "${library}" ]] || return 1
    [[ -f "${header}" ]] || return 1
    [[ -f "${types_header}" ]] || return 1
    [[ -f "${xpack_header}" ]] || return 1
    [[ -f "${boringssl_header}" ]] || return 1
    [[ -f "${boringssl_ssl}" ]] || return 1
    [[ -f "${boringssl_crypto}" ]] || return 1
    [[ -f "${METADATA_FILE}" ]] || return 1

    lock_sha="$(metadata_value KING_LSQUIC_RUNTIME_LOCK_SHA256)"
    arch="$(metadata_value KING_LSQUIC_RUNTIME_ARCH)"
    library_sha="$(metadata_value KING_LSQUIC_RUNTIME_LIBRARY_SHA256)"

    [[ "${lock_sha}" == "$(expected_lock_sha256)" ]] || return 1
    [[ "${arch}" == "$(runtime_arch)" ]] || return 1
    [[ "${library_sha}" == "$(file_sha256 "${library}")" ]] || return 1

    symbols_file="$(mktemp)"
    case "$(host_os)" in
        darwin)
            nm -gU "${library}" > "${symbols_file}"
            ;;
        *)
            nm -D "${library}" > "${symbols_file}"
            ;;
    esac

    if ! grep -Eq ' [Tt] _?lsquic_global_init$' "${symbols_file}"; then
        echo "Built LSQUIC runtime does not export lsquic_global_init: ${library}" >&2
        rm -f "${symbols_file}"
        return 1
    fi

    case "$(host_os)" in
        darwin)
            nm -u "${library}" > "${symbols_file}"
            ;;
        *)
            nm -D "${library}" > "${symbols_file}"
            ;;
    esac

    if grep -Eq ' U _?(SSL_|CRYPTO_|EVP_|OPENSSL_|HMAC_|HKDF|AES_|RAND_|SHA)' "${symbols_file}"; then
        echo "Built LSQUIC runtime still has unresolved BoringSSL symbols: ${library}" >&2
        rm -f "${symbols_file}"
        return 1
    fi

    rm -f "${symbols_file}"
}

print_env() {
    cat <<EOF
KING_LSQUIC_RUNTIME_PREFIX=${PREFIX_DIR}
KING_LSQUIC_INCLUDE_DIR=${PREFIX_DIR}/include/lsquic
KING_LSQUIC_LIBRARY_DIR=${PREFIX_DIR}/lib
KING_LSQUIC_LIBRARY=${PREFIX_DIR}/lib/$(runtime_library_name)
KING_BORINGSSL_INCLUDE_DIR=${PREFIX_DIR}/include/boringssl
KING_BORINGSSL_SSL_LIBRARY=${PREFIX_DIR}/boringssl/lib/libssl.a
KING_BORINGSSL_CRYPTO_LIBRARY=${PREFIX_DIR}/boringssl/lib/libcrypto.a
EOF
}

clean_runtime() {
    rm -rf "${BUILD_DIR}" "${PREFIX_DIR}"
}

build_runtime() {
    local boringssl_build="${BUILD_DIR}/boringssl"
    local boringssl_prefix="${PREFIX_DIR}/boringssl"
    local lsquic_build="${BUILD_DIR}/lsquic"
    local library="${PREFIX_DIR}/lib/$(runtime_library_name)"
    local lsquic_archive=""
    local shared_link_args=()

    require_tool cmake
    require_tool ninja
    require_tool cc
    require_tool nm

    if ! "${BOOTSTRAP_SCRIPT}" --verify-current >/dev/null 2>&1; then
        "${BOOTSTRAP_SCRIPT}"
    fi

    rm -rf "${BUILD_DIR}" "${PREFIX_DIR}"
    mkdir -p \
        "${PREFIX_DIR}/lib" \
        "${PREFIX_DIR}/include/lsquic" \
        "${PREFIX_DIR}/include/boringssl" \
        "${boringssl_prefix}/lib"

    cmake \
        -S "${SOURCE_DIR}/boringssl" \
        -B "${boringssl_build}" \
        -GNinja \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
        -DBUILD_TESTING=OFF \
        -DCMAKE_INSTALL_PREFIX="${BUILD_DIR}/boringssl-prefix"
    cmake --build "${boringssl_build}" --target ssl crypto -j"${JOBS}"

    cmake \
        -S "${SOURCE_DIR}/lsquic" \
        -B "${lsquic_build}" \
        -GNinja \
        -DCMAKE_BUILD_TYPE=Release \
        -DCMAKE_POSITION_INDEPENDENT_CODE=ON \
        -DLSQUIC_SHARED_LIB=OFF \
        -DLSQUIC_BIN=OFF \
        -DLSQUIC_TESTS=OFF \
        -DLSQUIC_LIBSSL=BORINGSSL \
        -DBORINGSSL_INCLUDE="${SOURCE_DIR}/boringssl/include" \
        -DBORINGSSL_LIB_ssl="${boringssl_build}/libssl.a" \
        -DBORINGSSL_LIB_crypto="${boringssl_build}/libcrypto.a" \
        -DCMAKE_INSTALL_PREFIX="${PREFIX_DIR}"
    cmake --build "${lsquic_build}" --target lsquic -j"${JOBS}"

    lsquic_archive="$(find "${lsquic_build}" -name 'liblsquic.a' -print -quit)"
    if [[ -z "${lsquic_archive}" || ! -f "${lsquic_archive}" ]]; then
        echo "Failed to locate built liblsquic.a under ${lsquic_build}" >&2
        exit 1
    fi

    case "$(host_os)" in
        darwin)
            shared_link_args=(
                -dynamiclib
                -install_name
                "@rpath/$(runtime_library_name)"
                -o
                "${library}"
                -Wl,-all_load
                "${lsquic_archive}"
                "${boringssl_build}/libssl.a"
                "${boringssl_build}/libcrypto.a"
                -lz
                -lpthread
                -lm
                -lc++
            )
            ;;
        *)
            shared_link_args=(
                -shared
                -Wl,-soname,liblsquic.so
                -o
                "${library}"
                -Wl,--whole-archive
                "${lsquic_archive}"
                "${boringssl_build}/libssl.a"
                "${boringssl_build}/libcrypto.a"
                -Wl,--no-whole-archive
                -lz
                -lpthread
                -lm
                -lstdc++
            )
            ;;
    esac

    cc "${shared_link_args[@]}"

    install -m 0644 "${SOURCE_DIR}/lsquic/include/lsquic.h" "${PREFIX_DIR}/include/lsquic/lsquic.h"
    install -m 0644 "${SOURCE_DIR}/lsquic/include/lsquic_types.h" "${PREFIX_DIR}/include/lsquic/lsquic_types.h"
    install -m 0644 "${SOURCE_DIR}/lsquic/include/lsxpack_header.h" "${PREFIX_DIR}/include/lsquic/lsxpack_header.h"
    cp -R "${SOURCE_DIR}/boringssl/include/openssl" "${PREFIX_DIR}/include/boringssl/"
    install -m 0644 "${boringssl_build}/libssl.a" "${boringssl_prefix}/lib/libssl.a"
    install -m 0644 "${boringssl_build}/libcrypto.a" "${boringssl_prefix}/lib/libcrypto.a"
    write_metadata "${library}"
    verify_runtime
}

case "${MODE}" in
    --verify-current)
        verify_runtime
        echo "LSQUIC runtime is current: ${PREFIX_DIR}"
        ;;
    --print-env)
        print_env
        ;;
    --clean)
        clean_runtime
        ;;
    build)
        build_runtime
        print_env
        ;;
esac
