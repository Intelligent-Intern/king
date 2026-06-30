# King Chat Test App

This folder contains a small browser chat that talks to the local King
OpenAI-compatible inference router. It is a local runtime harness for the King
router, not an Ollama client and not a hosted API wrapper.

## Requirements

From the repository root, the chat expects:

- `extension/modules/king.so` built from the current tree.
- Local Docker image `king-local:php8.4-inference`.
- Local GGUF artifact at `var/inference-models/gemma3-1b.gguf`.
- Docker Compose and, for GPU execution, NVIDIA container runtime support.

Build the extension after native code changes:

```bash
make build
```

Build the local runtime image when `docker image inspect
king-local:php8.4-inference` fails. If PHP 8.4 package artifacts are missing,
create them first:

```bash
make release-package
docker build --load \
  -f infra/php-runtime.Dockerfile \
  -t king-local:php8.4-inference \
  --build-arg PHP_VERSION=8.4 \
  .
```

## Model Artifact

King needs a direct local GGUF path. Model files are ignored by git and should
stay in `var/`, a model registry, or an object store.

With an already approved GGUF file:

```bash
mkdir -p var/inference-models
ln -sf /absolute/path/to/gemma3-1b.gguf var/inference-models/gemma3-1b.gguf
```

With a local Ollama install as the download source:

```bash
ollama pull gemma3:1b
ollama show --modelfile gemma3:1b
mkdir -p var/inference-models
ln -sf /path/to/ollama/models/blobs/sha256-... var/inference-models/gemma3-1b.gguf
```

Use the `FROM` digest shown by `ollama show --modelfile gemma3:1b` to pick the
matching blob. Common blob roots are `/usr/share/ollama/.ollama/models/blobs`
and `$HOME/.ollama/models/blobs`. The router reads the linked GGUF directly; it
does not call the Ollama API during inference.

If the symlink points into a blob directory outside the repository, expose that
directory to Compose:

```bash
cd demo/chat
cp .env.example .env
# Edit KING_MODEL_BLOBS when your blob root differs from the default.
```

## Start

```bash
cd demo/chat
docker compose up -d
```

Open:

- Chat UI: http://127.0.0.1:19480
- OpenAI-compatible base URL: http://127.0.0.1:8080/v1
- King model list: http://127.0.0.1:19481/v1/models

## Runtime Shape

- `inference` runs `bin/king-openai-router-start --confirm` from the repository.
- `chat` runs a PHP backend that validates browser messages and proxies them to
  `/v1/chat/completions`.
- The browser receives streamed Server-Sent Events from the PHP backend.
- Threads are stored in `demo/chat/var/chat.sqlite`, which is local runtime
  state and ignored by git.

The router start log prints the effective profile, CPU/GPU model artifact,
context policy, VRAM guardrails, thermal ceiling, and streaming flags. Check
that log first when generation is slow or the wrong profile is selected.

## Stop

```bash
cd demo/chat
docker compose down
```
