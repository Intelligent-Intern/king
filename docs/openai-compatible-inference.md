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

Router options include bounded drain controls (`read_timeout_ms`,
`max_events`, `max_idle_events`), generation input limits
(`max_chat_messages`, `max_response_input_items`, `max_completion_prompts`),
and embedding limits (`max_embedding_inputs`, `max_embedding_tokens`,
`max_embedding_dimensions`). The default generation input limits are
`max_chat_messages=256`, `max_response_input_items=256`, and
`max_completion_prompts=128`; set them lower for public or tenant-shared routes
where one request must not fan out into excessive prompt construction or backend
generation work. Limit option values must be positive integers.

Model selection is explicit when more than one model is registered. The JSON
`model` field is matched against the registry key first and then against the
loaded model name. Direct helpers that already receive one loaded model may omit
`model`, but any provided `model` field must be a non-empty string.

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

The generic OpenAI HTTP generation routes accept either a text-generation stream
backend or a `king_native_cpu` request that explicitly carries `graph` or
`graphs`. `graph`, `graphs`, and `graph_options` must be JSON objects or arrays
where provided. Native graph requests still return OpenAI-compatible Chat
Completions chunks or JSON responses, but the router does not synthesize a
native graph from arbitrary Chat Completions payloads. When a generation route
selects a native graph backend without an explicit graph request, King returns an
OpenAI-shaped `400` JSON error instead of falling through to a low-level stream
exception.

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
options must be positive integers, numeric generation options must be finite
numbers, `temperature` must be non-negative, `top_p` must be greater than zero
and at most one, and `top_k` must be a non-negative integer. Invalid generation
controls return an OpenAI-shaped `400` response before any backend process is
started.
Chat Completions and legacy Completions also validate OpenAI `stop` as a
string or an array of one to four non-empty strings and pass those stop
sequences to the local generation runner. They accept `n` only when it is the
integer `1`; requests for multiple independent choices return an OpenAI-shaped
`400` instead of silently returning fewer choices than requested.

The local generation routes also reject active requests for features that this
runtime does not yet execute. Chat Completions rejects tool/function calling,
multimodal/audio output, prediction hints, and logprob output; it accepts
`response_format` only when `type` is `text`. Legacy Completions rejects
suffix, echo, best-of, and logprob output. Responses rejects tool calling,
reasoning blocks, include filters, and continuation from a previous response.
Neutral defaults such as `null`, `false`, or empty arrays are treated as absent.
Chat Completions `messages` arrays are supported only up to
`max_chat_messages`. Responses input item lists are supported only up to
`max_response_input_items`.
Legacy Completions prompt arrays are supported only up to
`max_completion_prompts`; streaming legacy Completions still require a single
string prompt.

All OpenAI generation routes validate `stream` as a boolean when it is provided.
They also validate `stream_options` as a JSON object, and
`stream_options.include_usage` must be a boolean. For streamed Chat Completions
and legacy Completions, `include_usage=true` appends a final OpenAI-shaped usage
chunk with empty `choices` immediately before the final `data: [DONE]` marker.
Other chunks carry `usage: null`, and the final usage chunk is computed through
the same tokenizer-backed path as non-streaming generation responses.

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
