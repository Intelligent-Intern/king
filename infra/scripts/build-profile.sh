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
  KING_CURL_INCLUDE_DIR
                 Directory containing curl/curl.h when not using vendored libcurl
                 or an OS default include path.
  KING_CURL_CFLAGS
                 Explicit libcurl compile flags. Appended before configure.
  KING_OPENSSL_INCLUDE_DIR
                 Directory containing openssl/ssl.h when not using vendored OpenSSL
                 or an OS default include path.
  KING_OPENSSL_CFLAGS
                 Explicit OpenSSL compile flags. Appended before configure.
  KING_OPENSSL_LIBS
                 Explicit OpenSSL linker flags. Appended before configure.
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
NATIVE_CURL_PREREQS_SCRIPT="${SCRIPT_DIR}/native-curl-build-prereqs.sh"
NATIVE_OPENSSL_PREREQS_SCRIPT="${SCRIPT_DIR}/native-openssl-build-prereqs.sh"
PHPIZE_GENERATED_LIST="${SCRIPT_DIR}/phpize-generated-files.list"
PROFILE_DIR="${EXT_DIR}/build/profiles/${PROFILE}"

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
        CYGWIN*|MINGW*|MSYS*)
            printf '%s\n' "windows"
            ;;
        *)
            uname -s | tr '[:upper:]' '[:lower:]'
            ;;
    esac
}

lsquic_runtime_library_name() {
    case "$(host_os)" in
        darwin)
            printf '%s\n' "liblsquic.dylib"
            ;;
        windows)
            printf '%s\n' "lsquic.dll"
            ;;
        *)
            printf '%s\n' "liblsquic.so"
            ;;
    esac
}

boringssl_static_link_libs() {
    case "$(host_os)" in
        darwin)
            printf '%s %s %s\n' \
                "${lsquic_runtime_prefix}/boringssl/lib/libssl.a" \
                "${lsquic_runtime_prefix}/boringssl/lib/libcrypto.a" \
                "-lc++"
            ;;
        windows)
            printf '%s %s\n' \
                "${lsquic_runtime_prefix}/boringssl/lib/ssl.lib" \
                "${lsquic_runtime_prefix}/boringssl/lib/crypto.lib"
            ;;
        *)
            printf '%s %s %s %s\n' \
                "-Wl,--exclude-libs,ALL" \
                "${lsquic_runtime_prefix}/boringssl/lib/libssl.a" \
                "${lsquic_runtime_prefix}/boringssl/lib/libcrypto.a" \
                "-lstdc++"
            ;;
    esac
}

JOBS="${JOBS:-$(default_jobs)}"

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
            if [[ -e "${target_path}" && ! -w "${target_path}" ]]; then
                chmod u+w "${target_path}"
            fi
            cp -a "${snapshot_path}" "${target_path}"
        else
            if [[ -e "${target_path}" && ! -w "${target_path}" ]]; then
                chmod u+w "${target_path}"
            fi
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

apply_native_curl_cflags() {
    local curl_cflags=""

    curl_cflags="$(
        KING_NATIVE_CURL_ROOT_DIR="${ROOT_DIR}" \
        KING_NATIVE_CURL_HOST_OS="$(uname -s)" \
        KING_NATIVE_CURL_CC="${profile_cc%% *}" \
        "${NATIVE_CURL_PREREQS_SCRIPT}" --cflags
    )"

    if [[ -n "${curl_cflags}" ]]; then
        profile_cflags="${profile_cflags} ${curl_cflags}"
        profile_cppflags="${profile_cppflags} ${curl_cflags}"
    fi
}

apply_native_openssl_flags() {
    local openssl_cflags=""
    local openssl_ldflags=""
    local openssl_include_dir="${KING_OPENSSL_INCLUDE_DIR:-}"
    local openssl_library_dir="${KING_OPENSSL_LIBRARY_DIR:-}"

    if [[ -n "${lsquic_runtime_prefix}" ]]; then
        openssl_include_dir="${openssl_include_dir:-${lsquic_runtime_prefix}/boringssl/include}"
        openssl_library_dir="${openssl_library_dir:-${lsquic_runtime_prefix}/boringssl/lib}"
    fi

    openssl_cflags="$(
        KING_NATIVE_OPENSSL_ROOT_DIR="${ROOT_DIR}" \
        KING_NATIVE_OPENSSL_HOST_OS="$(uname -s)" \
        KING_NATIVE_OPENSSL_CC="${profile_cc%% *}" \
        KING_OPENSSL_INCLUDE_DIR="${openssl_include_dir}" \
        KING_OPENSSL_LIBRARY_DIR="${openssl_library_dir}" \
        "${NATIVE_OPENSSL_PREREQS_SCRIPT}" --cflags
    )"
    openssl_ldflags="$(
        KING_NATIVE_OPENSSL_ROOT_DIR="${ROOT_DIR}" \
        KING_NATIVE_OPENSSL_HOST_OS="$(uname -s)" \
        KING_NATIVE_OPENSSL_CC="${profile_cc%% *}" \
        KING_OPENSSL_INCLUDE_DIR="${openssl_include_dir}" \
        KING_OPENSSL_LIBRARY_DIR="${openssl_library_dir}" \
        "${NATIVE_OPENSSL_PREREQS_SCRIPT}" --ldflags
    )"

    if [[ -n "${openssl_cflags}" ]]; then
        profile_cflags="${profile_cflags} ${openssl_cflags}"
        profile_cppflags="${profile_cppflags} ${openssl_cflags}"
    fi
    if [[ -n "${openssl_ldflags}" ]]; then
        profile_ldflags="${profile_ldflags}${profile_ldflags:+ }${openssl_ldflags}"
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

    runtime_library="${lsquic_runtime_prefix}/lib/$(lsquic_runtime_library_name)"
    runtime_metadata="${lsquic_runtime_prefix}/king-lsquic-runtime.env"

    mkdir -p "${PROFILE_DIR}/runtime"
    install -m 0644 "${runtime_library}" "${PROFILE_DIR}/runtime/$(lsquic_runtime_library_name)"
    if [[ -f "${runtime_metadata}" ]]; then
        install -m 0644 "${runtime_metadata}" "${PROFILE_DIR}/runtime/king-lsquic-runtime.env"
    fi
}

patch_generated_libtool_for_host() {
    case "$(host_os)" in
        darwin)
            # Modern Apple ld deprecates both -undefined suppress and
            # -single_module. phpize regenerates libtool from the host PHP
            # toolchain, so normalize the generated script before compiling.
            perl -0pi -e '
                s/\$\{wl\}-flat_namespace\s+\$\{wl\}-undefined\s+\$\{wl\}suppress/\${wl}-undefined \${wl}dynamic_lookup/g;
                s/\$\{wl\}-undefined\s+\$\{wl\}suppress/\${wl}-undefined \${wl}dynamic_lookup/g;
                s/\\\$\{wl\}-flat_namespace\s+\\\$\{wl\}-undefined\s+\\\$\{wl\}suppress/\\\${wl}-undefined \\\${wl}dynamic_lookup/g;
                s/\\\$\{wl\}-undefined\s+\\\$\{wl\}suppress/\\\${wl}-undefined \\\${wl}dynamic_lookup/g;
                s/allow_undefined_flag=\x27\$\{wl\}-flat_namespace \$\{wl\}-undefined \$\{wl\}suppress\x27/allow_undefined_flag=\x27\$\{wl\}-undefined \$\{wl\}dynamic_lookup\x27/g;
                s/allow_undefined_flag=\x27\$\{wl\}-undefined \$\{wl\}suppress\x27/allow_undefined_flag=\x27\$\{wl\}-undefined \$\{wl\}dynamic_lookup\x27/g;
                s/allow_undefined_flag="\\\$\{wl\}-flat_namespace \\\$\{wl\}-undefined \\\$\{wl\}suppress"/allow_undefined_flag="\\\${wl}-undefined \\\${wl}dynamic_lookup"/g;
                s/allow_undefined_flag="\\\$\{wl\}-undefined \\\$\{wl\}suppress"/allow_undefined_flag="\\\${wl}-undefined \\\${wl}dynamic_lookup"/g;
                s/_lt_dar_allow_undefined=\x27\$\{wl\}-flat_namespace \$\{wl\}-undefined \$\{wl\}suppress\x27/_lt_dar_allow_undefined=\x27\$\{wl\}-undefined \$\{wl\}dynamic_lookup\x27/g;
                s/_lt_dar_allow_undefined=\x27\$\{wl\}-undefined \$\{wl\}suppress\x27/_lt_dar_allow_undefined=\x27\$\{wl\}-undefined \$\{wl\}dynamic_lookup\x27/g;
            ' libtool
            if grep -q -- '-undefined .*suppress\|flat_namespace' libtool; then
                echo "Generated Darwin libtool still contains deprecated undefined/suppress flags." >&2
                grep -n -- '-undefined .*suppress\|flat_namespace' libtool >&2 || true
                exit 1
            fi
            ;;
        linux|windows)
            ;;
    esac
}

apply_native_curl_cflags
resolve_lsquic_runtime_prefix
apply_native_openssl_flags

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

if [[ "$(host_os)" == "darwin" ]]; then
    CONFIGURE_ENV+=(
        lt_cv_apple_cc_single_mod=no
        lt_cv_sys_max_cmd_len=196608
    )
fi

if [[ -n "${lsquic_runtime_prefix}" ]]; then
    CONFIGURE_ENV+=(
        KING_LSQUIC_INCLUDE_DIR="${lsquic_runtime_prefix}/include/lsquic"
        KING_LSQUIC_LIBRARY_DIR="${lsquic_runtime_prefix}/lib"
        KING_BORINGSSL_CFLAGS="-DKING_BORINGSSL_STATIC_LINK=1"
        KING_BORINGSSL_LIBS="$(boringssl_static_link_libs)"
    )
fi

env "${CONFIGURE_ENV[@]}" ./configure --enable-king
patch_generated_libtool_for_host

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
