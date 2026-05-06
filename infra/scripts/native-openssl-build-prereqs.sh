#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="${KING_NATIVE_OPENSSL_ROOT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
HOST_OS="${KING_NATIVE_OPENSSL_HOST_OS:-$(uname -s)}"
PKG_CONFIG_BIN="${KING_NATIVE_OPENSSL_PKG_CONFIG_BIN:-pkg-config}"
CC_BIN="${KING_NATIVE_OPENSSL_CC:-cc}"

host_family() {
    case "${HOST_OS}" in
        Darwin|darwin)
            printf '%s\n' "darwin"
            ;;
        Linux|linux)
            printf '%s\n' "linux"
            ;;
        Windows_NT|windows|CYGWIN*|MINGW*|MSYS*)
            printf '%s\n' "windows"
            ;;
        *)
            printf '%s\n' "unknown"
            ;;
    esac
}

install_command() {
    case "$(host_family)" in
        darwin)
            printf '%s\n' "brew install openssl@3 pkg-config"
            ;;
        linux)
            printf '%s\n' "sudo apt-get update && sudo apt-get install -y libssl-dev pkg-config"
            ;;
        windows)
            printf '%s\n' "install OpenSSL development headers and libraries via vcpkg, MSYS2, or set KING_OPENSSL_INCLUDE_DIR/KING_OPENSSL_LIBRARY_DIR explicitly"
            ;;
        *)
            printf '%s\n' "install OpenSSL development headers and pkg-config for this OS"
            ;;
    esac
}

candidate_include_dirs() {
    if [[ -n "${KING_OPENSSL_INCLUDE_DIR:-}" ]]; then
        printf '%s\n' "${KING_OPENSSL_INCLUDE_DIR}"
    fi

    if [[ -n "${KING_OPENSSL_CFLAGS:-}" ]]; then
        local flag=""
        for flag in ${KING_OPENSSL_CFLAGS}; do
            case "${flag}" in
                -I?*)
                    printf '%s\n' "${flag#-I}"
                    ;;
            esac
        done
    fi

    printf '%s\n' \
        "${ROOT_DIR}/openssl/include" \
        "${ROOT_DIR}/boringssl/include" \
        "${ROOT_DIR}/.cache/king/lsquic/runtime/prefix/boringssl/include"

    if [[ "${KING_NATIVE_OPENSSL_DISABLE_SYSTEM:-0}" == "1" ]]; then
        return
    fi

    case "$(host_family)" in
        darwin)
            printf '%s\n' \
                "/opt/homebrew/opt/openssl@3/include" \
                "/opt/homebrew/opt/openssl/include" \
                "/usr/local/opt/openssl@3/include" \
                "/usr/local/opt/openssl/include" \
                "/opt/local/include" \
                "/usr/local/include"
            ;;
        linux)
            local multiarch=""
            multiarch="$("${CC_BIN}" -print-multiarch 2>/dev/null || true)"
            if [[ -n "${multiarch}" ]]; then
                printf '%s\n' "/usr/include/${multiarch}"
            fi
            printf '%s\n' \
                "/usr/include" \
                "/usr/include/x86_64-linux-gnu" \
                "/usr/include/aarch64-linux-gnu" \
                "/usr/include/arm-linux-gnu" \
                "/usr/local/include"
            ;;
        windows)
            printf '%s\n' \
                "C:/vcpkg/installed/x64-windows/include" \
                "C:/msys64/mingw64/include" \
                "C:/msys64/ucrt64/include"
            ;;
        *)
            printf '%s\n' "/usr/local/include" "/usr/include"
            ;;
    esac
}

candidate_library_dirs() {
    if [[ -n "${KING_OPENSSL_LIBRARY_DIR:-}" ]]; then
        printf '%s\n' "${KING_OPENSSL_LIBRARY_DIR}"
    fi

    printf '%s\n' \
        "${ROOT_DIR}/openssl/lib" \
        "${ROOT_DIR}/boringssl/lib" \
        "${ROOT_DIR}/.cache/king/lsquic/runtime/prefix/boringssl/lib"

    if [[ "${KING_NATIVE_OPENSSL_DISABLE_SYSTEM:-0}" == "1" ]]; then
        return
    fi

    case "$(host_family)" in
        darwin)
            printf '%s\n' \
                "/opt/homebrew/opt/openssl@3/lib" \
                "/opt/homebrew/opt/openssl/lib" \
                "/usr/local/opt/openssl@3/lib" \
                "/usr/local/opt/openssl/lib" \
                "/opt/local/lib" \
                "/usr/local/lib"
            ;;
        linux)
            local multiarch=""
            multiarch="$("${CC_BIN}" -print-multiarch 2>/dev/null || true)"
            if [[ -n "${multiarch}" ]]; then
                printf '%s\n' "/usr/lib/${multiarch}"
            fi
            printf '%s\n' \
                "/usr/lib" \
                "/usr/lib/x86_64-linux-gnu" \
                "/usr/lib/aarch64-linux-gnu" \
                "/usr/lib/arm-linux-gnu" \
                "/usr/local/lib"
            ;;
        windows)
            printf '%s\n' \
                "C:/vcpkg/installed/x64-windows/lib" \
                "C:/msys64/mingw64/lib" \
                "C:/msys64/ucrt64/lib"
            ;;
        *)
            printf '%s\n' "/usr/local/lib" "/usr/lib"
            ;;
    esac
}

pkg_config_cflags() {
    if command -v "${PKG_CONFIG_BIN}" >/dev/null 2>&1 && "${PKG_CONFIG_BIN}" --exists openssl; then
        "${PKG_CONFIG_BIN}" --cflags openssl
    fi
}

pkg_config_libs() {
    if command -v "${PKG_CONFIG_BIN}" >/dev/null 2>&1 && "${PKG_CONFIG_BIN}" --exists openssl; then
        "${PKG_CONFIG_BIN}" --libs openssl
    fi
}

selected_include_dir() {
    local candidate=""
    while IFS= read -r candidate; do
        if [[ -n "${candidate}" && -f "${candidate}/openssl/ssl.h" ]]; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done < <(candidate_include_dirs)

    return 1
}

selected_library_dir() {
    local candidate=""
    while IFS= read -r candidate; do
        if library_dir_has_openssl "${candidate}"; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done < <(candidate_library_dirs)

    return 1
}

library_dir_has_openssl() {
    local candidate="$1"

    [[ -n "${candidate}" ]] \
        && { [[ -f "${candidate}/libssl.dylib" ]] || [[ -f "${candidate}/libssl.so" ]] || [[ -f "${candidate}/libssl.a" ]] || [[ -f "${candidate}/ssl.lib" ]] || [[ -f "${candidate}/libssl.dll.a" ]]; } \
        && { [[ -f "${candidate}/libcrypto.dylib" ]] || [[ -f "${candidate}/libcrypto.so" ]] || [[ -f "${candidate}/libcrypto.a" ]] || [[ -f "${candidate}/crypto.lib" ]] || [[ -f "${candidate}/libcrypto.dll.a" ]]; }
}

library_dir_for_include_dir() {
    local include_dir="$1"
    local base_dir=""

    if [[ -z "${include_dir}" ]]; then
        return 1
    fi

    base_dir="$(cd "${include_dir}/.." 2>/dev/null && pwd || true)"
    if library_dir_has_openssl "${base_dir}/lib"; then
        printf '%s\n' "${base_dir}/lib"
        return 0
    fi

    return 1
}

emit_cflags() {
    local include_dir=""
    local cflags=""

    cflags="${KING_OPENSSL_CFLAGS:-}"
    if [[ -z "${cflags}" ]]; then
        cflags="$(pkg_config_cflags || true)"
    fi

    if include_dir="$(selected_include_dir)"; then
        if [[ -z "${cflags}" || "${cflags}" != *"-I${include_dir}"* ]]; then
            cflags="${cflags}${cflags:+ }-I${include_dir}"
        fi
        printf '%s\n' "${cflags}"
        return 0
    fi

    return 1
}

emit_ldflags() {
    local include_dir=""
    local library_dir=""
    local ldflags=""

    ldflags="${KING_OPENSSL_LIBS:-}"
    if [[ -z "${ldflags}" ]]; then
        ldflags="$(pkg_config_libs || true)"
    fi

    include_dir="$(selected_include_dir || true)"
    if [[ -n "${include_dir}" ]]; then
        library_dir="$(library_dir_for_include_dir "${include_dir}" || true)"
    fi
    if [[ -z "${library_dir}" ]]; then
        library_dir="$(selected_library_dir || true)"
    fi

    if [[ -n "${library_dir}" ]]; then
        if [[ -z "${ldflags}" || "${ldflags}" != *"-L${library_dir}"* ]]; then
            ldflags="${ldflags}${ldflags:+ }-L${library_dir}"
        fi
        case " ${ldflags} " in
            *" -lssl "*) ;;
            *) ldflags="${ldflags}${ldflags:+ }-lssl" ;;
        esac
        case " ${ldflags} " in
            *" -lcrypto "*) ;;
            *) ldflags="${ldflags}${ldflags:+ }-lcrypto" ;;
        esac
        printf '%s\n' "${ldflags}"
        return 0
    fi

    printf '%s\n' "${ldflags}"
    return 0
}

check_prereqs() {
    if emit_cflags >/dev/null; then
        return 0
    fi

    {
        printf '%s\n' "openssl/ssl.h was not found in the vendored or system OpenSSL include paths."
        printf '%s\n' "Checked vendored paths: ${ROOT_DIR}/openssl/include/openssl/ssl.h and ${ROOT_DIR}/boringssl/include/openssl/ssl.h"
        printf '%s\n' "Install prerequisites: $(install_command)"
        printf '%s\n' "Alternatively set KING_OPENSSL_INCLUDE_DIR to a directory containing openssl/ssl.h, or KING_OPENSSL_CFLAGS with explicit -I flags."
    } >&2
    return 1
}

case "${1:---check}" in
    --check)
        check_prereqs
        ;;
    --cflags)
        if ! emit_cflags; then
            check_prereqs
        fi
        ;;
    --ldflags)
        emit_ldflags
        ;;
    --install-command)
        install_command
        ;;
    --list-include-candidates)
        candidate_include_dirs
        ;;
    --list-library-candidates)
        candidate_library_dirs
        ;;
    *)
        echo "Usage: $0 [--check|--cflags|--ldflags|--install-command|--list-include-candidates|--list-library-candidates]" >&2
        exit 2
        ;;
esac
