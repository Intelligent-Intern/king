--TEST--
King GPU runtime profile never falls back to CPU generation silently
--INI--
king.security_allow_config_override=1
king.gpu_bindings_enable=1
king.gpu_default_backend=cuda
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_GPU_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
}
if ($modelPath === false || $modelPath === '') {
    $candidate = dirname(__DIR__, 2) . '/var/inference-models/gemma3-1b.gguf';
    $modelPath = is_file($candidate) ? $candidate : '';
}
if ($modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_GPU_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$modelPath = getenv('KING_INFERENCE_GPU_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
}
if ($modelPath === false || $modelPath === '') {
    $modelPath = dirname(__DIR__, 2) . '/var/inference-models/gemma3-1b.gguf';
}

$config = King\Config::new([
    'inference.preferred_model_profile' => 'gpu',
    'inference.cpu_model_name' => 'cpu-fallback-must-not-run',
    'inference.cpu_model_artifact' => $modelPath,
    'inference.gpu_model_name' => 'gpu-profile-no-fallback-test',
    'inference.gpu_model_artifact' => $modelPath,
    'inference.gpu_max_gpu_layers' => 64,
    'inference.gpu_vram_reserve_mb' => 0,
    'inference.gpu_min_free_vram_mb' => 999999,
    'inference.gpu_thermal_max_temperature_c' => 95.0,
    'inference.gpu_allow_unmonitored' => true,
]);

$modelConfig = king_inference_runtime_model_config($config);
$model = king_inference_runtime_model_load($config);
$info = king_inference_model_info($model);
$modelsResponse = king_inference_openai_http_response(
    ['gpu-profile-no-fallback-test' => $model],
    ['method' => 'GET', 'path' => '/v1/models']
);
$modelsPayload = json_decode($modelsResponse['body'], true, 512, JSON_THROW_ON_ERROR);
$listed = $modelsPayload['data'][0]['x_king'];

$chatResponse = king_inference_openai_http_response(
    ['gpu-profile-no-fallback-test' => $model],
    [
        'method' => 'POST',
        'path' => '/v1/chat/completions',
        'body' => json_encode([
            'model' => 'gpu-profile-no-fallback-test',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello world'],
            ],
            'max_tokens' => 1,
            'temperature' => 0.0,
        ], JSON_UNESCAPED_SLASHES),
    ],
    ['read_timeout_ms' => 100, 'max_events' => 8]
);
$chatPayload = json_decode($chatResponse['body'], true, 512, JSON_THROW_ON_ERROR);
$message = $chatPayload['error']['message'] ?? '';

var_dump($modelConfig['runtime_requested_profile']);
var_dump($modelConfig['runtime_profile']);
var_dump($modelConfig['backend']);
var_dump($modelConfig['name']);
var_dump($modelConfig['runtime_cpu_model_name']);
var_dump($modelConfig['runtime_gpu_profile_available']);
var_dump($modelConfig['gpu']['enabled']);
var_dump($modelConfig['gpu']['min_free_vram_mb']);

var_dump($info['backend']);
var_dump($info['name']);
var_dump($info['gpu_enabled']);
var_dump($info['silent_cpu_fallback']);
var_dump($info['backend_capabilities']['silent_cpu_fallback']);
var_dump($info['gpu_runtime']['min_free_vram_mb']);
var_dump($info['gpu_runtime']['generation_ready']);
var_dump(in_array('gpu_free_vram_below_configured_floor', $info['gpu_runtime']['refusal_reasons'], true));

var_dump($modelsResponse['status']);
var_dump($listed['backend']);
var_dump($listed['client_capabilities']['requires_gpu']);
var_dump($listed['client_capabilities']['gpu_runtime_required']);
var_dump($listed['client_capabilities']['gpu_generation_ready']);
var_dump($listed['openai_generation']);
var_dump($listed['gpu_runtime']['generation_ready']);

var_dump($chatResponse['status']);
var_dump($chatResponse['headers']['content-type']);
var_dump($chatPayload['error']['type']);
var_dump(str_contains($message, 'selected King GPU model'));
var_dump(str_contains($message, 'will not silently fall back to CPU'));
var_dump(str_contains($chatResponse['body'], 'chat.completion'));
var_dump(str_contains($chatResponse['body'], 'cpu-fallback-must-not-run'));
?>
--EXPECT--
string(3) "gpu"
string(3) "gpu"
string(15) "king_native_gpu"
string(28) "gpu-profile-no-fallback-test"
string(25) "cpu-fallback-must-not-run"
bool(true)
bool(true)
int(999999)
string(15) "king_native_gpu"
string(28) "gpu-profile-no-fallback-test"
bool(true)
bool(false)
bool(false)
int(999999)
bool(false)
bool(true)
int(200)
string(15) "king_native_gpu"
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
int(400)
string(16) "application/json"
string(21) "invalid_request_error"
bool(true)
bool(true)
bool(false)
bool(false)
