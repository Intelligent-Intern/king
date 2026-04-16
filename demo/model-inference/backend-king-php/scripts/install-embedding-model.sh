#!/usr/bin/env bash
set -euo pipefail

# Idempotent installer for a pinned GGUF embedding model used by the R-batch
# RAG pipeline. Downloads nomic-embed-text-v1.5 (Q8_0) from HuggingFace and
# verifies the SHA-256 checksum. The server autoseed picks up the file from
# the fixtures directory on boot when MODEL_INFERENCE_AUTOSEED=1.
#
# The same llama.cpp binary (b8802) serves both inference and embedding;
# embedding mode is activated by the --embedding flag at worker spawn time.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FIXTURE_DIR="${BACKEND_DIR}/.local/fixtures"

EMBEDDING_GGUF_NAME="nomic-embed-text-v1.5.Q8_0.gguf"
EMBEDDING_GGUF_URL="https://huggingface.co/nomic-ai/nomic-embed-text-v1.5-GGUF/resolve/main/${EMBEDDING_GGUF_NAME}"
EMBEDDING_GGUF_SHA256="3e24342164b3d94991ba9692fdc0dd08571685c5eb2b7b1a141e60feda2e30b1"

EMBEDDING_MODEL_NAME="nomic-embed-text-v1.5"
EMBEDDING_MODEL_FAMILY="nomic-embed"
EMBEDDING_MODEL_QUANTIZATION="Q8_0"
EMBEDDING_MODEL_PARAMS="137000000"
EMBEDDING_MODEL_CONTEXT="2048"
EMBEDDING_MODEL_DIMS="768"
EMBEDDING_MODEL_LICENSE="apache-2.0"
EMBEDDING_MODEL_SOURCE="https://huggingface.co/nomic-ai/nomic-embed-text-v1.5-GGUF"

log() { echo "[install-embedding-model] $*"; }

install_embedding_gguf() {
    mkdir -p "${FIXTURE_DIR}"
    local target="${FIXTURE_DIR}/${EMBEDDING_GGUF_NAME}"
    if [[ -f "${target}" ]]; then
        local observed
        if command -v sha256sum &>/dev/null; then
            observed="$(sha256sum "${target}" | awk '{print $1}')"
        elif command -v shasum &>/dev/null; then
            observed="$(shasum -a 256 "${target}" | awk '{print $1}')"
        else
            log "no sha256sum or shasum available; skipping verification"
            return 0
        fi
        if [[ "${observed}" == "${EMBEDDING_GGUF_SHA256}" ]]; then
            log "embedding GGUF already present at ${target}"
            return 0
        fi
        log "embedding GGUF exists but SHA-256 differs; re-downloading (observed ${observed}, expected ${EMBEDDING_GGUF_SHA256})"
        rm -f "${target}"
    fi

    log "downloading ${EMBEDDING_GGUF_URL}"
    curl -fsSL -o "${target}.tmp" "${EMBEDDING_GGUF_URL}"
    local observed
    if command -v sha256sum &>/dev/null; then
        observed="$(sha256sum "${target}.tmp" | awk '{print $1}')"
    elif command -v shasum &>/dev/null; then
        observed="$(shasum -a 256 "${target}.tmp" | awk '{print $1}')"
    else
        log "warning: no sha256sum or shasum; accepting download without verification"
        mv "${target}.tmp" "${target}"
        log "installed embedding GGUF at ${target} (unverified)"
        return 0
    fi
    if [[ "${observed}" != "${EMBEDDING_GGUF_SHA256}" ]]; then
        log "SHA-256 mismatch: expected ${EMBEDDING_GGUF_SHA256}, observed ${observed}"
        log "hint: update EMBEDDING_GGUF_SHA256 in this script if the upstream model was re-published"
        rm -f "${target}.tmp"
        return 1
    fi
    mv "${target}.tmp" "${target}"
    local bytes
    bytes="$(wc -c < "${target}" | tr -d ' ')"
    log "installed embedding GGUF at ${target} (${bytes} bytes, sha256=${observed})"
}

print_metadata() {
    log "pinned embedding model metadata:"
    log "  model_name:    ${EMBEDDING_MODEL_NAME}"
    log "  family:        ${EMBEDDING_MODEL_FAMILY}"
    log "  quantization:  ${EMBEDDING_MODEL_QUANTIZATION}"
    log "  parameters:    ${EMBEDDING_MODEL_PARAMS}"
    log "  context:       ${EMBEDDING_MODEL_CONTEXT}"
    log "  dimensions:    ${EMBEDDING_MODEL_DIMS}"
    log "  license:       ${EMBEDDING_MODEL_LICENSE}"
    log "  model_type:    embedding"
}

mode="${1:-all}"
case "${mode}" in
    download) install_embedding_gguf ;;
    metadata) print_metadata ;;
    all) install_embedding_gguf && print_metadata ;;
    *) echo "usage: $0 [download|metadata|all]" >&2; exit 2 ;;
esac
