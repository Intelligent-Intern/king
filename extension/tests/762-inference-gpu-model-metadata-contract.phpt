--TEST--
King GPU inference model metadata is exposed through model info and OpenAI models
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
if ($modelPath === false || $modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_GPU_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$modelPath = getenv('KING_INFERENCE_GPU_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
}

$config = King\Config::new([
    'inference.preferred_model_profile' => 'gpu',
    'inference.gpu_model_name' => 'gpu-model-metadata-test',
    'inference.gpu_model_artifact' => $modelPath,
    'inference.gpu_max_gpu_layers' => 64,
    'inference.gpu_vram_reserve_mb' => 0,
    'inference.gpu_min_free_vram_mb' => 0,
    'inference.gpu_thermal_max_temperature_c' => 90.5,
    'inference.gpu_allow_unmonitored' => true,
]);

$model = king_inference_runtime_model_load($config);
$info = king_inference_model_info($model);

$response = king_inference_openai_http_response(
    ['gpu-model-metadata-test' => $model],
    ['method' => 'GET', 'path' => '/v1/models']
);
$payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
$listed = $payload['data'][0];
$king = $listed['x_king'];

var_dump($info['name']);
var_dump($info['backend']);
var_dump($info['engine']);
var_dump($info['external_runtime']);
var_dump($info['artifact_path'] === $modelPath);
var_dump($info['artifact_bytes'] > 0);
var_dump($info['gpu_enabled']);
var_dump($info['decoder_stream_contract_ready']);
var_dump(is_bool($info['decoder_kernel_ready']));
var_dump(is_bool($info['plain_text_chat_ready']));
var_dump(is_bool($info['generation_ready']));
var_dump(is_bool($info['openai_generation']));
var_dump($info['generation_ready'] === $info['decoder_kernel_ready']);
var_dump($info['openai_generation'] === $info['generation_ready']);
var_dump($info['silent_cpu_fallback']);
var_dump($info['backend_capabilities']['gpu_backend']);
var_dump($info['backend_capabilities']['gpu_runtime_status']);
var_dump($info['backend_capabilities']['native_stream_contract']);
var_dump($info['backend_capabilities']['silent_cpu_fallback']);
var_dump(is_array($info['decoder_blockers']));
var_dump(is_array($info['gpu_runtime']));
var_dump($info['gpu_runtime']['backend']);
var_dump($info['gpu_runtime']['artifact_path'] === $modelPath);
var_dump($info['gpu_runtime']['generation_ready'] === $info['generation_ready']);
var_dump($info['gpu_runtime']['decoder_kernel_ready'] === $info['decoder_kernel_ready']);
var_dump(is_array($info['gpu_runtime']['cuda_driver']));

var_dump($response['status']);
var_dump($payload['object']);
var_dump($listed['id']);
var_dump($listed['object']);
var_dump($listed['owned_by']);
var_dump($king['backend']);
var_dump($king['backend_config_valid']);
var_dump($king['gpu_enabled']);
var_dump($king['native_stream_contract']);
var_dump($king['openai_generation'] === $info['gpu_runtime']['generation_ready']);
var_dump($king['openai_chat_completions_stream'] === $king['openai_generation']);
var_dump($king['gpu_runtime']['artifact_path'] === $modelPath);
var_dump($king['gpu_runtime']['generation_ready'] === $info['generation_ready']);
var_dump($king['gpu_runtime']['decoder_kernel_ready'] === $info['decoder_kernel_ready']);
var_dump($king['capabilities']['gpu_backend']);
var_dump($king['capabilities']['gpu_runtime_status']);
var_dump($king['capabilities']['native_stream_contract']);
var_dump($king['client_capabilities']['version']);
var_dump($king['client_capabilities']['model_selectable']);
var_dump($king['client_capabilities']['requires_gpu']);
var_dump($king['client_capabilities']['gpu_runtime_required']);
var_dump($king['client_capabilities']['gpu_generation_ready'] === $info['generation_ready']);
var_dump($king['client_capabilities']['openai_tool_calls']);
?>
--EXPECT--
string(23) "gpu-model-metadata-test"
string(15) "king_native_gpu"
string(15) "king_native_gpu"
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
string(4) "cuda"
bool(true)
bool(true)
bool(true)
bool(true)
int(200)
string(4) "list"
string(23) "gpu-model-metadata-test"
string(5) "model"
string(12) "king-runtime"
string(15) "king_native_gpu"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
