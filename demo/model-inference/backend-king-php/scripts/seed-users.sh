#!/usr/bin/env bash
# A-6: manually re-run the demo-user seed (idempotent).
#
# The server also seeds at bootstrap, but this script is the stand-alone
# entry point for CI, smoke tests, and recovery when the fixture has
# been updated after the server started.
#
# Reads credentials from fixtures/demo-users.json with env overrides via
# MODEL_INFERENCE_DEMO_<USERNAME>_USERNAME / _PASSWORD / _DISPLAY_NAME /
# _ROLE. Set MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED=1 to make this a
# no-op (for hardened environments).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
DB_PATH="${MODEL_INFERENCE_KING_DB_PATH:-${BACKEND_DIR}/.local/model-inference.sqlite}"
FIXTURE="${MODEL_INFERENCE_AUTH_FIXTURE_PATH:-${BACKEND_DIR}/fixtures/demo-users.json}"

mkdir -p "$(dirname "${DB_PATH}")"

"${PHP_BIN}" -r "
  require_once '${BACKEND_DIR}/support/database.php';
  require_once '${BACKEND_DIR}/domain/auth/auth_store.php';
  \$pdo = model_inference_open_sqlite_pdo('${DB_PATH}');
  model_inference_auth_schema_migrate(\$pdo);
  \$result = model_inference_auth_seed_demo_users(\$pdo, '${FIXTURE}');
  fwrite(STDOUT, json_encode(\$result, JSON_PRETTY_PRINT) . PHP_EOL);
  exit(0);
"
