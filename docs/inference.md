# Local Quantized Inference

King inference is available procedurally through `king_inference_*` and as the
native OO surface `King\Inference`, `King\Inference\Model`, and
`King\Inference\Stream`.

This primitive is local and concrete: it registers a materialized GGUF model
artifact, parses the GGUF structure inside King, resolves an inference backend,
and streams backend output as King events. Model artifacts are passed as direct
filesystem paths through `artifact`, `artifact.path`, or `artifact_path`.
Those fields must be non-empty strings; object-store references are rejected
until they are materialized to a local GGUF path.
Optional public metadata fields are also strict: `name`, `quantization`,
`owned_by`, `embedding_tensor`, `token_embedding_tensor`, `output_tensor`,
`output_projection_tensor`, `lm_head_tensor`,
`attention_query_tensor_pattern`, `attention_key_tensor_pattern`,
`attention_value_tensor_pattern`, `attention_output_tensor_pattern`,
`rms_norm_attention_tensor_pattern`, `rms_norm_ffn_tensor_pattern`, and
`rms_norm_final_tensor`, `ffn_gate_tensor_pattern`, `ffn_up_tensor_pattern`,
and `ffn_down_tensor_pattern` must be non-empty strings when provided, and
`context_tokens` must be a positive integer. Invalid model metadata is rejected
during model load instead of being silently ignored by later model listings,
embedding routes, decoder graph construction, or runner argument mapping.
`king_inference_token_decode()` and `King\Inference\Model::tokenDecode()`
decode one native token id through the tokenizer loaded from the same GGUF
artifact. `king_inference_token_decode_graph()` and
`King\Inference\Model::tokenDecodeGraph()` build the complete native CPU
decode graph for one token position from the loaded model metadata and tensor
resolvers.

The implemented token-streaming backends are `local` and `king_native_cpu`.
`local` uses a King-owned process runner contract while the public backend name
stays independent from the runner implementation. The `king_native_cpu` backend
uses King's native GGUF loader, metadata parser, tokenizer lookup, paged
KV-cache planning, public tensor views, bounded tensor dequantization, first
CPU tensor/vector math, complete per-token decode graph construction, token
selection from logits, and a read-only memory map of the model artifact. Native
CPU streaming expects an explicit `graph` or `graphs` request and decodes the
selected token ids through the artifact tokenizer; it does not call an external
inference runtime.

When `backend` is omitted, King selects `king_native_cpu`. The process-runner
backend is still available, but it must be selected intentionally with
`backend => 'local'`, `backend.name => 'local'`, `backend.type => 'local'`, or
a runner-bearing backend config.

The repository ships `bin/king-local-infer` as the default local runner. It
loads the current King extension, materializes a Gemma3 GGUF model through the
native loader, builds token-step graphs with KV state, and streams decoded token
text on stdout for the OpenAI-compatible router.

For the local OpenAI-compatible router, `bin/king-openai-router` loads
`infra/inference/local-gpu.php.ini`. That profile enables GPU bindings, selects
`gemma4:12b` for the GPU profile, configures GPU layers, and uses
`nvidia-smi --query-gpu=temperature.gpu --format=csv,noheader,nounits` as the
temperature source. The router registers `gemma4:12b` only when GPU bindings
and a positive GPU-layer count are active; otherwise only the CPU model is
listed.

Native graph stream memory is opt-in. The compiled default is stateless and the
system baseline can be changed in `php.ini`:

```ini
king.inference_with_memory=0
```

The effective order is built-in default, `php.ini`, model config
`with_memory`, stream request, stream options, and finally `graph_options`.
Use `with_memory => true` only when a later graph should inherit the previous
graph result state. The alias `with-memory` is accepted for external payloads;
do not provide both spellings in the same array.

```php
<?php
$model = king_inference_model_load([
    'name' => 'king-native-stateless',
    'artifact' => '/models/king-small-q4.gguf',
    'backend' => 'king_native_cpu',
    'with_memory' => false,
]);

$stateless = king_inference_stream($model, [
    'graphs' => $decodeSteps,
], [
    'with_memory' => false,
]);

$stateful = king_inference_stream($model, [
    'graphs' => $decodeSteps,
], [
    'with_memory' => true,
]);
```

## GraphAttention Memory Cache

PagedAttention is not the first target here. The practical first step is
GraphAttention-style memory: native graph streams can carry state forward,
and a later evaluation pipeline can label prompts, inference results, and graph
paths as useful. A labelled "works well" path can then be promoted by
application code into a smaller candidate set for the next run instead of
forcing the model to see the full catalogue again.

King now exposes the cache admission policy for that memory mode. The LLM cache
is never active for stateless inference. It is checked only when effective
`with_memory` is `true`.

```ini
king.inference_llm_cache_enable=1
king.inference_llm_cache_path=/tmp/king-llm-cache
king.inference_llm_cache_min_free_mb=5120
king.inference_llm_cache_fail_closed=1
king.inference_llm_cache_disk_alert_webhook=
king.inference_llm_cache_disk_alert_mcp_service=
king.inference_llm_cache_disk_alert_mcp_method=
```

When active, King checks the cache path and the configured free-disk floor
before native graph streaming starts. With `fail_closed=1`, memory-enabled
inference is refused when the disk floor cannot be satisfied. With
`fail_closed=0`, a native King stream continues and emits a `llm_cache_status`
event before token events so the application can notify the configured webhook
or MCP target. OpenAI-compatible streams keep their response format pure; the
same cache policy is still checked, but the status should be queried through
`king_inference_llm_cache_status()` when an out-of-band preflight is needed.

```php
<?php
$config = King\Config::new([
    'inference.with_memory' => true,
    'inference.llm_cache_path' => '/var/cache/king/llm',
    'inference.llm_cache_min_free_mb' => 8192,
    'inference.llm_cache_fail_closed' => true,
    'inference.llm_cache_disk_alert_webhook' => 'https://ops.example/cache-alert',
    'inference.llm_cache_disk_alert_mcp_service' => 'ops.cache',
    'inference.llm_cache_disk_alert_mcp_method' => 'diskFloorWarning',
]);

$status = king_inference_llm_cache_status($config, ['with_memory' => true]);
```

Runtime-loaded models carry the same cache policy in their model config under
`llm_cache`, so `king_inference_runtime_model_load($config)` is enough for the
native stream to enforce the policy. Manual model configs may also provide a
`llm_cache` array with `enabled`, `path`, `min_free_mb`, `fail_closed`,
`disk_alert_webhook`, `disk_alert_mcp_service`, and
`disk_alert_mcp_method`. Request, stream options, and `graph_options` may
override that array for a single run.

GPU execution is conservative. CPU-only execution is the default. GPU use must
be explicitly enabled in the model config, the global
`king.gpu_bindings_enable` setting must allow it, and either a thermal sensor
path or a thermal sensor command is required unless the operator explicitly
accepts unmonitored GPU execution.
The `gpu` config itself is strict: `gpu.enabled` must be a boolean,
`gpu.max_gpu_layers`, `gpu.vram_reserve_mb`, and `gpu.min_free_vram_mb` must be
non-negative integers,
`gpu.thermal` must be an array when provided, `gpu.thermal.sensor_path` and
`gpu.thermal.sensor_command` must be non-empty strings when provided,
`gpu.thermal.max_temperature_c` must be a positive finite number, and
`gpu.thermal.allow_unmonitored_gpu` must be a boolean.

## Runtime Model Profile

Applications that should use "the configured King model" do not have to build
the model config array themselves. King exposes a runtime model primitive with
one explicit profile switch:

```ini
king.inference_preferred_model_profile=auto
king.inference_cpu_model_name=gemma3:1b
king.inference_cpu_model_artifact=/models/gemma3-1b.gguf
king.inference_gpu_model_name=gemma4:12b
king.inference_gpu_model_artifact=/models/gemma4-12b.gguf
king.inference_gpu_max_gpu_layers=99
king.inference_gpu_vram_reserve_mb=2048
king.inference_gpu_min_free_vram_mb=4096
king.inference_gpu_thermal_sensor_path=/sys/class/hwmon/hwmon0/temp1_input
king.inference_gpu_thermal_sensor_command=
king.inference_gpu_thermal_max_temperature_c=78
king.inference_gpu_allow_unmonitored=0
king.inference_llm_cache_enable=1
king.inference_llm_cache_path=/tmp/king-llm-cache
king.inference_llm_cache_min_free_mb=5120
king.inference_llm_cache_fail_closed=1
```

`auto` selects the GPU profile only when process-level GPU bindings are enabled,
the config-level GPU bindings are enabled, and
`inference_gpu_model_artifact` points to a materialized local GGUF file.
Otherwise it selects the CPU profile. `gpu` requires the GPU profile and fails
fast when the GPU artifact, config-level GPU allowance, or process-level GPU
allowance is missing. `cpu` always selects the CPU profile.

The same settings can be scoped to a `King\Config` snapshot:

```php
<?php
$config = King\Config::new([
    'inference.preferred_model_profile' => 'auto',
    'inference.cpu_model_name' => 'gemma3:1b',
    'inference.cpu_model_artifact' => '/models/gemma3-1b.gguf',
    'inference.gpu_model_name' => 'gemma4:12b',
    'inference.gpu_model_artifact' => '/models/gemma4-12b.gguf',
    'inference.gpu_max_gpu_layers' => 48,
    'inference.gpu_vram_reserve_mb' => 2048,
    'inference.gpu_min_free_vram_mb' => 4096,
    'inference.gpu_thermal_sensor_path' => '/sys/class/hwmon/hwmon0/temp1_input',
    'inference.gpu_thermal_sensor_command' => '',
    'inference.gpu_thermal_max_temperature_c' => 78.0,
    'inference.gpu_allow_unmonitored' => false,
    'inference.llm_cache_path' => '/var/cache/king/llm',
    'inference.llm_cache_min_free_mb' => 5120,
]);

$modelConfig = king_inference_runtime_model_config($config);
$model = king_inference_runtime_model_load($config);
```

The procedural and OO surfaces are equivalent:

```php
<?php
$modelConfig = King\Inference::runtimeModelConfig($config);
$model = King\Inference::runtimeModelLoad($config);
```

GPU readiness is inspectable before model load:

```php
<?php
$status = king_inference_gpu_runtime_status($config);

if (!$status['generation_ready']) {
    error_log('King GPU inference is not ready: ' . $status['reason']);
    error_log('All refusal reasons: ' . implode(', ', $status['refusal_reasons']));
}

$sameStatus = King\Inference::gpuRuntimeStatus($config);
```

The status probe is native. King opens the CUDA driver library at runtime when
available, checks driver initialization and device visibility, and reports the
first CUDA device name, total memory, and current free memory where the driver
exposes it. King also compares the configured model artifact size with the
reported free VRAM and reports `model_vram_admitted=false` when the artifact
alone cannot fit. Once a model is loaded, the same `gpu_runtime` payload also
includes the paged KV-cache estimate from context length, layer count, KV heads,
key/value dimensions, and element size. Loaded-model readiness then compares
`artifact_bytes + kv_cache_estimated_bytes` against
`free_vram_after_reserve_bytes`, which is the raw `free_vram_bytes` minus the
configured `inference_gpu_vram_reserve_mb`. This keeps desktop VRAM available
for the compositor and other operator work while still exposing both raw and
reserved admission values through `gpu_runtime`. King also enforces
`inference_gpu_min_free_vram_mb` as a hard floor: when the driver reports less
free VRAM than that configured minimum, GPU readiness fails closed with
`gpu_free_vram_below_configured_floor` before model execution is considered.
Thermal guardrails still come from the configured sensor path or command,
because operators may prefer platform-specific sensor files over driver-level
telemetry.

`reason` is the primary refusal reason, ordered by the first gate King would
need an operator to fix. `refusal_reasons` contains the complete ordered list of
currently active refusal reasons, so a broken setup can show, for example, a
missing artifact, unavailable VRAM telemetry, and a missing thermal monitor in
one response instead of hiding later blockers behind the first one.

GPU stream startup performs a fresh thermal preflight immediately before the
backend run is admitted. For the local process backend this check happens after
the command line is assembled and directly before `fork/exec`; for native
streams it happens directly before native graph events are prepared. Stream
start events and `King\Inference\Stream::getMetrics()` expose
`gpu_thermal_preflight_checked`, `gpu_thermal_preflight_at`, and
`gpu_thermal_preflight_temperature_c` so operators can distinguish stale model
metadata from the last run-time admission check. During an active GPU stream,
King checks the same thermal ceiling before every event read; when the ceiling
is reached, the stream is marked cancelled, the runner process is terminated,
and metrics expose `gpu_thermal_aborted`, `gpu_thermal_abort_at`,
`gpu_thermal_abort_temperature_c`, and `gpu_thermal_abort_ceiling_c`.

The GPU profile resolves to `king_native_gpu`. That is intentional: a 12B model
configured for GPU execution must not silently fall back to CPU. Current status
fields separate the layers:

- `config_ready` means King can see the GPU-facing configuration, artifact,
  driver signal, and thermal policy.
- `decoder_kernel_ready` means the native GPU decoder kernel is implemented.
- `generation_ready` means both are true and token generation may run.

In the current implementation, `decoder_kernel_ready` and `generation_ready`
remain `false` for the native GPU backend. The local OpenAI-compatible router
therefore exposes the configured large model with explicit
`x_king.gpu_runtime` metadata, but refuses GPU generation instead of burning the
CPU accidentally.

`king_native_gpu` model registration is still allowed. Registration means King
can load the materialized GGUF artifact, parse metadata, build tokenizer/tensor
indexes, map the file for native access, and expose the model through
`/v1/models`. It does not mean GPU token generation is available. The backend
capabilities keep `model_registration=true`, while `implemented=false`,
`streaming=false`, and `token_generation=false` until the GPU decoder kernel is
present.
The same capabilities explicitly describe the ready GPU support surfaces:
`gpu_runtime_status`, `gpu_cuda_driver_probe`, `gpu_vram_admission`,
`gpu_kv_cache_vram_estimate`, `gpu_thermal_policy`,
`gpu_thermal_preflight`, and `gpu_thermal_stream_abort` are true for the GPU
backend. `gpu_decoder_kernel`, `gpu_generation`, `token_generation`, and
`silent_cpu_fallback` remain false.

## Internal Backend Layout

The public API stays stable while backend internals can be optimized one module
at a time:

```text
extension/src/inference/
├── api.inc
├── backend_contract.inc
├── backend_king_local.inc
├── backend_king_native.inc
├── backend_registry.inc
├── class_entries.inc
├── gguf_architecture_metadata.inc
├── gguf_loader.inc
├── gguf_metadata_helpers.inc
├── gpu_runtime_reason.inc
├── gpu_runtime_status.inc
├── gpu_vram_policy.inc
├── helpers.inc
├── model_config.inc
├── native_memory.inc
├── object_handlers.inc
├── openai_backend.inc
├── openai_compat.inc
├── openai_completions.inc
├── openai_embeddings.inc
├── openai_http_body.inc
├── openai_http_helpers.inc
├── openai_http_request.inc
├── openai_http_router.inc
├── openai_messages.inc
├── openai_models.inc
├── openai_options.inc
├── openai_responses.inc
├── openai_usage.inc
├── paged_kv_cache.inc
├── php_binding.inc
├── registration.inc
├── resource_policy.inc
├── state.inc
├── stream_events.inc
├── tensor_attention_resolver.inc
├── tensor_ffn_resolver.inc
├── token_decode_graph_builder.inc
├── tensor_graph.inc
├── tensor_graph_kv.inc
├── tensor_resolver.inc
├── tensor_rms_norm_resolver.inc
├── tensor_graph_ops.inc
├── tensor_graph_sampling.inc
├── tensor_math.inc
├── tensor_view.inc
├── thermal_policy.inc
└── tokenizer.inc
```

The object and metadata contracts live in
`extension/include/inference/inference.h`. PHP arginfo, function-table entries,
and OO method-table entries live under `extension/include/inference/` and are
consumed by the extension bootstrap through `extension/include/php_king/`. The
runtime implementation remains under `extension/src/inference/` and is included
directly by the extension bootstrap.

`backend_contract.inc` owns backend names and capabilities.
`backend_registry.inc` dispatches stream startup to the selected backend.
`backend_king_local.inc` owns the current process runner path, executable
preflight, argument mapping, fork/exec handoff, and prompt normalization. If a
configured local runner path is not executable, or a PATH-based runner name
cannot be resolved before `fork()`, King raises a runtime error before stream
startup instead of returning an empty stream with a child-process exit code.
Resource and thermal policy are intentionally separate so CPU/GPU scheduling,
VRAM limits, and temperature behavior can evolve without changing userland code.
`openai_http_router.inc` owns route selection only. Request extraction,
response helpers, body decoding, model registry handling, route generation
helpers, and endpoint-specific payloads live in the focused `openai_*`
fragments so the OpenAI-compatible router remains inspectable as it grows.
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
`tensor_resolver.inc` centralizes model tensor name resolution. The token
embedding resolver honors explicit `tensor`, `embedding_tensor`, and
`token_embedding_tensor` configuration first, then checks known GGUF names, and
finally performs a guarded shape/name-hint scan when architecture embedding
length and tokenizer row count are available. The output projection resolver
uses the same explicit-first order for `tensor`, `output_tensor`,
`output_projection_tensor`, and `lm_head_tensor`, then checks known GGUF
output/lm-head names and performs a guarded shape/name-hint scan. If the model
does not carry a separate output projection tensor but the token embedding
tensor resolves, King reports the output projection as `tied_token_embedding`
instead of guessing a second tensor. `tensor_attention_resolver.inc` resolves
per-layer attention query, key, value, and output projection tensors. It checks
configured `{layer}` patterns first, then known GGUF layer-name patterns, and
finally a guarded layer/name/shape scan. Shape validation uses the loaded
embedding length, attention head count, KV head count, and key/value head
dimensions, so grouped-query attention tensors are not mistaken for full-width
query projections. `tensor_rms_norm_resolver.inc` resolves per-layer
attention/feed-forward RMSNorm tensors and the final output RMSNorm tensor. It
checks configured `{layer}` patterns first for layer norms, direct configured
final tensor names first for the final norm, then known GGUF/HF-style names,
and finally guarded name/shape scans. Shape validation requires rank-1 tensors
whose width matches the loaded embedding length. `tensor_ffn_resolver.inc`
resolves per-layer FFN gate, up, and down projection tensors. It checks
configured `{layer}` patterns first, then known GGUF/HF-style names, and
finally guarded layer/name/shape scans. Shape validation requires `gate` and
`up` matrices to project from embedding width to feed-forward width, and
`down` matrices to project from feed-forward width back to embedding width.
`token_decode_graph_builder.inc` uses those resolvers to create the full
single-position CPU decode graph: token embedding, per-layer attention norm,
Q/K/V projections, RoPE, KV write/read attention, attention residual, FFN
SwiGLU, final norm, output projection, and token sampling/argmax.
`gguf_architecture_metadata.inc` captures model-shape metadata such as context
length, layer count, head count, KV head count, embedding length, and
key/value dimensions. It also classifies the loaded GGUF architecture against
King's native decoder target set. `gemma3` and `gemma4` are exposed as
supported decoder profiles; unsupported or missing architecture metadata remains
inspectable, but is not reported as decoder-ready. `paged_kv_cache.inc` turns
the shape metadata into a deterministic page plan for the native attention
cache.
`native_memory.inc` owns the read-only `mmap()` lifecycle used by native King
backends so tensor bytes can be addressed directly by later graph execution
without handing the model to an external runtime.

`King\Inference\Model::info()` and `king_inference_model_info()` expose backend
metadata, including `backend`, `engine`, `artifact_bytes`, `gguf`,
`runner_path`, `runner_protocol`, `runner_executable`, `gpu_enabled`, and
`backend_capabilities`. For `king_native_gpu`, model info also exposes
`decoder_kernel_ready=false` and `generation_ready=false` directly, so clients
do not need to infer decoder or generation state from model registration or
backend name.
The `gguf` entry contains `architecture`, `architecture_supported`,
`architecture_family`, `architecture_generation`, `decoder_profile`,
`decoder_shape_ready`, `decoder_ready`, `architecture_support_status`,
`architecture_missing_fields`, `supported_architectures`, `tokenizer_model`,
`tokenizer_token_count`, `tensor_data_offset`, `tensor_type_counts`, and parser
status fields when the source artifact provides them. Native backend info
additionally exposes `native_model_mapped`, `native_map_bytes`,
`native_tensor_index_count`, `native_tokenizer_token_count`,
`native_tokenizer_merge_count`, `tokenization_ready`, and
`paged_kv_cache_ready`. The model info payload also contains `paged_kv_cache`
and `resolved_tensors.token_embedding` plus
`resolved_tensors.output_projection` plus `resolved_tensors.attention` plus
`resolved_tensors.rms_norm` plus `resolved_tensors.ffn`, so callers can inspect
the selected embedding, logits projection, per-layer attention tensors, RMSNorm
weights, and FFN projection tensors before building decoder graphs. The output
projection entry includes
`tied_token_embedding=true` when the model uses the token embedding matrix for
logits projection. The attention entry exposes one layer record per GGUF block
with `query`, `key`, `value`, and `output` entries, including the resolved
tensor name, source, status, and expected matrix dimensions. The RMSNorm entry
exposes one layer record per GGUF block with `attention` and `feed_forward`
entries plus a `final` entry for the output norm. The FFN entry exposes one
layer record per GGUF block with `gate`, `up`, and `down` entries, including
the resolved tensor name, source, status, and expected matrix dimensions.
`backend_capabilities.gpu` and `backend_capabilities.gpu_backend` describe the
selected backend kind; configured GPU use remains visible through
`gpu_enabled`. `backend_capabilities.native_token_selection` refers to King
graph finishers such as `argmax_token` and `sample_token`, not to local runner
text generation. GPU-specific capability flags separate registration,
metadata, CUDA probing, VRAM admission, thermal enforcement, and decoder
generation so clients do not infer generation readiness from model presence.

Generation stream options are validated before the local runner process starts.
`max_tokens` must be a positive integer, numeric generation options must be
finite numbers, `temperature` must be non-negative, `top_p` must be greater than
zero and at most one, and `top_k` must be a non-negative integer. `stop` can be
one non-empty string or one to four non-empty strings; invalid stop sequences are
rejected before runner arguments are built.

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
does not decide a model topology by itself; the local runner builds the
Gemma3 token-step graph on top of these operations. The graph runner executes
named operations in order, stores each vector by id, and feeds those vectors
into later steps.
`embedding` gathers one row from a rank-2 tensor. Its `tensor` field may be
omitted when the shared token embedding resolver can identify exactly one
supported embedding matrix from model config, known GGUF names, or guarded
shape scan. `rms_norm` applies native RMSNorm with an optional weight tensor,
and `linear` reuses the blockwise CPU matmul path. `rope` applies rotary
position embedding to an even head slice using caller-supplied inverse
frequencies or a previously produced frequency vector. `slice` isolates
head-sized spans for per-head normalization, and
`silu` plus `mul` cover the gated feed-forward path used by local decoder
layers. `dot`, `stack`, `softmax`, and `weighted_sum` cover the first useful
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
`token_offset` for sharded vocab projections. Graph numeric options such as
sampling temperature, top-p, vector scales, softmax scale, KV-attention scale,
RMS epsilon, and RoPE position scale must be finite numbers. Both
token-selection ops return `[token_id, probability, logit, rank]`. This is still
CPU-side vector state, but it matches the page-table contract that later native
paged attention needs.

Set graph option `return_outputs => false` for decoder loops that only need
`final` and `state`; the default stays `true` for interactive inspection.

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
$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$model = king_inference_model_load([
    'name' => 'invoice-assistant-small',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
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
$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$model = king_inference_model_load([
    'name' => 'king-local-invoice',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
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
`openai_compatible` must be a boolean when provided, and `format` must be one
of `openai`, `openai_chat`, or `openai_chat_completions`.
For `king_native_cpu`, the request must provide a native `graph` or `graphs`
sequence whose final output is a token vector produced by `argmax_token` or
`sample_token`. Direct stream requests use the same native graph shape contract
as the HTTP router: `graph` is an object array, `graphs` is a list array, and
`graph_options` is an object array. King decodes those token ids through the
model tokenizer and emits the same stream surface without creating a second
inference runtime.

## Function, Example 1b: Native Graph Streaming

```php
<?php
$model = king_inference_model_load([
    'name' => 'king-native-invoice',
    'artifact' => '/models/invoice-assistant-q4.gguf',
    'backend' => 'king_native_cpu',
    'with_memory' => false,
]);

$encoded = king_inference_tokenize($model, 'Explain invoice rejection HU-2026-0007.');
$promptTokens = $encoded['tokens'];

$graphs = [];
foreach (array_slice($promptTokens, -3, 3, true) as $position => $tokenId) {
    $graphs[] = king_inference_token_decode_graph($model, (int) $tokenId, (int) $position, [
        'temperature' => 0.4,
        'top_k' => 40,
        'top_p' => 0.95,
        'seed' => 90210,
    ]);
}

$stream = king_inference_stream($model, [
    'graphs' => $graphs,
], [
    'max_native_stream_tokens' => 64,
    'with_memory' => true,
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
decode steps. Streams are stateless by default: King does not carry graph
result state into the next graph unless `with_memory => true` is set in the
stream options, request, or `graph_options`. When memory is enabled and a graph
omits `state`, King carries the previous graph result state into the next graph,
so KV cache entries written by `kv_write` can be read by later steps through
`kv_read` or `kv_attention`. This is the current native handoff for token
events; higher-level prompt-to-graph compilation is a later layer and does not
need a second inference runtime.

Native graph stream startup is bounded by `max_native_stream_tokens`, which can
be set as a stream option or graph option. If it is not set, King allows up to
4096 native token steps before rejecting the stream request.
`with_memory` and the alias `with-memory` must be booleans when provided.

## CI and Test Model Strategy

King keeps the default CI path independent from downloaded model artifacts.
Contract tests can validate exported functions, INI defaults, strict config
validation, OpenAI-compatible routing, and error contracts without a GGUF file.
Native model integration tests remain opt-in through
`KING_INFERENCE_TEST_MODEL_PATH`, because CI should not silently fetch hundreds
of megabytes or depend on an external model host.

The split is deliberate:

- Model-free CI tests prove the extension API, INI surface, and validation.
- Optional GGUF tests prove loader, tokenizer, tensor, graph, and stream
  behavior against a real artifact.
- Release or nightly CI can mount a cached GGUF artifact and set
  `KING_INFERENCE_TEST_MODEL_PATH`.

For a small King command model, start with a compact instruct model and
fine-tune it on deterministic King-specific command traces instead of training
from scratch. The initial dataset should be JSONL chat records with:

- system prompt describing the King runtime contract
- user request in natural language
- assistant response containing one validated King command or structured plan
- tool/result records for negative cases and error handling
- metadata tags for primitive, sync/async, stateless/stateful, and security

Example record:

```json
{"messages":[{"role":"system","content":"You emit precise King PHP runtime calls. Prefer stateless inference unless memory is explicitly requested."},{"role":"user","content":"Open a native inference stream without memory for three decode graphs."},{"role":"assistant","content":"$stream = king_inference_stream($model, ['graphs' => $graphs], ['with_memory' => false]);"}],"metadata":{"primitive":"inference","mode":"stateless","language":"php"}}
```

Build the first dataset from King docs, public stubs, PHPT tests, and manually
reviewed examples. Do not train on failing or obsolete snippets unless the
assistant answer explicitly fixes them. The first useful target is command
selection and argument correctness, not general chat quality.

## Function, Example 1c: OpenAI-Compatible HTTP Route

```php
<?php
$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}
$modelPath = getenv('KING_INFERENCE_MODEL_PATH');
if (!is_string($modelPath) || $modelPath === '') {
    throw new RuntimeException('KING_INFERENCE_MODEL_PATH must point to a local GGUF model artifact.');
}

$model = king_inference_model_load([
    'name' => 'local-small-model',
    'artifact_path' => $modelPath,
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
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
    );
}
```

The helper accepts the normalized King HTTP request array and owns the
OpenAI-compatible endpoint contract for `POST /v1/chat/completions`. The request
body is decoded as a Chat Completions JSON payload, `messages` are validated,
and the loaded King model is used for both normal and streaming responses. For
`king_native_cpu` models, the same route accepts an explicit `graph` object or
`graphs` array in the JSON payload and emits OpenAI-shaped responses from the
native graph-selected token stream. `graph_options` must be a JSON object when
provided. Native graph streams are stateless unless the payload or options set
`with_memory` or `with-memory` to `true`.

For `stream=false`, the helper drains into one OpenAI-shaped `chat.completion`
JSON response. For `stream=true`, it returns a bounded `text/event-stream` body
with `data: {chunk}` events and a final `data: [DONE]` marker.
If the selected model uses `king_native_gpu`, `POST /v1/chat/completions`
returns a precise OpenAI error while the GPU decoder kernel is not ready. The
message states that the model is registered for metadata/readiness inspection,
reports `gpu_runtime.generation_ready=false`,
`gpu_runtime.decoder_kernel_ready=false`, includes the primary
`gpu_runtime.reason`, and makes the no-silent-CPU-fallback rule explicit.
Clients should treat the `/v1/models` `x_king.gpu_runtime` object as the
authoritative readiness source for GPU models. A registered `king_native_gpu`
model can be listed and selected for inspection, but UI and autodetect flows
must not infer generation readiness from the model id or backend name.

## Function, Example 1d: OpenAI-Compatible Model Router

```php
<?php
$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}
$supportModelPath = getenv('KING_SUPPORT_MODEL_PATH');
if (!is_string($supportModelPath) || $supportModelPath === '') {
    throw new RuntimeException('KING_SUPPORT_MODEL_PATH must point to a local GGUF model artifact.');
}
$invoiceModelPath = getenv('KING_INVOICE_MODEL_PATH');
if (!is_string($invoiceModelPath) || $invoiceModelPath === '') {
    throw new RuntimeException('KING_INVOICE_MODEL_PATH must point to a local GGUF model artifact.');
}

$models = [
    'support-small' => king_inference_model_load([
        'name' => 'support-small',
        'artifact_path' => $supportModelPath,
        'backend' => ['name' => 'local', 'runner_path' => $runner],
        'owned_by' => 'internal-platform',
    ]),
    'invoice-checker' => king_inference_model_load([
        'name' => 'invoice-checker',
        'artifact_path' => $invoiceModelPath,
        'backend' => ['name' => 'local', 'runner_path' => $runner],
        'owned_by' => 'internal-platform',
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
$modelPath = getenv('KING_INFERENCE_MODEL_PATH');
if (!is_string($modelPath) || $modelPath === '') {
    throw new RuntimeException('KING_INFERENCE_MODEL_PATH must point to a local GGUF model artifact.');
}

$model = king_inference_model_load([
    'name' => 'local-small-model',
    'artifact' => [
        'path' => $modelPath,
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
        'vram_reserve_mb' => 2048,
        'min_free_vram_mb' => 4096,
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
$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$supportModel = king_inference_model_load([
    'name' => 'support-routing',
    'artifact' => '/models/support-routing-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
]);
$invoiceModel = king_inference_model_load([
    'name' => 'invoice-format-check',
    'artifact' => '/models/invoice-format-check-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
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

$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$model = Inference::loadModel([
    'name' => 'assistant',
    'artifact_path' => '/models/assistant-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
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

$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$model = new Model([
    'name' => 'procurement-assistant',
    'artifact' => '/models/procurement-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'type' => 'local',
        'runner' => [
            'path' => $runner,
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
`native_event_index`. GPU-enabled streams also report the last run preflight
through `gpu_thermal_preflight_checked`, `gpu_thermal_preflight_at`, and the
optional `gpu_thermal_preflight_temperature_c`. If a running GPU stream is
aborted at the configured thermal ceiling, metrics include
`gpu_thermal_aborted`, `gpu_thermal_abort_at`,
`gpu_thermal_abort_temperature_c`, and `gpu_thermal_abort_ceiling_c`.

## OO, Example 3: Parallel Inference Streams

```php
<?php
use King\Awaitable;
use King\Inference\Model;
use King\Inference\Stream;

$runner = getenv('KING_INFERENCE_RUNNER');
if (!is_string($runner) || $runner === '') {
    throw new RuntimeException('KING_INFERENCE_RUNNER must point to the local King inference runner.');
}

$model = new Model([
    'name' => 'operations-assistant',
    'artifact_path' => '/models/operations-assistant-q4.gguf',
    'quantization' => 'q4',
    'backend' => [
        'name' => 'local',
        'runner_path' => $runner,
    ],
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
