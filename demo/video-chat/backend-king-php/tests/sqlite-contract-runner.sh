#!/usr/bin/env bash

run_videochat_sqlite_contract() {
  local script_dir="$1"
  local log_name="$2"
  local contract_name="$3"
  local wrapper_name="$4"
  shift 4

  local php_bin="${PHP_BIN:-php}"
  local contract_path="${script_dir}/${contract_name}"

  "${php_bin}" -l "${contract_path}" >/dev/null

  if ! "${php_bin}" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
    if [[ "${IAM_SQLITE_RUNTIME_PROOF_ACTIVE:-0}" == "1" ]]; then
      echo "[${log_name}] FAIL: pdo_sqlite is not available for ${php_bin}" >&2
      exit 1
    fi

    echo "[${log_name}] Host PHP lacks pdo_sqlite; using iam-call-access-sqlite-runtime-proof.sh" >&2
    IAM_SQLITE_CONTRACTS="${wrapper_name}" "${script_dir}/iam-call-access-sqlite-runtime-proof.sh"
    exit $?
  fi

  "${php_bin}" "${contract_path}" "$@"
}
