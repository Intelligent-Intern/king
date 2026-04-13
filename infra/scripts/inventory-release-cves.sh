#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./infra/scripts/inventory-release-cves.sh [options]

Produce a deterministic CVE inventory for container images using pinned Trivy.

Options:
  --images CSV       Comma-separated image tags to scan.
                     Default: intelligentintern/king:v1.0.5-beta
  --platforms CSV    Comma-separated target platforms.
                     Default: linux/amd64,linux/arm64
  --cves CSV         Comma-separated CVE IDs to inventory.
                     Default: CVE-2025-45582,CVE-2024-56433,CVE-2024-2236
  --output PATH      Output JSON path.
                     Default: dist/release-cve-inventory.json
  --scanner-image    Trivy container image to use.
                     Default: aquasec/trivy:0.56.2
  -h, --help         Show this help text.

Example:
  ./infra/scripts/inventory-release-cves.sh \
    --images intelligentintern/king:v1.0.5-beta \
    --output documentation/release-cve-inventory-v1.0.5-beta.json
USAGE
}

csv_to_json_array() {
    local csv="$1"
    local -a values=()
    local -a parts=()

    IFS=',' read -r -a parts <<< "${csv}"
    for value in "${parts[@]}"; do
        value="${value//[[:space:]]/}"
        if [[ -n "${value}" ]]; then
            values+=("${value}")
        fi
    done

    if [[ ${#values[@]} -eq 0 ]]; then
        return 1
    fi

    printf '%s\n' "${values[@]}" | jq -R . | jq -s .
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"

IMAGES_CSV="${IMAGES_CSV:-intelligentintern/king:v1.0.5-beta}"
PLATFORMS_CSV="${PLATFORMS_CSV:-linux/amd64,linux/arm64}"
CVES_CSV="${CVES_CSV:-CVE-2025-45582,CVE-2024-56433,CVE-2024-2236}"
OUTPUT_PATH="${OUTPUT_PATH:-${ROOT_DIR}/dist/release-cve-inventory.json}"
SCANNER_IMAGE="${SCANNER_IMAGE:-aquasec/trivy:0.56.2}"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --images)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --images." >&2
                exit 1
            fi
            IMAGES_CSV="$2"
            shift 2
            ;;
        --platforms)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --platforms." >&2
                exit 1
            fi
            PLATFORMS_CSV="$2"
            shift 2
            ;;
        --cves)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --cves." >&2
                exit 1
            fi
            CVES_CSV="$2"
            shift 2
            ;;
        --output)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --output." >&2
                exit 1
            fi
            OUTPUT_PATH="$2"
            shift 2
            ;;
        --scanner-image)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --scanner-image." >&2
                exit 1
            fi
            SCANNER_IMAGE="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is required to run Trivy inventory scans." >&2
    exit 1
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq is required to normalize deterministic inventory output." >&2
    exit 1
fi

if ! CVES_JSON="$(csv_to_json_array "${CVES_CSV}")"; then
    echo "At least one CVE must be provided." >&2
    exit 1
fi

if ! IMAGES_JSON="$(csv_to_json_array "${IMAGES_CSV}")"; then
    echo "At least one image must be provided." >&2
    exit 1
fi

if ! PLATFORMS_JSON="$(csv_to_json_array "${PLATFORMS_CSV}")"; then
    echo "At least one platform must be provided." >&2
    exit 1
fi

TRIVY_VERSION="$(docker run --rm "${SCANNER_IMAGE}" version --format json | jq -r '.Version // empty')"
if [[ -z "${TRIVY_VERSION}" ]]; then
    echo "Failed to resolve Trivy version from ${SCANNER_IMAGE}." >&2
    exit 1
fi

TRIVY_DOCKER_RUN_ARGS=(--rm)
if [[ -S /var/run/docker.sock ]]; then
    TRIVY_DOCKER_RUN_ARGS+=(-v /var/run/docker.sock:/var/run/docker.sock)
fi

TMP_DIR="$(mktemp -d)"
cleanup() {
    rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

SCAN_MATRIX_JSON="$(jq -n --argjson images "${IMAGES_JSON}" --argjson platforms "${PLATFORMS_JSON}" '
    [
      $images[] as $image
      | $platforms[] as $platform
      | {image: $image, platform: $platform}
    ]
')"

SCAN_COUNT="$(jq 'length' <<< "${SCAN_MATRIX_JSON}")"
if [[ "${SCAN_COUNT}" -eq 0 ]]; then
    echo "Nothing to scan after parsing image/platform options." >&2
    exit 1
fi

for ((i = 0; i < SCAN_COUNT; i++)); do
    IMAGE="$(jq -r ".[$i].image" <<< "${SCAN_MATRIX_JSON}")"
    PLATFORM="$(jq -r ".[$i].platform" <<< "${SCAN_MATRIX_JSON}")"
    RAW_SCAN_PATH="${TMP_DIR}/raw-${i}.json"
    NORMALIZED_SCAN_PATH="${TMP_DIR}/scan-${i}.json"

    echo "Scanning ${IMAGE} (${PLATFORM}) with ${SCANNER_IMAGE}" >&2
    docker run "${TRIVY_DOCKER_RUN_ARGS[@]}" "${SCANNER_IMAGE}" image \
        --quiet \
        --format json \
        --scanners vuln \
        --vuln-type os,library \
        --platform "${PLATFORM}" \
        "${IMAGE}" > "${RAW_SCAN_PATH}"

    jq \
        --arg image "${IMAGE}" \
        --arg platform "${PLATFORM}" \
        --argjson cves "${CVES_JSON}" \
        '
        def normalize_fixed_version:
            if . == null or . == "" then null else . end;

        def affected_entries($scan; $cve):
            [
                $scan.Results[]?.Vulnerabilities[]?
                | select((.VulnerabilityID // "") == $cve)
                | {
                    package: (.PkgName // ""),
                    installed_version: (.InstalledVersion // ""),
                    fixed_target_version: ((.FixedVersion // null) | normalize_fixed_version),
                    severity: (.Severity // "UNKNOWN")
                }
            ]
            | unique
            | sort_by(.package, .installed_version, (.fixed_target_version // ""), .severity);

        . as $scan
        | {
            image: $image,
            platform: $platform,
            cves: [
                $cves[]
                | {
                    id: .,
                    affected: affected_entries($scan; .)
                }
            ]
        }
        ' "${RAW_SCAN_PATH}" > "${NORMALIZED_SCAN_PATH}"
done

mkdir -p "$(dirname "${OUTPUT_PATH}")"

jq -s \
    --arg scanner_image "${SCANNER_IMAGE}" \
    --arg scanner_version "${TRIVY_VERSION}" \
    --argjson cves "${CVES_JSON}" \
    '
    {
        scanner: {
            tool: "trivy",
            container_image: $scanner_image,
            version: $scanner_version
        },
        cve_targets: $cves,
        inventories: (sort_by(.image, .platform))
    }
    ' "${TMP_DIR}"/scan-*.json > "${OUTPUT_PATH}"

echo "Wrote deterministic CVE inventory: ${OUTPUT_PATH}"
