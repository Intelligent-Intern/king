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
