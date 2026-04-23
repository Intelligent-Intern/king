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
  KING_LSQUIC_RUNTIME_PREFIX
                 Existing LSQUIC runtime prefix to use for HTTP/3 builds.
  KING_LSQUIC_RUNTIME_BUILD=1
                 Build the pinned LSQUIC runtime before configuring King.
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
LSQUIC_BOOTSTRAP_SCRIPT="${SCRIPT_DIR}/bootstrap-lsquic.sh"
LSQUIC_RUNTIME_SCRIPT="${SCRIPT_DIR}/build-lsquic-runtime.sh"
PHPIZE_GENERATED_LIST="${SCRIPT_DIR}/phpize-generated-files.list"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"
JOBS="${JOBS:-$(nproc)}"

BASE_CFLAGS="${CFLAGS:-}"
BASE_CPPFLAGS="${CPPFLAGS:-}"
BASE_LDFLAGS="${LDFLAGS:-}"
BASE_CC="${CC:-}"
BASE_CXX="${CXX:-}"

profile_cc=""
profile_cxx=""
profile_cflags=""
profile_cppflags="${BASE_CPPFLAGS}"
profile_ldflags="${BASE_LDFLAGS}"
sanitizer_kind=""
lsquic_runtime_prefix="${KING_LSQUIC_RUNTIME_PREFIX:-}"
declare -a PHPIZE_GENERATED_RELATIVE_PATHS=()
declare -a CONFIGURE_ENV=()
PHPIZE_SNAPSHOT_DIR=""

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

resolve_lsquic_runtime_prefix() {
    if [[ "${KING_LSQUIC_RUNTIME_BUILD:-0}" == "1" ]]; then
        "${LSQUIC_RUNTIME_SCRIPT}"
        lsquic_runtime_prefix="${KING_LSQUIC_RUNTIME_PREFIX:-${ROOT_DIR}/.cache/king/lsquic/runtime/prefix}"
    fi

    if [[ -z "${lsquic_runtime_prefix}" ]]; then
        return 0
    fi

    KING_LSQUIC_RUNTIME_PREFIX="${lsquic_runtime_prefix}" "${LSQUIC_RUNTIME_SCRIPT}" --verify-current
}

stage_lsquic_runtime() {
    local runtime_library=""
    local runtime_metadata=""

    if [[ -z "${lsquic_runtime_prefix}" ]]; then
        return 0
    fi

    runtime_library="${lsquic_runtime_prefix}/lib/liblsquic.so"
    runtime_metadata="${lsquic_runtime_prefix}/king-lsquic-runtime.env"

    mkdir -p "${PROFILE_DIR}/runtime"
    install -m 0644 "${runtime_library}" "${PROFILE_DIR}/runtime/liblsquic.so"
    if [[ -f "${runtime_metadata}" ]]; then
        install -m 0644 "${runtime_metadata}" "${PROFILE_DIR}/runtime/king-lsquic-runtime.env"
    fi
}

validate_curl_headers
apply_pkg_config_curl_cppflags
resolve_lsquic_runtime_prefix

if [[ "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
    "${LSQUIC_BOOTSTRAP_SCRIPT}" --verify-lock

    # CI checkouts may not contain the generated source cache. Rebuild it from
    # the deterministic lock whenever the local cache is missing or stale. A
    # downloaded LSQUIC runtime artifact already carries matching lock metadata.
    if [[ -z "${lsquic_runtime_prefix}" ]] && ! "${LSQUIC_BOOTSTRAP_SCRIPT}" --verify-current; then
        echo "Pinned LSQUIC source cache is missing in CI; bootstrapping pinned source cache." >&2
        "${LSQUIC_BOOTSTRAP_SCRIPT}"
    fi
else
    if [[ -z "${lsquic_runtime_prefix}" ]]; then
        "${LSQUIC_BOOTSTRAP_SCRIPT}"
    fi
fi

echo "Building King profile: ${PROFILE}"
echo "Compiler: ${profile_cc}"
echo "Jobs: ${JOBS}"

load_phpize_generated_relative_paths
snapshot_phpize_generated_files
trap restore_phpize_generated_files EXIT

cd "${EXT_DIR}"

if [[ -f Makefile ]]; then
    make clean >/dev/null 2>&1 || true
fi

phpize --clean >/dev/null 2>&1 || true
phpize

CONFIGURE_ENV=(
    CC="${profile_cc}"
    CXX="${profile_cxx}"
    CFLAGS="${profile_cflags}"
    CPPFLAGS="${profile_cppflags}"
    LDFLAGS="${profile_ldflags}"
)

if [[ -n "${lsquic_runtime_prefix}" ]]; then
    CONFIGURE_ENV+=(
        KING_LSQUIC_INCLUDE_DIR="${lsquic_runtime_prefix}/include/lsquic"
        KING_LSQUIC_LIBRARY_DIR="${lsquic_runtime_prefix}/lib"
    )
fi

env "${CONFIGURE_ENV[@]}" ./configure --enable-king

make -j"${JOBS}"

mkdir -p "${PROFILE_DIR}"
cp "${EXT_DIR}/modules/king.so" "${PROFILE_DIR}/king.so"
stage_lsquic_runtime

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
