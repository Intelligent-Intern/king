#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PREREQS_SCRIPT="${SCRIPT_DIR}/native-openssl-build-prereqs.sh"
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

mkdir -p "${TMP_DIR}/vendored/openssl/include/openssl" "${TMP_DIR}/vendored/openssl/lib"
touch "${TMP_DIR}/vendored/openssl/include/openssl/ssl.h"
touch "${TMP_DIR}/vendored/openssl/lib/libssl.a" "${TMP_DIR}/vendored/openssl/lib/libcrypto.a"

vendored_cflags="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/vendored" \
    KING_NATIVE_OPENSSL_HOST_OS="Darwin" \
    KING_NATIVE_OPENSSL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    "${PREREQS_SCRIPT}" --cflags
)"
assert_contains "${vendored_cflags}" "-I${TMP_DIR}/vendored/openssl/include" "vendored OpenSSL include selector"

vendored_ldflags="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/vendored" \
    KING_NATIVE_OPENSSL_HOST_OS="Darwin" \
    KING_NATIVE_OPENSSL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    "${PREREQS_SCRIPT}" --ldflags
)"
assert_contains "${vendored_ldflags}" "-L${TMP_DIR}/vendored/openssl/lib" "vendored OpenSSL library selector"
assert_contains "${vendored_ldflags}" "-lssl" "OpenSSL SSL library flag"
assert_contains "${vendored_ldflags}" "-lcrypto" "OpenSSL crypto library flag"

mkdir -p "${TMP_DIR}/custom-openssl/include/openssl"
touch "${TMP_DIR}/custom-openssl/include/openssl/ssl.h"

custom_cflags="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_OPENSSL_HOST_OS="Linux" \
    KING_NATIVE_OPENSSL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    KING_OPENSSL_INCLUDE_DIR="${TMP_DIR}/custom-openssl/include" \
    "${PREREQS_SCRIPT}" --cflags
)"
assert_contains "${custom_cflags}" "-I${TMP_DIR}/custom-openssl/include" "explicit OpenSSL include selector"

darwin_candidates="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_OPENSSL_HOST_OS="Darwin" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${darwin_candidates}" "/opt/homebrew/opt/openssl@3/include" "macOS Apple Silicon OpenSSL candidate"
assert_contains "${darwin_candidates}" "/usr/local/opt/openssl@3/include" "macOS Intel OpenSSL candidate"

linux_candidates="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_OPENSSL_HOST_OS="Linux" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${linux_candidates}" "/usr/include" "Linux OpenSSL include candidate"
assert_contains "${linux_candidates}" "/usr/local/include" "Linux local OpenSSL include candidate"

windows_candidates="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_OPENSSL_HOST_OS="MSYS_NT" \
    "${PREREQS_SCRIPT}" --list-include-candidates
)"
assert_contains "${windows_candidates}" "C:/vcpkg/installed/x64-windows/include" "Windows vcpkg OpenSSL include candidate"
assert_contains "${windows_candidates}" "C:/msys64/mingw64/include" "Windows MSYS2 OpenSSL include candidate"

windows_libraries="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/empty-root" \
    KING_NATIVE_OPENSSL_HOST_OS="Windows_NT" \
    "${PREREQS_SCRIPT}" --list-library-candidates
)"
assert_contains "${windows_libraries}" "C:/vcpkg/installed/x64-windows/lib" "Windows vcpkg OpenSSL library candidate"
assert_contains "${windows_libraries}" "C:/msys64/mingw64/lib" "Windows MSYS2 OpenSSL library candidate"

set +e
missing_output="$(
    KING_NATIVE_OPENSSL_ROOT_DIR="${TMP_DIR}/missing-root" \
    KING_NATIVE_OPENSSL_HOST_OS="Darwin" \
    KING_NATIVE_OPENSSL_PKG_CONFIG_BIN="${TMP_DIR}/missing-pkg-config" \
    KING_NATIVE_OPENSSL_DISABLE_SYSTEM=1 \
    "${PREREQS_SCRIPT}" --check 2>&1
)"
missing_status=$?
set -e

if [[ "${missing_status}" -eq 0 ]]; then
    printf 'FAIL: missing OpenSSL headers unexpectedly passed\n' >&2
    exit 1
fi

assert_contains "${missing_output}" "openssl/ssl.h was not found" "missing OpenSSL diagnostic"
assert_contains "${missing_output}" "brew install openssl@3 pkg-config" "macOS OpenSSL install command diagnostic"
assert_contains "${missing_output}" "KING_OPENSSL_INCLUDE_DIR" "manual OpenSSL override diagnostic"

printf '%s\n' "native OpenSSL build prerequisite selector contract passed"
