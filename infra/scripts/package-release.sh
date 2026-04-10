#!/usr/bin/env bash

set -euo pipefail

usage() {
    cat <<'EOF'
Usage: ./infra/scripts/package-release.sh [--output-dir DIR] [--rebuild] [--verify-reproducible]

Packages the staged canonical release profile into a deterministic tarball under:
  dist/

Options:
  --output-dir DIR         Output directory for the final package files
  --rebuild                Rebuild the canonical release profile before packaging
  --verify-reproducible    Build the package twice and require byte-identical output
  -h, --help               Show this help
EOF
}

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
EXT_DIR="${ROOT_DIR}/extension"
PROFILE_DIR="${EXT_DIR}/build/profiles/release"
DEFAULT_OUTPUT_DIR="${ROOT_DIR}/dist"

OUTPUT_DIR="${DEFAULT_OUTPUT_DIR}"
REBUILD=0
VERIFY_REPRODUCIBLE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output-dir)
            if [[ $# -lt 2 ]]; then
                echo "Missing value for --output-dir." >&2
                exit 1
            fi
            OUTPUT_DIR="$2"
            shift 2
            ;;
        --rebuild)
            REBUILD=1
            shift
            ;;
        --verify-reproducible)
            VERIFY_REPRODUCIBLE=1
            shift
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

umask 022
export LC_ALL=C
export TZ=UTC

resolve_version() {
    sed -n 's/^#  define PHP_KING_VERSION[[:space:]]*"\(.*\)"/\1/p' "${EXT_DIR}/include/php_king.h" | head -n 1
}

resolve_git_short() {
    git -C "${ROOT_DIR}" rev-parse --short=12 HEAD 2>/dev/null || printf '%s\n' "nogit"
}

resolve_git_commit() {
    git -C "${ROOT_DIR}" rev-parse HEAD 2>/dev/null || printf '%s\n' "unknown"
}

resolve_source_epoch() {
    git -C "${ROOT_DIR}" show -s --format=%ct HEAD 2>/dev/null || printf '%s\n' "0"
}

resolve_dirty_state() {
    local status_output=""

    if ! git -C "${ROOT_DIR}" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        printf '%s\n' "0"
        return 0
    fi

    status_output="$(
        git -C "${ROOT_DIR}" status \
            --porcelain \
            --untracked-files=no \
            --ignore-submodules=all \
            -- \
            . \
            ':(exclude)extension/config.h'
    )"

    if [[ -n "${status_output}" ]]; then
        printf '%s\n' "1"
        return 0
    fi

    printf '%s\n' "0"
}

VERSION="$(resolve_version)"
if [[ -z "${VERSION}" ]]; then
    echo "Failed to resolve PHP_KING_VERSION from ${EXT_DIR}/include/php_king.h." >&2
    exit 1
fi

PHP_API="$(php-config --phpapi 2>/dev/null || true)"
if [[ -z "${PHP_API}" ]]; then
    echo "Failed to resolve PHP API number via php-config --phpapi." >&2
    exit 1
fi

OS_NAME="$(uname -s | tr '[:upper:]' '[:lower:]')"
ARCH_NAME="$(uname -m)"
GIT_SHORT="$(resolve_git_short)"
GIT_COMMIT="$(resolve_git_commit)"
SOURCE_DATE_EPOCH="$(resolve_source_epoch)"
SOURCE_DIRTY="$(resolve_dirty_state)"
BUILD_REF="git.${GIT_SHORT}"

if [[ "${SOURCE_DIRTY}" == "1" ]]; then
    BUILD_REF="${BUILD_REF}.dirty"
fi

PACKAGE_BASENAME="king-${VERSION}-${BUILD_REF}-${OS_NAME}-${ARCH_NAME}-phpapi-${PHP_API}"

require_release_profile() {
    local missing=0
    local required_files=(
        "${PROFILE_DIR}/king.so"
        "${PROFILE_DIR}/libquiche.so"
        "${PROFILE_DIR}/quiche-server"
    )

    if [[ "${REBUILD}" == "1" ]]; then
        "${SCRIPT_DIR}/build-profile.sh" release
        return 0
    fi

    for path in "${required_files[@]}"; do
        if [[ ! -e "${path}" ]]; then
            missing=1
            break
        fi
    done

    if [[ "${missing}" == "1" ]]; then
        "${SCRIPT_DIR}/build-profile.sh" release
    fi
}

generate_install_doc() {
    local target="$1"

    cat > "${target}" <<EOF
# King Release Package

Package: \`${PACKAGE_BASENAME}\`
Version: \`${VERSION}\`
Git commit: \`${GIT_COMMIT}\`
PHP API: \`${PHP_API}\`
Platform: \`${OS_NAME}/${ARCH_NAME}\`

## Contents

- \`modules/king.so\`
- \`runtime/libquiche.so\`
- \`runtime/quiche-server\`
- \`bin/smoke.sh\`
- \`bin/smoke.php\`
- \`manifest.json\`
- \`SHA256SUMS\`

## Install

1. Copy \`modules/king.so\` into your PHP extension directory or reference it by absolute path.
2. Keep \`runtime/libquiche.so\` and \`runtime/quiche-server\` together with the extension package.
3. Export the runtime paths before loading the extension:

\`\`\`bash
export KING_QUICHE_LIBRARY=/path/to/runtime/libquiche.so
export KING_QUICHE_SERVER=/path/to/runtime/quiche-server
export LD_LIBRARY_PATH=/path/to/runtime:\${LD_LIBRARY_PATH}
\`\`\`

4. Enable the extension in PHP:

\`\`\`ini
extension=/absolute/path/to/modules/king.so
\`\`\`

## Verify

Run the package-local smoke test after extraction:

\`\`\`bash
./bin/smoke.sh
\`\`\`

The shell wrapper delegates to \`bin/smoke.php\`, which runs the same runtime
install smoke used by the staged profile and published container checks.
EOF
}

generate_smoke_script() {
    local target="$1"

    cat > "${target}" <<'EOF'
#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

export KING_QUICHE_LIBRARY="${KING_QUICHE_LIBRARY:-${PACKAGE_DIR}/runtime/libquiche.so}"
export KING_QUICHE_SERVER="${KING_QUICHE_SERVER:-${PACKAGE_DIR}/runtime/quiche-server}"
export LD_LIBRARY_PATH="${PACKAGE_DIR}/runtime${LD_LIBRARY_PATH:+:${LD_LIBRARY_PATH}}"

exec "${PHP_BIN}" \
    -d "extension=${PACKAGE_DIR}/modules/king.so" \
    -d "king.security_allow_config_override=1" \
    "${PACKAGE_DIR}/bin/smoke.php"
EOF

    chmod 0755 "${target}"
}

install_runtime_smoke_script() {
    local target="$1"

    install -m 0644 "${SCRIPT_DIR}/runtime-install-smoke.php" "${target}"
}

generate_manifest() {
    local package_root="$1"

    PKG_ROOT="${package_root}" \
    PKG_NAME="${PACKAGE_BASENAME}" \
    PKG_VERSION="${VERSION}" \
    PKG_GIT_COMMIT="${GIT_COMMIT}" \
    PKG_GIT_SHORT="${GIT_SHORT}" \
    PKG_SOURCE_DIRTY="${SOURCE_DIRTY}" \
    PKG_SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH}" \
    PKG_OS="${OS_NAME}" \
    PKG_ARCH="${ARCH_NAME}" \
    PKG_PHP_API="${PHP_API}" \
    php <<'PHP' > "${package_root}/manifest.json"
<?php
$root = getenv('PKG_ROOT');
$files = [];

foreach ([
    'SHA256SUMS',
    'bin/smoke.php',
    'bin/smoke.sh',
    'docs/INSTALL.md',
    'modules/king.so',
    'runtime/libquiche.so',
    'runtime/quiche-server',
] as $relative) {
    $full = $root . DIRECTORY_SEPARATOR . $relative;
    $files[$relative] = [
        'sha256' => hash_file('sha256', $full),
        'size' => filesize($full),
        'mode' => substr(sprintf('%o', fileperms($full)), -4),
    ];
}

$manifest = [
    'package_format' => 1,
    'name' => 'king',
    'package_name' => getenv('PKG_NAME'),
    'version' => getenv('PKG_VERSION'),
    'git_commit' => getenv('PKG_GIT_COMMIT'),
    'git_short' => getenv('PKG_GIT_SHORT'),
    'source_dirty' => getenv('PKG_SOURCE_DIRTY') === '1',
    'source_date_epoch' => (int) getenv('PKG_SOURCE_DATE_EPOCH'),
    'platform' => [
        'os' => getenv('PKG_OS'),
        'arch' => getenv('PKG_ARCH'),
        'php_api' => getenv('PKG_PHP_API'),
    ],
    'files' => $files,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
PHP
}

write_inner_checksums() {
    local package_root="$1"

    (
        cd "${package_root}"
        sha256sum \
            bin/smoke.php \
            bin/smoke.sh \
            docs/INSTALL.md \
            modules/king.so \
            runtime/libquiche.so \
            runtime/quiche-server \
        > SHA256SUMS
    )
}

package_once() {
    local destination_dir="$1"
    local temp_dir
    local package_root
    local tar_path
    local archive_path
    local archive_name

    temp_dir="$(mktemp -d)"
    package_root="${temp_dir}/${PACKAGE_BASENAME}"
    mkdir -p \
        "${package_root}/bin" \
        "${package_root}/docs" \
        "${package_root}/modules" \
        "${package_root}/runtime"

    install -m 0644 "${PROFILE_DIR}/king.so" "${package_root}/modules/king.so"
    install -m 0644 "${PROFILE_DIR}/libquiche.so" "${package_root}/runtime/libquiche.so"
    install -m 0755 "${PROFILE_DIR}/quiche-server" "${package_root}/runtime/quiche-server"

    generate_smoke_script "${package_root}/bin/smoke.sh"
    install_runtime_smoke_script "${package_root}/bin/smoke.php"
    generate_install_doc "${package_root}/docs/INSTALL.md"
    write_inner_checksums "${package_root}"
    generate_manifest "${package_root}"

    mkdir -p "${destination_dir}"
    archive_name="${PACKAGE_BASENAME}.tar.gz"
    tar_path="${temp_dir}/${PACKAGE_BASENAME}.tar"
    archive_path="${temp_dir}/${archive_name}"

    tar \
        --sort=name \
        --mtime="@${SOURCE_DATE_EPOCH}" \
        --owner=0 \
        --group=0 \
        --numeric-owner \
        -C "${temp_dir}" \
        -cf "${tar_path}" \
        "${PACKAGE_BASENAME}"
    gzip -n -f "${tar_path}"

    (
        cd "${temp_dir}"
        sha256sum "${archive_name}" > "${archive_name}.sha256"
    )

    cp -f "${archive_path}" "${destination_dir}/${archive_name}"
    cp -f "${temp_dir}/${archive_name}.sha256" "${destination_dir}/${archive_name}.sha256"

    rm -rf "${temp_dir}"
    printf '%s\n' "${destination_dir}/${archive_name}"
}

verify_reproducible() {
    local temp_output_a
    local temp_output_b
    local archive_a
    local archive_b

    temp_output_a="$(mktemp -d)"
    temp_output_b="$(mktemp -d)"

    archive_a="$(package_once "${temp_output_a}")"
    archive_b="$(package_once "${temp_output_b}")"

    if ! cmp -s "${archive_a}" "${archive_b}"; then
        echo "Reproducibility verification failed: package bytes differ across two runs." >&2
        rm -rf "${temp_output_a}" "${temp_output_b}"
        exit 1
    fi

    mkdir -p "${OUTPUT_DIR}"
    cp "${archive_a}" "${OUTPUT_DIR}/"
    cp "${archive_a}.sha256" "${OUTPUT_DIR}/"

    rm -rf "${temp_output_a}" "${temp_output_b}"
    printf '%s\n' "${OUTPUT_DIR}/$(basename "${archive_a}")"
}

require_release_profile

if [[ "${VERIFY_REPRODUCIBLE}" == "1" ]]; then
    ARCHIVE_PATH="$(verify_reproducible)"
else
    ARCHIVE_PATH="$(package_once "${OUTPUT_DIR}")"
fi

ARCHIVE_SHA_PATH="${ARCHIVE_PATH}.sha256"

echo "Package created: ${ARCHIVE_PATH}"
echo "Archive SHA256: $(cut -d ' ' -f 1 "${ARCHIVE_SHA_PATH}")"
