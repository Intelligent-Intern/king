#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_PREFIX="[iam-backend-docker-runtime-proof-wrapper]"
FAILURE_LINES="${IAM_DOCKER_PROOF_FAILURE_LINES:-80}"

if ! [[ "${FAILURE_LINES}" =~ ^[0-9]+$ ]] || [[ "${FAILURE_LINES}" -lt 1 ]]; then
  echo "${LOG_PREFIX} FAIL: IAM_DOCKER_PROOF_FAILURE_LINES must be a positive integer" >&2
  exit 1
fi

mapfile -t DOCKER_PROOFS < <(
  find "${SCRIPT_DIR}" -maxdepth 1 -type f -name '*docker-proof.sh' -print | sort
)

if [[ "${#DOCKER_PROOFS[@]}" -eq 0 ]]; then
  echo "${LOG_PREFIX} FAIL: no docker-proof scripts found under ${SCRIPT_DIR}" >&2
  exit 1
fi

echo "${LOG_PREFIX} discovered ${#DOCKER_PROOFS[@]} docker proof(s)"

for proof in "${DOCKER_PROOFS[@]}"; do
  proof_name="$(basename "${proof}")"
  log_file="$(mktemp "${TMPDIR:-/tmp}/iam-docker-proof-${proof_name}.XXXXXX.log")"
  echo "${LOG_PREFIX} RUN ${proof_name}"

  set +e
  "${proof}" >"${log_file}" 2>&1
  status=$?
  set -e

  if [[ "${status}" -eq 0 ]]; then
    echo "${LOG_PREFIX} PASS ${proof_name}"
    rm -f "${log_file}"
    continue
  fi

  echo "${LOG_PREFIX} FAIL ${proof_name} exited ${status}" >&2
  echo "${LOG_PREFIX} --- ${proof_name} last ${FAILURE_LINES} line(s) ---" >&2
  tail -n "${FAILURE_LINES}" "${log_file}" >&2 || true
  echo "${LOG_PREFIX} --- end ${proof_name} ---" >&2
  rm -f "${log_file}"
  exit "${status}"
done

echo "${LOG_PREFIX} PASS"
