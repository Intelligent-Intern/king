#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
REPO_ROOT="$(cd -- "${VIDEOCHAT_DIR}/../.." && pwd)"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"

usage() {
  cat <<'USAGE'
Usage:
  VIDEOCHAT_DEPLOY_HOST=<server-ip-or-host> \
  VIDEOCHAT_DEPLOY_DOMAIN=<video.example.com> \
  VIDEOCHAT_DEPLOY_EMAIL=<admin@example.com> \
  demo/video-chat/scripts/deploy.sh [wizard|deploy|prepare|public-http|http-preview|status|credentials|certonly|sync]

Interactive Hetzner bootstrap:
  demo/video-chat/scripts/deploy.sh wizard

Local state:
    demo/video-chat/.env.local is loaded before validation. The wizard persists
  domain, email, API token, server settings, SSH key path, and resolved server IP
  there. The file is ignored by git.

Required remote environment:
  VIDEOCHAT_DEPLOY_HOST          SSH target host/IP.
  VIDEOCHAT_DEPLOY_DOMAIN        Public DNS name. Its A/AAAA record must point to the server.
  VIDEOCHAT_DEPLOY_EMAIL         Let's Encrypt registration/notification email.

Optional environment:
  VIDEOCHAT_DEPLOY_USER          SSH user, default: root.
  VIDEOCHAT_DEPLOY_SSH_KEY       SSH private key path.
  VIDEOCHAT_DEPLOY_SSH_PORT      SSH port, default: 22.
  VIDEOCHAT_DEPLOY_PATH          Remote checkout path, default: /opt/king-videochat.
  VIDEOCHAT_DEPLOY_RSYNC_DELETE  Delete remote files missing locally, default: 1.
  VIDEOCHAT_DEPLOY_ADMIN_PASSWORD  Admin password to write on first deploy. Generated if omitted
                                  and synced back into .env.local.
  VIDEOCHAT_DEPLOY_USER_PASSWORD   User password to write on first deploy. Generated if omitted
                                  and synced back into .env.local.
  VIDEOCHAT_DEPLOY_TURN_SECRET     TURN secret to write on first deploy. Generated if omitted
                                  and synced back into .env.local.
  VIDEOCHAT_DEPLOY_SYNC_REMOTE_SECRETS
                                  Sync generated remote secrets into .env.local, default: 1.
  VIDEOCHAT_DEPLOY_PUBLIC_IP       Optional expected DNS target IP for preflight.
  VIDEOCHAT_DEPLOY_COMPOSE_URL     Override Compose v2 plugin fallback download URL.
  VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS
                              Remove stale SSH known_hosts entries before connecting.
                              Default: auto-enabled when VIDEOCHAT_DEPLOY_PUBLIC_IP is known.
                              The Hetzner wizard persists this as 1.
  VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE
                              Known hosts file for refresh, default: ~/.ssh/known_hosts.
  VIDEOCHAT_DEPLOY_REMOTE_LOCALE
                              Locale for remote shell commands, default: C.UTF-8.
  VIDEOCHAT_DEPLOY_API_DOMAIN  API host, default: api.<domain>.
  VIDEOCHAT_DEPLOY_WS_DOMAIN   Lobby websocket host, default: ws.<domain>.
  VIDEOCHAT_DEPLOY_SFU_DOMAIN  SFU websocket host, default: sfu.<domain>.
  VIDEOCHAT_DEPLOY_TURN_DOMAIN TURN host, default: turn.<domain>.
  VIDEOCHAT_DEPLOY_CDN_DOMAIN  Static/CDN asset host, default: cnd.<domain>.
  VIDEOCHAT_DEPLOY_VUE_ALLOWED_HOSTS
                              Comma-separated frontend dev-server hosts, default:
                              deploy domain plus api/ws/sfu/turn/cdn hosts.

Optional Hetzner wizard environment:
  VIDEOCHAT_DEPLOY_HCLOUD_TOKEN       Hetzner Cloud read/write API token.
  VIDEOCHAT_DEPLOY_HCLOUD_API_BASE    API base, default: https://api.hetzner.cloud/v1.
  VIDEOCHAT_DEPLOY_HCLOUD_LOCATION    Server location, default: fsn1.
  VIDEOCHAT_DEPLOY_HCLOUD_SERVER_TYPE Server type, default: cpx21.
  VIDEOCHAT_DEPLOY_HCLOUD_IMAGE       Server image, default: ubuntu-24.04.
  VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME Server name, generated from domain if omitted.
  VIDEOCHAT_DEPLOY_HCLOUD_DNS         Set Hetzner DNS A record if zone exists, default: prompt yes.
  VIDEOCHAT_DEPLOY_DNS_WAIT_SECONDS   DNS wait timeout, default: 900.

Actions:
  wizard       Ask for domain, email, Hetzner token, create/reuse a server,
               upload an SSH key, set DNS if available, then run production deploy.
  deploy       Production deployment: bootstrap, sync, renew certs, then start
               the King/PHP HTTPS edge on :80/:443. API/WS/SFU stay internal.
  prepare       Bootstrap host, sync repo, obtain/renew cert, write production env/secrets.
                Does not start the public stack.
  public-http   Legacy smoke mode: same as prepare, then starts the current King compose stack publicly over HTTP.
                Frontend binds :80; King HTTP/WS/SFU bind :18080/:18081/:18082.
  http-preview  Same as prepare, then starts the current HTTP compose stack for server smoke only.
                Requires VIDEOCHAT_DEPLOY_ALLOW_HTTP_PREVIEW=1.
  status        Show remote compose status and certbot certificate state.
  credentials   Sync generated remote deploy secrets into local .env.local.
  certonly      Only obtain/renew the Let's Encrypt cert on the remote host.
  sync          Only sync this checkout to the remote host.

This script intentionally does not create third-party reverse-proxy configs.
King remains the application webserver. Certbot is used only to provision certs.
USAGE
}

log() {
  printf '[videochat-deploy] %s\n' "$*"
}

die() {
  printf '[videochat-deploy] ERROR: %s\n' "$*" >&2
  exit 1
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "missing required command: $1"
}

require_env() {
  local name="$1"
  local value="${!name:-}"
  [[ -n "${value}" ]] || die "missing required environment variable: ${name}"
}

shell_quote() {
  printf '%q' "$1"
}

turn_external_ip_value() {
  local candidate="${DEPLOY_PUBLIC_IP:-${DEPLOY_HOST:-}}"
  if [[ "${candidate}" =~ ^[0-9]+(\.[0-9]+){3}$ || "${candidate}" == *:* ]]; then
    printf '%s' "${candidate}"
  fi
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0
  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
}

load_local_env

ACTION="${1:-deploy}"
case "${ACTION}" in
  help|-h|--help)
    usage
    exit 0
    ;;
  wizard|hetzner|deploy|production|prepare|public-http|http-preview|status|credentials|certonly|sync)
    ;;
  *)
    usage >&2
    die "unknown action: ${ACTION}"
    ;;
esac

if [[ "${ACTION}" != "wizard" && "${ACTION}" != "hetzner" ]]; then
  require_env VIDEOCHAT_DEPLOY_HOST
  require_env VIDEOCHAT_DEPLOY_DOMAIN

  if [[ "${ACTION}" == "deploy" || "${ACTION}" == "production" || "${ACTION}" == "prepare" || "${ACTION}" == "public-http" || "${ACTION}" == "http-preview" || "${ACTION}" == "certonly" ]]; then
    require_env VIDEOCHAT_DEPLOY_EMAIL
  fi
fi

refresh_deploy_config() {
  DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:-}"
  DEPLOY_DOMAIN="${VIDEOCHAT_DEPLOY_DOMAIN:-}"
  DEPLOY_EMAIL="${VIDEOCHAT_DEPLOY_EMAIL:-}"
  DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-root}"
  DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-22}"
  DEPLOY_PATH="${VIDEOCHAT_DEPLOY_PATH:-/opt/king-videochat}"
  DEPLOY_RSYNC_DELETE="${VIDEOCHAT_DEPLOY_RSYNC_DELETE:-1}"
  DEPLOY_PUBLIC_IP="${VIDEOCHAT_DEPLOY_PUBLIC_IP:-}"
  DEPLOY_REFRESH_KNOWN_HOSTS="${VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS:-}"
  if [[ -z "${DEPLOY_REFRESH_KNOWN_HOSTS}" && -n "${DEPLOY_PUBLIC_IP}" ]]; then
    DEPLOY_REFRESH_KNOWN_HOSTS="1"
  fi
  DEPLOY_REMOTE_LOCALE="${VIDEOCHAT_DEPLOY_REMOTE_LOCALE:-C.UTF-8}"
  DEPLOY_API_DOMAIN="${VIDEOCHAT_DEPLOY_API_DOMAIN:-}"
  DEPLOY_WS_DOMAIN="${VIDEOCHAT_DEPLOY_WS_DOMAIN:-}"
  DEPLOY_SFU_DOMAIN="${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-}"
  DEPLOY_TURN_DOMAIN="${VIDEOCHAT_DEPLOY_TURN_DOMAIN:-}"
  DEPLOY_CDN_DOMAIN="${VIDEOCHAT_DEPLOY_CDN_DOMAIN:-}"

  if [[ -n "${DEPLOY_DOMAIN}" ]]; then
    DEPLOY_API_DOMAIN="${DEPLOY_API_DOMAIN:-api.${DEPLOY_DOMAIN}}"
    DEPLOY_WS_DOMAIN="${DEPLOY_WS_DOMAIN:-ws.${DEPLOY_DOMAIN}}"
    DEPLOY_SFU_DOMAIN="${DEPLOY_SFU_DOMAIN:-sfu.${DEPLOY_DOMAIN}}"
    DEPLOY_TURN_DOMAIN="${DEPLOY_TURN_DOMAIN:-turn.${DEPLOY_DOMAIN}}"
    DEPLOY_CDN_DOMAIN="${DEPLOY_CDN_DOMAIN:-cnd.${DEPLOY_DOMAIN}}"
  fi

  DEPLOY_VUE_ALLOWED_HOSTS="${VIDEOCHAT_DEPLOY_VUE_ALLOWED_HOSTS:-}"
  if [[ -z "${DEPLOY_VUE_ALLOWED_HOSTS}" && -n "${DEPLOY_DOMAIN}" ]]; then
    DEPLOY_VUE_ALLOWED_HOSTS="${DEPLOY_DOMAIN},${DEPLOY_API_DOMAIN},${DEPLOY_WS_DOMAIN},${DEPLOY_SFU_DOMAIN},${DEPLOY_TURN_DOMAIN},${DEPLOY_CDN_DOMAIN}"
  fi

  export VIDEOCHAT_DEPLOY_API_DOMAIN="${DEPLOY_API_DOMAIN}"
  export VIDEOCHAT_DEPLOY_WS_DOMAIN="${DEPLOY_WS_DOMAIN}"
  export VIDEOCHAT_DEPLOY_SFU_DOMAIN="${DEPLOY_SFU_DOMAIN}"
  export VIDEOCHAT_DEPLOY_TURN_DOMAIN="${DEPLOY_TURN_DOMAIN}"
  export VIDEOCHAT_DEPLOY_CDN_DOMAIN="${DEPLOY_CDN_DOMAIN}"
  export VIDEOCHAT_DEPLOY_VUE_ALLOWED_HOSTS="${DEPLOY_VUE_ALLOWED_HOSTS}"
  if [[ -n "${DEPLOY_REFRESH_KNOWN_HOSTS}" ]]; then
    export VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS="${DEPLOY_REFRESH_KNOWN_HOSTS}"
  fi

  SSH_DEST="${DEPLOY_USER}@${DEPLOY_HOST}"
  SSH_ARGS=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
  RSYNC_SSH=(ssh -p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)

  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    SSH_ARGS+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
    RSYNC_SSH+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
  fi
}

refresh_deploy_config

deploy_refresh_known_hosts_enabled() {
  case "${DEPLOY_REFRESH_KNOWN_HOSTS:-}" in
    1|true|TRUE|yes|YES) return 0 ;;
    *) return 1 ;;
  esac
}

deploy_dns_targets() {
  local target seen=""
  for target in "${DEPLOY_DOMAIN}" "${DEPLOY_API_DOMAIN}" "${DEPLOY_WS_DOMAIN}" "${DEPLOY_SFU_DOMAIN}" "${DEPLOY_TURN_DOMAIN}" "${DEPLOY_CDN_DOMAIN}"; do
    [[ -n "${target}" ]] || continue
    case " ${seen} " in
      *" ${target} "*) continue ;;
    esac
    seen="${seen} ${target}"
    printf '%s\n' "${target}"
  done
}

refresh_known_hosts_for_target() {
  deploy_refresh_known_hosts_enabled || return 0

  [[ -n "${DEPLOY_HOST}" ]] || return 0
  require_cmd ssh-keygen

  local known_hosts_file target target_targets=()
  known_hosts_file="${VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE:-${HOME:-}/.ssh/known_hosts}"
  [[ -n "${known_hosts_file}" && -f "${known_hosts_file}" ]] || return 0

  for target in "${DEPLOY_HOST}" "${DEPLOY_PUBLIC_IP}"; do
    [[ -n "${target}" ]] || continue
    target_targets+=("${target}" "[${target}]:${DEPLOY_SSH_PORT}")
  done
  while IFS= read -r target; do
    [[ -n "${target}" ]] || continue
    target_targets+=("${target}" "[${target}]:${DEPLOY_SSH_PORT}")
  done < <(deploy_dns_targets)

  for target in "${target_targets[@]}"; do
    ssh-keygen -f "${known_hosts_file}" -R "${target}" >/dev/null 2>&1 || true
  done

  log "Refreshed SSH known_hosts entries for ${DEPLOY_HOST}"
}

remote() {
  ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "$@"
}

remote_bash() {
  local locale_q
  locale_q="$(shell_quote "${DEPLOY_REMOTE_LOCALE}")"
  ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "LC_ALL=${locale_q} LANG=${locale_q} LANGUAGE= bash -s"
}

sudo_prefix() {
  if [[ "${DEPLOY_USER}" == "root" ]]; then
    printf ''
  else
    printf 'sudo '
  fi
}

# shellcheck source=demo/video-chat/scripts/lib/deploy-hetzner.sh
source "${SCRIPT_DIR}/lib/deploy-hetzner.sh"

persist_current_deploy_config() {
  case "${VIDEOCHAT_DEPLOY_PERSIST_LOCAL:-1}" in
    1|true|TRUE|yes|YES) ;;
    *) return 0 ;;
  esac

  [[ -n "${DEPLOY_HOST}" && -n "${DEPLOY_DOMAIN}" ]] || return 0

  local_env_upsert VIDEOCHAT_DEPLOY_HOST "${DEPLOY_HOST}"
  local_env_upsert VIDEOCHAT_DEPLOY_DOMAIN "${DEPLOY_DOMAIN}"
  [[ -n "${DEPLOY_EMAIL}" ]] && local_env_upsert VIDEOCHAT_DEPLOY_EMAIL "${DEPLOY_EMAIL}"
  [[ -n "${DEPLOY_PUBLIC_IP}" ]] && local_env_upsert VIDEOCHAT_DEPLOY_PUBLIC_IP "${DEPLOY_PUBLIC_IP}"
  local_env_upsert VIDEOCHAT_DEPLOY_USER "${DEPLOY_USER}"
  local_env_upsert VIDEOCHAT_DEPLOY_SSH_PORT "${DEPLOY_SSH_PORT}"
  local_env_upsert VIDEOCHAT_DEPLOY_PATH "${DEPLOY_PATH}"
  local_env_upsert VIDEOCHAT_DEPLOY_RSYNC_DELETE "${DEPLOY_RSYNC_DELETE}"
  local_env_upsert VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS "${DEPLOY_REFRESH_KNOWN_HOSTS:-0}"
  local_env_upsert VIDEOCHAT_DEPLOY_REMOTE_LOCALE "${DEPLOY_REMOTE_LOCALE}"
  local_env_upsert VIDEOCHAT_DEPLOY_API_DOMAIN "${DEPLOY_API_DOMAIN}"
  local_env_upsert VIDEOCHAT_DEPLOY_WS_DOMAIN "${DEPLOY_WS_DOMAIN}"
  local_env_upsert VIDEOCHAT_DEPLOY_SFU_DOMAIN "${DEPLOY_SFU_DOMAIN}"
  local_env_upsert VIDEOCHAT_DEPLOY_TURN_DOMAIN "${DEPLOY_TURN_DOMAIN}"
  local_env_upsert VIDEOCHAT_DEPLOY_CDN_DOMAIN "${DEPLOY_CDN_DOMAIN}"
  local_env_upsert VIDEOCHAT_DEPLOY_VUE_ALLOWED_HOSTS "${DEPLOY_VUE_ALLOWED_HOSTS}"
  [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]] && local_env_upsert VIDEOCHAT_DEPLOY_SSH_KEY "${VIDEOCHAT_DEPLOY_SSH_KEY}"
  [[ -n "${VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE:-}" ]] && local_env_upsert VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE "${VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE}"
  [[ -n "${VIDEOCHAT_DEPLOY_COMPOSE_URL:-}" ]] && local_env_upsert VIDEOCHAT_DEPLOY_COMPOSE_URL "${VIDEOCHAT_DEPLOY_COMPOSE_URL}"
  return 0
}

check_dns_hint() {
  if ! command -v getent >/dev/null 2>&1; then
    return 0
  fi

  local resolved target
  while IFS= read -r target; do
    [[ -n "${target}" ]] || continue
    resolved="$(resolved_ips_for_domain "${target}" | tr '\n' ' ' | sed -E 's/[[:space:]]+$//')"
    if [[ -z "${resolved}" ]]; then
      die "DNS preflight missing: ${target} does not resolve. Certbot requires DNS before deploy."
    fi

    log "DNS local resolve: ${target} -> ${resolved}"
    if [[ -n "${DEPLOY_PUBLIC_IP}" ]] && ! resolved_ips_for_domain "${target}" | grep -Fxq "${DEPLOY_PUBLIC_IP}"; then
      die "DNS preflight mismatch for ${target}: expected ${DEPLOY_PUBLIC_IP}, got ${resolved}"
    fi
  done < <(deploy_dns_targets)
}

ensure_hcloud_dns_records_if_configured() {
  case "${VIDEOCHAT_DEPLOY_HCLOUD_DNS:-0}" in
    1|true|TRUE|yes|YES) ;;
    *) return 0 ;;
  esac

  [[ -n "${VIDEOCHAT_DEPLOY_HCLOUD_TOKEN:-}" && -n "${DEPLOY_PUBLIC_IP}" ]] || return 0
  require_cmd curl
  require_cmd jq

  HCLOUD_TOKEN="${VIDEOCHAT_DEPLOY_HCLOUD_TOKEN}"
  HCLOUD_API_BASE="${VIDEOCHAT_DEPLOY_HCLOUD_API_BASE:-https://api.hetzner.cloud/v1}"
  HCLOUD_API_BASE="${HCLOUD_API_BASE%/}"

  hcloud_set_dns_a_record || true
  hcloud_set_videochat_subdomain_records
}

bootstrap_remote() {
  local deploy_path_q domain_q user_q compose_url_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  user_q="$(shell_quote "${DEPLOY_USER}")"
  compose_url_q="$(shell_quote "${VIDEOCHAT_DEPLOY_COMPOSE_URL:-}")"

  log "Bootstrapping remote host ${SSH_DEST}"
  remote_bash <<REMOTE
set -euo pipefail
SUDO="$(sudo_prefix)"
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
DEPLOY_USER=${user_q}
COMPOSE_URL_OVERRIDE=${compose_url_q}

if command -v apt-get >/dev/null 2>&1; then
  \${SUDO}apt-get update
  DEBIAN_FRONTEND=noninteractive \${SUDO}apt-get install -y --no-install-recommends \\
    ca-certificates \\
    certbot \\
    curl \\
    docker.io \\
    git \\
    openssl \\
    rsync
else
  echo "Only apt-based hosts are supported by this deploy helper right now." >&2
  exit 1
fi

install_compose_v2() {
  if docker compose version >/dev/null 2>&1; then
    return 0
  fi

  for compose_package in docker-compose-plugin docker-compose-v2; do
    if apt-cache show "\${compose_package}" >/dev/null 2>&1; then
      if DEBIAN_FRONTEND=noninteractive \${SUDO}apt-get install -y --no-install-recommends "\${compose_package}"; then
        docker compose version >/dev/null 2>&1 && return 0
      fi
    fi
  done

  local machine compose_arch compose_url compose_tmp
  machine="\$(uname -m)"
  case "\${machine}" in
    x86_64|amd64) compose_arch="x86_64" ;;
    aarch64|arm64) compose_arch="aarch64" ;;
    armv7l|armv7) compose_arch="armv7" ;;
    *) echo "Unsupported architecture for Docker Compose fallback: \${machine}" >&2; return 1 ;;
  esac

  if [ -n "\${COMPOSE_URL_OVERRIDE}" ]; then
    compose_url="\${COMPOSE_URL_OVERRIDE}"
  else
    compose_url="https://github.com/docker/compose/releases/latest/download/docker-compose-linux-\${compose_arch}"
  fi
  compose_tmp="\$(mktemp)"
  curl -fsSL "\${compose_url}" -o "\${compose_tmp}"

  \${SUDO}install -d -m 0755 /usr/local/lib/docker/cli-plugins
  \${SUDO}install -m 0755 "\${compose_tmp}" /usr/local/lib/docker/cli-plugins/docker-compose
  rm -f "\${compose_tmp}"

  if ! docker compose version >/dev/null 2>&1; then
    \${SUDO}install -d -m 0755 /usr/libexec/docker/cli-plugins
    \${SUDO}install -m 0755 /usr/local/lib/docker/cli-plugins/docker-compose /usr/libexec/docker/cli-plugins/docker-compose
  fi

  docker compose version >/dev/null 2>&1
}

install_compose_v2 || {
  echo "Docker Compose v2 could not be installed. Set VIDEOCHAT_DEPLOY_COMPOSE_URL to a compatible compose plugin binary and rerun." >&2
  exit 1
}

\${SUDO}install -d -m 0755 "\${DEPLOY_PATH}"
if [ "\${DEPLOY_USER}" != "root" ]; then
  \${SUDO}chown "\${DEPLOY_USER}:\${DEPLOY_USER}" "\${DEPLOY_PATH}" || true
fi
\${SUDO}systemctl enable --now docker

if command -v ufw >/dev/null 2>&1 && \${SUDO}ufw status | grep -q 'Status: active'; then
  \${SUDO}ufw allow OpenSSH >/dev/null || true
  \${SUDO}ufw allow 80/tcp >/dev/null || true
  \${SUDO}ufw allow 443/tcp >/dev/null || true
  \${SUDO}ufw allow 3478/tcp >/dev/null || true
  \${SUDO}ufw allow 3478/udp >/dev/null || true
  \${SUDO}ufw allow 49160:49200/udp >/dev/null || true
fi

if command -v firewall-cmd >/dev/null 2>&1 && \${SUDO}firewall-cmd --state >/dev/null 2>&1; then
  \${SUDO}firewall-cmd --permanent --add-service=http >/dev/null || true
  \${SUDO}firewall-cmd --permanent --add-service=https >/dev/null || true
  \${SUDO}firewall-cmd --permanent --add-port=3478/tcp >/dev/null || true
  \${SUDO}firewall-cmd --permanent --add-port=3478/udp >/dev/null || true
  \${SUDO}firewall-cmd --permanent --add-port=49160-49200/udp >/dev/null || true
  \${SUDO}firewall-cmd --reload >/dev/null || true
fi

echo "Remote bootstrap complete for \${DOMAIN}"
REMOTE
}

sync_checkout() {
  require_cmd rsync

  local delete_arg=()
  if [[ "${DEPLOY_RSYNC_DELETE}" == "1" ]]; then
    delete_arg=(--delete)
  fi

  log "Syncing checkout to ${SSH_DEST}:${DEPLOY_PATH}"
  remote "mkdir -p $(shell_quote "${DEPLOY_PATH}")"
  rsync -az "${delete_arg[@]}" \
    -e "$(printf '%q ' "${RSYNC_SSH[@]}")" \
    --exclude '.git/' \
    --exclude '.codex/' \
    --exclude '.env.local' \
    --exclude '*.env.local' \
    --exclude 'demo/video-chat/docker-compose.deploy.local.yml' \
    --exclude 'demo/video-chat/frontend-vue/node_modules/' \
    --exclude 'demo/video-chat/frontend-vue/.vite/' \
    --exclude 'demo/video-chat/frontend-vue/dist/' \
    --exclude 'demo/video-chat/backend-king-php/.local/' \
    --exclude 'target/' \
    --exclude '.pytest_cache/' \
    --exclude '.mypy_cache/' \
    "${REPO_ROOT}/" \
    "${SSH_DEST}:${DEPLOY_PATH}/"
}

certbot_standalone() {
  local deploy_path_q domain_q email_q api_domain_q ws_domain_q sfu_domain_q turn_domain_q cdn_domain_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  email_q="$(shell_quote "${DEPLOY_EMAIL}")"
  api_domain_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_domain_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_domain_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_domain_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"
  cdn_domain_q="$(shell_quote "${DEPLOY_CDN_DOMAIN}")"

  log "Obtaining/renewing Let's Encrypt cert for ${DEPLOY_DOMAIN}"
  remote_bash <<REMOTE
set -euo pipefail
SUDO="$(sudo_prefix)"
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
EMAIL=${email_q}
API_DOMAIN=${api_domain_q}
WS_DOMAIN=${ws_domain_q}
SFU_DOMAIN=${sfu_domain_q}
TURN_DOMAIN=${turn_domain_q}
CDN_DOMAIN=${cdn_domain_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
FRONTEND_WAS_RUNNING=0
EDGE_WAS_RUNNING=0

if ! command -v certbot >/dev/null 2>&1; then
  echo "certbot is missing on remote host." >&2
  exit 1
fi

restore_certbot_stopped_services() {
  if [ ! -d "\${VIDEOCHAT_DIR}" ] || [ ! -f "\${VIDEOCHAT_DIR}/docker-compose.v1.yml" ] || [ ! -f "\${VIDEOCHAT_DIR}/docker-compose.deploy.local.yml" ]; then
    return 0
  fi
  cd "\${VIDEOCHAT_DIR}"
  if [ "\${FRONTEND_WAS_RUNNING}" = "1" ]; then
    docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      up -d --no-deps videochat-frontend-v1 >/dev/null || true
  fi

  if [ "\${EDGE_WAS_RUNNING}" = "1" ]; then
    docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      --profile edge \\
      --profile turn \\
      up -d --no-deps videochat-edge-v1 >/dev/null || true
  fi
}
trap restore_certbot_stopped_services EXIT

if [ -f "\${VIDEOCHAT_DIR}/docker-compose.v1.yml" ] && [ -f "\${VIDEOCHAT_DIR}/.env.local" ] && [ -f "\${VIDEOCHAT_DIR}/docker-compose.deploy.local.yml" ]; then
  cd "\${VIDEOCHAT_DIR}"
  if docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      --profile edge \\
      --profile turn \\
      ps -q videochat-edge-v1 2>/dev/null | grep -q .; then
    EDGE_WAS_RUNNING=1
    docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      --profile edge \\
      --profile turn \\
      stop videochat-edge-v1 >/dev/null || true
  fi

  if docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      ps -q videochat-frontend-v1 2>/dev/null | grep -q .; then
    FRONTEND_WAS_RUNNING=1
    docker compose --env-file .env --env-file .env.local \\
      -f docker-compose.v1.yml \\
      -f docker-compose.deploy.local.yml \\
      stop videochat-frontend-v1 >/dev/null || true
  fi
fi

\${SUDO}certbot certonly \\
  --standalone \\
  --non-interactive \\
  --agree-tos \\
  --email "\${EMAIL}" \\
  --cert-name "\${DOMAIN}" \\
  --expand \\
  --keep-until-expiring \\
  -d "\${DOMAIN}" \\
  -d "\${API_DOMAIN}" \\
  -d "\${WS_DOMAIN}" \\
  -d "\${SFU_DOMAIN}" \\
  -d "\${TURN_DOMAIN}" \\
  -d "\${CDN_DOMAIN}"

\${SUDO}test -r "/etc/letsencrypt/live/\${DOMAIN}/fullchain.pem"
\${SUDO}test -r "/etc/letsencrypt/live/\${DOMAIN}/privkey.pem"

restore_certbot_stopped_services
trap - EXIT
REMOTE
}

write_remote_runtime_files() {
  local deploy_path_q domain_q api_domain_q ws_domain_q sfu_domain_q turn_domain_q cdn_domain_q turn_external_ip_q admin_q user_q turn_q vue_allowed_hosts_q
  local infra_provider_q infra_cluster_q infra_node_roles_q infra_hcloud_token_q infra_hcloud_api_base_q
  local otel_enable_q otel_endpoint_q otel_protocol_q otel_metrics_q otel_logs_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  api_domain_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_domain_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_domain_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_domain_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"
  cdn_domain_q="$(shell_quote "${DEPLOY_CDN_DOMAIN}")"
  turn_external_ip_q="$(shell_quote "$(turn_external_ip_value)")"
  admin_q="$(shell_quote "${VIDEOCHAT_DEPLOY_ADMIN_PASSWORD:-}")"
  user_q="$(shell_quote "${VIDEOCHAT_DEPLOY_USER_PASSWORD:-}")"
  turn_q="$(shell_quote "${VIDEOCHAT_DEPLOY_TURN_SECRET:-}")"
  vue_allowed_hosts_q="$(shell_quote "${DEPLOY_VUE_ALLOWED_HOSTS}")"
  infra_provider_q="$(shell_quote "${VIDEOCHAT_INFRA_PROVIDER:-auto}")"
  infra_cluster_q="$(shell_quote "${VIDEOCHAT_INFRA_CLUSTER_NAME:-${DEPLOY_DOMAIN}}")"
  infra_node_roles_q="$(shell_quote "${VIDEOCHAT_INFRA_NODE_ROLES:-edge,http,ws,sfu}")"
  infra_hcloud_token_q="$(shell_quote "${VIDEOCHAT_INFRA_HETZNER_TOKEN:-${VIDEOCHAT_DEPLOY_HCLOUD_TOKEN:-}}")"
  infra_hcloud_api_base_q="$(shell_quote "${VIDEOCHAT_INFRA_HETZNER_API_BASE:-${VIDEOCHAT_DEPLOY_HCLOUD_API_BASE:-https://api.hetzner.cloud/v1}}")"
  otel_enable_q="$(shell_quote "${VIDEOCHAT_OTEL_ENABLE:-}")"
  otel_endpoint_q="$(shell_quote "${VIDEOCHAT_OTEL_EXPORTER_ENDPOINT:-}")"
  otel_protocol_q="$(shell_quote "${VIDEOCHAT_OTEL_EXPORTER_PROTOCOL:-grpc}")"
  otel_metrics_q="$(shell_quote "${VIDEOCHAT_OTEL_METRICS_ENABLE:-}")"
  otel_logs_q="$(shell_quote "${VIDEOCHAT_OTEL_LOGS_ENABLE:-}")"

  log "Writing remote production env, secrets, and cert renewal hook"
  remote_bash <<REMOTE
set -euo pipefail
SUDO="$(sudo_prefix)"
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
API_DOMAIN=${api_domain_q}
WS_DOMAIN=${ws_domain_q}
SFU_DOMAIN=${sfu_domain_q}
TURN_DOMAIN=${turn_domain_q}
CDN_DOMAIN=${cdn_domain_q}
TURN_EXTERNAL_IP=${turn_external_ip_q}
ADMIN_PASSWORD=${admin_q}
USER_PASSWORD=${user_q}
TURN_SECRET=${turn_q}
VUE_ALLOWED_HOSTS=${vue_allowed_hosts_q}
INFRA_PROVIDER=${infra_provider_q}
INFRA_CLUSTER=${infra_cluster_q}
INFRA_NODE_ROLES=${infra_node_roles_q}
INFRA_HCLOUD_TOKEN=${infra_hcloud_token_q}
INFRA_HCLOUD_API_BASE=${infra_hcloud_api_base_q}
OTEL_ENABLE=${otel_enable_q}
OTEL_ENDPOINT=${otel_endpoint_q}
OTEL_PROTOCOL=${otel_protocol_q}
OTEL_METRICS=${otel_metrics_q}
OTEL_LOGS=${otel_logs_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
SECRETS_DIR="\${VIDEOCHAT_DIR}/secrets"
LOCAL_COMPOSE="\${VIDEOCHAT_DIR}/docker-compose.deploy.local.yml"
LOCAL_ENV="\${VIDEOCHAT_DIR}/.env.local"

\${SUDO}install -d -m 0700 "\${SECRETS_DIR}"

write_secret_once() {
  local path="\$1"
  local value="\$2"
  local generator="\$3"
  if [ -n "\${value}" ]; then
    printf '%s\n' "\${value}" | \${SUDO}tee "\${path}" >/dev/null
  elif ! \${SUDO}test -s "\${path}"; then
    eval "\${generator}" | \${SUDO}tee "\${path}" >/dev/null
  fi
  \${SUDO}chmod 0600 "\${path}"
}

write_secret_once "\${SECRETS_DIR}/admin-password" "\${ADMIN_PASSWORD}" 'openssl rand -base64 36'
write_secret_once "\${SECRETS_DIR}/user-password" "\${USER_PASSWORD}" 'openssl rand -base64 36'
write_secret_once "\${SECRETS_DIR}/turn-secret" "\${TURN_SECRET}" 'openssl rand -base64 48'
TURN_SECRET_VALUE="\$(\${SUDO}cat "\${SECRETS_DIR}/turn-secret")"

\${SUDO}tee "\${LOCAL_ENV}" >/dev/null <<ENVEOF
# Generated by demo/video-chat/scripts/deploy.sh.
# Machine-specific file; never commit.
VIDEOCHAT_V1_PUBLIC_HOST=\${DOMAIN}
VIDEOCHAT_V1_PUBLIC_SCHEME=https
VIDEOCHAT_V1_ALLOW_INSECURE_WS=

VIDEOCHAT_V1_FRONTEND_PORT=5176
VIDEOCHAT_V1_FRONTEND_BIND=127.0.0.1
VIDEOCHAT_V1_BACKEND_PORT=18080
VIDEOCHAT_V1_BACKEND_BIND=127.0.0.1
VIDEOCHAT_V1_BACKEND_WS_PORT=18081
VIDEOCHAT_V1_BACKEND_WS_BIND=127.0.0.1
VIDEOCHAT_V1_BACKEND_SFU_PORT=18082
VIDEOCHAT_V1_BACKEND_SFU_BIND=127.0.0.1
VIDEOCHAT_V1_EDGE_HTTP_PORT=80
VIDEOCHAT_V1_EDGE_HTTPS_PORT=443

VIDEOCHAT_DEPLOY_API_DOMAIN=\${API_DOMAIN}
VIDEOCHAT_DEPLOY_WS_DOMAIN=\${WS_DOMAIN}
VIDEOCHAT_DEPLOY_SFU_DOMAIN=\${SFU_DOMAIN}
VIDEOCHAT_DEPLOY_TURN_DOMAIN=\${TURN_DOMAIN}
VIDEOCHAT_DEPLOY_CDN_DOMAIN=\${CDN_DOMAIN}
VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat/turn-secret
VIDEOCHAT_TURN_URIS=turn:\${TURN_DOMAIN}:3478?transport=udp,turn:\${TURN_DOMAIN}:3478?transport=tcp
VIDEOCHAT_TURN_TTL_SECONDS=3600
VIDEOCHAT_V1_TURN_REALM=\${TURN_DOMAIN}
VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET=\${TURN_SECRET_VALUE}
VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE=/run/secrets/videochat/turn-secret
VIDEOCHAT_V1_TURN_EXTERNAL_IP=\${TURN_EXTERNAL_IP}
VIDEOCHAT_V1_BACKEND_ORIGIN=https://\${API_DOMAIN}
VIDEOCHAT_V1_BACKEND_WS_ORIGIN=https://\${WS_DOMAIN}
VIDEOCHAT_V1_BACKEND_SFU_ORIGIN=https://\${SFU_DOMAIN}
VITE_VIDEOCHAT_CDN_ORIGIN=https://\${CDN_DOMAIN}
VIDEOCHAT_V1_FRONTEND_BACKEND_PORT_FALLBACK=
VIDEOCHAT_V1_FRONTEND_WS_PORT_FALLBACK=
VIDEOCHAT_V1_FRONTEND_SFU_PORT_FALLBACK=
VIDEOCHAT_VUE_ALLOWED_HOSTS=\${VUE_ALLOWED_HOSTS}
VIDEOCHAT_FRONTEND_ORIGIN=https://\${DOMAIN}
VIDEOCHAT_INFRA_PROVIDER=\${INFRA_PROVIDER}
VIDEOCHAT_INFRA_CLUSTER_NAME=\${INFRA_CLUSTER}
VIDEOCHAT_INFRA_PUBLIC_DOMAIN=\${DOMAIN}
VIDEOCHAT_INFRA_NODE_ROLES=\${INFRA_NODE_ROLES}
VIDEOCHAT_INFRA_HETZNER_TOKEN=\${INFRA_HCLOUD_TOKEN}
VIDEOCHAT_INFRA_HETZNER_API_BASE=\${INFRA_HCLOUD_API_BASE}
VIDEOCHAT_OTEL_ENABLE=\${OTEL_ENABLE}
VIDEOCHAT_OTEL_EXPORTER_ENDPOINT=\${OTEL_ENDPOINT}
VIDEOCHAT_OTEL_EXPORTER_PROTOCOL=\${OTEL_PROTOCOL}
VIDEOCHAT_OTEL_METRICS_ENABLE=\${OTEL_METRICS}
VIDEOCHAT_OTEL_LOGS_ENABLE=\${OTEL_LOGS}
VITE_VIDEOCHAT_ICE_SERVERS=
ENVEOF
\${SUDO}chmod 0600 "\${LOCAL_ENV}"

\${SUDO}tee "\${LOCAL_COMPOSE}" >/dev/null <<YAMLEOF
# Generated by demo/video-chat/scripts/deploy.sh.
# This is a host-local compose override for secrets/certs only.
services:
  videochat-backend-v1:
    environment: &videochat_prod_backend_env
      VIDEOCHAT_KING_ENV: production
      VIDEOCHAT_REQUIRE_SECRET_SOURCES: "1"
      VIDEOCHAT_DEMO_SEED_CALLS: "0"
      VIDEOCHAT_DEMO_ADMIN_PASSWORD_FILE: /run/secrets/videochat/admin-password
      VIDEOCHAT_DEMO_USER_PASSWORD_FILE: /run/secrets/videochat/user-password
      VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE: /run/secrets/videochat/turn-secret
      VIDEOCHAT_TURN_URIS: turn:\${TURN_DOMAIN}:3478?transport=udp,turn:\${TURN_DOMAIN}:3478?transport=tcp
      VIDEOCHAT_TURN_TTL_SECONDS: "3600"
      VIDEOCHAT_FRONTEND_ORIGIN: https://\${DOMAIN}
    volumes:
      - ./secrets:/run/secrets/videochat:ro
      - /etc/letsencrypt/live/\${DOMAIN}:/run/certs/live:ro
      - /etc/letsencrypt/archive/\${DOMAIN}:/run/archive/\${DOMAIN}:ro
  videochat-backend-ws-v1:
    environment: *videochat_prod_backend_env
    volumes:
      - ./secrets:/run/secrets/videochat:ro
      - /etc/letsencrypt/live/\${DOMAIN}:/run/certs/live:ro
      - /etc/letsencrypt/archive/\${DOMAIN}:/run/archive/\${DOMAIN}:ro
  videochat-backend-sfu-v1:
    environment: *videochat_prod_backend_env
    volumes:
      - ./secrets:/run/secrets/videochat:ro
      - /etc/letsencrypt/live/\${DOMAIN}:/run/certs/live:ro
      - /etc/letsencrypt/archive/\${DOMAIN}:/run/archive/\${DOMAIN}:ro
  videochat-turn-v1:
    environment:
      VIDEOCHAT_V1_TURN_REALM: \${TURN_DOMAIN}
      VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE: /run/secrets/videochat/turn-secret
      VIDEOCHAT_V1_TURN_EXTERNAL_IP: \${TURN_EXTERNAL_IP}
    volumes:
      - ./secrets:/run/secrets/videochat:ro
  videochat-edge-v1:
    volumes:
      - /etc/letsencrypt/live/\${DOMAIN}:/run/certs/live:ro
      - /etc/letsencrypt/archive/\${DOMAIN}:/run/archive/\${DOMAIN}:ro
YAMLEOF
\${SUDO}chmod 0600 "\${LOCAL_COMPOSE}"

\${SUDO}install -d -m 0755 /etc/letsencrypt/renewal-hooks/deploy
\${SUDO}tee /etc/letsencrypt/renewal-hooks/deploy/king-videochat-restart.sh >/dev/null <<HOOKEOF
#!/usr/bin/env bash
set -euo pipefail
cd "\${VIDEOCHAT_DIR}"
if [ -f docker-compose.deploy.local.yml ]; then
  docker compose --env-file .env --env-file .env.local \\
    -f docker-compose.v1.yml \\
    -f docker-compose.deploy.local.yml \\
    --profile edge \\
    --profile turn \\
    restart || true
fi
HOOKEOF
\${SUDO}chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/king-videochat-restart.sh
REMOTE
}

remote_compose_status() {
  local deploy_path_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  remote_bash <<REMOTE
set -euo pipefail
DEPLOY_PATH=${deploy_path_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
if [ -d "\${VIDEOCHAT_DIR}" ]; then
  cd "\${VIDEOCHAT_DIR}"
  if [ -f docker-compose.deploy.local.yml ]; then
    docker compose --env-file .env --env-file .env.local -f docker-compose.v1.yml -f docker-compose.deploy.local.yml --profile edge --profile turn ps || true
  elif [ -x scripts/compose-v1.sh ]; then
    ./scripts/compose-v1.sh ps || true
  fi
fi
if command -v certbot >/dev/null 2>&1; then
  certbot certificates -d $(shell_quote "${DEPLOY_DOMAIN}") || true
fi
REMOTE
}

sync_remote_secrets_to_local() {
  case "${VIDEOCHAT_DEPLOY_SYNC_REMOTE_SECRETS:-1}" in
    1|true|TRUE|yes|YES) ;;
    *) return 0 ;;
  esac

  local deploy_path_q output name value synced=0
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"

  output="$(remote_bash <<REMOTE
set -euo pipefail
SUDO="$(sudo_prefix)"
DEPLOY_PATH=${deploy_path_q}
SECRETS_DIR="\${DEPLOY_PATH}/demo/video-chat/secrets"

emit_secret() {
  local key="\$1"
  local path="\$2"
  if \${SUDO}test -s "\${path}"; then
    printf '%s\t' "\${key}"
    \${SUDO}cat "\${path}"
    printf '\n'
  fi
}

emit_secret VIDEOCHAT_DEPLOY_ADMIN_PASSWORD "\${SECRETS_DIR}/admin-password"
emit_secret VIDEOCHAT_DEPLOY_USER_PASSWORD "\${SECRETS_DIR}/user-password"
emit_secret VIDEOCHAT_DEPLOY_TURN_SECRET "\${SECRETS_DIR}/turn-secret"
REMOTE
)"

  while IFS=$'\t' read -r name value; do
    [[ -n "${name}" && -n "${value}" ]] || continue
    case "${name}" in
      VIDEOCHAT_DEPLOY_ADMIN_PASSWORD|VIDEOCHAT_DEPLOY_USER_PASSWORD|VIDEOCHAT_DEPLOY_TURN_SECRET)
        local_env_upsert "${name}" "${value}"
        printf -v "${name}" '%s' "${value}"
        export "${name}"
        synced=1
        ;;
    esac
  done <<<"${output}"

  if [[ "${synced}" == "1" ]]; then
    log "Synced production credentials into ${LOCAL_ENV_FILE}"
  fi
}

start_production_https() {
  local deploy_path_q domain_q api_domain_q ws_domain_q sfu_domain_q turn_domain_q cdn_domain_q turn_external_ip_q vue_allowed_hosts_q
  local infra_provider_q infra_cluster_q infra_node_roles_q infra_hcloud_token_q infra_hcloud_api_base_q
  local otel_enable_q otel_endpoint_q otel_protocol_q otel_metrics_q otel_logs_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  api_domain_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_domain_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_domain_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_domain_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"
  cdn_domain_q="$(shell_quote "${DEPLOY_CDN_DOMAIN}")"
  turn_external_ip_q="$(shell_quote "$(turn_external_ip_value)")"
  vue_allowed_hosts_q="$(shell_quote "${DEPLOY_VUE_ALLOWED_HOSTS}")"
  infra_provider_q="$(shell_quote "${VIDEOCHAT_INFRA_PROVIDER:-auto}")"
  infra_cluster_q="$(shell_quote "${VIDEOCHAT_INFRA_CLUSTER_NAME:-${DEPLOY_DOMAIN}}")"
  infra_node_roles_q="$(shell_quote "${VIDEOCHAT_INFRA_NODE_ROLES:-edge,http,ws,sfu}")"
  infra_hcloud_token_q="$(shell_quote "${VIDEOCHAT_INFRA_HETZNER_TOKEN:-${VIDEOCHAT_DEPLOY_HCLOUD_TOKEN:-}}")"
  infra_hcloud_api_base_q="$(shell_quote "${VIDEOCHAT_INFRA_HETZNER_API_BASE:-${VIDEOCHAT_DEPLOY_HCLOUD_API_BASE:-https://api.hetzner.cloud/v1}}")"
  otel_enable_q="$(shell_quote "${VIDEOCHAT_OTEL_ENABLE:-}")"
  otel_endpoint_q="$(shell_quote "${VIDEOCHAT_OTEL_EXPORTER_ENDPOINT:-}")"
  otel_protocol_q="$(shell_quote "${VIDEOCHAT_OTEL_EXPORTER_PROTOCOL:-grpc}")"
  otel_metrics_q="$(shell_quote "${VIDEOCHAT_OTEL_METRICS_ENABLE:-}")"
  otel_logs_q="$(shell_quote "${VIDEOCHAT_OTEL_LOGS_ENABLE:-}")"

  log "Starting production King HTTPS edge"
  remote_bash <<REMOTE
set -euo pipefail
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
API_DOMAIN=${api_domain_q}
WS_DOMAIN=${ws_domain_q}
SFU_DOMAIN=${sfu_domain_q}
TURN_DOMAIN=${turn_domain_q}
CDN_DOMAIN=${cdn_domain_q}
TURN_EXTERNAL_IP=${turn_external_ip_q}
VUE_ALLOWED_HOSTS=${vue_allowed_hosts_q}
INFRA_PROVIDER=${infra_provider_q}
INFRA_CLUSTER=${infra_cluster_q}
INFRA_NODE_ROLES=${infra_node_roles_q}
INFRA_HCLOUD_TOKEN=${infra_hcloud_token_q}
INFRA_HCLOUD_API_BASE=${infra_hcloud_api_base_q}
OTEL_ENABLE=${otel_enable_q}
OTEL_ENDPOINT=${otel_endpoint_q}
OTEL_PROTOCOL=${otel_protocol_q}
OTEL_METRICS=${otel_metrics_q}
OTEL_LOGS=${otel_logs_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
LOCAL_ENV="\${VIDEOCHAT_DIR}/.env.local"
cd "\${VIDEOCHAT_DIR}"

set_env_value() {
  local key="\$1"
  local value="\$2"
  local tmp
  tmp="\$(mktemp)"
  if [ -f "\${LOCAL_ENV}" ]; then
    grep -v "^\${key}=" "\${LOCAL_ENV}" >"\${tmp}" || true
  fi
  printf '%s=%s\n' "\${key}" "\${value}" >>"\${tmp}"
  cat "\${tmp}" >"\${LOCAL_ENV}"
  rm -f "\${tmp}"
}

set_env_value VIDEOCHAT_V1_PUBLIC_HOST "\${DOMAIN}"
set_env_value VIDEOCHAT_V1_PUBLIC_SCHEME https
set_env_value VIDEOCHAT_V1_ALLOW_INSECURE_WS ""
set_env_value VIDEOCHAT_V1_FRONTEND_PORT 5176
set_env_value VIDEOCHAT_V1_FRONTEND_BIND 127.0.0.1
set_env_value VIDEOCHAT_V1_BACKEND_PORT 18080
set_env_value VIDEOCHAT_V1_BACKEND_BIND 127.0.0.1
set_env_value VIDEOCHAT_V1_BACKEND_WS_PORT 18081
set_env_value VIDEOCHAT_V1_BACKEND_WS_BIND 127.0.0.1
set_env_value VIDEOCHAT_V1_BACKEND_SFU_PORT 18082
set_env_value VIDEOCHAT_V1_BACKEND_SFU_BIND 127.0.0.1
set_env_value VIDEOCHAT_V1_EDGE_HTTP_PORT 80
set_env_value VIDEOCHAT_V1_EDGE_HTTPS_PORT 443
set_env_value VIDEOCHAT_DEPLOY_API_DOMAIN "\${API_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_WS_DOMAIN "\${WS_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_SFU_DOMAIN "\${SFU_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_TURN_DOMAIN "\${TURN_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_CDN_DOMAIN "\${CDN_DOMAIN}"
set_env_value VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE /run/secrets/videochat/turn-secret
set_env_value VIDEOCHAT_TURN_URIS "turn:\${TURN_DOMAIN}:3478?transport=udp,turn:\${TURN_DOMAIN}:3478?transport=tcp"
set_env_value VIDEOCHAT_TURN_TTL_SECONDS 3600
set_env_value VIDEOCHAT_V1_TURN_REALM "\${TURN_DOMAIN}"
if [ -s secrets/turn-secret ]; then
  set_env_value VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET "\$(cat secrets/turn-secret)"
fi
set_env_value VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE /run/secrets/videochat/turn-secret
set_env_value VIDEOCHAT_V1_TURN_EXTERNAL_IP "\${TURN_EXTERNAL_IP}"
set_env_value VIDEOCHAT_V1_BACKEND_ORIGIN "https://\${API_DOMAIN}"
set_env_value VIDEOCHAT_V1_BACKEND_WS_ORIGIN "https://\${WS_DOMAIN}"
set_env_value VIDEOCHAT_V1_BACKEND_SFU_ORIGIN "https://\${SFU_DOMAIN}"
set_env_value VITE_VIDEOCHAT_CDN_ORIGIN "https://\${CDN_DOMAIN}"
set_env_value VIDEOCHAT_V1_FRONTEND_BACKEND_PORT_FALLBACK ""
set_env_value VIDEOCHAT_V1_FRONTEND_WS_PORT_FALLBACK ""
set_env_value VIDEOCHAT_V1_FRONTEND_SFU_PORT_FALLBACK ""
set_env_value VIDEOCHAT_VUE_ALLOWED_HOSTS "\${VUE_ALLOWED_HOSTS}"
set_env_value VIDEOCHAT_FRONTEND_ORIGIN "https://\${DOMAIN}"
set_env_value VIDEOCHAT_INFRA_PROVIDER "\${INFRA_PROVIDER}"
set_env_value VIDEOCHAT_INFRA_CLUSTER_NAME "\${INFRA_CLUSTER}"
set_env_value VIDEOCHAT_INFRA_PUBLIC_DOMAIN "\${DOMAIN}"
set_env_value VIDEOCHAT_INFRA_NODE_ROLES "\${INFRA_NODE_ROLES}"
set_env_value VIDEOCHAT_INFRA_HETZNER_TOKEN "\${INFRA_HCLOUD_TOKEN}"
set_env_value VIDEOCHAT_INFRA_HETZNER_API_BASE "\${INFRA_HCLOUD_API_BASE}"
set_env_value VIDEOCHAT_OTEL_ENABLE "\${OTEL_ENABLE}"
set_env_value VIDEOCHAT_OTEL_EXPORTER_ENDPOINT "\${OTEL_ENDPOINT}"
set_env_value VIDEOCHAT_OTEL_EXPORTER_PROTOCOL "\${OTEL_PROTOCOL}"
set_env_value VIDEOCHAT_OTEL_METRICS_ENABLE "\${OTEL_METRICS}"
set_env_value VIDEOCHAT_OTEL_LOGS_ENABLE "\${OTEL_LOGS}"

docker compose --env-file .env --env-file .env.local \\
  -f docker-compose.v1.yml \\
  -f docker-compose.deploy.local.yml \\
  stop videochat-frontend-v1 >/dev/null 2>&1 || true

docker compose --env-file .env --env-file .env.local \\
  -f docker-compose.v1.yml \\
  -f docker-compose.deploy.local.yml \\
  --profile edge \\
  --profile turn \\
  up -d --build --remove-orphans \\
  videochat-backend-v1 \\
  videochat-backend-ws-v1 \\
  videochat-backend-sfu-v1 \\
  videochat-turn-v1 \\
  videochat-edge-v1

wait_for_url() {
  local label="\$1"
  local url="\$2"
  local attempt
  for attempt in \$(seq 1 90); do
    if curl -fsS "\${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  printf '%s did not become reachable: %s\n' "\${label}" "\${url}" >&2
  return 1
}

wait_for_code() {
  local label="\$1"
  local expected="\$2"
  shift 2
  local attempt code
  for attempt in \$(seq 1 90); do
    code="\$(curl -sS -o /tmp/king-videochat-probe.out -w '%{http_code}' "\$@" || true)"
    if [ "\${code}" = "\${expected}" ]; then
      return 0
    fi
    sleep 1
  done
  printf '%s probe failed: expected HTTP %s, got %s\n' "\${label}" "\${expected}" "\${code:-none}" >&2
  cat /tmp/king-videochat-probe.out >&2 || true
  return 1
}

wait_for_allowed_code() {
  local label="\$1"
  local output="\$2"
  shift 2
  local attempt code
  for attempt in \$(seq 1 90); do
    code="\$(curl -sS -o "\${output}" -w '%{http_code}' "\$@" || true)"
    case "\${code}" in
      200|204|400|401|405|426)
        return 0
        ;;
    esac
    sleep 1
  done
  printf '%s probe failed with HTTP %s\n' "\${label}" "\${code:-none}" >&2
  cat "\${output}" >&2 || true
  return 1
}

wait_for_url backend-health "http://127.0.0.1:18080/health"
wait_for_code http-redirect 301 \\
  -H "Host: \${DOMAIN}" \\
  "http://127.0.0.1/"
wait_for_code https-frontend 200 \\
  --resolve "\${DOMAIN}:443:127.0.0.1" \\
  "https://\${DOMAIN}/"
wait_for_code https-api-health 200 \\
  --resolve "\${API_DOMAIN}:443:127.0.0.1" \\
  "https://\${API_DOMAIN}/health"

wait_for_allowed_code wss-route /tmp/king-videochat-ws-probe.out \\
  --resolve "\${WS_DOMAIN}:443:127.0.0.1" \\
  "https://\${WS_DOMAIN}/ws"

wait_for_allowed_code api-wss-route /tmp/king-videochat-api-ws-probe.out \\
  --resolve "\${API_DOMAIN}:443:127.0.0.1" \\
  "https://\${API_DOMAIN}/ws"

wait_for_allowed_code sfu-route /tmp/king-videochat-sfu-probe.out \\
  --resolve "\${SFU_DOMAIN}:443:127.0.0.1" \\
  "https://\${SFU_DOMAIN}/sfu"

wait_for_allowed_code api-sfu-route /tmp/king-videochat-api-sfu-probe.out \\
  --resolve "\${API_DOMAIN}:443:127.0.0.1" \\
  "https://\${API_DOMAIN}/sfu"

wait_for_tcp() {
  local label="\$1"
  local host="\$2"
  local port="\$3"
  local attempt
  for attempt in \$(seq 1 60); do
    if bash -c ":</dev/tcp/\${host}/\${port}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  printf '%s TCP probe failed: %s:%s\n' "\${label}" "\${host}" "\${port}" >&2
  return 1
}

wait_for_tcp turn-tcp 127.0.0.1 3478

if [ -s secrets/admin-password ]; then
  admin_password="\$(cat secrets/admin-password)"
  login_payload="\$(ADMIN_PASSWORD="\${admin_password}" python3 - <<'PY'
import json
import os

print(json.dumps({
    "email": "admin@intelligent-intern.com",
    "password": os.environ["ADMIN_PASSWORD"],
}))
PY
)"
  wait_for_code admin-login 200 \\
    --resolve "\${API_DOMAIN}:443:127.0.0.1" \\
    -H 'content-type: application/json' \\
    -H "origin: https://\${DOMAIN}" \\
    --data "\${login_payload}" \\
    "https://\${API_DOMAIN}/api/auth/login"

  session_token="\$(python3 - /tmp/king-videochat-probe.out <<'PY'
import json
import sys

try:
    with open(sys.argv[1], "r", encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    print("")
    raise SystemExit(0)

result = payload.get("result") if isinstance(payload, dict) else {}
for key in ("session_token", "token"):
    value = result.get(key) if isinstance(result, dict) else None
    if isinstance(value, str) and value:
        print(value)
        raise SystemExit(0)
session = result.get("session") if isinstance(result, dict) else {}
value = session.get("token") if isinstance(session, dict) else None
if isinstance(value, str) and value:
    print(value)
    raise SystemExit(0)
session = payload.get("session") if isinstance(payload, dict) else {}
value = session.get("token") if isinstance(session, dict) else None
print(value if isinstance(value, str) else "")
PY
)"
  if [ -n "\${session_token}" ]; then
    wait_for_code ice-servers 200 \\
      --resolve "\${API_DOMAIN}:443:127.0.0.1" \\
      -H "Authorization: Bearer \${session_token}" \\
      -H "origin: https://\${DOMAIN}" \\
      "https://\${API_DOMAIN}/api/user/media/ice-servers"
    python3 - /tmp/king-videochat-probe.out <<'PY'
import json
import sys

with open(sys.argv[1], "r", encoding="utf-8") as handle:
    payload = json.load(handle)
result = payload.get("result") if isinstance(payload, dict) else {}
servers = result.get("ice_servers") if isinstance(result, dict) else []
turn_servers = [
    item for item in servers
    if isinstance(item, dict) and str(item.get("urls", "")).startswith("turn:")
]
if len(turn_servers) < 2:
    raise SystemExit("ICE endpoint did not return both TURN transports")
if not result.get("enabled"):
    raise SystemExit("ICE endpoint reports TURN disabled")
PY
  fi
fi

printf 'Production frontend: https://%s/\\n' "\${DOMAIN}"
printf 'Production API: https://%s/health\\n' "\${API_DOMAIN}"
printf 'Production lobby websocket: wss://%s/ws\\n' "\${WS_DOMAIN}"
printf 'Production SFU websocket: wss://%s/sfu\\n' "\${SFU_DOMAIN}"
printf 'Production TURN relay: turn:%s:3478\\n' "\${TURN_DOMAIN}"
REMOTE
}

start_public_http() {
  local deploy_path_q domain_q api_domain_q ws_domain_q sfu_domain_q turn_domain_q cdn_domain_q turn_external_ip_q vue_allowed_hosts_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"
  api_domain_q="$(shell_quote "${DEPLOY_API_DOMAIN}")"
  ws_domain_q="$(shell_quote "${DEPLOY_WS_DOMAIN}")"
  sfu_domain_q="$(shell_quote "${DEPLOY_SFU_DOMAIN}")"
  turn_domain_q="$(shell_quote "${DEPLOY_TURN_DOMAIN}")"
  cdn_domain_q="$(shell_quote "${DEPLOY_CDN_DOMAIN}")"
  turn_external_ip_q="$(shell_quote "$(turn_external_ip_value)")"
  vue_allowed_hosts_q="$(shell_quote "${DEPLOY_VUE_ALLOWED_HOSTS}")"

  log "Starting public HTTP King stack"
  remote_bash <<REMOTE
set -euo pipefail
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
API_DOMAIN=${api_domain_q}
WS_DOMAIN=${ws_domain_q}
SFU_DOMAIN=${sfu_domain_q}
TURN_DOMAIN=${turn_domain_q}
CDN_DOMAIN=${cdn_domain_q}
TURN_EXTERNAL_IP=${turn_external_ip_q}
VUE_ALLOWED_HOSTS=${vue_allowed_hosts_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
LOCAL_ENV="\${VIDEOCHAT_DIR}/.env.local"
LOCAL_COMPOSE="\${VIDEOCHAT_DIR}/docker-compose.deploy.local.yml"
cd "\${VIDEOCHAT_DIR}"

set_env_value() {
  local key="\$1"
  local value="\$2"
  local tmp
  tmp="\$(mktemp)"
  if [ -f "\${LOCAL_ENV}" ]; then
    grep -v "^\${key}=" "\${LOCAL_ENV}" >"\${tmp}" || true
  fi
  printf '%s=%s\n' "\${key}" "\${value}" >>"\${tmp}"
  cat "\${tmp}" >"\${LOCAL_ENV}"
  rm -f "\${tmp}"
}

set_env_value VIDEOCHAT_V1_PUBLIC_SCHEME http
set_env_value VIDEOCHAT_V1_ALLOW_INSECURE_WS true
set_env_value VIDEOCHAT_V1_FRONTEND_PORT 80
set_env_value VIDEOCHAT_V1_FRONTEND_BIND 0.0.0.0
set_env_value VIDEOCHAT_V1_BACKEND_BIND 0.0.0.0
set_env_value VIDEOCHAT_V1_BACKEND_WS_BIND 0.0.0.0
set_env_value VIDEOCHAT_V1_BACKEND_SFU_BIND 0.0.0.0
set_env_value VIDEOCHAT_DEPLOY_API_DOMAIN "\${API_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_WS_DOMAIN "\${WS_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_SFU_DOMAIN "\${SFU_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_TURN_DOMAIN "\${TURN_DOMAIN}"
set_env_value VIDEOCHAT_DEPLOY_CDN_DOMAIN "\${CDN_DOMAIN}"
set_env_value VIDEOCHAT_TURN_STATIC_AUTH_SECRET_FILE /run/secrets/videochat/turn-secret
set_env_value VIDEOCHAT_TURN_URIS "turn:\${TURN_DOMAIN}:3478?transport=udp,turn:\${TURN_DOMAIN}:3478?transport=tcp"
set_env_value VIDEOCHAT_TURN_TTL_SECONDS 3600
set_env_value VIDEOCHAT_V1_TURN_REALM "\${TURN_DOMAIN}"
if [ -s secrets/turn-secret ]; then
  set_env_value VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET "\$(cat secrets/turn-secret)"
fi
set_env_value VIDEOCHAT_V1_TURN_STATIC_AUTH_SECRET_FILE /run/secrets/videochat/turn-secret
set_env_value VIDEOCHAT_V1_TURN_EXTERNAL_IP "\${TURN_EXTERNAL_IP}"
set_env_value VIDEOCHAT_V1_BACKEND_ORIGIN "http://\${API_DOMAIN}:18080"
set_env_value VIDEOCHAT_V1_BACKEND_WS_ORIGIN "http://\${WS_DOMAIN}:18081"
set_env_value VIDEOCHAT_V1_BACKEND_SFU_ORIGIN "http://\${SFU_DOMAIN}:18082"
set_env_value VITE_VIDEOCHAT_CDN_ORIGIN "http://\${CDN_DOMAIN}:80"
set_env_value VIDEOCHAT_VUE_ALLOWED_HOSTS "\${VUE_ALLOWED_HOSTS}"
set_env_value VIDEOCHAT_FRONTEND_ORIGIN "http://\${DOMAIN}"

if [ -f "\${LOCAL_COMPOSE}" ]; then
  sed -i "s#VIDEOCHAT_FRONTEND_ORIGIN: https://#VIDEOCHAT_FRONTEND_ORIGIN: http://#" "\${LOCAL_COMPOSE}"
fi

docker compose --env-file .env --env-file .env.local \\
  -f docker-compose.v1.yml \\
  -f docker-compose.deploy.local.yml \\
  --profile edge \\
  --profile turn \\
  stop videochat-edge-v1 >/dev/null 2>&1 || true

docker compose --env-file .env --env-file .env.local \\
  -f docker-compose.v1.yml \\
  -f docker-compose.deploy.local.yml \\
  --profile turn \\
  up -d --build

wait_for_url() {
  local label="\$1"
  local url="\$2"
  local attempt
  for attempt in \$(seq 1 60); do
    if curl -fsS "\${url}" >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done

  printf '%s did not become reachable: %s\n' "\${label}" "\${url}" >&2
  return 1
}

wait_for_url frontend "http://127.0.0.1/"
wait_for_url backend-health "http://127.0.0.1:18080/health"

if [ -s secrets/admin-password ]; then
  admin_password="\$(cat secrets/admin-password)"
  login_payload="\$(ADMIN_PASSWORD="\${admin_password}" python3 - <<'PY'
import json
import os

print(json.dumps({
    "email": "admin@intelligent-intern.com",
    "password": os.environ["ADMIN_PASSWORD"],
}))
PY
)"
  login_status=""
  for attempt in \$(seq 1 60); do
    login_status="\$(curl -sS -o /tmp/king-videochat-login.json -w '%{http_code}' \\
      -H 'content-type: application/json' \\
      --data "\${login_payload}" \\
      "http://127.0.0.1:18080/api/auth/login" || true)"
    if [ "\${login_status}" = "200" ]; then
      break
    fi
    sleep 1
  done
  if [ "\${login_status}" != "200" ]; then
    printf 'Admin login probe failed with HTTP %s\n' "\${login_status}" >&2
    cat /tmp/king-videochat-login.json >&2 || true
    exit 1
  fi
fi

printf 'Public HTTP frontend: http://%s/\\n' "\${DOMAIN}"
printf 'King backend health: http://%s:18080/health\\n' "\${API_DOMAIN}"
printf 'King lobby websocket: ws://%s:18081/ws\\n' "\${WS_DOMAIN}"
printf 'King SFU websocket: ws://%s:18082/sfu\\n' "\${SFU_DOMAIN}"
REMOTE
}

start_http_preview() {
  [[ "${VIDEOCHAT_DEPLOY_ALLOW_HTTP_PREVIEW:-0}" == "1" ]] || die "http-preview requires VIDEOCHAT_DEPLOY_ALLOW_HTTP_PREVIEW=1"

  local deploy_path_q domain_q
  deploy_path_q="$(shell_quote "${DEPLOY_PATH}")"
  domain_q="$(shell_quote "${DEPLOY_DOMAIN}")"

  log "Starting explicit HTTP preview stack on high ports. This is not browser-media-ready live HTTPS."
  remote_bash <<REMOTE
set -euo pipefail
DEPLOY_PATH=${deploy_path_q}
DOMAIN=${domain_q}
VIDEOCHAT_DIR="\${DEPLOY_PATH}/demo/video-chat"
cd "\${VIDEOCHAT_DIR}"

sed -i \
  -e 's/^VIDEOCHAT_V1_PUBLIC_SCHEME=.*/VIDEOCHAT_V1_PUBLIC_SCHEME=http/' \
  -e 's/^VIDEOCHAT_V1_ALLOW_INSECURE_WS=.*/VIDEOCHAT_V1_ALLOW_INSECURE_WS=true/' \
  .env.local

docker compose --env-file .env --env-file .env.local \\
  -f docker-compose.v1.yml \\
  -f docker-compose.deploy.local.yml \\
  up -d --build

curl -fsS "http://127.0.0.1:18080/health" >/dev/null
printf 'HTTP preview frontend: http://%s:5176\\n' "\${DOMAIN}"
REMOTE
}

prepare() {
  refresh_known_hosts_for_target
  require_cmd ssh
  ensure_hcloud_dns_records_if_configured
  check_dns_hint
  bootstrap_remote
  sync_checkout
  certbot_standalone
  write_remote_runtime_files
  sync_remote_secrets_to_local
}

if [[ "${ACTION}" != "wizard" && "${ACTION}" != "hetzner" ]]; then
  persist_current_deploy_config
fi

case "${ACTION}" in
  wizard|hetzner)
    hetzner_wizard
    ;;
  deploy|production)
    prepare
    start_production_https
    ;;
  prepare)
    prepare
    log "Prepared. Run deploy to start the King/PHP HTTPS edge on :80/:443."
    ;;
  public-http)
    prepare
    start_public_http
    ;;
  http-preview)
    prepare
    start_http_preview
    ;;
  status)
    refresh_known_hosts_for_target
    remote_compose_status
    ;;
  credentials)
    refresh_known_hosts_for_target
    require_cmd ssh
    sync_remote_secrets_to_local
    ;;
  certonly)
    refresh_known_hosts_for_target
    bootstrap_remote
    certbot_standalone
    ;;
  sync)
    refresh_known_hosts_for_target
    require_cmd ssh
    bootstrap_remote
    sync_checkout
    ;;
esac
