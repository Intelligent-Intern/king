#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/check-inference-port-exposure.sh [--ports CSV]

Fails when a configured King inference/OpenAI-compatible port is listening on
a public interface. Loopback-only listeners are accepted; no listener is also
accepted.

Environment variables:
  KING_OPENAI_PORT  Added to the checked port list when set.
USAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
    usage
    exit 0
fi

PORTS="${KING_OPENAI_PORT:-8080}"
while [[ "$#" -gt 0 ]]; do
    case "$1" in
        --ports)
            PORTS="${2:-}"
            shift 2
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [[ -n "${KING_OPENAI_PORT:-}" && ",${PORTS}," != *",${KING_OPENAI_PORT},"* ]]; then
    PORTS="${PORTS},${KING_OPENAI_PORT}"
fi

socket_table() {
    if command -v ss >/dev/null 2>&1; then
        ss -H -ltn
        return
    fi
    if command -v netstat >/dev/null 2>&1; then
        netstat -ltn | awk 'NR > 2 {print}'
        return
    fi

    echo "ss or netstat is required to inspect listening ports." >&2
    exit 1
}

normalize_ports() {
    tr ',' '\n' <<<"${PORTS}" \
        | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' \
        | awk '/^[0-9]+$/ && $1 > 0 && $1 <= 65535 {seen[$1]=1} END {for (port in seen) print port}' \
        | LC_ALL=C sort -n
}

is_loopback_address() {
    local address="$1"

    address="${address#[}"
    address="${address%]}"
    case "${address}" in
        127.*|::1|localhost)
            return 0
            ;;
    esac
    return 1
}

local_address_from_line() {
    awk '{print $4}' <<<"$1"
}

address_without_port() {
    local endpoint="$1"

    endpoint="${endpoint%:*}"
    endpoint="${endpoint#[}"
    endpoint="${endpoint%]}"
    printf '%s\n' "${endpoint}"
}

port_matches_line() {
    local line="$1"
    local port="$2"
    local endpoint=""

    endpoint="$(local_address_from_line "${line}")"
    [[ "${endpoint}" == *":${port}" ]]
}

SOCKETS="$(socket_table)"
FAIL=0

while IFS= read -r port; do
    [[ -z "${port}" ]] && continue
    MATCHED=0
    while IFS= read -r line; do
        [[ -z "${line}" ]] && continue
        if ! port_matches_line "${line}" "${port}"; then
            continue
        fi

        MATCHED=1
        endpoint="$(local_address_from_line "${line}")"
        address="$(address_without_port "${endpoint}")"
        case "${address}" in
            0.0.0.0|::|'*')
                echo "Public inference port exposure detected on ${endpoint}: ${line}" >&2
                FAIL=1
                ;;
            *)
                if is_loopback_address "${address}"; then
                    echo "Loopback-only inference listener accepted on ${endpoint}."
                else
                    echo "Non-loopback inference listener detected on ${endpoint}: ${line}" >&2
                    FAIL=1
                fi
                ;;
        esac
    done <<<"${SOCKETS}"

    if [[ "${MATCHED}" == "0" ]]; then
        echo "No listener on inference port ${port}."
    fi
done < <(normalize_ports)

exit "${FAIL}"
