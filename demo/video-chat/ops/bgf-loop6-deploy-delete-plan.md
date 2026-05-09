# BGF Loop 6 Deploy/Delete Plan

Purpose: deploy the current BGF cleanup without leaving the removed background
matrix/capture tests active on the production checkout.

Base assumption: Loop 6 starts from local `bgf-sprint-integration` at or after
`84f024997fe286abce4cadb95c3890063316acb2`.

Do not run `demo/video-chat/scripts/deploy.sh` for this cleanup. Its normal
deploy paths include DNS/certbot flows that are outside this BGF-only deletion
scope.

## Files To Sync

Sync only these existing files from the repo root:

```text
README.md
SPRINT.md
demo/video-chat/frontend-vue/package.json
demo/video-chat/frontend-vue/tests/contract/prod-debug-observability-contract.mjs
demo/video-chat/scripts/deploy-smoke.sh
demo/video-chat/scripts/prod-debug.sh
```

## Remote Files To Delete

Delete exactly these remote checkout paths:

```text
demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-contract.mjs
demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-fixture.json
demo/video-chat/frontend-vue/tests/e2e/background-regression-capture.mjs
```

## Safe Command Sequence

Run from the local integration checkout after setting the production deploy
environment. This sequence uses SSH and rsync only. It does not push, run
certbot, change DNS, or invoke `deploy.sh`.

```bash
set -euo pipefail
cd /home/jochen/projects/king.site/worktrees/bgf-sprint-integration

if [ -f demo/video-chat/.env.local ]; then
  set -a
  # shellcheck source=/dev/null
  source demo/video-chat/.env.local
  set +a
fi

DEPLOY_HOST="${VIDEOCHAT_DEPLOY_HOST:?VIDEOCHAT_DEPLOY_HOST is required}"
DEPLOY_USER="${VIDEOCHAT_DEPLOY_USER:-root}"
DEPLOY_SSH_PORT="${VIDEOCHAT_DEPLOY_SSH_PORT:-22}"
DEPLOY_PATH="${VIDEOCHAT_DEPLOY_PATH:-/opt/king-videochat}"
SSH_DEST="${DEPLOY_USER}@${DEPLOY_HOST}"
SSH_ARGS=(-p "${DEPLOY_SSH_PORT}" -o BatchMode=yes -o StrictHostKeyChecking=accept-new)
if [ -n "${VIDEOCHAT_DEPLOY_SSH_KEY:-}" ]; then
  SSH_ARGS+=(-i "${VIDEOCHAT_DEPLOY_SSH_KEY}")
fi

SYNC_LIST="$(mktemp)"
trap 'rm -f "${SYNC_LIST}"' EXIT
cat >"${SYNC_LIST}" <<'FILES'
README.md
SPRINT.md
demo/video-chat/frontend-vue/package.json
demo/video-chat/frontend-vue/tests/contract/prod-debug-observability-contract.mjs
demo/video-chat/scripts/deploy-smoke.sh
demo/video-chat/scripts/prod-debug.sh
FILES

printf 'Dry-run rsync file list:\n'
rsync -azin --files-from="${SYNC_LIST}" \
  -e "$(printf '%q ' ssh "${SSH_ARGS[@]}")" \
  ./ "${SSH_DEST}:${DEPLOY_PATH}/"

ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "DEPLOY_PATH=$(printf '%q' "${DEPLOY_PATH}") bash -s" <<'REMOTE'
set -euo pipefail
cd "${DEPLOY_PATH}"
for path in \
  demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-contract.mjs \
  demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-fixture.json \
  demo/video-chat/frontend-vue/tests/e2e/background-regression-capture.mjs
do
  if [ -e "${path}" ]; then
    printf 'would delete: %s\n' "${path}"
  else
    printf 'already absent: %s\n' "${path}"
  fi
done
REMOTE

rsync -az --files-from="${SYNC_LIST}" \
  -e "$(printf '%q ' ssh "${SSH_ARGS[@]}")" \
  ./ "${SSH_DEST}:${DEPLOY_PATH}/"

ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "DEPLOY_PATH=$(printf '%q' "${DEPLOY_PATH}") bash -s" <<'REMOTE'
set -euo pipefail
cd "${DEPLOY_PATH}"

delete_exact() {
  local path="$1"
  case "${path}" in
    demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-contract.mjs|\
    demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-fixture.json|\
    demo/video-chat/frontend-vue/tests/e2e/background-regression-capture.mjs)
      ;;
    *)
      printf 'Refusing unexpected delete path: %s\n' "${path}" >&2
      exit 1
      ;;
  esac
  rm -f -- "${path}"
}

delete_exact demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-contract.mjs
delete_exact demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-fixture.json
delete_exact demo/video-chat/frontend-vue/tests/e2e/background-regression-capture.mjs

test ! -e demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-contract.mjs
test ! -e demo/video-chat/frontend-vue/tests/contract/background-regression-matrix-fixture.json
test ! -e demo/video-chat/frontend-vue/tests/e2e/background-regression-capture.mjs
REMOTE
```

If Loop 6 also needs to rebuild/restart containers after the scoped file sync,
use remote compose directly from the video-chat directory; do not run the full
deploy script:

```bash
ssh "${SSH_ARGS[@]}" "${SSH_DEST}" "DEPLOY_PATH=$(printf '%q' "${DEPLOY_PATH}") bash -s" <<'REMOTE'
set -euo pipefail
cd "${DEPLOY_PATH}/demo/video-chat"
docker compose --env-file .env --env-file .env.local \
  -f docker-compose.v1.yml \
  -f docker-compose.deploy.local.yml \
  --profile edge \
  --profile turn \
  up -d --build --remove-orphans
REMOTE
```

## Post-Sync Verification

Use prod-debug for routine read-only diagnostics. Keep deploy smoke for explicit
domain/certificate validation only.

```bash
VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com VIDEOCHAT_PROD_DEBUG_SKIP_REMOTE=1 \
  demo/video-chat/scripts/prod-debug.sh

VIDEOCHAT_DEPLOY_DOMAIN=kingrt.com \
  demo/video-chat/scripts/prod-debug.sh
```
