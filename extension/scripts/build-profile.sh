#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./scripts/build-profile.sh <release|debug|asan|ubsan>

Builds the King extension and stages the resulting artifact under:
  extension/build/profiles/<profile>/

Environment variables:
  JOBS           Number of parallel make jobs
  CC             Override the C compiler for release/debug profiles
  CXX            Override the C++ compiler for release/debug profiles
  CFLAGS         Extra C compiler flags appended to the profile defaults
  CPPFLAGS       Extra preprocessor flags appended to the profile defaults
  LDFLAGS        Extra linker flags appended to the profile defaults
EOF
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

PROFILE="${1:-release}"

case "${PROFILE}" in
    release|debug|asan|ubsan)
        ;;
    *)
        echo "Unknown profile: ${PROFILE}" >&2
        usage >&2
        exit 1
        ;;
esac

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
ROOT_DIR="$(cd "${EXT_DIR}/.." && pwd)"
QUICHE_DIR="${ROOT_DIR}/quiche"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
JOBS="${JOBS:-$(nproc)}"
QUICHE_REMOTE_URL="${KING_QUICHE_REMOTE_URL:-https://github.com/cloudflare/quiche.git}"

BASE_CFLAGS="${CFLAGS:-}"
BASE_CPPFLAGS="${CPPFLAGS:-}"
BASE_LDFLAGS="${LDFLAGS:-}"
BASE_CC="${CC:-}"
BASE_CXX="${CXX:-}"
export CARGO_HOME="${CARGO_HOME:-${ROOT_DIR}/.cargo}"
mkdir -p "${CARGO_HOME}"

profile_cc=""
profile_cxx=""
profile_cflags=""
profile_cppflags="${BASE_CPPFLAGS}"
profile_ldflags="${BASE_LDFLAGS}"
sanitizer_runtime=""
cargo_target="release"
cargo_args=(--release)
cargo_lock_args=(--locked)

case "${PROFILE}" in
    release)
        profile_cc="${BASE_CC:-cc}"
        profile_cxx="${BASE_CXX:-c++}"
        profile_cflags="-O2 -g -DNDEBUG"
        ;;
    debug)
        profile_cc="${BASE_CC:-cc}"
        profile_cxx="${BASE_CXX:-c++}"
        profile_cflags="-O0 -g3"
        cargo_target="debug"
        cargo_args=()
        ;;
    asan)
        profile_cc="${BASE_CC:-clang}"
        profile_cxx="${BASE_CXX:-clang++}"
        profile_cflags="-O1 -g -fno-omit-frame-pointer -fsanitize=address"
        profile_ldflags="-fsanitize=address${BASE_LDFLAGS:+ ${BASE_LDFLAGS}}"
        sanitizer_runtime="libclang_rt.asan-x86_64.so"
        ;;
    ubsan)
        profile_cc="${BASE_CC:-clang}"
        profile_cxx="${BASE_CXX:-clang++}"
        profile_cflags="-O1 -g -fno-omit-frame-pointer -fsanitize=undefined -fno-sanitize-recover=all"
        profile_ldflags="-fsanitize=undefined -fno-sanitize-recover=all${BASE_LDFLAGS:+ ${BASE_LDFLAGS}}"
        sanitizer_runtime="libclang_rt.ubsan_standalone-x86_64.so"
        ;;
esac

if [[ -n "${BASE_CFLAGS}" ]]; then
    profile_cflags="${profile_cflags} ${BASE_CFLAGS}"
fi

normalize_wirefilter_dependency() {
    local manifest_path="${QUICHE_DIR}/qlog-dancer/Cargo.toml"

    if [[ ! -f "${manifest_path}" ]]; then
        echo "No qlog-dancer manifest found at ${manifest_path}; using upstream lockfile as-is." >&2
        return
    fi

    if grep -q 'wirefilter-engine = { git = "https://github.com/cloudflare/wirefilter.git", rev=' "${manifest_path}"; then
        if perl -0pi -e \
            's/wirefilter-engine = \{ git = "https:\/\/github.com\/cloudflare\/wirefilter\.git",\s*rev="[^"]+"\s*\}/wirefilter-engine = { git = "https:\/\/github.com\/cloudflare\/wirefilter.git", branch = "master" }/' \
            "${manifest_path}"; then
            echo "Patched qlog-dancer wirefilter dependency to branch 'master' in ${manifest_path}." >&2
            cargo_lock_args=()
            rm -rf "${CARGO_HOME}/git/db/wirefilter-"*
        else
            echo "Failed to patch wirefilter dependency in ${manifest_path}; keeping upstream lockfile." >&2
        fi
    fi
}

validate_curl_headers() {
    if [[ -f "${ROOT_DIR}/libcurl/include/curl/curl.h" ]]; then
        return
    fi

    if ! command -v pkg-config >/dev/null 2>&1; then
        echo "curl/curl.h is not available from vendored libcurl and pkg-config is not present." >&2
        exit 1
    fi

    if ! pkg-config --exists libcurl; then
        echo "Build requires curl headers. Install a libcurl dev package (for example libcurl4-openssl-dev) or restore vendored libcurl." >&2
        exit 1
    fi
}

ensure_quiche_checkout() {
    if [[ -d "${QUICHE_DIR}/.git" ]]; then
        return
    fi

    if [[ -e "${QUICHE_DIR}" ]]; then
        echo "Existing path is blocking quiche checkout: ${QUICHE_DIR}" >&2
        exit 1
    fi

    echo "Restoring external quiche checkout under ${QUICHE_DIR}" >&2
    git clone --recursive "${QUICHE_REMOTE_URL}" "${QUICHE_DIR}"
}

ensure_quiche_checkout

if [[ ! -f "${QUICHE_DIR}/quiche/deps/boringssl/CMakeLists.txt" ]]; then
    git -C "${QUICHE_DIR}" submodule update --init --recursive quiche/deps/boringssl
fi

normalize_wirefilter_dependency
validate_curl_headers

if ! cargo metadata \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
    --format-version 1 \
    --locked >/dev/null 2>&1; then
    echo "quiche Cargo.lock is out of sync; falling back to an unlocked local build." >&2
    cargo_lock_args=()
fi

echo "Building King profile: ${PROFILE}"
echo "Compiler: ${profile_cc}"
echo "Jobs: ${JOBS}"

cargo build \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
    "${cargo_args[@]}" \
    "${cargo_lock_args[@]}" \
    --features ffi

cargo build \
    --manifest-path "${QUICHE_DIR}/apps/Cargo.toml" \
    "${cargo_args[@]}" \
    "${cargo_lock_args[@]}" \
    --bin quiche-server

cd "${EXT_DIR}"

if [[ -f Makefile ]]; then
    make clean >/dev/null 2>&1 || true
fi

phpize --clean >/dev/null 2>&1 || true
phpize

env \
    CC="${profile_cc}" \
    CXX="${profile_cxx}" \
    CFLAGS="${profile_cflags}" \
    CPPFLAGS="${profile_cppflags}" \
    LDFLAGS="${profile_ldflags}" \
    ./configure --enable-king

make -j"${JOBS}"

mkdir -p "${PROFILE_DIR}"
cp "${EXT_DIR}/modules/king.so" "${PROFILE_DIR}/king.so"
cp "${QUICHE_DIR}/target/${cargo_target}/libquiche.so" "${PROFILE_DIR}/libquiche.so"
cp "${QUICHE_DIR}/target/${cargo_target}/quiche-server" "${PROFILE_DIR}/quiche-server"

if [[ -n "${sanitizer_runtime}" ]]; then
    compiler_bin="${profile_cc%% *}"
    runtime_path="$("${compiler_bin}" -print-file-name="${sanitizer_runtime}")"

    if [[ -z "${runtime_path}" || "${runtime_path}" == "${sanitizer_runtime}" || ! -f "${runtime_path}" ]]; then
        echo "Failed to resolve sanitizer runtime '${sanitizer_runtime}' via ${compiler_bin}." >&2
        exit 1
    fi

    cp "${runtime_path}" "${PROFILE_DIR}/$(basename "${runtime_path}")"
fi

echo "Staged ${PROFILE} artifacts under ${PROFILE_DIR}"
