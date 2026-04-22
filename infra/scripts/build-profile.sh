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
LSQUIC_BOOTSTRAP_SCRIPT="${SCRIPT_DIR}/bootstrap-lsquic.sh"
TOOLCHAIN_LOCK_SCRIPT="${SCRIPT_DIR}/toolchain-lock.sh"
TOOLCHAIN_LOCK_FILE="${SCRIPT_DIR}/toolchain.lock"
PHPIZE_GENERATED_LIST="${SCRIPT_DIR}/phpize-generated-files.list"
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
sanitizer_kind=""
cargo_target="release"
cargo_args=(--release)
declare -a PHPIZE_GENERATED_RELATIVE_PATHS=()
PHPIZE_SNAPSHOT_DIR=""
PINNED_RUST_TOOLCHAIN=""

trim_ascii_whitespace() {
    local value="$1"

    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s\n' "${value}"
}

load_phpize_generated_relative_paths() {
    local raw_line=""
    local normalized_line=""

    if [[ ! -f "${PHPIZE_GENERATED_LIST}" ]]; then
        echo "Missing phpize generated-file list: ${PHPIZE_GENERATED_LIST}" >&2
        exit 1
    fi

    while IFS= read -r raw_line || [[ -n "${raw_line}" ]]; do
        normalized_line="${raw_line%%#*}"
        normalized_line="$(trim_ascii_whitespace "${normalized_line}")"
        if [[ -z "${normalized_line}" ]]; then
            continue
        fi

        if [[ "${normalized_line}" != extension/* ]]; then
            echo "Invalid path in ${PHPIZE_GENERATED_LIST}: ${normalized_line}" >&2
            exit 1
        fi

        PHPIZE_GENERATED_RELATIVE_PATHS+=("${normalized_line#extension/}")
    done < "${PHPIZE_GENERATED_LIST}"
}

load_pinned_toolchain_version() {
    if [[ ! -f "${TOOLCHAIN_LOCK_FILE}" ]]; then
        echo "Missing toolchain lock file: ${TOOLCHAIN_LOCK_FILE}" >&2
        exit 1
    fi

    # shellcheck source=/dev/null
    source "${TOOLCHAIN_LOCK_FILE}"

    if [[ -z "${KING_RUST_TOOLCHAIN_VERSION:-}" ]]; then
        echo "KING_RUST_TOOLCHAIN_VERSION is empty in ${TOOLCHAIN_LOCK_FILE}." >&2
        exit 1
    fi

    PINNED_RUST_TOOLCHAIN="${KING_RUST_TOOLCHAIN_VERSION}"
}

activate_pinned_rust_toolchain() {
    local rustup_bin=""
    local rustc_version=""
    local cargo_version=""
    local toolchain_installed=0

    if [[ -z "${PINNED_RUST_TOOLCHAIN}" ]]; then
        return 1
    fi

    rustup_bin="$(command -v rustup || true)"
    if [[ -z "${rustup_bin}" ]]; then
        return 1
    fi

    if [[ ":${PATH}:" != *":${HOME}/.cargo/bin:"* && -d "${HOME}/.cargo/bin" ]]; then
        export PATH="${HOME}/.cargo/bin:${PATH}"
    fi

    if rustup toolchain list --installed 2>/dev/null | awk '{print $1}' | grep -Fq "${PINNED_RUST_TOOLCHAIN}"; then
        toolchain_installed=1
    fi

    if [[ "${toolchain_installed}" -ne 1 ]]; then
        echo "Installing pinned Rust toolchain ${PINNED_RUST_TOOLCHAIN} via rustup." >&2
        rustup toolchain install "${PINNED_RUST_TOOLCHAIN}" --profile minimal
    fi

    export RUSTUP_TOOLCHAIN="${PINNED_RUST_TOOLCHAIN}"

    rustc_version="$(rustc --version 2>/dev/null | awk '{print $2}')"
    cargo_version="$(cargo --version 2>/dev/null | awk '{print $2}')"

    if [[ "${rustc_version}" != "${PINNED_RUST_TOOLCHAIN}" || "${cargo_version}" != "${PINNED_RUST_TOOLCHAIN}" ]]; then
        return 1
    fi

    echo "Activated pinned Rust toolchain ${PINNED_RUST_TOOLCHAIN}." >&2
    return 0
}

snapshot_phpize_generated_files() {
    local relative_path=""
    local source_path=""
    local snapshot_path=""
    local state_path=""

    PHPIZE_SNAPSHOT_DIR="$(mktemp -d)"

    for relative_path in "${PHPIZE_GENERATED_RELATIVE_PATHS[@]}"; do
        source_path="${EXT_DIR}/${relative_path}"
        snapshot_path="${PHPIZE_SNAPSHOT_DIR}/${relative_path}"
        state_path="${snapshot_path}.state"
        mkdir -p "$(dirname "${snapshot_path}")"

        if [[ -e "${source_path}" ]]; then
            cp -a "${source_path}" "${snapshot_path}"
            printf '%s\n' "present" > "${state_path}"
        else
            printf '%s\n' "absent" > "${state_path}"
        fi
    done
}

restore_phpize_generated_files() {
    local relative_path=""
    local snapshot_path=""
    local state_path=""
    local target_path=""
    local state_value=""

    if [[ -z "${PHPIZE_SNAPSHOT_DIR}" || ! -d "${PHPIZE_SNAPSHOT_DIR}" ]]; then
        return 0
    fi

    for relative_path in "${PHPIZE_GENERATED_RELATIVE_PATHS[@]}"; do
        snapshot_path="${PHPIZE_SNAPSHOT_DIR}/${relative_path}"
        state_path="${snapshot_path}.state"
        target_path="${EXT_DIR}/${relative_path}"
        state_value=""
        if [[ -f "${state_path}" ]]; then
            state_value="$(<"${state_path}")"
        fi

        if [[ "${state_value}" == "present" ]]; then
            mkdir -p "$(dirname "${target_path}")"
            cp -a "${snapshot_path}" "${target_path}"
        else
            rm -f "${target_path}"
        fi
    done

    rm -rf "${PHPIZE_SNAPSHOT_DIR}"
    PHPIZE_SNAPSHOT_DIR=""
}

resolve_sanitizer_arch_suffix() {
    local compiler_bin="$1"
    local machine=""

    machine="$("${compiler_bin}" -dumpmachine 2>/dev/null || true)"
    case "${machine}" in
        x86_64*|amd64*)
            printf '%s\n' "x86_64"
            return 0
            ;;
        aarch64*|arm64*)
            printf '%s\n' "aarch64"
            return 0
            ;;
        armv7*|armv6*|arm*)
            printf '%s\n' "armhf"
            return 0
            ;;
        riscv64*)
            printf '%s\n' "riscv64"
            return 0
            ;;
    esac

    printf '%s\n' ""
}

resolve_sanitizer_runtime_path() {
    local kind="$1"
    local compiler_bin="$2"
    local suffix=""
    local candidate=""
    local runtime_path=""
    local -a candidates=()

    suffix="$(resolve_sanitizer_arch_suffix "${compiler_bin}")"

    case "${kind}" in
        asan)
            if [[ -n "${suffix}" ]]; then
                candidates+=("libclang_rt.asan-${suffix}.so")
            fi
            candidates+=("libclang_rt.asan.so")
            ;;
        ubsan)
            if [[ -n "${suffix}" ]]; then
                candidates+=("libclang_rt.ubsan_standalone-${suffix}.so")
            fi
            candidates+=("libclang_rt.ubsan_standalone.so")
            ;;
        *)
            return 1
            ;;
    esac

    for candidate in "${candidates[@]}"; do
        runtime_path="$("${compiler_bin}" -print-file-name="${candidate}" 2>/dev/null || true)"
        if [[ -n "${runtime_path}" && "${runtime_path}" != "${candidate}" && -f "${runtime_path}" ]]; then
            printf '%s\n' "${runtime_path}"
            return 0
        fi
    done

    return 1
}

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
        sanitizer_kind="asan"
        ;;
    ubsan)
        profile_cc="${BASE_CC:-clang}"
        profile_cxx="${BASE_CXX:-clang++}"
        profile_cflags="-O1 -g -fno-omit-frame-pointer -fsanitize=undefined -fno-sanitize-recover=all"
        profile_ldflags="-fsanitize=undefined -fno-sanitize-recover=all${BASE_LDFLAGS:+ ${BASE_LDFLAGS}}"
        sanitizer_kind="ubsan"
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

load_pinned_toolchain_version
if ! bash "${TOOLCHAIN_LOCK_SCRIPT}" --verify-rust; then
    if ! activate_pinned_rust_toolchain; then
        echo "Unable to activate pinned Rust toolchain ${PINNED_RUST_TOOLCHAIN}." >&2
        exit 1
    fi
    bash "${TOOLCHAIN_LOCK_SCRIPT}" --verify-rust
fi
if [[ "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
    "${LSQUIC_BOOTSTRAP_SCRIPT}" --verify-lock

    # CI checkouts may not contain the generated source cache. Rebuild it from
    # the deterministic lock whenever the local cache is missing or stale.
    if ! "${LSQUIC_BOOTSTRAP_SCRIPT}" --verify-current; then
        echo "Pinned LSQUIC source cache is missing in CI; bootstrapping pinned source cache." >&2
        "${LSQUIC_BOOTSTRAP_SCRIPT}"
    fi
else
    "${LSQUIC_BOOTSTRAP_SCRIPT}"
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

load_phpize_generated_relative_paths
snapshot_phpize_generated_files
trap restore_phpize_generated_files EXIT

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

if [[ -n "${sanitizer_kind}" ]]; then
    compiler_bin="${profile_cc%% *}"
    runtime_path="$(resolve_sanitizer_runtime_path "${sanitizer_kind}" "${compiler_bin}")"

    if [[ -z "${runtime_path}" || ! -f "${runtime_path}" ]]; then
        echo "Failed to resolve ${sanitizer_kind} runtime via ${compiler_bin}." >&2
        exit 1
    fi

    cp "${runtime_path}" "${PROFILE_DIR}/$(basename "${runtime_path}")"
fi

restore_phpize_generated_files
trap - EXIT

echo "Staged ${PROFILE} artifacts under ${PROFILE_DIR}"
