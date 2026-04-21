#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASELINE="${ROOT_DIR}/TURN_BASELINE.md"
COMPOSE_FILE="${ROOT_DIR}/docker-compose.v1.yml"
DEPLOY_SCRIPT="${ROOT_DIR}/scripts/deploy.sh"
GENERATOR="${ROOT_DIR}/scripts/generate-turn-ice-servers.php"
NAT_MATRIX="${ROOT_DIR}/scripts/turn-nat-matrix-contract.sh"

fail() {
  printf '[turn-baseline] FAIL: %s\n' "$*" >&2
  exit 1
}

require_file() {
  local path="$1"
  [[ -f "${path}" ]] || fail "missing required file: ${path}"
}

require_text() {
  local path="$1"
  local needle="$2"
  grep -Fq -- "${needle}" "${path}" || fail "missing '${needle}' in ${path}"
}

require_file "${BASELINE}"
require_file "${COMPOSE_FILE}"
require_file "${DEPLOY_SCRIPT}"
require_file "${GENERATOR}"
require_file "${NAT_MATRIX}"

for marker in \
  'Status: baselinefaehig, aber nicht default-aktiv.' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE' \
  'VITE_VIDEOCHAT_ICE_SERVERS' \
  'turn-nat-matrix-contract.sh' \
  'mobile-lte' \
  'restrictive-nat' \
  'udp-blocked-turn-tcp' \
  'corporate-firewall'
do
  require_text "${BASELINE}" "${marker}"
done

for marker in \
  'videochat-turn-v1:' \
  'profiles:' \
  'coturn/coturn' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE' \
  'VIDEOCHAT_V1_TURN_EXTERNAL_IP' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET' \
  '--external-ip=$${VIDEOCHAT_V1_TURN_EXTERNAL_IP}' \
  '--use-auth-secret' \
  '--static-auth-secret="$${secret}"' \
  '--min-port="$${VIDEOCHAT_V1_TURN_RELAY_MIN_PORT}"' \
  '--max-port="$${VIDEOCHAT_V1_TURN_RELAY_MAX_PORT}"' \
  '3478/tcp' \
  '3478/udp'
do
  require_text "${COMPOSE_FILE}" "${marker}"
done

for marker in \
  '--profile turn' \
  'VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat/turn-secret' \
  'VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET=' \
  'VIDEOCHAT_TURN_URIS=turn:\${TURN_DOMAIN}:3478?transport=udp,turn:\${TURN_DOMAIN}:3478?transport=tcp' \
  'videochat-turn-v1' \
  'wait_for_tcp turn-tcp'
do
  require_text "${DEPLOY_SCRIPT}" "${marker}"
done

bash -n "${NAT_MATRIX}"
php -l "${GENERATOR}" >/dev/null

sample_one="$(
  VIDEOCHAT_TURN_STATIC_AUTH_SECRET='contract-secret-1234567890' \
  VIDEOCHAT_TURN_URIS='turn:turn.example.test:3478?transport=udp,turn:turn.example.test:3478?transport=tcp' \
  VIDEOCHAT_TURN_NOW=1000 \
  php "${GENERATOR}"
)"

sample_two="$(
  VIDEOCHAT_TURN_STATIC_AUTH_SECRET='contract-secret-1234567890' \
  VIDEOCHAT_TURN_URIS='turn:turn.example.test:3478?transport=udp' \
  VIDEOCHAT_TURN_NOW=1060 \
  php "${GENERATOR}"
)"

php -r '
$one = json_decode($argv[1], true);
$two = json_decode($argv[2], true);
if (!is_array($one) || count($one) !== 2) {
    fwrite(STDERR, "sample one must contain two ICE servers\n");
    exit(1);
}
if (!is_array($two) || count($two) !== 1) {
    fwrite(STDERR, "sample two must contain one ICE server\n");
    exit(1);
}
foreach ($one as $entry) {
    if (!is_array($entry) || !str_starts_with((string) ($entry["urls"] ?? ""), "turn:")) {
        fwrite(STDERR, "ICE entry URL must be TURN\n");
        exit(1);
    }
    if ((string) ($entry["username"] ?? "") !== "4600:king-videochat") {
        fwrite(STDERR, "unexpected generated username\n");
        exit(1);
    }
    if ((string) ($entry["credential"] ?? "") === "") {
        fwrite(STDERR, "missing generated credential\n");
        exit(1);
    }
}
if ((string) ($one[0]["credential"] ?? "") === (string) ($two[0]["credential"] ?? "")) {
    fwrite(STDERR, "credential must rotate when username expiry changes\n");
    exit(1);
}
' "${sample_one}" "${sample_two}"

printf '[turn-baseline] PASS\n'
