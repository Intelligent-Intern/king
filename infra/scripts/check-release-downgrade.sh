#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/check-release-downgrade.sh --from-ref REF [--current-archive PATH] [--php-bin BIN] [--artifacts-dir DIR]

Builds a packaged King release archive from a previous git ref, packages the
current tree if needed, verifies both archives, installs the current archive
first, then reinstalls the previous archive into the same prefix, and runs the
packaged smoke test before and after the downgrade.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    usage
    exit 0
fi

exec "${SCRIPT_DIR}/check-release-upgrade.sh" --direction downgrade "$@"
