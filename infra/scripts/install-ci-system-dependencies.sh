#!/usr/bin/env bash

set -euo pipefail

export DEBIAN_FRONTEND="${DEBIAN_FRONTEND:-noninteractive}"

APT_ATTEMPTS="${KING_CI_APT_ATTEMPTS:-3}"
APT_TIMEOUT="${KING_CI_APT_TIMEOUT:-600s}"
APT_KILL_AFTER="${KING_CI_APT_KILL_AFTER:-30s}"

packages=(
  autoconf
  automake
  bison
  build-essential
  clang
  cmake
  libssl-dev
  libcurl4-openssl-dev
  libtool
  ninja-build
  pkg-config
  re2c
  zlib1g-dev
)

dependencies_present() {
  local missing=0
  local tool

  for tool in autoconf automake bison cc clang cmake ninja pkg-config re2c php phpize nm; do
    if ! command -v "${tool}" >/dev/null 2>&1; then
      echo "[ci-deps] missing tool: ${tool}" >&2
      missing=1
    fi
  done

  for pkg in libcurl openssl zlib; do
    if ! pkg-config --exists "${pkg}" >/dev/null 2>&1; then
      echo "[ci-deps] missing pkg-config dependency: ${pkg}" >&2
      missing=1
    fi
  done

  return "${missing}"
}

if ! command -v sudo >/dev/null 2>&1 || ! sudo -n true >/dev/null 2>&1; then
  if dependencies_present; then
    echo "[ci-deps] sudo is unavailable, but required CI dependencies are already installed."
    exit 0
  fi

  echo "[ci-deps] sudo is unavailable and required CI dependencies are missing." >&2
  exit 1
fi

run_with_retry() {
  local label="$1"
  shift

  local attempt=1
  local status=0
  while (( attempt <= APT_ATTEMPTS )); do
    echo "[ci-deps] ${label} attempt ${attempt}/${APT_ATTEMPTS}"
    set +e
    timeout --kill-after="${APT_KILL_AFTER}" "${APT_TIMEOUT}" "$@"
    status=$?
    set -e

    if [[ "${status}" -eq 0 ]]; then
      return 0
    fi

    echo "[ci-deps] ${label} failed with exit ${status}" >&2
    sudo dpkg --configure -a || true
    sleep $((attempt * 10))
    attempt=$((attempt + 1))
  done

  return "${status}"
}

disable_setup_php_apt_sources() {
  local source_dir="/etc/apt/sources.list.d"
  local disabled_dir="${source_dir}/king-disabled-setup-php"
  local found=0

  if [[ ! -d "${source_dir}" ]]; then
    return 0
  fi

  while IFS= read -r source_path; do
    [[ -n "${source_path}" ]] || continue
    found=1
    echo "[ci-deps] disabling setup-php apt source: ${source_path}"
    sudo mkdir -p "${disabled_dir}"
    sudo mv "${source_path}" "${disabled_dir}/$(basename "${source_path}")"
  done < <(
    find "${source_dir}" -maxdepth 1 -type f \
      \( -name '*ondrej*php*' -o -name '*setup-php*' \) \
      -print 2>/dev/null
  )

  if [[ "${found}" -eq 1 ]]; then
    echo "[ci-deps] setup-php apt sources disabled before dependency refresh"
  fi
}

apt_options=(
  -o Acquire::Retries=5
  -o Acquire::http::Timeout=30
  -o Acquire::https::Timeout=30
  -o Dpkg::Use-Pty=0
)

disable_setup_php_apt_sources
run_with_retry "apt-get update" sudo apt-get "${apt_options[@]}" update
run_with_retry "apt-get install" sudo apt-get "${apt_options[@]}" install -y --no-install-recommends "${packages[@]}"
