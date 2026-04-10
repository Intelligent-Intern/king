#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
README_FILE="${ROOT_DIR}/README.md"

if [[ ! -f "${README_FILE}" ]]; then
    echo "Missing README file: ${README_FILE}" >&2
    exit 1
fi

require_literal() {
    local literal="$1"
    local description="$2"

    if ! grep -Fq "${literal}" "${README_FILE}"; then
        echo "Missing required public-contract caveat in README: ${description}" >&2
        echo "Expected literal: ${literal}" >&2
        exit 1
    fi
}

require_literal \
    "it does not currently claim a built-in distributed bucket-watch API." \
    "distributed bucket-watch API caveat"

require_literal \
    "stays single-node on purpose instead of pretending the current router or" \
    "single-node WebSocket forwarding caveat (line 1/2)"

require_literal \
    "load-balancer control-plane layer is already a verified WebSocket forwarding" \
    "single-node WebSocket forwarding caveat (line 2/2)"

require_literal \
    "dataplane." \
    "single-node WebSocket forwarding caveat (line 3/3)"

if rg -n "built-in distributed bucket-watch API" "${README_FILE}" \
    | rg -v "does not currently claim a built-in distributed bucket-watch API\." >/dev/null 2>&1; then
    echo "README contains an uncontrolled bucket-watch claim variant; keep the explicit non-claim caveat wording." >&2
    exit 1
fi

if rg -n "verified WebSocket forwarding dataplane" "${README_FILE}" \
    | rg -v "already a verified WebSocket forwarding" >/dev/null 2>&1; then
    echo "README contains an uncontrolled WebSocket dataplane claim variant; keep the explicit single-node caveat wording." >&2
    exit 1
fi

echo "Public contract claim caveats OK."
