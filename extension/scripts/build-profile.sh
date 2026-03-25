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

    if ! grep -Eq '^[[:space:]]*wirefilter-engine[[:space:]]*=.*branch[[:space:]]*=' "${manifest_path}"; then
        if perl -0pi -e \
            's/wirefilter-engine\s*=\s*\{[^\n]*\}/wirefilter-engine = { git = "https:\/\/github.com\/cloudflare\/wirefilter.git", branch = "master" }/g' \
            "${manifest_path}"; then
            echo "Patched qlog-dancer wirefilter dependency to branch 'master' in ${manifest_path}." >&2
            cargo_lock_args=()
            cleanup_wirefilter_git_cache
        else
            echo "Failed to patch wirefilter dependency in ${manifest_path}; keeping upstream lockfile." >&2
        fi
    fi
}

cleanup_wirefilter_git_cache() {
    if [[ ! -d "${CARGO_HOME}/git" ]]; then
        return
    fi

    rm -rf "${CARGO_HOME}/git/db/wirefilter-"* \
        "${CARGO_HOME}/git/checkouts/wirefilter-"* \
        "${CARGO_HOME}/git/checkouts/.tmp-*wirefilter"* || true
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

apply_pkg_config_curl_flags() {
    local curl_cppflags;
    local curl_ldflags;

    if command -v pkg-config >/dev/null 2>&1; then
        curl_cppflags="$(pkg-config --cflags libcurl || true)"
        curl_ldflags="$(pkg-config --libs libcurl || true)"
    else
        curl_cppflags=""
        curl_ldflags=""
    fi

    if [[ -n "${curl_cppflags}" ]]; then
        profile_cflags="${profile_cflags} ${curl_cppflags}"
        profile_cppflags="${profile_cppflags} ${curl_cppflags}";
    fi

    if [[ -n "${curl_ldflags}" ]]; then
        profile_ldflags="${profile_ldflags} ${curl_ldflags}"
    fi

    if [[ -z "${curl_cppflags}" ]] || ! grep -q -- "-I" <<<"${curl_cppflags}"; then
        ensure_system_curl_include_path
    fi
}

ensure_quiche_checkout() {
    if [[ -d "${QUICHE_DIR}" && ! -d "${QUICHE_DIR}/.git" ]]; then
        if [[ -d "${QUICHE_DIR}" && -f "${QUICHE_DIR}/quiche/Cargo.toml" && -d "${QUICHE_DIR}/apps" ]]; then
            return
        fi
        rm -rf "${QUICHE_DIR}"
    fi

    if [[ -d "${QUICHE_DIR}/.git" ]]; then
        return
    fi

    if [[ -d "${QUICHE_DIR}" ]]; then
        if [[ -f "${QUICHE_DIR}/quiche/Cargo.toml" && -d "${QUICHE_DIR}/apps" ]]; then
            return
        fi
        rm -rf "${QUICHE_DIR}"
    fi

    echo "Restoring external quiche checkout under ${QUICHE_DIR}" >&2
    git clone --recursive "${QUICHE_REMOTE_URL}" "${QUICHE_DIR}"
}

ensure_quiche_checkout

if [[ ! -f "${QUICHE_DIR}/quiche/deps/boringssl/CMakeLists.txt" ]]; then
    git -C "${QUICHE_DIR}" submodule update --init --recursive quiche/deps/boringssl
fi

normalize_wirefilter_dependency
cleanup_wirefilter_git_cache
validate_curl_headers
apply_pkg_config_curl_flags

echo "Building King profile: ${PROFILE}"
echo "Compiler: ${profile_cc}"
echo "Jobs: ${JOBS}"

if ! cargo build \
    --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
    --package quiche \
    "${cargo_args[@]}" \
    "${cargo_lock_args[@]}" \
    --features ffi
then
    if [[ "${#cargo_lock_args[@]}" -gt 0 ]]; then
        echo "Locked quiche build failed; retrying unlocked to recover from stale lock or git cache state." >&2
        cargo_lock_args=()
        cleanup_wirefilter_git_cache
        cargo build \
            --manifest-path "${QUICHE_DIR}/quiche/Cargo.toml" \
            --package quiche \
            "${cargo_args[@]}" \
            --features ffi
    else
        echo "Locked quiche build failed without lock available; check upstream cargo failures." >&2
        exit 1
    fi
fi

if ! cargo build \
    --manifest-path "${QUICHE_DIR}/apps/Cargo.toml" \
    "${cargo_args[@]}" \
    "${cargo_lock_args[@]}" \
    --bin quiche-server
then
    if [[ "${#cargo_lock_args[@]}" -gt 0 ]]; then
        echo "Locked quiche-server build failed; retrying unlocked to recover from stale lock or git cache state." >&2
        cargo_lock_args=()
        cleanup_wirefilter_git_cache
        cargo build \
            --manifest-path "${QUICHE_DIR}/apps/Cargo.toml" \
            "${cargo_args[@]}" \
            --bin quiche-server
    else
        echo "Locked quiche-server build failed without lock available; check upstream cargo failures." >&2
        exit 1
    fi
fi

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
