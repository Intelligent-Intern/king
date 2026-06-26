--TEST--
King Gemma4 12B GPU profile enforces VRAM guardrails and loads admitted weights
--INI--
king.security_allow_config_override=1
king.gpu_bindings_enable=1
king.gpu_default_backend=cuda
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_GPU_12B_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $candidate = dirname(__DIR__, 2) . '/var/inference-models/gemma4-12b.gguf';
    $modelPath = is_file($candidate) ? $candidate : '';
}
if ($modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_GPU_12B_TEST_MODEL_PATH must point to a local Gemma4 12B GGUF artifact\n";
    return;
}
if (filesize($modelPath) < 6000000000) {
    echo "skip KING_INFERENCE_GPU_12B_TEST_MODEL_PATH does not look like a 12B-class artifact\n";
    return;
}
$free = @shell_exec('nvidia-smi --query-gpu=memory.free --format=csv,noheader,nounits 2>/dev/null');
if (is_string($free) && preg_match('/^\s*(\d+)/', $free, $match) && (int) $match[1] < 8500) {
    echo "skip Gemma4 12B GPU check needs at least 8500 MiB free VRAM before loading\n";
}
?>
--FILE--
<?php
$modelPath = getenv('KING_INFERENCE_GPU_12B_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = dirname(__DIR__, 2) . '/var/inference-models/gemma4-12b.gguf';
}

$fullContext = king_inference_model_load([
    'name' => 'gemma4-12b-full-context-guardrail',
    'artifact' => ['path' => $modelPath],
    'backend' => 'king_native_gpu',
    'gpu' => [
        'enabled' => true,
        'max_gpu_layers' => 99,
        'vram_reserve_mb' => 0,
        'min_free_vram_mb' => 0,
        'thermal' => [
            'allow_unmonitored_gpu' => true,
            'max_temperature_c' => 78.0,
        ],
    ],
]);
$fullInfo = king_inference_model_info($fullContext);
$fullGpu = $fullInfo['gpu_runtime'];
$fullRequiredBytes = $fullGpu['runtime_vram_required_bytes'];
unset($fullContext);
gc_collect_cycles();

$smallContext = king_inference_model_load([
    'name' => 'gemma4-12b-small-context-gpu',
    'artifact' => ['path' => $modelPath],
    'backend' => 'king_native_gpu',
    'paged_attention' => [
        'max_context_tokens' => 64,
        'page_tokens' => 16,
        'element_bytes' => 2,
    ],
    'gpu' => [
        'enabled' => true,
        'max_gpu_layers' => 99,
        'vram_reserve_mb' => 0,
        'min_free_vram_mb' => 0,
        'thermal' => [
            'allow_unmonitored_gpu' => true,
            'max_temperature_c' => 78.0,
        ],
    ],
]);
$smallInfo = king_inference_model_info($smallContext);
$smallGpu = $smallInfo['gpu_runtime'];
$upload = $smallGpu['required_weight_upload'];
$paged = $smallInfo['paged_kv_cache'];

var_dump($fullInfo['backend']);
var_dump($fullInfo['gguf']['architecture']);
var_dump($fullInfo['artifact_bytes'] > 6000000000);
var_dump($fullGpu['runtime_vram_required_source']);
var_dump($fullGpu['kv_cache_estimate_available']);
var_dump($fullGpu['runtime_vram_fits_free']);
var_dump($fullGpu['runtime_vram_required_bytes'] > $fullInfo['artifact_bytes']);
var_dump($fullGpu['reason']);
var_dump($fullInfo['silent_cpu_fallback']);

var_dump($smallInfo['backend']);
var_dump($smallGpu['backend']);
var_dump($smallGpu['runtime_vram_required_source']);
var_dump($smallGpu['kv_cache_context_tokens']);
var_dump($paged['ready']);
var_dump($paged['max_context_tokens']);
var_dump($smallGpu['runtime_vram_fits_free']);
var_dump($smallGpu['runtime_vram_required_bytes'] < $fullRequiredBytes);
var_dump($smallGpu['device_memory_allocator']['available']);
var_dump($upload['attempted']);
var_dump($upload['complete']);
var_dump($upload['required_tensors']);
var_dump($upload['resolved_tensors'] === $upload['required_tensors']);
var_dump($upload['uploaded_tensors'] + $upload['duplicate_tensors'] === $upload['required_tensors']);
var_dump($upload['duplicate_tensors'] >= 1);
var_dump($upload['failed_tensors']);
var_dump($upload['error']);
var_dump($smallInfo['silent_cpu_fallback']);
var_dump($smallGpu['decoder_blockers']);
?>
--EXPECT--
string(15) "king_native_gpu"
string(6) "gemma4"
bool(true)
string(22) "artifact_plus_kv_cache"
bool(true)
bool(false)
bool(true)
string(35) "gpu_required_vram_exceeds_free_vram"
bool(false)
string(15) "king_native_gpu"
string(4) "cuda"
string(22) "artifact_plus_kv_cache"
int(64)
bool(true)
int(64)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(435)
bool(true)
bool(true)
bool(true)
int(0)
string(0) ""
bool(false)
array(0) {
}
