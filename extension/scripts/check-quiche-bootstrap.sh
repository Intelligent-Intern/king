#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BUILD_PROFILE_SCRIPT="${SCRIPT_DIR}/build-profile.sh"
BOOTSTRAP_SCRIPT="${SCRIPT_DIR}/bootstrap-quiche.sh"

if [[ ! -f "${BUILD_PROFILE_SCRIPT}" ]]; then
    echo "Missing build-profile.sh under ${SCRIPT_DIR}." >&2
    exit 1
fi

if [[ ! -f "${BOOTSTRAP_SCRIPT}" ]]; then
    echo "Missing bootstrap-quiche.sh under ${SCRIPT_DIR}." >&2
    exit 1
fi

"${BOOTSTRAP_SCRIPT}" --verify-lock

if ! grep -Fq '"${QUICHE_BOOTSTRAP_SCRIPT}"' "${BUILD_PROFILE_SCRIPT}"; then
    echo "build-profile.sh no longer delegates to bootstrap-quiche.sh." >&2
    exit 1
fi

if grep -Fq 'git clone --recursive' "${BUILD_PROFILE_SCRIPT}"; then
    echo "build-profile.sh still contains an external branch clone fallback." >&2
    exit 1
fi

if grep -Fq 'retrying unlocked' "${BUILD_PROFILE_SCRIPT}"; then
    echo "build-profile.sh still contains unlocked cargo fallback text." >&2
    exit 1
fi

if grep -Fq 'branch = "master"' "${BUILD_PROFILE_SCRIPT}"; then
    echo "build-profile.sh still references a branch-based wirefilter fallback." >&2
    exit 1
fi

echo "Bootstrap contract: deterministic-pinned"
