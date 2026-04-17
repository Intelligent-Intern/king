#!/usr/bin/env bash
set -euo pipefail

# Idempotent host-side TCP proxy for the King model-inference backend.
#
# Why this exists:
#   The backend binds :18090 inside its dev container. VS Code's dev-container
#   port forwarder (which publishes :18090 on the Mac host) is unreliable for
#   long-lived binary WebSocket streams — it silently drops frames mid-burst,
#   which manifests as [ws_closed 1006] in the chat UI.
#
#   This script spins a lightweight `alpine/socat` sidecar that publishes
#   host :18091 and forwards every TCP connection directly to the backend
#   container's bridge IP on port 18090. The path bypasses VS Code's
#   forwarder entirely and carries the streaming WS cleanly.
#
# After this script runs successfully, open http://localhost:18091/ui in
# your browser. The UI's boot fetches and the WebSocket both use the
# current origin, so everything lines up automatically.
#
# Usage:
#   scripts/run-proxy.sh            # start / refresh the proxy
#   scripts/run-proxy.sh --stop     # tear the proxy down
#   scripts/run-proxy.sh --status   # print state and exit

CONTAINER_NAME="${MODEL_INFERENCE_PROXY_NAME:-mi-proxy}"
HOST_PORT="${MODEL_INFERENCE_PROXY_HOST_PORT:-18091}"
BACKEND_PORT="${MODEL_INFERENCE_PROXY_BACKEND_PORT:-18090}"
SOCAT_IMAGE="${MODEL_INFERENCE_PROXY_IMAGE:-alpine/socat}"

backend_bridge_ip() {
    # Discover the bridge IP of the first running container that binds
    # BACKEND_PORT. We grep by the model-inference backend process command
    # line via `docker ps`, but since multiple containers can co-exist, we
    # fall back to a filter by any container on the default bridge that is
    # NOT this proxy.
    local candidates
    candidates=$(docker ps --format '{{.ID}} {{.Names}}' \
        | awk -v me="${CONTAINER_NAME}" '$2 != me {print $1}')
    for id in $candidates; do
        local ip
        ip=$(docker inspect "$id" \
            --format '{{range $k,$v := .NetworkSettings.Networks}}{{$v.IPAddress}} {{end}}' \
            2>/dev/null | awk '{print $1}')
        if [[ -z "$ip" ]]; then continue; fi
        # Probe BACKEND_PORT via busybox nc inside a throwaway container.
        if docker run --rm --network bridge "${SOCAT_IMAGE}" \
            -T 1 - TCP:"${ip}":"${BACKEND_PORT}",connect-timeout=1 \
            </dev/null >/dev/null 2>&1; then
            echo "$ip"
            return 0
        fi
    done
    return 1
}

status() {
    if docker ps --filter "name=^${CONTAINER_NAME}$" --format '{{.Status}} {{.Ports}}' | grep -q .; then
        docker ps --filter "name=^${CONTAINER_NAME}$" --format 'proxy: {{.Names}} status={{.Status}} ports={{.Ports}}'
        return 0
    fi
    echo "proxy: ${CONTAINER_NAME} not running"
    return 1
}

stop() {
    if docker ps -a --filter "name=^${CONTAINER_NAME}$" --format '{{.ID}}' | grep -q .; then
        docker rm -f "${CONTAINER_NAME}" >/dev/null
        echo "proxy: ${CONTAINER_NAME} stopped"
    else
        echo "proxy: ${CONTAINER_NAME} was not running"
    fi
}

start() {
    # If already running, print status and exit 0 (idempotent).
    if docker ps --filter "name=^${CONTAINER_NAME}$" --format '{{.ID}}' | grep -q .; then
        echo "proxy: ${CONTAINER_NAME} already running"
        status
        return 0
    fi
    # If stopped-but-present, clear the carcass.
    if docker ps -a --filter "name=^${CONTAINER_NAME}$" --format '{{.ID}}' | grep -q .; then
        docker rm -f "${CONTAINER_NAME}" >/dev/null
    fi

    local ip
    ip=$(backend_bridge_ip)
    if [[ -z "${ip:-}" ]]; then
        cat >&2 <<MSG
proxy: could not find a running backend container listening on port ${BACKEND_PORT}
on the docker bridge network. Start the backend first (run-dev.sh or a compose
service), then re-run this script. If you are running the backend on a custom
network, set MODEL_INFERENCE_PROXY_BACKEND_PORT and point the sidecar by hand:

    docker run -d --rm --name ${CONTAINER_NAME} -p ${HOST_PORT}:${HOST_PORT} \\
      ${SOCAT_IMAGE} TCP-LISTEN:${HOST_PORT},fork,reuseaddr TCP:<ip>:${BACKEND_PORT}
MSG
        return 1
    fi

    docker run -d --rm --name "${CONTAINER_NAME}" \
        -p "${HOST_PORT}:${HOST_PORT}" \
        "${SOCAT_IMAGE}" \
        TCP-LISTEN:"${HOST_PORT}",fork,reuseaddr \
        TCP:"${ip}":"${BACKEND_PORT}" >/dev/null

    echo "proxy: bridging host :${HOST_PORT} -> backend ${ip}:${BACKEND_PORT}"
    status
    cat <<'MSG'

Open in your browser:
  http://localhost:18091/ui

This URL bypasses the VS Code dev-container port forwarder, which drops
long-lived binary WebSocket frames mid-burst on our streaming path.
MSG
}

case "${1:-}" in
    --stop)   stop ;;
    --status) status ;;
    *)        start ;;
esac
