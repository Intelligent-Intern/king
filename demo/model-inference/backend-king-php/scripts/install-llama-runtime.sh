#!/usr/bin/env bash
set -euo pipefail

# Idempotent installer for the pinned llama.cpp runtime + a tiny real GGUF
# fixture. The demo deliberately does not ship an inference engine of its
# own; llama.cpp is the execution engine behind King's native inference
# contract (tracker Z). No mock mode is supported — if the real binary or
# the real GGUF is not available, the M-7 test SKIPs rather than pretending.
#
# Both artifacts are pinned by SHA-256. A download with a mismatched hash
# aborts the install; there is no "retry with a different build" fallback.
#
# Host detection: the arm64 and x86_64 ubuntu bundles published by the
# upstream release pipeline are selected here; other platforms can still
# hand-install the binaries into LLAMA_CPP_HOME and skip the download path.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
FIXTURE_DIR="${BACKEND_DIR}/.local/fixtures"
LLAMA_CPP_HOME="${LLAMA_CPP_HOME:-/opt/llama-cpp/llama-b8802}"

LLAMA_CPP_TAG="b8802"
LLAMA_CPP_UBUNTU_ARM64_URL="https://github.com/ggml-org/llama.cpp/releases/download/${LLAMA_CPP_TAG}/llama-${LLAMA_CPP_TAG}-bin-ubuntu-arm64.tar.gz"
LLAMA_CPP_UBUNTU_ARM64_SHA256="64ab9e2b4c09973662a6f3c04ae46dc4e2a37e44a125618c9800262d255f0eda"
LLAMA_CPP_UBUNTU_X64_URL="https://github.com/ggml-org/llama.cpp/releases/download/${LLAMA_CPP_TAG}/llama-${LLAMA_CPP_TAG}-bin-ubuntu-x64.tar.gz"
LLAMA_CPP_UBUNTU_X64_SHA256="6be3f24748bf7143b7d7fb25a43ddf54609cd5ca6662ea9896cc33fa07e37c3f"

GGUF_FIXTURE_NAME="SmolLM2-135M-Instruct-Q4_K_S.gguf"
GGUF_FIXTURE_URL="https://huggingface.co/bartowski/SmolLM2-135M-Instruct-GGUF/resolve/main/${GGUF_FIXTURE_NAME}"
GGUF_FIXTURE_SHA256="a8654d8eb14c45ef3c6170b3f0c6ccd02ba3e1a6b406ca02f4ffadf0b1b222a0"
GGUF_FIXTURE_BYTES="102039904"

log() { echo "[install-llama-runtime] $*"; }

install_llama_binary() {
    if [[ -x "${LLAMA_CPP_HOME}/llama-server" ]]; then
        log "llama-server already present at ${LLAMA_CPP_HOME}/llama-server"
        return 0
    fi
    local arch
    arch="$(uname -m)"
    local url sha
    case "${arch}" in
        aarch64|arm64)
            url="${LLAMA_CPP_UBUNTU_ARM64_URL}"
            sha="${LLAMA_CPP_UBUNTU_ARM64_SHA256}"
            ;;
        x86_64|amd64)
            url="${LLAMA_CPP_UBUNTU_X64_URL}"
            sha="${LLAMA_CPP_UBUNTU_X64_SHA256}"
            ;;
        *)
            log "unsupported arch '${arch}'; set LLAMA_CPP_HOME to a pre-installed runtime or extend the installer."
            return 1
            ;;
    esac

    local tmpdir
    tmpdir="$(mktemp -d)"
    local archive="${tmpdir}/llama-${LLAMA_CPP_TAG}-${arch}.tar.gz"
    log "downloading ${url}"
    curl -fsSL -o "${archive}" "${url}"
    local observed
    observed="$(sha256sum "${archive}" | awk '{print $1}')"
    if [[ "${observed}" != "${sha}" ]]; then
        log "SHA-256 mismatch: expected ${sha}, observed ${observed}"
        rm -rf "${tmpdir}"
        return 1
    fi
    mkdir -p "$(dirname "${LLAMA_CPP_HOME}")"
    tar -xzf "${archive}" -C "$(dirname "${LLAMA_CPP_HOME}")"
    rm -rf "${tmpdir}"
    if [[ ! -x "${LLAMA_CPP_HOME}/llama-server" ]]; then
        log "extracted archive does not expose ${LLAMA_CPP_HOME}/llama-server"
        return 1
    fi
    log "installed llama.cpp ${LLAMA_CPP_TAG} (${arch}) at ${LLAMA_CPP_HOME}"
}

install_gguf_fixture() {
    mkdir -p "${FIXTURE_DIR}"
    local target="${FIXTURE_DIR}/${GGUF_FIXTURE_NAME}"
    if [[ -f "${target}" ]]; then
        local observed
        observed="$(sha256sum "${target}" | awk '{print $1}')"
        if [[ "${observed}" == "${GGUF_FIXTURE_SHA256}" ]]; then
            log "GGUF fixture already present at ${target}"
            return 0
        fi
        log "GGUF fixture exists but SHA-256 differs; re-downloading (observed ${observed}, expected ${GGUF_FIXTURE_SHA256})"
        rm -f "${target}"
    fi

    log "downloading ${GGUF_FIXTURE_URL}"
    curl -fsSL -o "${target}.tmp" "${GGUF_FIXTURE_URL}"
    local observed
    observed="$(sha256sum "${target}.tmp" | awk '{print $1}')"
    if [[ "${observed}" != "${GGUF_FIXTURE_SHA256}" ]]; then
        log "GGUF SHA-256 mismatch: expected ${GGUF_FIXTURE_SHA256}, observed ${observed}"
        rm -f "${target}.tmp"
        return 1
    fi
    mv "${target}.tmp" "${target}"
    log "installed GGUF fixture at ${target} (${GGUF_FIXTURE_BYTES} bytes)"
}

mode="${1:-all}"
case "${mode}" in
    binary) install_llama_binary ;;
    fixture) install_gguf_fixture ;;
    all) install_llama_binary && install_gguf_fixture ;;
    *) echo "usage: $0 [binary|fixture|all]" >&2; exit 2 ;;
esac
