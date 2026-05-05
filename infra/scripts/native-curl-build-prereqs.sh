#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="${KING_NATIVE_CURL_ROOT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
HOST_OS="${KING_NATIVE_CURL_HOST_OS:-$(uname -s)}"
PKG_CONFIG_BIN="${KING_NATIVE_CURL_PKG_CONFIG_BIN:-pkg-config}"
CC_BIN="${KING_NATIVE_CURL_CC:-cc}"

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
            printf '%s\n' "brew install curl pkg-config"
            ;;
        linux)
            printf '%s\n' "sudo apt-get update && sudo apt-get install -y libcurl4-openssl-dev pkg-config"
            ;;
        windows)
            printf '%s\n' "install curl development headers and libraries via vcpkg, MSYS2, or set KING_CURL_INCLUDE_DIR/KING_CURL_CFLAGS explicitly"
            ;;
        *)
            printf '%s\n' "install libcurl development headers and pkg-config for this OS"
            ;;
    esac
}

candidate_include_dirs() {
    if [[ -n "${KING_CURL_INCLUDE_DIR:-}" ]]; then
        printf '%s\n' "${KING_CURL_INCLUDE_DIR}"
    fi

    if [[ -n "${KING_CURL_CFLAGS:-}" ]]; then
        local flag=""
        for flag in ${KING_CURL_CFLAGS}; do
            case "${flag}" in
                -I?*)
                    printf '%s\n' "${flag#-I}"
                    ;;
            esac
        done
    fi

    printf '%s\n' "${ROOT_DIR}/libcurl/include"

    if [[ "${KING_NATIVE_CURL_DISABLE_SYSTEM:-0}" == "1" ]]; then
        return
    fi

    case "$(host_family)" in
        darwin)
            printf '%s\n' \
                "/opt/homebrew/opt/curl/include" \
                "/usr/local/opt/curl/include" \
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
                "${ROOT_DIR}/libcurl/include" \
                "C:/vcpkg/installed/x64-windows/include" \
                "C:/msys64/mingw64/include" \
                "C:/msys64/ucrt64/include"
            ;;
        *)
            printf '%s\n' "/usr/local/include" "/usr/include"
            ;;
    esac
}

candidate_library_paths() {
    case "$(host_family)" in
        darwin)
            printf '%s\n' \
                "${ROOT_DIR}/libcurl/lib/libcurl.4.dylib" \
                "${ROOT_DIR}/libcurl/lib/libcurl.dylib" \
                "/opt/homebrew/opt/curl/lib/libcurl.4.dylib" \
                "/opt/homebrew/opt/curl/lib/libcurl.dylib" \
                "/usr/local/opt/curl/lib/libcurl.4.dylib" \
                "/usr/local/opt/curl/lib/libcurl.dylib" \
                "/opt/local/lib/libcurl.4.dylib" \
                "/opt/local/lib/libcurl.dylib" \
                "/usr/local/lib/libcurl.4.dylib" \
                "/usr/local/lib/libcurl.dylib"
            ;;
        linux)
            local multiarch=""
            multiarch="$("${CC_BIN}" -print-multiarch 2>/dev/null || true)"
            printf '%s\n' \
                "${ROOT_DIR}/libcurl/lib/libcurl.so.4" \
                "${ROOT_DIR}/libcurl/lib/libcurl.so"
            if [[ -n "${multiarch}" ]]; then
                printf '%s\n' \
                    "/usr/lib/${multiarch}/libcurl.so.4" \
                    "/usr/lib/${multiarch}/libcurl.so"
            fi
            printf '%s\n' \
                "/usr/lib/libcurl.so.4" \
                "/usr/lib/libcurl.so" \
                "/usr/local/lib/libcurl.so.4" \
                "/usr/local/lib/libcurl.so"
            ;;
        windows)
            printf '%s\n' \
                "${ROOT_DIR}/libcurl/lib/libcurl.lib" \
                "${ROOT_DIR}/libcurl/bin/libcurl.dll" \
                "C:/vcpkg/installed/x64-windows/lib/libcurl.lib" \
                "C:/msys64/mingw64/lib/libcurl.dll.a" \
                "C:/msys64/ucrt64/lib/libcurl.dll.a"
            ;;
        *)
            printf '%s\n' \
                "${ROOT_DIR}/libcurl/lib/libcurl.so" \
                "/usr/local/lib/libcurl.so" \
                "/usr/lib/libcurl.so"
            ;;
    esac
}

pkg_config_cflags() {
    if command -v "${PKG_CONFIG_BIN}" >/dev/null 2>&1 && "${PKG_CONFIG_BIN}" --exists libcurl; then
        "${PKG_CONFIG_BIN}" --cflags libcurl
    fi
}

selected_include_dir() {
    local candidate=""
    while IFS= read -r candidate; do
        if [[ -n "${candidate}" && -f "${candidate}/curl/curl.h" ]]; then
            printf '%s\n' "${candidate}"
            return 0
        fi
    done < <(candidate_include_dirs)

    return 1
}

emit_cflags() {
    local include_dir=""
    local cflags=""

    cflags="${KING_CURL_CFLAGS:-}"
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

check_prereqs() {
    if emit_cflags >/dev/null; then
        return 0
    fi

    {
        printf '%s\n' "curl/curl.h was not found in the vendored or system curl include paths."
        printf '%s\n' "Checked vendored path: ${ROOT_DIR}/libcurl/include/curl/curl.h"
        printf '%s\n' "Install prerequisites: $(install_command)"
        printf '%s\n' "Alternatively set KING_CURL_INCLUDE_DIR to a directory containing curl/curl.h or restore the vendored libcurl checkout."
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
    --install-command)
        install_command
        ;;
    --list-include-candidates)
        candidate_include_dirs
        ;;
    --list-library-candidates)
        candidate_library_paths
        ;;
    *)
        echo "Usage: $0 [--check|--cflags|--install-command|--list-include-candidates|--list-library-candidates]" >&2
        exit 2
        ;;
esac
