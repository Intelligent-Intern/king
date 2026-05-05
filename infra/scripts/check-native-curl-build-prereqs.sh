#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PREREQS_SCRIPT="${SCRIPT_DIR}/native-curl-build-prereqs.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "${TMP_DIR}"' EXIT

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"

    if [[ "${haystack}" != *"${needle}"* ]]; then
        printf 'FAIL: %s\nExpected to find: %s\nIn: %s\n' "${label}" "${needle}" "${haystack}" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local label="$3"

    if [[ "${haystack}" == *"${needle}"* ]]; then
        printf 'FAIL: %s\nDid not expect to find: %s\nIn: %s\n' "${label}" "${needle}" "${haystack}" >&2
        exit 1
    fi
}

mkdir -p "${TMP_DIR}/vendored/libcurl/include/curl"
touch "${TMP_DIR}/vendored/libcurl/include/curl/curl.h"

vendored_cflags="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/vendored" \
    KING_NATIVE_CURL_HOST_OS="Darwin" \
    KING_NATIVE_CURL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    "${PREREQS_SCRIPT}" --cflags
)"
assert_contains "${vendored_cflags}" "-I${TMP_DIR}/vendored/libcurl/include" "vendored curl include selector"

mkdir -p "${TMP_DIR}/custom-curl/include/curl"
touch "${TMP_DIR}/custom-curl/include/curl/curl.h"

custom_cflags="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Linux" \
    KING_NATIVE_CURL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    KING_CURL_INCLUDE_DIR="${TMP_DIR}/custom-curl/include" \
    "${PREREQS_SCRIPT}" --cflags
)"
assert_contains "${custom_cflags}" "-I${TMP_DIR}/custom-curl/include" "explicit system curl include selector"

darwin_candidates="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Darwin" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${darwin_candidates}" "/opt/homebrew/opt/curl/include" "macOS Homebrew curl candidate"
assert_contains "${darwin_candidates}" "/usr/local/opt/curl/include" "macOS Intel Homebrew curl candidate"

darwin_libraries="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Darwin" \
    "${PREREQS_SCRIPT}" --list-library-candidates
)"
assert_contains "${darwin_libraries}" "/opt/homebrew/opt/curl/lib/libcurl.dylib" "macOS Homebrew curl library candidate"
assert_contains "${darwin_libraries}" "/usr/local/opt/curl/lib/libcurl.dylib" "macOS Intel Homebrew curl library candidate"

linux_candidates="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Linux" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${linux_candidates}" "/usr/include" "Linux system curl candidate"
assert_contains "${linux_candidates}" "/usr/local/include" "Linux local curl candidate"

linux_libraries="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Linux" \
    "${PREREQS_SCRIPT}" --list-library-candidates
)"
assert_contains "${linux_libraries}" "/usr/lib/libcurl.so" "Linux system curl library candidate"
assert_contains "${linux_libraries}" "/usr/local/lib/libcurl.so" "Linux local curl library candidate"

windows_candidates="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="MINGW64_NT" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${windows_candidates}" "C:/vcpkg/installed/x64-windows/include" "Windows vcpkg curl candidate"
assert_contains "${windows_candidates}" "C:/msys64/mingw64/include" "Windows MSYS2 curl candidate"

windows_libraries="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_CURL_HOST_OS="Windows_NT" \
    "${PREREQS_SCRIPT}" --list-library-candidates
)"
assert_contains "${windows_libraries}" "C:/vcpkg/installed/x64-windows/lib/libcurl.lib" "Windows vcpkg curl library candidate"
assert_contains "${windows_libraries}" "C:/msys64/mingw64/lib/libcurl.dll.a" "Windows MSYS2 curl import library candidate"

set +e
missing_output="$(
    KING_NATIVE_CURL_ROOT_DIR="${TMP_DIR}/missing-root" \
    KING_NATIVE_CURL_HOST_OS="Darwin" \
    KING_NATIVE_CURL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    KING_NATIVE_CURL_DISABLE_SYSTEM=1 \
    "${PREREQS_SCRIPT}" --check 2>&1
)"
missing_status=$?
set -e

if [[ "${missing_status}" -eq 0 ]]; then
    printf 'FAIL: missing curl headers unexpectedly passed\n' >&2
    exit 1
fi

assert_contains "${missing_output}" "curl/curl.h was not found" "missing header diagnostic"
assert_contains "${missing_output}" "brew install curl pkg-config" "macOS install command diagnostic"
assert_contains "${missing_output}" "KING_CURL_INCLUDE_DIR" "manual override diagnostic"
assert_not_contains "${missing_output}" "pkg-config is not present" "pkg-config is no longer the primary failure"

printf '%s\n' "native curl build prerequisite selector contract passed"
