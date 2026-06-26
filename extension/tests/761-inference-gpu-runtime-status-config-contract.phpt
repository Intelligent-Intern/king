--TEST--
King GPU inference runtime status follows local model config
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
    'inference.gpu_model_name' => 'gpu-runtime-status-test',
    'inference.gpu_model_artifact' => $modelPath,
    'inference.gpu_max_gpu_layers' => 64,
    'inference.gpu_vram_reserve_mb' => 0,
    'inference.gpu_min_free_vram_mb' => 0,
    'inference.gpu_thermal_max_temperature_c' => 90.5,
    'inference.gpu_allow_unmonitored' => true,
]);
$snapshot = $config->toArray();
$status = king_inference_gpu_runtime_status($config);

var_dump($config->get('inference.preferred_model_profile'));
var_dump($config->get('inference.gpu_model_name'));
var_dump($snapshot['inference.gpu_model_artifact'] === $modelPath);
var_dump($snapshot['inference.gpu_max_gpu_layers']);
var_dump($snapshot['inference.gpu_vram_reserve_mb']);
var_dump($snapshot['inference.gpu_min_free_vram_mb']);
var_dump($snapshot['inference.gpu_allow_unmonitored']);

var_dump($status['gpu_enabled']);
var_dump($status['process_gpu_bindings_enable']);
var_dump($status['config_gpu_bindings_enable']);
var_dump($status['backend']);
var_dump($status['backend_supported']);
var_dump($status['artifact_path'] === $modelPath);
var_dump($status['artifact_configured']);
var_dump($status['artifact_readable']);
var_dump($status['artifact_size_available']);
var_dump($status['artifact_bytes'] > 0);
var_dump($status['max_gpu_layers']);
var_dump($status['vram_reserve_mb']);
var_dump($status['min_free_vram_mb']);
var_dump($status['thermal']['allow_unmonitored_gpu']);
var_dump($status['thermal']['monitored']);
var_dump($status['thermal']['max_temperature_c']);
var_dump($status['vram_admission_checked']);
var_dump($status['runtime_vram_compared_to_free']);
var_dump(is_bool($status['runtime_vram_fits_free']));
var_dump(is_bool($status['config_ready']));
var_dump(is_string($status['reason']) && $status['reason'] !== '');
var_dump(is_array($status['refusal_reasons']));
var_dump(array_key_exists('decoder_kernel_ready', $status));
var_dump(array_key_exists('generation_ready', $status));
var_dump(is_array($status['cuda_driver']));
var_dump($status['cuda_driver']['initialized'] || $status['driver_visible']);
?>
--EXPECT--
string(3) "gpu"
string(23) "gpu-runtime-status-test"
bool(true)
int(64)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
string(4) "cuda"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(64)
int(0)
int(0)
bool(true)
bool(false)
float(90.5)
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
