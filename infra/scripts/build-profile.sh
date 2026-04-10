#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/build-profile.sh <release|debug|asan|ubsan>

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
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
QUICHE_DIR="${ROOT_DIR}/quiche"
QUICHE_BOOTSTRAP_SCRIPT="${SCRIPT_DIR}/bootstrap-quiche.sh"
TOOLCHAIN_LOCK_SCRIPT="${SCRIPT_DIR}/toolchain-lock.sh"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
JOBS="${JOBS:-$(nproc)}"

BASE_CFLAGS="${CFLAGS:-}"
BASE_CPPFLAGS="${CPPFLAGS:-}"
BASE_LDFLAGS="${LDFLAGS:-}"
BASE_CC="${CC:-}"
BASE_CXX="${CXX:-}"
export CARGO_NET_GIT_FETCH_WITH_CLI="${CARGO_NET_GIT_FETCH_WITH_CLI:-true}"
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

validate_curl_headers() {
    if [[ -f "${ROOT_DIR}/libcurl/include/curl/curl.h" ]]; then
        return
    fi

    if ! command -v pkg-config >/dev/null 2>&1; then
        echo "curl/curl.h is not available from vendored libcurl and pkg-config is not present." >&2
        exit 1
    fi

    if ! pkg-config --exists libcurl; then
        local system_include
        system_include="$(find_system_curl_include)"
        if [[ -n "${system_include}" ]]; then
            return
        fi
        echo "Build requires curl headers. Install a libcurl dev package (for example libcurl4-openssl-dev) or restore vendored libcurl." >&2
        exit 1
    fi
}

ensure_system_curl_include_path() {
    local system_include
    if [[ -f "${ROOT_DIR}/libcurl/include/curl/curl.h" ]]; then
        return
    fi

    system_include="$(find_system_curl_include)"
    if [[ -n "${system_include}" ]]; then
        profile_cflags="${profile_cflags} -I${system_include}"
        profile_cppflags="${profile_cppflags} -I${system_include}"
    fi
}

find_system_curl_include() {
    local multiarch

    if [[ -f /usr/include/curl/curl.h ]]; then
        echo "/usr/include"
        return
    fi

    multiarch="$(cc -print-multiarch 2>/dev/null || true)"
    if [[ -n "${multiarch}" && -f "/usr/include/${multiarch}/curl/curl.h" ]]; then
        echo "/usr/include/${multiarch}"
        return
    fi

    if [[ -f /usr/include/x86_64-linux-gnu/curl/curl.h ]]; then
        echo "/usr/include/x86_64-linux-gnu"
        return
    fi

    if [[ -f /usr/include/arm-linux-gnu/curl/curl.h ]]; then
        echo "/usr/include/arm-linux-gnu"
        return
    fi

    if [[ -f /usr/include/aarch64-linux-gnu/curl/curl.h ]]; then
        echo "/usr/include/aarch64-linux-gnu"
        return
    fi

    if [[ -f /usr/local/include/curl/curl.h ]]; then
        echo "/usr/local/include"
        return
    fi

    echo ""
}

apply_pkg_config_curl_cppflags() {
    local curl_cppflags;

    if command -v pkg-config >/dev/null 2>&1; then
        curl_cppflags="$(pkg-config --cflags libcurl || true)"
    else
        curl_cppflags=""
    fi

    if [[ -n "${curl_cppflags}" ]]; then
        profile_cflags="${profile_cflags} ${curl_cppflags}"
        profile_cppflags="${profile_cppflags} ${curl_cppflags}";
    fi

    if [[ -z "${curl_cppflags}" ]] || ! grep -q -- "-I" <<<"${curl_cppflags}"; then
        ensure_system_curl_include_path
    fi
}

validate_curl_headers
apply_pkg_config_curl_cppflags

bash "${TOOLCHAIN_LOCK_SCRIPT}" --verify-rust
if [[ "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
    "${QUICHE_BOOTSTRAP_SCRIPT}" --verify-lock
    "${QUICHE_BOOTSTRAP_SCRIPT}" --verify-current
else
    "${QUICHE_BOOTSTRAP_SCRIPT}"
fi

echo "Building King profile: ${PROFILE}"
echo "Compiler: ${profile_cc}"
echo "Jobs: ${JOBS}"

cargo fetch \
    --locked \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml"

cargo fetch \
    --locked \
    --manifest-path "${QUICHE_DIR}/apps/Cargo.toml"

cargo build \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
    --package quiche \
    "${cargo_args[@]}" \
    --locked \
    --features ffi

cargo build \
    --manifest-path "${QUICHE_DIR}/apps/Cargo.toml" \
    "${cargo_args[@]}" \
    --locked \
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
