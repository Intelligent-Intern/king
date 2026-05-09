#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
VIDEOCHAT_DIR="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
FRONTEND_DIR="${VIDEOCHAT_DIR}/frontend-vue"
LOCAL_ENV_FILE="${VIDEOCHAT_DIR}/.env.local"
NPM_SCRIPT="test:e2e:production-browser-smoke"
LOCAL_ENV_LOADED=0

log() {
  printf '[bgf-production-browser-smoke] %s\n' "$*"
}

fail() {
  printf '[bgf-production-browser-smoke] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<'USAGE'
Usage:
  VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com demo/video-chat/scripts/bgf-production-browser-smoke.sh

Set VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN=1 to print the normalized,
redacted command/config without starting browser or network work.
USAGE
}

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || fail "missing required command: $1"
}

shell_quote() {
  printf '%q' "$1"
}

is_enabled() {
  case "${1:-}" in
    1|true|TRUE|yes|YES|on|ON) return 0 ;;
    *) return 1 ;;
  esac
}

set_env_var() {
  local name="$1"
  local value="$2"
  printf -v "${name}" '%s' "${value}"
  export "${name}"
}

trim_value() {
  local value="${1:-}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  printf '%s' "${value}"
}

without_trailing_slash() {
  local value
  value="$(trim_value "${1:-}")"
  while [[ "${value}" == */ ]]; do
    value="${value%/}"
  done
  printf '%s' "${value}"
}

host_from_value() {
  local value
  value="$(without_trailing_slash "${1:-}")"
  value="${value#http://}"
  value="${value#https://}"
  value="${value#ws://}"
  value="${value#wss://}"
  value="${value%%/*}"
  printf '%s' "${value}"
}

origin_from_value() {
  local protocol="$1"
  local value
  value="$(without_trailing_slash "${2:-}")"
  [[ -n "${value}" ]] || return 0

  if [[ "${value}" =~ ^([A-Za-z][A-Za-z0-9+.-]*://)([^/]+) ]]; then
    printf '%s%s' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
    return 0
  fi

  printf '%s://%s' "${protocol}" "$(host_from_value "${value}")"
}

load_local_env() {
  [[ -f "${LOCAL_ENV_FILE}" ]] || return 0

  local preserved_names=(
    VIDEOCHAT_DEPLOY_DOMAIN DEPLOY_DOMAIN VIDEOCHAT_V1_PUBLIC_HOST
    VIDEOCHAT_DEPLOY_APP_DOMAIN DEPLOY_APP_DOMAIN
    VIDEOCHAT_DEPLOY_API_DOMAIN DEPLOY_API_DOMAIN
    VIDEOCHAT_DEPLOY_WS_DOMAIN DEPLOY_WS_DOMAIN
    VIDEOCHAT_DEPLOY_SFU_DOMAIN DEPLOY_SFU_DOMAIN
    VIDEOCHAT_DEPLOY_APP_ORIGIN VIDEOCHAT_DEPLOY_API_ORIGIN
    VIDEOCHAT_DEPLOY_WS_ORIGIN VIDEOCHAT_DEPLOY_SFU_ORIGIN
    PLAYWRIGHT_PRODUCTION_BASE_URL VIDEOCHAT_ONLINE_BASE_URL
    VITE_VIDEOCHAT_BACKEND_ORIGIN VITE_VIDEOCHAT_WS_ORIGIN
    VITE_VIDEOCHAT_SFU_ORIGIN VITE_VIDEOCHAT_ALLOW_INSECURE_WS
    PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH PLAYWRIGHT_PRODUCTION_BROWSER_VIDEO
    PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE VIDEOCHAT_PRODUCTION_BROWSER_SMOKE
    VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN
    VIDEOCHAT_PRODUCTION_ADMIN_EMAIL VIDEOCHAT_PRODUCTION_ADMIN_PASSWORD
    VIDEOCHAT_PRODUCTION_USER_EMAIL VIDEOCHAT_PRODUCTION_USER_PASSWORD
    VIDEOCHAT_E2E_ADMIN_EMAIL VIDEOCHAT_E2E_ADMIN_PASSWORD
    VIDEOCHAT_E2E_USER_EMAIL VIDEOCHAT_E2E_USER_PASSWORD
    VIDEOCHAT_DEPLOY_ADMIN_EMAIL VIDEOCHAT_DEPLOY_ADMIN_PASSWORD
    VIDEOCHAT_DEPLOY_USER_EMAIL VIDEOCHAT_DEPLOY_USER_PASSWORD
    VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE VIDEOCHAT_DEPLOY_USER_PASSWORD_FILE
  )
  local derived_names=(
    VIDEOCHAT_DEPLOY_APP_DOMAIN DEPLOY_APP_DOMAIN
    VIDEOCHAT_DEPLOY_API_DOMAIN DEPLOY_API_DOMAIN
    VIDEOCHAT_DEPLOY_WS_DOMAIN DEPLOY_WS_DOMAIN
    VIDEOCHAT_DEPLOY_SFU_DOMAIN DEPLOY_SFU_DOMAIN
    VIDEOCHAT_DEPLOY_APP_ORIGIN VIDEOCHAT_DEPLOY_API_ORIGIN
    VIDEOCHAT_DEPLOY_WS_ORIGIN VIDEOCHAT_DEPLOY_SFU_ORIGIN
    PLAYWRIGHT_PRODUCTION_BASE_URL VIDEOCHAT_ONLINE_BASE_URL
    VITE_VIDEOCHAT_BACKEND_ORIGIN VITE_VIDEOCHAT_WS_ORIGIN
    VITE_VIDEOCHAT_SFU_ORIGIN
  )
  local name
  declare -A preserved_values=()

  for name in "${preserved_names[@]}"; do
    if [[ -n "${!name+x}" ]]; then
      preserved_values["${name}"]="${!name}"
    fi
  done

  set -a
  # shellcheck source=/dev/null
  source "${LOCAL_ENV_FILE}"
  set +a
  LOCAL_ENV_LOADED=1

  if [[ -n "${preserved_values[VIDEOCHAT_DEPLOY_DOMAIN]+x}" || -n "${preserved_values[DEPLOY_DOMAIN]+x}" || -n "${preserved_values[VIDEOCHAT_V1_PUBLIC_HOST]+x}" ]]; then
    for name in "${derived_names[@]}"; do
      [[ -n "${preserved_values[${name}]+x}" ]] || unset "${name}"
    done
  fi

  for name in "${!preserved_values[@]}"; do
    set_env_var "${name}" "${preserved_values[${name}]}"
  done
}

normalize_domains() {
  local root_domain
  root_domain="$(host_from_value "${VIDEOCHAT_DEPLOY_DOMAIN:-${DEPLOY_DOMAIN:-${VIDEOCHAT_V1_PUBLIC_HOST:-kingrt.com}}}")"
  [[ -n "${root_domain}" ]] || fail "VIDEOCHAT_DEPLOY_DOMAIN resolved to an empty domain"

  set_env_var VIDEOCHAT_DEPLOY_DOMAIN "${root_domain}"
  set_env_var DEPLOY_DOMAIN "${root_domain}"

  local app_domain api_domain ws_domain sfu_domain
  app_domain="$(host_from_value "${VIDEOCHAT_DEPLOY_APP_DOMAIN:-${DEPLOY_APP_DOMAIN:-app.${root_domain}}}")"
  api_domain="$(host_from_value "${VIDEOCHAT_DEPLOY_API_DOMAIN:-${DEPLOY_API_DOMAIN:-api.${root_domain}}}")"
  ws_domain="$(host_from_value "${VIDEOCHAT_DEPLOY_WS_DOMAIN:-${DEPLOY_WS_DOMAIN:-ws.${root_domain}}}")"
  sfu_domain="$(host_from_value "${VIDEOCHAT_DEPLOY_SFU_DOMAIN:-${DEPLOY_SFU_DOMAIN:-sfu.${root_domain}}}")"

  [[ -n "${app_domain}" ]] || fail "VIDEOCHAT_DEPLOY_APP_DOMAIN resolved to an empty domain"
  [[ -n "${api_domain}" ]] || fail "VIDEOCHAT_DEPLOY_API_DOMAIN resolved to an empty domain"
  [[ -n "${ws_domain}" ]] || fail "VIDEOCHAT_DEPLOY_WS_DOMAIN resolved to an empty domain"
  [[ -n "${sfu_domain}" ]] || fail "VIDEOCHAT_DEPLOY_SFU_DOMAIN resolved to an empty domain"

  set_env_var VIDEOCHAT_DEPLOY_APP_DOMAIN "${app_domain}"
  set_env_var VIDEOCHAT_DEPLOY_API_DOMAIN "${api_domain}"
  set_env_var VIDEOCHAT_DEPLOY_WS_DOMAIN "${ws_domain}"
  set_env_var VIDEOCHAT_DEPLOY_SFU_DOMAIN "${sfu_domain}"
  set_env_var DEPLOY_APP_DOMAIN "${app_domain}"
  set_env_var DEPLOY_API_DOMAIN "${api_domain}"
  set_env_var DEPLOY_WS_DOMAIN "${ws_domain}"
  set_env_var DEPLOY_SFU_DOMAIN "${sfu_domain}"
}

set_origin_env() {
  local name="$1"
  local protocol="$2"
  local fallback="$3"
  local raw="${!name:-${fallback}}"
  local value
  value="$(origin_from_value "${protocol}" "${raw}")"
  [[ -n "${value}" ]] || fail "${name} resolved to an empty origin"
  set_env_var "${name}" "${value}"
}

normalize_origins() {
  local app_origin api_origin ws_origin sfu_origin
  app_origin="$(origin_from_value https "${PLAYWRIGHT_PRODUCTION_BASE_URL:-${VIDEOCHAT_ONLINE_BASE_URL:-${VIDEOCHAT_DEPLOY_APP_ORIGIN:-${VIDEOCHAT_DEPLOY_APP_DOMAIN}}}}")"
  api_origin="$(origin_from_value https "${VITE_VIDEOCHAT_BACKEND_ORIGIN:-${VIDEOCHAT_DEPLOY_API_ORIGIN:-${VIDEOCHAT_DEPLOY_API_DOMAIN}}}")"
  ws_origin="$(origin_from_value wss "${VITE_VIDEOCHAT_WS_ORIGIN:-${VIDEOCHAT_DEPLOY_WS_ORIGIN:-${VIDEOCHAT_DEPLOY_WS_DOMAIN}}}")"
  sfu_origin="$(origin_from_value wss "${VITE_VIDEOCHAT_SFU_ORIGIN:-${VIDEOCHAT_DEPLOY_SFU_ORIGIN:-${VIDEOCHAT_DEPLOY_SFU_DOMAIN}}}")"

  set_origin_env PLAYWRIGHT_PRODUCTION_BASE_URL https "${app_origin}"
  set_origin_env VIDEOCHAT_ONLINE_BASE_URL https "${app_origin}"
  set_origin_env VIDEOCHAT_DEPLOY_APP_ORIGIN https "${app_origin}"
  set_origin_env VIDEOCHAT_DEPLOY_API_ORIGIN https "${api_origin}"
  set_origin_env VIDEOCHAT_DEPLOY_WS_ORIGIN wss "${ws_origin}"
  set_origin_env VIDEOCHAT_DEPLOY_SFU_ORIGIN wss "${sfu_origin}"
  set_origin_env VITE_VIDEOCHAT_BACKEND_ORIGIN https "${api_origin}"
  set_origin_env VITE_VIDEOCHAT_WS_ORIGIN wss "${ws_origin}"
  set_origin_env VITE_VIDEOCHAT_SFU_ORIGIN wss "${sfu_origin}"

  if [[ -z "${VITE_VIDEOCHAT_ALLOW_INSECURE_WS:-}" ]]; then
    set_env_var VITE_VIDEOCHAT_ALLOW_INSECURE_WS "0"
  fi
}

role_password_present() {
  local role="$1"
  local production_password="VIDEOCHAT_PRODUCTION_${role}_PASSWORD"
  local e2e_password="VIDEOCHAT_E2E_${role}_PASSWORD"
  local deploy_password="VIDEOCHAT_DEPLOY_${role}_PASSWORD"
  [[ -n "${!production_password:-}" || -n "${!e2e_password:-}" || -n "${!deploy_password:-}" ]]
}

read_secret_file() {
  local file_var="$1"
  local file_path="${!file_var:-}"
  [[ -n "${file_path}" ]] || return 1
  [[ -r "${file_path}" ]] || fail "${file_var} is not readable: ${file_path}"

  local secret
  secret="$(<"${file_path}")"
  secret="${secret%$'\n'}"
  secret="${secret%$'\r'}"
  printf '%s' "${secret}"
}

load_deploy_password_file() {
  local role="$1"
  local deploy_password="VIDEOCHAT_DEPLOY_${role}_PASSWORD"
  local deploy_password_file="VIDEOCHAT_DEPLOY_${role}_PASSWORD_FILE"
  local secret

  role_password_present "${role}" && return 0
  [[ -n "${!deploy_password_file:-}" ]] || return 0

  secret="$(read_secret_file "${deploy_password_file}")"
  set_env_var "${deploy_password}" "${secret}"
}

promote_deploy_email_alias() {
  local role="$1"
  local production_email="VIDEOCHAT_PRODUCTION_${role}_EMAIL"
  local e2e_email="VIDEOCHAT_E2E_${role}_EMAIL"
  local deploy_email="VIDEOCHAT_DEPLOY_${role}_EMAIL"

  if [[ -z "${!production_email:-}" && -z "${!e2e_email:-}" && -n "${!deploy_email:-}" ]]; then
    set_env_var "${production_email}" "${!deploy_email}"
  fi
}

normalize_credentials() {
  local role
  for role in ADMIN USER; do
    load_deploy_password_file "${role}"
    promote_deploy_email_alias "${role}"
  done
}

redacted_value() {
  local name="$1"
  local value="$2"
  case "${name}" in
    *PASSWORD*|*TOKEN*|*SECRET*|*KEY*|*COOKIE*|*SESSION*|*CREDENTIAL*)
      printf '[REDACTED]'
      ;;
    *)
      printf '%s' "${value}"
      ;;
  esac
}

print_env_line() {
  local name="$1"
  local value="${!name-}"
  if [[ -z "${value}" ]]; then
    printf '%s=<unset>\n' "${name}"
    return 0
  fi
  printf '%s=%s\n' "${name}" "$(redacted_value "${name}" "${value}")"
}

print_dry_run() {
  log "dry run: no browser or network work will be started"
  printf 'local_env_file=%s\n' "${LOCAL_ENV_FILE}"
  printf 'local_env_loaded=%s\n' "${LOCAL_ENV_LOADED}"
  printf 'frontend_dir=%s\n' "${FRONTEND_DIR}"
  printf 'command=(cd %s && PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE=1 VIDEOCHAT_PRODUCTION_BROWSER_SMOKE=1 npm run %s)\n' \
    "$(shell_quote "${FRONTEND_DIR}")" \
    "$(shell_quote "${NPM_SCRIPT}")"

  local name
  for name in \
    VIDEOCHAT_DEPLOY_DOMAIN \
    VIDEOCHAT_DEPLOY_APP_DOMAIN \
    VIDEOCHAT_DEPLOY_API_DOMAIN \
    VIDEOCHAT_DEPLOY_WS_DOMAIN \
    VIDEOCHAT_DEPLOY_SFU_DOMAIN \
    PLAYWRIGHT_PRODUCTION_BASE_URL \
    VIDEOCHAT_ONLINE_BASE_URL \
    VITE_VIDEOCHAT_BACKEND_ORIGIN \
    VITE_VIDEOCHAT_WS_ORIGIN \
    VITE_VIDEOCHAT_SFU_ORIGIN \
    VITE_VIDEOCHAT_ALLOW_INSECURE_WS \
    PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE \
    VIDEOCHAT_PRODUCTION_BROWSER_SMOKE \
    VIDEOCHAT_PRODUCTION_ADMIN_EMAIL \
    VIDEOCHAT_PRODUCTION_ADMIN_PASSWORD \
    VIDEOCHAT_PRODUCTION_USER_EMAIL \
    VIDEOCHAT_PRODUCTION_USER_PASSWORD \
    VIDEOCHAT_E2E_ADMIN_EMAIL \
    VIDEOCHAT_E2E_ADMIN_PASSWORD \
    VIDEOCHAT_E2E_USER_EMAIL \
    VIDEOCHAT_E2E_USER_PASSWORD \
    VIDEOCHAT_DEPLOY_ADMIN_EMAIL \
    VIDEOCHAT_DEPLOY_ADMIN_PASSWORD \
    VIDEOCHAT_DEPLOY_ADMIN_PASSWORD_FILE \
    VIDEOCHAT_DEPLOY_USER_EMAIL \
    VIDEOCHAT_DEPLOY_USER_PASSWORD \
    VIDEOCHAT_DEPLOY_USER_PASSWORD_FILE; do
    print_env_line "${name}"
  done
}

main() {
  case "${1:-}" in
    -h|--help)
      usage
      return 0
      ;;
    "")
      ;;
    *)
      usage >&2
      fail "unknown argument: $1"
      ;;
  esac

  [[ -d "${FRONTEND_DIR}" ]] || fail "frontend directory not found: ${FRONTEND_DIR}"

  load_local_env
  normalize_domains
  normalize_origins
  normalize_credentials
  set_env_var PLAYWRIGHT_PRODUCTION_BROWSER_SMOKE "1"
  set_env_var VIDEOCHAT_PRODUCTION_BROWSER_SMOKE "1"

  if is_enabled "${VIDEOCHAT_PRODUCTION_BROWSER_SMOKE_DRY_RUN:-0}"; then
    print_dry_run
    return 0
  fi

  require_cmd npm
  log "running ${NPM_SCRIPT} against ${PLAYWRIGHT_PRODUCTION_BASE_URL}"
  (
    cd "${FRONTEND_DIR}"
    npm run "${NPM_SCRIPT}"
  )
}

main "$@"
