# Local Quantized Inference

King inference is available procedurally through `king_inference_*` and as the
native OO surface `King\Inference`, `King\Inference\Model`, and
`King\Inference\Stream`.

This primitive is local and concrete: it registers a materialized GGUF model
artifact, parses the GGUF structure inside King, resolves an inference backend,
and streams backend output as King events. Model artifacts are passed as direct
filesystem paths through `artifact`, `artifact.path`, or `artifact_path`.

The implemented token-streaming backends are `local` and `king_native_cpu`.
`local` uses a King-owned process runner contract while the public backend name
stays independent from the runner implementation. The `king_native_cpu` backend
uses King's native GGUF loader, metadata parser, tokenizer lookup, paged
KV-cache planning, public tensor views, bounded tensor dequantization, first
CPU tensor/vector math, native mini-graph execution, token selection from
logits, and a read-only memory map of the model artifact. Native CPU streaming
expects an explicit `graph` or `graphs` request and decodes the selected token
ids through the artifact tokenizer; it does not call an external inference
runtime.

GPU execution is conservative. CPU-only execution is the default. GPU use must
be explicitly enabled in the model config, the global
`king.gpu_bindings_enable` setting must allow it, and a thermal sensor path is
required unless the operator explicitly accepts unmonitored GPU execution.

## Internal Backend Layout

The public API stays stable while backend internals can be optimized one module
at a time:

```text
extension/src/inference/
├── api.inc
├── helpers.inc
├── backend_contract.inc
├── backend_registry.inc
├── backend_king_local.inc
├── backend_king_native.inc
├── gguf_loader.inc
├── gguf_architecture_metadata.inc
├── gguf_metadata_helpers.inc
├── native_memory.inc
├── tensor_view.inc
├── tensor_math.inc
├── tensor_graph.inc
├── tensor_graph_ops.inc
├── tensor_graph_kv.inc
├── tensor_graph_sampling.inc
├── paged_kv_cache.inc
├── model_config.inc
├── openai_compat.inc
├── openai_completions.inc
├── openai_embeddings.inc
├── openai_http_router.inc
├── openai_messages.inc
├── openai_options.inc
├── openai_responses.inc
├── openai_usage.inc
├── resource_policy.inc
├── thermal_policy.inc
├── tokenizer.inc
└── stream_events.inc
```

The object and metadata contracts live in
`extension/include/inference/inference.h`. PHP arginfo, function-table entries,
and OO method-table entries live under `extension/include/inference/` and are
consumed by the extension bootstrap through `extension/include/php_king/`. The
runtime implementation remains under `extension/src/inference/` and is included
directly by the extension bootstrap.

`backend_contract.inc` owns backend names and capabilities.
`backend_registry.inc` dispatches stream startup to the selected backend.
`backend_king_local.inc` owns the current process runner path, argument
mapping, fork/exec handoff, and prompt normalization. Resource and thermal
policy are intentionally separate so CPU/GPU scheduling, VRAM limits, and
temperature behavior can evolve without changing userland code.
`gguf_loader.inc` validates the model artifact, parses GGUF metadata key/value
entries, records architecture and tokenizer metadata where present, walks the
tensor directory, builds an internal tensor index keyed by tensor name, computes
the aligned tensor-data offset, and exposes tensor type counts before the
normal model object is exposed to userland.
`gguf_metadata_helpers.inc` keeps scalar and tokenizer metadata loading out of
the loader core. `tokenizer.inc` owns native tokenizer normalization and
longest-prefix token lookup so application code can call
`king_inference_tokenize()` or `King\Inference\Model::tokenize()` without
leaving the King extension.
`tensor_view.inc` turns the internal GGUF tensor directory into a read-only
userland contract. It resolves tensor names to shape, quantized block format,
file offsets, byte ranges, bounds status, and native mapping readiness without
exposing process-local native pointers to PHP.
`tensor_math.inc` owns the first native CPU tensor operations on top of those
views. It reads bytes from the read-only model mapping, dequantizes bounded
ranges for supported scalar and block formats, and can multiply rank-1 or
rank-2 tensors by a PHP vector with explicit safety limits.
`gguf_architecture_metadata.inc` captures model-shape metadata such as context
length, layer count, head count, KV head count, embedding length, and
key/value dimensions. `paged_kv_cache.inc` turns that into a deterministic
page plan for the native attention cache.
`native_memory.inc` owns the read-only `mmap()` lifecycle used by native King
backends so tensor bytes can be addressed directly by later graph execution
without handing the model to an external runtime.

`King\Inference\Model::info()` and `king_inference_model_info()` expose backend
metadata, including `backend`, `engine`, `artifact_bytes`, `gguf`,
`runner_path`, `runner_protocol`, `gpu_enabled`, and `backend_capabilities`.
The `gguf` entry contains `architecture`, `tokenizer_model`,
`tokenizer_token_count`, `tensor_data_offset`, `tensor_type_counts`, and parser
status fields when the source artifact provides them. Native backend info
additionally exposes `native_model_mapped`, `native_map_bytes`,
`native_tensor_index_count`, `native_tokenizer_token_count`,
`native_tokenizer_merge_count`, `tokenization_ready`, and
`paged_kv_cache_ready`. The model info payload also contains `paged_kv_cache`.
`backend_capabilities.gpu` and `backend_capabilities.gpu_backend` describe the
selected backend kind; configured GPU use remains visible through
`gpu_enabled`. `backend_capabilities.native_token_selection` refers to King
graph finishers such as `argmax_token` and `sample_token`, not to local runner
text generation.

Generation stream options are validated before the local runner process starts.
`max_tokens` must be a positive integer, `temperature` must be non-negative,
`top_p` must be greater than zero and at most one, and `top_k` must be a
non-negative integer. `stop` can be one non-empty string or one to four
non-empty strings; invalid stop sequences are rejected before runner arguments
are built.

## Function, Tensor Index and Tensor View

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
]);

$index = king_inference_tensor_index($model, [
    'prefix' => 'blk.0.',
    'limit' => 64,
]);

foreach ($index['tensors'] as $name => $tensor) {
    printf(
        "%s %s elements=%d bytes=%d ready=%s\n",
        $name,
        $tensor['type_name'],
        $tensor['elements'],
        $tensor['byte_length'],
        $tensor['native_view_ready'] ? 'yes' : 'no',
    );
}

$query = king_inference_tensor_view($model, 'blk.0.attn_q.weight');
if (!$query['bounds_safe']) {
    throw new RuntimeException('Tensor byte range is outside the mapped model artifact.');
}
```

Tensor views are the stable handoff between the GGUF parser and later native
execution. The view describes where a tensor lives in the memory-mapped model
file and how its bytes are packed. PHP receives the descriptor, not a raw
native address. Native kernels can use the same descriptor path internally when
the quantized block decoders and matrix operations are wired into King.

## Function, Tensor Dequantization and CPU Matmul

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
]);

$sample = king_inference_tensor_dequantize($model, 'blk.0.attn_q.weight', [
    'offset' => 0,
    'count' => 32,
]);

print_r($sample['values']);

$input = array_fill(0, 4096, 0.0);
$input[0] = 1.0;

$projection = king_inference_tensor_matmul($model, 'blk.0.attn_q.weight', $input, [
    'row_limit' => 64,
    'max_operations' => 262144,
]);

printf(
    "rows=%d cols=%d output=%d complete=%s\n",
    $projection['rows'],
    $projection['cols'],
    $projection['output_count'],
    $projection['complete'] ? 'yes' : 'no',
);
```

This is one compute step inside the native graph path. The CPU path currently
supports scalar F32, F16, BF16, I8, I16, I32, I64, F64 and the Q4_0, Q4_1,
Q8_0, Q4_K, Q5_K, and Q6_K block formats. Rank-2 matmul follows GGUF tensor
order: dimension 0 is the input width and dimension 1 is the output row count.
Quantized rows use blockwise dot decoding where supported. The operation
guards input size, output size, and total multiply-add count so a large model
tensor cannot accidentally consume the host without an explicit operator
decision.

## Function, Mini Tensor Graph

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
]);

$result = king_inference_graph_run($model, [
    'state' => [
        'kv_cache' => [
            'default/11/key' => [
                'cache' => 'default',
                'slot' => 11,
                'kind' => 'key',
                'length' => 8,
                'values' => [0.12, -0.04, 0.25, 0.31, -0.19, 0.08, 0.44, -0.11],
            ],
            'default/11/value' => [
                'cache' => 'default',
                'slot' => 11,
                'kind' => 'value',
                'length' => 8,
                'values' => [0.03, 0.14, -0.09, 0.21, 0.18, -0.05, 0.07, 0.12],
            ],
        ],
    ],
    'ops' => [
        [
            'id' => 'x',
            'op' => 'embedding',
            'tensor' => 'token_embd.weight',
            'token_id' => 42,
        ],
        [
            'id' => 'norm',
            'op' => 'rms_norm',
            'input' => 'x',
            'weight' => 'blk.0.attn_norm.weight',
            'epsilon' => 1e-6,
        ],
        [
            'id' => 'query',
            'op' => 'linear',
            'input' => 'norm',
            'weight' => 'blk.0.attn_q.weight',
            'row_limit' => 8,
        ],
        [
            'id' => 'key',
            'op' => 'linear',
            'input' => 'norm',
            'weight' => 'blk.0.attn_k.weight',
            'row_limit' => 8,
        ],
        [
            'id' => 'value',
            'op' => 'linear',
            'input' => 'norm',
            'weight' => 'blk.0.attn_v.weight',
            'row_limit' => 8,
        ],
        [
            'id' => 'query_rope',
            'op' => 'rope',
            'input' => 'query',
            'position' => 12,
            'head_dim' => 8,
            'inv_freqs' => [1.0, 0.1, 0.01, 0.001],
        ],
        [
            'id' => 'key_rope',
            'op' => 'rope',
            'input' => 'key',
            'position' => 12,
            'head_dim' => 8,
            'inv_freqs' => [1.0, 0.1, 0.01, 0.001],
        ],
        [
            'id' => 'cache_write',
            'op' => 'kv_write',
            'slot' => 12,
            'key' => 'key_rope',
            'value' => 'value',
        ],
        [
            'id' => 'attention_context',
            'op' => 'kv_attention',
            'query' => 'query_rope',
            'slot_start' => 11,
            'slot_count' => 2,
            'scale' => 0.353553,
        ],
        [
            'id' => 'logits',
            'op' => 'linear',
            'input' => 'attention_context',
            'weight' => 'output.weight',
            'row_limit' => 32000,
        ],
        [
            'id' => 'next_token',
            'op' => 'sample_token',
            'logits' => 'logits',
            'temperature' => 0.7,
            'top_k' => 40,
            'top_p' => 0.95,
            'seed' => 123456,
            'sample_index' => 12,
        ],
    ],
    'output' => 'next_token',
], [
    'max_vector_values' => 65536,
    'max_operations' => 524288,
]);

print_r($result['final']);
$nextState = $result['state'];
```

The graph runner is a small native execution surface for layer-sized work. It
does not schedule a whole transformer yet. It executes named operations in
order, stores each vector by id, and feeds those vectors into later steps.
`embedding` gathers one row from a rank-2 tensor, `rms_norm` applies native
RMSNorm with an optional weight tensor, and `linear` reuses the blockwise CPU
matmul path. `rope` applies rotary position embedding to an even head slice
using caller-supplied inverse frequencies or a previously produced frequency
vector. `dot`, `stack`, `softmax`, and `weighted_sum` cover the first useful
attention path: scores become probabilities and probabilities produce a context
vector from value vectors. `scale` and `add` cover score scaling and
residual-style vector composition. `kv_read` and `kv_write` make the KV cache a
serializable graph state: callers pass `state` into `graphRun()` and pass the
returned `state` into the next token step. `kv_attention` is the compact path for
token decoding: it reads a strict slot range from `state.kv_cache`, computes
scaled QK softmax, and returns the weighted context vector from the cached value
vectors. `argmax_token` and `sample_token` are the native token-selection
finishers for logits. `sample_token` supports temperature, top-k, top-p, optional
seeded deterministic sampling, `sample_index` as a per-step seed salt, and
`token_offset` for sharded vocab projections. Both token-selection ops return
`[token_id, probability, logit, rank]`. This is still CPU-side vector state, but
it matches the page-table contract that later native paged attention needs.

## Function, Paged KV-Cache Plan

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
    'paged_attention' => [
        'page_tokens' => 16,
        'element_bytes' => 2,
    ],
]);

$plan = king_inference_kv_cache_plan($model, [
    'max_context_tokens' => 8192,
]);

if (!$plan['ready']) {
    throw new RuntimeException('Incomplete model metadata: ' . implode(', ', $plan['missing_fields']));
}

printf(
    "pages=%d pageBytes=%d maxSequenceBytes=%d\n",
    $plan['pages_per_sequence'],
    $plan['page_bytes_all_layers'],
    $plan['max_sequence_bytes'],
);
```

The plan is exposed before native token generation because it is the memory
contract the later graph executor needs. Sequence state can reference fixed
KV pages by block table instead of reallocating one large contiguous cache per
request. Copy-on-write is intentionally reported as not ready until shared
prefix handling exists.

## Function, Native Tokenization

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
]);

$encoded = king_inference_tokenize($model, 'Reject invoice if VAT total is missing.');

printf(
    "tokens=%d unknown=%d normalization=%s\n",
    $encoded['token_count'],
    $encoded['unknown_count'],
    $encoded['normalization'],
);

print_r($encoded['tokens']);
```

The tokenizer API uses the token table embedded in the GGUF artifact. For
SentencePiece-style models King applies the expected space marker
normalization, then performs greedy longest-prefix matching against the loaded
token lookup. If the artifact exposes byte fallback tokens, those are used
before falling back to the model's unknown token id.

## Function, Example 1: Compact Streaming

```php
<?php
$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'quantization' => 'q4',
]);

$stream = king_inference_stream($model, [
    'prompt' => 'Explain why this invoice was rejected.',
    'max_tokens' => 256,
    'temperature' => 0.2,
]);

while (($event = king_inference_next($stream, 1000)) !== null) {
    if ($event['type'] === 'token') {
        echo $event['text'];
    }
    if ($event['type'] === 'done') {
        break;
    }
}
```

## Function, Example 1a: OpenAI-Compatible Chat Streaming

```php
<?php
$model = king_inference_model_load([
    'name' => 'king-local-invoice',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'quantization' => 'q4',
]);

$stream = king_inference_stream($model, [
    'model' => 'king-local-invoice',
    'messages' => [
        ['role' => 'system', 'content' => 'You explain invoice validation decisions.'],
        ['role' => 'user', 'content' => 'Why was invoice HU-2026-0007 rejected?'],
    ],
    'stream' => true,
    'max_tokens' => 256,
    'temperature' => 0.2,
], [
    'format' => 'openai_chat_completions',
]);

while (($chunk = king_inference_next($stream, 1000)) !== null) {
    // $chunk is shaped like a Chat Completions streaming chunk:
    // id, object=chat.completion.chunk, created, model, choices[0].delta.
    print json_encode($chunk, JSON_UNESCAPED_SLASHES) . "\n";

    if (($chunk['choices'][0]['finish_reason'] ?? null) === 'stop') {
        break;
    }
}
```

The compatibility mode is explicit. Set `openai_compatible => true` or
`format => openai_chat_completions` in the request/options and King returns
Chat-Completions-style streaming chunks from `king_inference_next()`. The same
stream object still supports King-native events when the mode is not enabled.
For `king_native_cpu`, the request must provide a native `graph` or `graphs`
sequence whose final output is a token vector produced by `argmax_token` or
`sample_token`. King decodes those token ids through the model tokenizer and
emits the same stream surface without creating a second inference runtime.

## Function, Example 1b: Native Graph Streaming

```php
<?php
$model = king_inference_model_load([
    'name' => 'king-native-invoice',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
]);

$encoded = king_inference_tokenize($model, 'Explain invoice rejection HU-2026-0007.');
$promptTokens = $encoded['tokens'];

$decodeStep = static function (int $position, int $tokenId): array {
    return [
        'ops' => [
            [
                'id' => 'x',
                'op' => 'embedding',
                'tensor' => 'token_embd.weight',
                'token_id' => $tokenId,
            ],
            [
                'id' => 'norm',
                'op' => 'rms_norm',
                'input' => 'x',
                'weight' => 'blk.0.attn_norm.weight',
                'epsilon' => 1e-6,
            ],
            [
                'id' => 'logits',
                'op' => 'linear',
                'input' => 'norm',
                'weight' => 'output.weight',
                'row_limit' => 32000,
            ],
            [
                'id' => 'next_token',
                'op' => 'sample_token',
                'logits' => 'logits',
                'temperature' => 0.4,
                'top_k' => 40,
                'top_p' => 0.95,
                'seed' => 90210,
                'sample_index' => $position,
            ],
        ],
        'output' => 'next_token',
    ];
};

$graphs = [];
foreach (array_slice($promptTokens, -3, 3, true) as $position => $tokenId) {
    $graphs[] = $decodeStep((int) $position, (int) $tokenId);
}

$stream = king_inference_stream($model, [
    'graphs' => $graphs,
], [
    'max_native_stream_tokens' => 64,
    'graph_options' => [
        'max_vector_values' => 65536,
        'max_operations' => 524288,
    ],
]);

while (($event = king_inference_next($stream, 0)) !== null) {
    if (($event['type'] ?? '') === 'token') {
        echo $event['text'];
    }
    if (($event['type'] ?? '') === 'done') {
        break;
    }
}
```

The native stream path is intentionally graph-driven. A request can provide one
`graph` repeated for a bounded token count or a `graphs` sequence for explicit
decode steps. If a graph omits `state`, King carries the previous graph result
state into the next graph, so KV cache entries written by `kv_write` can be read
by later steps through `kv_read` or `kv_attention`. This is the current native
handoff for token events; higher-level prompt-to-graph compilation is a later
layer and does not need a second inference runtime.

Native graph stream startup is bounded by `max_native_stream_tokens`, which can
be set as a stream option or graph option. If it is not set, King allows up to
4096 native token steps before rejecting the stream request.

## Function, Example 1c: OpenAI-Compatible HTTP Route

```php
<?php
$model = king_inference_model_load([
    'name' => 'local-small-model',
    'artifact_path' => getenv('KING_INFERENCE_MODEL_PATH'),
    'backend' => [
        'name' => 'local',
        'runner_path' => getenv('KING_INFERENCE_RUNNER'),
    ],
]);

while (true) {
    king_http1_server_listen_once(
        '127.0.0.1',
        8080,
        null,
        static function (array $request) use ($model): array {
            return king_inference_openai_chat_http_response($model, $request, [
                'read_timeout_ms' => 250,
                'max_events' => 4096,
                'max_idle_events' => 240,
            ]);
        }
    );}
```

The helper accepts the normalized King HTTP request array and owns the
OpenAI-compatible endpoint contract for `POST /v1/chat/completions`. The request
body is decoded as a Chat Completions JSON payload, `messages` are validated,
and the loaded King model is used for both normal and streaming responses.

For `stream=false`, the helper drains into one OpenAI-shaped `chat.completion`
JSON response. For `stream=true`, it returns a bounded `text/event-stream` body
with `data: {chunk}` events and a final `data: [DONE]` marker.

## Function, Example 1d: OpenAI-Compatible Model Router

```php
<?php
$models = [
    'support-small' => king_inference_model_load([
        'name' => 'support-small',
        'artifact_path' => getenv('KING_SUPPORT_MODEL_PATH'),
        'backend' => ['name' => 'local'], 'owned_by' => 'internal-platform',
    ]),
    'invoice-checker' => king_inference_model_load([
        'name' => 'invoice-checker',
        'artifact_path' => getenv('KING_INVOICE_MODEL_PATH'),
        'backend' => ['name' => 'local'], 'owned_by' => 'internal-platform',
    ]),
];

while (true) {
    king_http1_server_listen_once('127.0.0.1', 8080, null, static fn (array $request): array =>
        king_inference_openai_http_response($models, $request, [
            'read_timeout_ms' => 250,
            'max_events' => 4096,
            'max_idle_events' => 240,
        ])
    );
}
```
`king_inference_openai_http_response()` is the higher-level router for
`GET /v1/models`, `GET /v1/models/{model}`, `POST /v1/chat/completions`, and
`POST /v1/responses`, legacy `POST /v1/completions`, and `POST /v1/embeddings`; generation requests
resolve the JSON `model` field against the `$models` key first and then against
the loaded model name. If exactly one model is registered, `model` may be omitted.

The Responses route accepts string or message-list `input`, top-level
`instructions`, and maps into the same King model stream. Non-streaming calls
return a `response` object with `output` and `output_text`; `stream=true`
returns semantic SSE events such as `response.created`,
`response.output_text.delta`, and `response.completed`.
The legacy completions route accepts string prompts; embeddings use the native tokenizer plus the configured token embedding tensor.

## Function, Example 1e: Configured Model Path

```php
<?php
$model = king_inference_model_load([
    'name' => 'local-small-model',
    'artifact' => [
        'path' => getenv('KING_INFERENCE_MODEL_PATH'),
    ],
    'backend' => 'local',
]);

print_r(king_inference_model_info($model));
```

## Function, Example 2: GPU With Thermal Guard

```php
<?php
$model = king_inference_model_load([
    'name' => 'local-support-model',
    'artifact' => [
        'path' => '/models/support-q5.gguf',
    ],
    'quantization' => 'q5',
    'context_tokens' => 8192,
    'backend' => [
        'type' => 'local',
        'runner' => [
            'path' => '/opt/king/bin/king-local-infer',
        ],
    ],
    'gpu' => [
        'enabled' => true,
        'max_gpu_layers' => 24,
        'thermal' => [
            'sensor_path' => '/sys/class/hwmon/hwmon2/temp1_input',
            'max_temperature_c' => 78.0,
        ],
    ],
]);

$stream = king_inference_stream($model, [
    'messages' => [
        ['role' => 'system', 'content' => 'Answer precisely and do not invent facts.'],
        ['role' => 'user', 'content' => 'Summarize this NAV error.'],
    ],
    'max_tokens' => 512,
    'temperature' => 0.1,
    'seed' => 42,
    'top_k' => 40,
    'top_p' => 0.92,
]);

while (($event = king_inference_next($stream, 250)) !== null) {
    if ($event['type'] === 'stderr') {
        error_log($event['text']);
        continue;
    }
    if ($event['type'] === 'token') {
        echo $event['text'];
    }
    if ($event['type'] === 'done' || $event['type'] === 'cancelled') {
        break;
    }
}
```

## Function, Example 3: Parallel Stream Reads with king_awaitable_any

```php
<?php
$supportModel = king_inference_model_load([
    'name' => 'support-routing',
    'artifact' => '/models/support-routing-q4.gguf',
    'quantization' => 'q4',
]);
$invoiceModel = king_inference_model_load([
    'name' => 'invoice-format-check',
    'artifact' => '/models/invoice-format-check-q4.gguf',
    'quantization' => 'q4',
]);

$streams = [
    'support' => king_inference_stream($supportModel, [
        'messages' => [
            ['role' => 'system', 'content' => 'Classify support requests by department.'],
            ['role' => 'user', 'content' => 'Customer cannot download an invoice PDF.'],
        ],
        'max_tokens' => 128,
        'temperature' => 0.1,
    ]),
    'invoice' => king_inference_stream($invoiceModel, [
        'messages' => [
            ['role' => 'system', 'content' => 'Return concise validation observations.'],
            ['role' => 'user', 'content' => 'Check whether this invoice has all required buyer tax fields.'],
        ],
        'max_tokens' => 256,
        'temperature' => 0.0,
    ]),
];

$reads = [];
foreach ($streams as $name => $stream) {
    $reads[$name] = king_inference_next_async($stream, 0);
}

while ($reads !== []) {
    $readyAwaitable = king_awaitable_any($reads);

    if (!king_awaitable_poll($readyAwaitable, 25)) {
        usleep(5_000);
        continue;
    }

    $ready = king_await($readyAwaitable);
    $name = $ready['key'];
    $event = $ready['value'];

    if ($ready['status'] !== 'resolved') {
        error_log($name . ' failed: ' . ($ready['error'] ?? $ready['status']));
        unset($reads[$name]);
        continue;
    }

    if ($event === null || ($event['type'] ?? '') === 'done' || ($event['type'] ?? '') === 'cancelled') {
        unset($reads[$name]);
        continue;
    }

    if (($event['type'] ?? '') === 'token') {
        echo '[' . $name . '] ' . $event['text'];
    }

    $reads[$name] = king_inference_next_async($streams[$name], 0);
}
```

## OO, Example 1: Static Facade

```php
<?php
use King\Inference;

$model = Inference::loadModel([
    'name' => 'assistant',
    'artifact_path' => '/models/assistant-q4.gguf',
    'quantization' => 'q4',
]);
$info = Inference::modelInfo($model);

$stream = Inference::stream($model, [
    'prompt' => 'Write a short customer support answer.',
    'max_tokens' => 128,
]);

while (($event = Inference::next($stream, 500)) !== null) {
    if ($event['type'] === 'token') {
        echo $event['text'];
    }
}
```

The static facade mirrors the procedural surface: `Inference::nextAsync($stream)`
returns a `King\Awaitable`, and `Inference::cancel($stream)` closes the stream.

## OO, Example 2: Explicit Model And Cancellation

```php
<?php
use King\Inference\Model;
use King\Inference\Stream;

$model = new Model([
    'name' => 'procurement-assistant',
    'artifact' => '/models/procurement-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'type' => 'local',
        'runner' => [
            'path' => getenv('KING_INFERENCE_RUNNER') ?: 'king-local-infer',
        ],
    ],
]);

$stream = new Stream($model, [
    'messages' => [
        ['role' => 'user', 'content' => 'Compare these supplier offers.'],
    ],
    'max_tokens' => 512,
]);

while (!$stream->isDone()) {
    $event = $stream->next(1000);
    if ($event === null) {
        continue;
    }
    if (($event['type'] ?? '') === 'token') {
        echo $event['text'];
    }
}

$metrics = $stream->getMetrics();
```

`Stream::getMetrics()` reports emitted token chunks, stderr chunks, bytes,
terminal state, cancellation state, exit code, OpenAI-compatible mode, and for
native graph streams also `native_stream`, `native_event_count`, and
`native_event_index`.

## OO, Example 3: Parallel Inference Streams

```php
<?php
use King\Awaitable;
use King\Inference\Model;
use King\Inference\Stream;

$model = new Model([
    'name' => 'operations-assistant',
    'artifact_path' => '/models/operations-assistant-q4.gguf',
    'quantization' => 'q4',
]);

$streams = [
    'purchase-order' => new Stream($model, [
        'prompt' => 'Summarize open procurement risks for PO-1009.',
        'max_tokens' => 192,
    ]),
    'invoice' => new Stream($model, [
        'prompt' => 'Explain the invoice rounding difference for INV-1009.',
        'max_tokens' => 192,
    ]),
];

$reads = [];
foreach ($streams as $name => $stream) {
    $reads[$name] = $stream->nextAsync(0);
}

while ($reads !== []) {
    $first = Awaitable::any($reads);

    if (!$first->poll(25)) {
        usleep(5_000);
        continue;
    }

    $ready = $first->await();
    $name = $ready['key'];
    $event = $ready['value'];

    if ($ready['status'] !== 'resolved' || $event === null) {
        unset($reads[$name]);
        continue;
    }

    if (($event['type'] ?? '') === 'token') {
        echo '[' . $name . '] ' . $event['text'];
        $reads[$name] = $streams[$name]->nextAsync(0);
        continue;
    }

    if (($event['type'] ?? '') === 'stderr') {
        error_log('inference stderr: ' . $event['text']);
        $reads[$name] = $streams[$name]->nextAsync(0);
        continue;
    }

    unset($reads[$name]);
}
```
