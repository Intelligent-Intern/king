#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_SCRIPT="${ROOT_DIR}/scripts/deploy.sh"
HETZNER_HELPER="${ROOT_DIR}/scripts/lib/deploy-hetzner.sh"
DOC="${ROOT_DIR}/../../documentation/dev/video-chat.md"

fail() {
  printf '[deploy-idempotency] FAIL: %s\n' "$*" >&2
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

require_file "${DEPLOY_SCRIPT}"
require_file "${HETZNER_HELPER}"
require_file "${DOC}"

for marker in \
  'persist_current_deploy_config' \
  'VIDEOCHAT_DEPLOY_PERSIST_LOCAL' \
  'deploy_refresh_known_hosts_enabled' \
  'deploy_dns_targets' \
  'VIDEOCHAT_DEPLOY_CDN_DOMAIN' \
  'VIDEOCHAT_DEPLOY_HCLOUD_DNS' \
  'VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE' \
  'ensure_hcloud_dns_records_if_configured' \
  'resolved_ips_for_domain "${target}"' \
  'restore_certbot_stopped_services' \
  'trap restore_certbot_stopped_services EXIT' \
  '--keep-until-expiring' \
  '--remove-orphans'
do
  require_text "${DEPLOY_SCRIPT}" "${marker}"
done

for marker in \
  'persist_wizard_env' \
  'VIDEOCHAT_DEPLOY_HCLOUD_TOKEN' \
  'hcloud_set_videochat_subdomain_records' \
  '/rrsets?per_page=500' \
  'action="set_records"' \
  'action="add_records"' \
  'wait_for_dns_to_server' \
  'refresh_known_hosts_for_target'
do
  require_text "${HETZNER_HELPER}" "${marker}"
done

for marker in \
  'manual deploy actions write the effective deploy' \
  'Hetzner Cloud API token, derived' \
  '`api/ws/sfu/turn/cdn` hostnames' \
  '`VIDEOCHAT_DEPLOY_REFRESH_DNS_ON_PREPARE=1`' \
  'auto-enable this when `VIDEOCHAT_DEPLOY_PUBLIC_IP` is' \
  'same DNS preflight for the root domain and `api/ws/sfu/turn/cdn`' \
  'safe to rerun'
do
  require_text "${DOC}" "${marker}"
done

bash -n "${DEPLOY_SCRIPT}"
bash -n "${HETZNER_HELPER}"

printf '[deploy-idempotency] PASS\n'
