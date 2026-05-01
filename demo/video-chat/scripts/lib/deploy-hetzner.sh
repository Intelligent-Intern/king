prompt_value() {
  local name="$1" label="$2" default="${3:-}" required="${4:-1}" current value
  current="${!name:-}"
  [[ -n "${current}" ]] && return 0

  if [[ -n "${default}" ]]; then
    read -r -p "${label} [${default}]: " value || true
    value="${value:-${default}}"
  else
    read -r -p "${label}: " value || true
  fi

  if [[ -z "${value}" && "${required}" == "1" ]]; then
    die "missing required value: ${label}"
  fi

  printf -v "${name}" '%s' "${value}"
  export "${name}"
}

prompt_secret() {
  local name="$1" label="$2" required="${3:-1}" current value
  current="${!name:-}"
  [[ -n "${current}" ]] && return 0

  if [[ -t 0 ]]; then
    read -r -s -p "${label}: " value || true
    printf '\n'
  else
    read -r -s value || true
  fi

  if [[ -z "${value}" && "${required}" == "1" ]]; then
    die "missing required value: ${label}"
  fi

  printf -v "${name}" '%s' "${value}"
  export "${name}"
}

prompt_yes_no() {
  local label="$1" default="${2:-yes}" suffix answer
  if [[ "${default}" == "yes" ]]; then
    suffix="Y/n"
  else
    suffix="y/N"
  fi

  if [[ ! -t 0 ]]; then
    [[ "${default}" == "yes" ]]
    return
  fi

  read -r -p "${label} [${suffix}]: " answer || true
  answer="${answer:-${default}}"
  case "${answer}" in
    y|Y|yes|YES|Yes|1|true|TRUE) return 0 ;;
    n|N|no|NO|No|0|false|FALSE) return 1 ;;
    *) die "expected yes or no for: ${label}" ;;
  esac
}

local_env_upsert() {
  local key="$1" value="$2" file="${LOCAL_ENV_FILE}" quoted tmp
  mkdir -p "$(dirname -- "${file}")"
  touch "${file}"
  chmod 0600 "${file}" || true

  if ! grep -q '^# Videochat deploy wizard state' "${file}"; then
    {
      printf '\n'
      printf '# Videochat deploy wizard state. This file is ignored by git.\n'
    } >>"${file}"
  fi

  printf -v quoted '%q' "${value}"
  tmp="$(mktemp)"
  awk -v key="${key}" -v line="${key}=${quoted}" '
    BEGIN { done = 0 }
    $0 ~ "^" key "=" {
      print line
      done = 1
      next
    }
    { print }
    END {
      if (!done) {
        print line
      }
    }
  ' "${file}" >"${tmp}"
  cat "${tmp}" >"${file}"
  rm -f "${tmp}"
}

persist_wizard_env() {
  local key value
  local keys=(
    VIDEOCHAT_DEPLOY_DOMAIN
    VIDEOCHAT_DEPLOY_EMAIL
    VIDEOCHAT_DEPLOY_HOST
    VIDEOCHAT_DEPLOY_PUBLIC_IP
    VIDEOCHAT_DEPLOY_USER
    VIDEOCHAT_DEPLOY_SSH_KEY
    VIDEOCHAT_DEPLOY_SSH_PORT
    VIDEOCHAT_DEPLOY_PATH
    VIDEOCHAT_DEPLOY_RSYNC_DELETE
    VIDEOCHAT_DEPLOY_COMPOSE_URL
    VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS
    VIDEOCHAT_DEPLOY_KNOWN_HOSTS_FILE
    VIDEOCHAT_DEPLOY_REMOTE_LOCALE
    VIDEOCHAT_DEPLOY_API_DOMAIN
    VIDEOCHAT_DEPLOY_WS_DOMAIN
    VIDEOCHAT_DEPLOY_SFU_DOMAIN
    VIDEOCHAT_DEPLOY_TURN_DOMAIN
    VIDEOCHAT_DEPLOY_CDN_DOMAIN
    VIDEOCHAT_DEPLOY_VUE_ALLOWED_HOSTS
    VIDEOCHAT_DEPLOY_ADMIN_PASSWORD
    VIDEOCHAT_DEPLOY_USER_PASSWORD
    VIDEOCHAT_DEPLOY_TURN_SECRET
    VIDEOCHAT_DEPLOY_HCLOUD_TOKEN
    VIDEOCHAT_DEPLOY_HCLOUD_API_BASE
    VIDEOCHAT_DEPLOY_HCLOUD_LOCATION
    VIDEOCHAT_DEPLOY_HCLOUD_SERVER_TYPE
    VIDEOCHAT_DEPLOY_HCLOUD_IMAGE
    VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME
    VIDEOCHAT_DEPLOY_HCLOUD_SSH_KEY_NAME
    VIDEOCHAT_DEPLOY_HCLOUD_DNS
    VIDEOCHAT_DEPLOY_DNS_WAIT_SECONDS
  )

  for key in "${keys[@]}"; do
    value="${!key:-}"
    [[ -n "${value}" ]] || continue
    local_env_upsert "${key}" "${value}"
  done
}

hcloud_api() {
  local method="$1" path="$2" body="${3-}" tmp code
  tmp="$(mktemp)"
  local curl_args=(
    curl -sS -o "${tmp}" -w '%{http_code}'
    -X "${method}"
    -H "Authorization: Bearer ${HCLOUD_TOKEN}"
    -H "Content-Type: application/json"
    "${HCLOUD_API_BASE}${path}"
  )

  if [[ $# -ge 3 ]]; then
    curl_args+=(-d "${body}")
  fi

  if ! code="$("${curl_args[@]}")"; then
    rm -f "${tmp}"
    die "Hetzner API request failed: ${method} ${path}"
  fi

  case "${code}" in
    2*)
      cat "${tmp}"
      rm -f "${tmp}"
      ;;
    *)
      printf '[videochat-deploy] Hetzner API error %s for %s %s\n' "${code}" "${method}" "${path}" >&2
      cat "${tmp}" >&2 || true
      printf '\n' >&2
      rm -f "${tmp}"
      return 1
      ;;
  esac
}

hcloud_default_server_name() {
  local source="${VIDEOCHAT_DEPLOY_DOMAIN:-king-videochat}"
  printf 'king-videochat-%s' "${source}" \
    | tr '[:upper:]' '[:lower:]' \
    | sed -E 's/[^a-z0-9-]+/-/g; s/^-+//; s/-+$//' \
    | cut -c1-63
}

choose_ssh_key_for_hcloud() {
  local private="" public_file="" public_key=""

  if [[ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]]; then
    private="${VIDEOCHAT_DEPLOY_SSH_KEY}"
  else
    for candidate in "${HOME:-}/.ssh/id_ed25519" "${HOME:-}/.ssh/id_rsa"; do
      if [[ -f "${candidate}" ]]; then
        private="${candidate}"
        break
      fi
    done
  fi

  if [[ -z "${private}" ]]; then
    [[ -n "${HOME:-}" ]] || die "HOME is not set; cannot create SSH key"
    private="${HOME}/.ssh/king_videochat_ed25519"
    if [[ ! -f "${private}" ]]; then
      log "Creating SSH deploy key ${private}"
      install -d -m 0700 "${HOME}/.ssh"
      ssh-keygen -q -t ed25519 -N '' -C "king-videochat-deploy" -f "${private}"
    fi
  fi

  [[ -f "${private}" ]] || die "SSH private key not found: ${private}"
  public_file="${private}.pub"
  if [[ -f "${public_file}" ]]; then
    public_key="$(head -n 1 "${public_file}")"
  else
    public_key="$(ssh-keygen -y -f "${private}" 2>/dev/null || true)"
  fi

  [[ -n "${public_key}" ]] || die "could not read SSH public key for ${private}"
  VIDEOCHAT_DEPLOY_SSH_KEY="${private}"
  SELECTED_SSH_PUBLIC_KEY="${public_key}"
  export VIDEOCHAT_DEPLOY_SSH_KEY
  refresh_deploy_config
  persist_wizard_env
  log "Using SSH key ${private}"
}

hcloud_ensure_ssh_key() {
  local key_name list ssh_key_id body response
  key_name="${VIDEOCHAT_DEPLOY_HCLOUD_SSH_KEY_NAME:-king-videochat-$(hostname 2>/dev/null || echo local)}"
  list="$(hcloud_api GET '/ssh_keys?per_page=100')"
  ssh_key_id="$(jq -r --arg pub "${SELECTED_SSH_PUBLIC_KEY}" '[.ssh_keys[]? | select(.public_key == $pub) | .id][0] // empty' <<<"${list}")"

  if [[ -n "${ssh_key_id}" ]]; then
    log "Reusing Hetzner SSH key id ${ssh_key_id}"
    HCLOUD_SSH_KEY_ID="${ssh_key_id}"
    return 0
  fi

  log "Uploading SSH key to Hetzner"
  body="$(jq -n --arg name "${key_name}" --arg public_key "${SELECTED_SSH_PUBLIC_KEY}" \
    '{name: $name, public_key: $public_key}')"
  response="$(hcloud_api POST '/ssh_keys' "${body}")"
  HCLOUD_SSH_KEY_ID="$(jq -r '.ssh_key.id // empty' <<<"${response}")"
  [[ -n "${HCLOUD_SSH_KEY_ID}" ]] || die "Hetzner did not return an SSH key id"
}

hcloud_wait_for_server() {
  local server_id="$1" response status ip
  for _ in {1..90}; do
    response="$(hcloud_api GET "/servers/${server_id}")"
    status="$(jq -r '.server.status // empty' <<<"${response}")"
    ip="$(jq -r '.server.public_net.ipv4.ip // empty' <<<"${response}")"
    if [[ "${status}" == "running" && -n "${ip}" && "${ip}" != "null" ]]; then
      HCLOUD_SERVER_IPV4="${ip}"
      return 0
    fi
    sleep 5
  done
  die "server ${server_id} did not become ready in time"
}

hcloud_ensure_server() {
  local list server_info server_id status ip body response
  prompt_value VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME "Hetzner server name" "$(hcloud_default_server_name)" 1
  prompt_value VIDEOCHAT_DEPLOY_HCLOUD_SERVER_TYPE "Hetzner server type" "${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_TYPE:-cpx21}" 1
  prompt_value VIDEOCHAT_DEPLOY_HCLOUD_LOCATION "Hetzner location" "${VIDEOCHAT_DEPLOY_HCLOUD_LOCATION:-fsn1}" 1
  prompt_value VIDEOCHAT_DEPLOY_HCLOUD_IMAGE "Hetzner image" "${VIDEOCHAT_DEPLOY_HCLOUD_IMAGE:-ubuntu-24.04}" 1
  persist_wizard_env

  list="$(hcloud_api GET '/servers?per_page=100')"
  server_info="$(jq -r --arg name "${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME}" \
    '[.servers[]? | select(.name == $name)][0] // empty
     | if type == "object" then [.id, .status, (.public_net.ipv4.ip // "")] | @tsv else empty end' <<<"${list}")"

  if [[ -n "${server_info}" ]]; then
    IFS=$'\t' read -r server_id status ip <<<"${server_info}"
    if prompt_yes_no "Server ${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME} exists. Reuse it?" yes; then
      log "Reusing Hetzner server ${server_id}"
      if [[ "${status}" != "running" ]]; then
        hcloud_api POST "/servers/${server_id}/actions/poweron" '{}' >/dev/null || true
      fi
      hcloud_wait_for_server "${server_id}"
      return 0
    fi
    die "choose another VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME and rerun"
  fi

  log "Creating Hetzner server ${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME}"
  body="$(jq -n \
    --arg name "${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_NAME}" \
    --arg server_type "${VIDEOCHAT_DEPLOY_HCLOUD_SERVER_TYPE}" \
    --arg image "${VIDEOCHAT_DEPLOY_HCLOUD_IMAGE}" \
    --arg location "${VIDEOCHAT_DEPLOY_HCLOUD_LOCATION}" \
    --argjson ssh_key_id "${HCLOUD_SSH_KEY_ID}" \
    '{
      name: $name,
      server_type: $server_type,
      image: $image,
      location: $location,
      ssh_keys: [$ssh_key_id],
      public_net: {enable_ipv4: true, enable_ipv6: true},
      labels: {app: "king-videochat", managed_by: "king-deploy"}
    }')"
  response="$(hcloud_api POST '/servers' "${body}")"
  server_id="$(jq -r '.server.id // empty' <<<"${response}")"
  [[ -n "${server_id}" ]] || die "Hetzner did not return a server id"
  hcloud_wait_for_server "${server_id}"
}

resolved_ips_for_domain() {
  local domain="$1" resolved
  if ! command -v getent >/dev/null 2>&1; then
    return 0
  fi
  resolved="$(getent ahostsv4 "${domain}" 2>/dev/null | awk '{ print $1 }' | sort -u || true)"
  if [[ -z "${resolved}" ]]; then
    resolved="$(getent ahosts "${domain}" 2>/dev/null | awk '{ print $1 }' | sort -u || true)"
  fi
  printf '%s\n' "${resolved}"
}

wait_for_dns_to_server() {
  local timeout="${VIDEOCHAT_DEPLOY_DNS_WAIT_SECONDS:-900}" deadline resolved target target_resolved all_ok
  local targets=("${DEPLOY_DOMAIN}" "${DEPLOY_API_DOMAIN}" "${DEPLOY_WS_DOMAIN}" "${DEPLOY_SFU_DOMAIN}" "${DEPLOY_TURN_DOMAIN}" "${DEPLOY_CDN_DOMAIN}")
  if ! command -v getent >/dev/null 2>&1; then
    log "WARN: getent is missing locally; skipping DNS wait"
    return 0
  fi

  deadline=$((SECONDS + timeout))
  while (( SECONDS <= deadline )); do
    all_ok=1
    resolved=""
    for target in "${targets[@]}"; do
      [[ -n "${target}" ]] || continue
      target_resolved="$(resolved_ips_for_domain "${target}" | tr '\n' ' ')"
      resolved+="${target}=${target_resolved:-none} "
      if ! resolved_ips_for_domain "${target}" | grep -Fxq "${DEPLOY_PUBLIC_IP}"; then
        all_ok=0
      fi
    done
    if [[ "${all_ok}" == "1" ]]; then
      log "DNS points deploy hosts to ${DEPLOY_PUBLIC_IP}"
      return 0
    fi
    log "Waiting for DNS: deploy hosts should point to ${DEPLOY_PUBLIC_IP}; current: ${resolved:-none}"
    sleep 15
  done

  die "DNS timeout: point ${targets[*]} A records to ${DEPLOY_PUBLIC_IP} and rerun"
}

hcloud_find_zone_for_domain() {
  local target_domain="${1:-${DEPLOY_DOMAIN}}"
  local zones
  zones="$(hcloud_api GET '/zones?per_page=100')" || return 1
  jq -r --arg domain "${target_domain}" '
    [.zones[]? | .name as $zone | select($domain == $zone or ($domain | endswith("." + $zone)))]
    | sort_by(.name | length)
    | reverse
    | .[0]
    | select(.)
    | [.id, .name, (.mode // "")]
    | @tsv
  ' <<<"${zones}"
}

hcloud_set_dns_a_record_for_domain() {
  local target_domain="$1"
  local zone_line zone_id zone_name zone_mode rr_name rrsets exists action body
  zone_line="$(hcloud_find_zone_for_domain "${target_domain}" || true)"
  if [[ -z "${zone_line}" ]]; then
    log "No matching Hetzner DNS zone found for ${target_domain}; manual A record is required."
    return 1
  fi

  IFS=$'\t' read -r zone_id zone_name zone_mode <<<"${zone_line}"
  if [[ -n "${zone_mode}" && "${zone_mode}" != "primary" ]]; then
    log "Hetzner DNS zone ${zone_name} is ${zone_mode}; manual A record is required."
    return 1
  fi

  if [[ "${target_domain}" == "${zone_name}" ]]; then
    rr_name="@"
  else
    rr_name="${target_domain%.${zone_name}}"
  fi

  rrsets="$(hcloud_api GET "/zones/${zone_id}/rrsets?per_page=500")"
  exists="$(jq -r --arg name "${rr_name}" '
    [.rrsets[]? | select(.name == $name and .type == "A")][0].name // empty
  ' <<<"${rrsets}")"

  action="add_records"
  [[ -n "${exists}" ]] && action="set_records"
  body="$(jq -n --arg value "${DEPLOY_PUBLIC_IP}" \
    '{records: [{value: $value, comment: "King video chat"}]}')"

  hcloud_api POST "/zones/${zone_id}/rrsets/${rr_name}/A/actions/${action}" "${body}" >/dev/null
  log "Hetzner DNS A record set: ${target_domain} -> ${DEPLOY_PUBLIC_IP}"
}

hcloud_set_dns_a_record() {
  hcloud_set_dns_a_record_for_domain "${DEPLOY_DOMAIN}"
}

hcloud_set_videochat_subdomain_records() {
  local target seen="" legacy_cdn_domain=""
  [[ -n "${DEPLOY_DOMAIN:-}" ]] && legacy_cdn_domain="cnd.${DEPLOY_DOMAIN}"
  for target in "${DEPLOY_API_DOMAIN}" "${DEPLOY_WS_DOMAIN}" "${DEPLOY_SFU_DOMAIN}" "${DEPLOY_TURN_DOMAIN}" "${DEPLOY_CDN_DOMAIN}" "${legacy_cdn_domain}"; do
    [[ -n "${target}" ]] || continue
    case " ${seen} " in
      *" ${target} "*) continue ;;
    esac
    seen="${seen} ${target}"
    hcloud_set_dns_a_record_for_domain "${target}" || true
  done
}

run_hcloud_dns_step() {
  local choice="${VIDEOCHAT_DEPLOY_HCLOUD_DNS:-}"
  if [[ -z "${choice}" ]]; then
    if prompt_yes_no "Set Hetzner DNS A record automatically if the zone exists?" yes; then
      choice="1"
    else
      choice="0"
    fi
  fi
  VIDEOCHAT_DEPLOY_HCLOUD_DNS="${choice}"
  export VIDEOCHAT_DEPLOY_HCLOUD_DNS

  persist_wizard_env

  if [[ "${choice}" == "1" || "${choice}" == "yes" || "${choice}" == "true" ]]; then
    hcloud_set_dns_a_record || true
    hcloud_set_videochat_subdomain_records
  else
    log "Manual DNS required: set A ${DEPLOY_DOMAIN}, ${DEPLOY_API_DOMAIN}, ${DEPLOY_WS_DOMAIN}, ${DEPLOY_SFU_DOMAIN}, ${DEPLOY_TURN_DOMAIN}, ${DEPLOY_CDN_DOMAIN} -> ${DEPLOY_PUBLIC_IP}"
  fi

  wait_for_dns_to_server
}

wait_for_ssh() {
  log "Waiting for SSH on ${SSH_DEST}"
  for _ in {1..90}; do
    if ssh "${SSH_ARGS[@]}" -o ConnectTimeout=5 "${SSH_DEST}" true >/dev/null 2>&1; then
      return 0
    fi
    sleep 5
  done
  die "SSH did not become reachable on ${SSH_DEST}"
}

hetzner_wizard() {
  require_cmd curl
  require_cmd jq
  require_cmd rsync
  require_cmd ssh
  require_cmd ssh-keygen

  prompt_value VIDEOCHAT_DEPLOY_DOMAIN "Domain for the video chat" "" 1
  prompt_value VIDEOCHAT_DEPLOY_EMAIL "Email for Let's Encrypt/certbot" "" 1
  prompt_secret VIDEOCHAT_DEPLOY_HCLOUD_TOKEN "Hetzner Cloud API token with read/write access" 1

  if [[ "${VIDEOCHAT_DEPLOY_DOMAIN}" == *"://"* || "${VIDEOCHAT_DEPLOY_DOMAIN}" == */* ]]; then
    die "VIDEOCHAT_DEPLOY_DOMAIN must be a plain host name, not a URL"
  fi

  HCLOUD_TOKEN="${VIDEOCHAT_DEPLOY_HCLOUD_TOKEN}"
  HCLOUD_API_BASE="${VIDEOCHAT_DEPLOY_HCLOUD_API_BASE:-https://api.hetzner.cloud/v1}"
  HCLOUD_API_BASE="${HCLOUD_API_BASE%/}"
  VIDEOCHAT_DEPLOY_HCLOUD_API_BASE="${HCLOUD_API_BASE}"
  export VIDEOCHAT_DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-root}"
  export VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS="${VIDEOCHAT_DEPLOY_REFRESH_KNOWN_HOSTS:-1}"
  export VIDEOCHAT_DEPLOY_HCLOUD_API_BASE
  export VIDEOCHAT_DEPLOY_DNS_WAIT_SECONDS="${VIDEOCHAT_DEPLOY_DNS_WAIT_SECONDS:-900}"
  persist_wizard_env

  choose_ssh_key_for_hcloud
  hcloud_ensure_ssh_key
  hcloud_ensure_server

  VIDEOCHAT_DEPLOY_HOST="${HCLOUD_SERVER_IPV4}"
  VIDEOCHAT_DEPLOY_PUBLIC_IP="${HCLOUD_SERVER_IPV4}"
  export VIDEOCHAT_DEPLOY_HOST VIDEOCHAT_DEPLOY_PUBLIC_IP
  refresh_deploy_config
  persist_wizard_env
  refresh_known_hosts_for_target

  run_hcloud_dns_step
  wait_for_ssh
  prepare
  start_production_https
  persist_wizard_env
  log "Hetzner wizard complete for https://${DEPLOY_DOMAIN} on ${DEPLOY_PUBLIC_IP}"
}
