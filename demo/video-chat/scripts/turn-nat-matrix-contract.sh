#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RESULTS_PATH="${VIDEOCHAT_TURN_NAT_MATRIX_RESULTS:-}"

if [[ "${VIDEOCHAT_TURN_NAT_MATRIX_REQUIRE:-0}" != "1" ]]; then
  echo "[turn-nat-matrix-contract] SKIP: set VIDEOCHAT_TURN_NAT_MATRIX_REQUIRE=1 and VIDEOCHAT_TURN_NAT_MATRIX_RESULTS=/path/to/results.json"
  exit 0
fi

if [[ -z "${RESULTS_PATH}" || ! -f "${RESULTS_PATH}" ]]; then
  echo "[turn-nat-matrix-contract] FAIL: VIDEOCHAT_TURN_NAT_MATRIX_RESULTS must point to a JSON results file" >&2
  exit 1
fi

php -r '
$path = $argv[1];
$raw = file_get_contents($path);
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "[turn-nat-matrix-contract] FAIL: results JSON is invalid\n");
    exit(1);
}

$rows = $data["scenarios"] ?? null;
if (!is_array($rows)) {
    fwrite(STDERR, "[turn-nat-matrix-contract] FAIL: results.scenarios must be an array\n");
    exit(1);
}

$required = [
    "mobile-lte",
    "restrictive-nat",
    "udp-blocked-turn-tcp",
    "corporate-firewall",
];
$byName = [];
foreach ($rows as $row) {
    if (is_array($row) && isset($row["name"])) {
        $byName[(string) $row["name"]] = $row;
    }
}

foreach ($required as $name) {
    if (!isset($byName[$name])) {
        fwrite(STDERR, "[turn-nat-matrix-contract] FAIL: missing scenario {$name}\n");
        exit(1);
    }
    $row = $byName[$name];
    if (($row["status"] ?? "") !== "pass") {
        fwrite(STDERR, "[turn-nat-matrix-contract] FAIL: scenario {$name} did not pass\n");
        exit(1);
    }
    foreach (["browser", "network", "turn_transport", "selected_candidate_pair"] as $field) {
        if (trim((string) ($row[$field] ?? "")) === "") {
            fwrite(STDERR, "[turn-nat-matrix-contract] FAIL: scenario {$name} missing {$field}\n");
            exit(1);
        }
    }
}

echo "[turn-nat-matrix-contract] PASS\n";
' "${RESULTS_PATH}"
