# OpenAI-Compatible Inference Router

King's OpenAI-compatible router is a King HTTP response helper over loaded
`King\Inference\Model` objects. It does not proxy to another inference service.
Chat, Responses, legacy Completions, Models, and Embeddings are all routed
through the same model registry.

## Function, Example 1: One Router

```php
<?php

$models = [
    'support-small' => king_inference_model_load([
        'name' => 'support-small',
        'artifact_path' => getenv('KING_SUPPORT_MODEL_PATH'),
        'backend' => [
            'name' => 'local',
            'runner_path' => getenv('KING_INFERENCE_RUNNER'),
        ],
        'owned_by' => 'internal-platform',
    ]),
    'support-embeddings' => king_inference_model_load([
        'name' => 'support-embeddings',
        'artifact_path' => getenv('KING_SUPPORT_MODEL_PATH'),
        'backend' => 'king_native_cpu',
        'embedding_tensor' => 'token_embd.weight',
        'owned_by' => 'internal-platform',
    ]),
];

while (true) {
    king_http1_server_listen_once(
        '127.0.0.1',
        8080,
        null,
        static fn (array $request): array =>
            king_inference_openai_http_response($models, $request, [
                'read_timeout_ms' => 250,
                'max_events' => 4096,
                'max_idle_events' => 240,
                'max_embedding_inputs' => 512,
                'max_embedding_tokens' => 2048,
                'max_embedding_dimensions' => 8192,
            ])
    );
}
```

The router supports:

- `GET /v1/models`
- `GET /v1/models/{model}`
- `POST /v1/chat/completions`
- `POST /v1/responses`
- `POST /v1/completions`
- `POST /v1/embeddings`

Model selection is explicit when more than one model is registered. The JSON
`model` field is matched against the registry key first and then against the
loaded model name.

`GET /v1/models` and `GET /v1/models/{model}` return normal OpenAI model
objects plus an `x_king` extension object. That object contains the resolved
King backend, whether the backend configuration resolved cleanly, whether the
model can serve the generic OpenAI generation routes, whether it supports
native graph streaming, whether it can serve embeddings, whether configured GPU
use is enabled for the model, and the backend capability map from the loaded
model. If a backend configuration cannot be resolved, `x_king.backend` is
`invalid`, `x_king.backend_config_valid` is `false`, and all executable
capability flags are reported as unavailable.

Chat message `content` and Responses input item `content` may be a string or an
array of text content parts. King extracts text from `text`, `content`, or
`refusal` fields and feeds that into the local text-generation prompt. Non-text
parts such as image, audio, or file payloads are rejected for this local text
inference route instead of being silently ignored.

The generic OpenAI HTTP generation routes expect a text-generation stream
backend. `king_native_cpu` can stream graph-selected tokens through
`king_inference_stream()` when the request carries `graph` or `graphs`, and it
serves native embeddings through the router, but the router does not synthesize
a native graph from arbitrary Chat Completions payloads. When a generation
route selects a native graph-only backend, King returns an OpenAI-shaped `400`
JSON error instead of falling through to a low-level stream exception.

## Function, Example 2: Embeddings

```php
<?php

$response = king_inference_openai_http_response($models, [
    'method' => 'POST',
    'uri' => '/v1/embeddings',
    'body' => json_encode([
        'model' => 'support-embeddings',
        'input' => [
            'invoice status: rejected because VAT summary does not match lines',
            'question: why did my electronic invoice fail validation?',
        ],
        'encoding_format' => 'float',
        'dimensions' => 1024,
    ], JSON_UNESCAPED_SLASHES),
], [
    'embedding_tensor' => 'token_embd.weight',
    'max_embedding_inputs' => 512,
    'max_embedding_tokens' => 2048,
]);

if ($response['status'] !== 200) {
    throw new RuntimeException($response['body']);
}

$payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
$firstVector = $payload['data'][0]['embedding'];
```

Embeddings are mean-pooled from the model's native token embedding tensor after
King tokenizes the input. The tensor name can be supplied in the loaded model
config as `embedding_tensor` or `token_embedding_tensor`, or as router option
`embedding_tensor`. If no name is supplied, King checks common GGUF tensor
names such as `token_embd.weight`.

`input` may be a string, a list of strings, one token-id list, or a batch of
token-id lists. `dimensions` can request a bounded prefix of the native
embedding width; the tensor row stride remains the native width internally.
`encoding_format` supports `float` arrays and `base64` strings. The base64 form
contains the same mean-pooled vector encoded as Float32 little-endian bytes.

Generation responses include tokenizer-backed usage when the loaded model can
count both the prompt and the produced text. Chat Completions and legacy
Completions return `prompt_tokens`, `completion_tokens`, and `total_tokens`.
Responses payloads return `input_tokens`, `output_tokens`, and `total_tokens`.
If token counting is unavailable for the model, the router keeps `usage` as
`null` instead of failing an otherwise completed inference request.

Generation controls are normalized before the stream starts. Chat Completions
accept `max_completion_tokens` and legacy `max_tokens`; Responses accepts
`max_output_tokens` and legacy `max_tokens`. King maps those fields to the
internal `max_tokens` stream contract and carries `temperature`, `top_p`,
`seed`, and the King-specific `top_k` option through the same path. Max-token
options must be positive integers, `temperature` must be non-negative, `top_p`
must be greater than zero and at most one, and `top_k` must be a non-negative
integer. Invalid generation controls return an OpenAI-shaped `400` response
before any backend process is started.
Chat Completions and legacy Completions also validate OpenAI `stop` as a
string or an array of one to four non-empty strings and pass those stop
sequences to the local generation runner.

For streamed Chat Completions and legacy Completions,
`stream_options.include_usage=true` appends a final OpenAI-shaped usage chunk
with empty `choices` immediately before `data: [DONE]`. Other chunks carry
`usage: null`, and the final usage chunk is computed through the same
tokenizer-backed path as non-streaming generation responses.

## OO, Example 1: Static Facade

```php
<?php

use King\Inference;

$response = Inference::openaiHttpResponse($models, [
    'method' => 'POST',
    'path' => '/v1/chat/completions',
    'body' => json_encode([
        'model' => 'support-small',
        'messages' => [
            ['role' => 'system', 'content' => 'Answer support questions from verified context.'],
            ['role' => 'user', 'content' => 'Summarize the invoice rejection.'],
        ],
        'stream' => false,
    ], JSON_UNESCAPED_SLASHES),
]);
```

The OO facade uses the same router implementation as the procedural function.
Application code can mount it behind a King HTTP server, apply its own auth and
tenant checks before the call, and keep the response array compatible with
King's server handlers.

## OO, Example 2: Tenant-Aware Handler

```php
<?php

use King\Inference;

$handler = static function (array $request) use ($tenantModels): array {
    $tenantId = $request['headers']['x-tenant-id'] ?? null;
    if (!is_string($tenantId) || !isset($tenantModels[$tenantId])) {
        return [
            'status' => 403,
            'headers' => ['content-type' => 'application/json'],
            'body' => '{"error":{"message":"tenant not allowed","type":"invalid_request_error"}}',
        ];
    }

    return Inference::openaiHttpResponse($tenantModels[$tenantId], $request, [
        'read_timeout_ms' => 250,
        'max_events' => 4096,
        'max_embedding_tokens' => 2048,
    ]);
};
```

The router intentionally does not own tenant authorization. It expects the
application boundary to decide which model registry a request can access. That
keeps OpenAI-compatible transport behavior inside King while preserving the
host application's security model.
