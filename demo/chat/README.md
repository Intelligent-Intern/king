# King Chat Test App

This folder contains a small browser chat that talks to the local King
OpenAI-compatible inference router.

## Start

```bash
cd demo/chat
docker compose up --build
```

Open:

- Chat UI: http://127.0.0.1:19480
- King model list: http://127.0.0.1:19481/v1/models

## Runtime Shape

- `inference` runs `bin/king-openai-router-start --confirm` from the repository.
- `chat` runs a PHP backend that validates browser messages and proxies them to
  `/v1/chat/completions`.
- The browser receives streamed Server-Sent Events from the PHP backend.

The compose file expects the local `king-local:php8.4-inference` image, the
repository build at `extension/modules/king.so`, and the local
`var/inference-models/gemma3-1b.gguf` artifact. If that GGUF is a symlink into a
different Ollama blob directory, copy `.env.example` to `.env` and adjust
`KING_MODEL_BLOBS`.

## Stop

```bash
cd demo/chat
docker compose down
```

