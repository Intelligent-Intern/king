#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/resolve-previous-compatibility-ref.sh [--event-name NAME] [--push-before SHA] [--pull-request-base SHA] [--github-output PATH]

Resolves the previous release-compatibility baseline ref for CI.

For pull requests this uses the PR base SHA. For pushes this uses the event
`before` SHA when it is still available. Rebase and force-push events can point
`before` at an orphaned commit that is no longer fetchable; in that case the
resolver falls back to HEAD^ so compatibility gates still compare the current
tree against a real first-parent baseline.
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

EVENT_NAME="${GITHUB_EVENT_NAME:-}"
PUSH_BEFORE="${PUSH_BEFORE:-}"
PULL_REQUEST_BASE_SHA="${PULL_REQUEST_BASE_SHA:-}"
GITHUB_OUTPUT_PATH="${GITHUB_OUTPUT:-}"
REMOTE_NAME="${KING_COMPAT_REMOTE:-origin}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --event-name)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --event-name." >&2
                exit 1
            fi
            EVENT_NAME="$2"
            shift 2
            ;;
        --push-before)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --push-before." >&2
                exit 1
            fi
            PUSH_BEFORE="$2"
            shift 2
            ;;
        --pull-request-base)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --pull-request-base." >&2
                exit 1
            fi
            PULL_REQUEST_BASE_SHA="$2"
            shift 2
            ;;
        --github-output)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --github-output." >&2
                exit 1
            fi
            GITHUB_OUTPUT_PATH="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

is_blank_or_zero_ref() {
    local ref="$1"

    [[ -z "${ref}" || "${ref}" =~ ^0+$ ]]
}

is_resolvable_commit() {
    local ref="$1"

    git -C "${ROOT_DIR}" rev-parse --verify "${ref}^{commit}" >/dev/null 2>&1
}

canonical_commit() {
    local ref="$1"

    git -C "${ROOT_DIR}" rev-parse "${ref}^{commit}"
}

fetch_candidate_ref() {
    local ref="$1"

    if is_blank_or_zero_ref "${ref}"; then
        return 1
    fi

    git -C "${ROOT_DIR}" fetch --no-tags --depth=1 "${REMOTE_NAME}" "${ref}" >/dev/null 2>&1
}

candidate_ref=""
candidate_source=""

case "${EVENT_NAME}" in
    pull_request)
        candidate_ref="${PULL_REQUEST_BASE_SHA}"
        candidate_source="pull_request_base"
        ;;
    push)
        candidate_ref="${PUSH_BEFORE}"
        candidate_source="push_before"
        ;;
    *)
        candidate_ref=""
        candidate_source="default"
        ;;
esac

resolved_ref=""
resolved_source=""

if ! is_blank_or_zero_ref "${candidate_ref}"; then
    if is_resolvable_commit "${candidate_ref}"; then
        resolved_ref="$(canonical_commit "${candidate_ref}")"
        resolved_source="${candidate_source}"
    elif fetch_candidate_ref "${candidate_ref}" && is_resolvable_commit "${candidate_ref}"; then
        resolved_ref="$(canonical_commit "${candidate_ref}")"
        resolved_source="${candidate_source}_fetched"
    else
        echo "Compatibility baseline ${candidate_ref} from ${candidate_source} is not available; falling back to HEAD^." >&2
    fi
fi

if [[ -z "${resolved_ref}" ]]; then
    if is_resolvable_commit "HEAD^"; then
        resolved_ref="$(canonical_commit "HEAD^")"
        resolved_source="head_parent"
    else
        echo "Could not resolve a previous compatibility ref from ${candidate_source:-none} or HEAD^." >&2
        exit 1
    fi
fi

echo "Resolved previous compatibility ref ${resolved_ref} (${resolved_source})." >&2

if [[ -n "${GITHUB_OUTPUT_PATH}" ]]; then
    {
        echo "ref=${resolved_ref}"
        echo "source=${resolved_source}"
    } >> "${GITHUB_OUTPUT_PATH}"
else
    printf '%s\n' "${resolved_ref}"
fi
