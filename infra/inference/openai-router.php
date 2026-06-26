<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$host = getenv('KING_OPENAI_HOST') ?: '127.0.0.1';
$port = (int) (getenv('KING_OPENAI_PORT') ?: '8080');
$iniCpuModelName = ini_get('king.inference_cpu_model_name');
$iniCpuModelArtifact = ini_get('king.inference_cpu_model_artifact');
$iniGpuModelName = ini_get('king.inference_gpu_model_name');
$iniGpuModelArtifact = ini_get('king.inference_gpu_model_artifact');
$cpuModelName = is_string($iniCpuModelName) && $iniCpuModelName !== '' ? $iniCpuModelName : 'gemma3:1b';
$gpuModelName = is_string($iniGpuModelName) && $iniGpuModelName !== '' ? $iniGpuModelName : 'gemma4:12b';
$cpuModelPath = getenv('KING_INFERENCE_CPU_MODEL_PATH')
    ?: getenv('KING_INFERENCE_MODEL_PATH')
    ?: getenv('KING_INFERENCE_TEST_MODEL_PATH')
    ?: (is_string($iniCpuModelArtifact) && $iniCpuModelArtifact !== '' ? $iniCpuModelArtifact : null)
    ?: $root . '/var/inference-models/gemma3-1b.gguf';
$gpuModelPath = getenv('KING_INFERENCE_GPU_MODEL_PATH')
    ?: getenv('KING_INFERENCE_GEMMA4_MODEL_PATH')
    ?: (is_string($iniGpuModelArtifact) && $iniGpuModelArtifact !== '' ? $iniGpuModelArtifact : null)
    ?: $root . '/var/inference-models/gemma4-12b.gguf';
$runner = getenv('KING_INFERENCE_RUNNER') ?: $root . '/bin/king-local-infer';

function king_local_ini_bool(string $key, bool $fallback = false): bool
{
    $value = ini_get($key);
    if ($value === false || $value === '') {
        return $fallback;
    }
    return in_array(strtolower((string) $value), ['1', 'on', 'true', 'yes'], true);
}

function king_local_ini_int(string $key, int $fallback = 0): int
{
    $value = ini_get($key);
    return $value === false || $value === '' ? $fallback : (int) $value;
}

function king_local_ini_float(string $key, float $fallback): float
{
    $value = ini_get($key);
    return $value === false || $value === '' ? $fallback : (float) $value;
}

function king_local_ini_string(string $key, string $fallback = ''): string
{
    $value = ini_get($key);
    return $value === false || $value === '' ? $fallback : (string) $value;
}

if (!is_file($cpuModelPath) || !is_readable($cpuModelPath)) {
    fwrite(STDERR, "CPU model artifact is not readable: {$cpuModelPath}\n");
    exit(1);
}

$backend = is_string($runner) && $runner !== '' && is_executable($runner)
    ? ['name' => 'local', 'runner_path' => $runner]
    : 'king_native_cpu';

$gpuLayers = (int) (getenv('KING_INFERENCE_GPU_LAYERS') ?: king_local_ini_int('king.inference_gpu_max_gpu_layers', 0));
$gpuVramReserveMb = king_local_ini_int('king.inference_gpu_vram_reserve_mb', 2048);
$gpuEnabled = king_local_ini_bool('king.gpu_bindings_enable') && $gpuLayers > 0;
$gpuThermal = [
    'max_temperature_c' => king_local_ini_float('king.inference_gpu_thermal_max_temperature_c', 78.0),
    'allow_unmonitored_gpu' => king_local_ini_bool('king.inference_gpu_allow_unmonitored'),
];
$gpuSensorPath = king_local_ini_string('king.inference_gpu_thermal_sensor_path');
$gpuSensorCommand = king_local_ini_string('king.inference_gpu_thermal_sensor_command');
if ($gpuSensorPath !== '') {
    $gpuThermal['sensor_path'] = $gpuSensorPath;
}
if ($gpuSensorCommand !== '') {
    $gpuThermal['sensor_command'] = $gpuSensorCommand;
}

$loadModel = static function (string $name, string $modelPath, bool $useGpu = false) use ($backend, $gpuLayers, $gpuVramReserveMb, $gpuThermal): object {
    $config = [
        'name' => $name,
        'artifact_path' => $modelPath,
        'backend' => $backend,
        'owned_by' => 'local-king',
        'context_tokens' => 2048,
    ];
    if ($useGpu) {
        $config['gpu'] = [
            'enabled' => true,
            'max_gpu_layers' => $gpuLayers,
            'vram_reserve_mb' => $gpuVramReserveMb,
            'thermal' => $gpuThermal,
        ];
    }
    return king_inference_model_load($config);
};

$models = [
    $cpuModelName => $loadModel($cpuModelName, $cpuModelPath),
];
if ($gpuEnabled && is_file($gpuModelPath) && is_readable($gpuModelPath)) {
    $models = [$gpuModelName => $loadModel($gpuModelName, $gpuModelPath, true)] + $models;
}

fwrite(STDERR, "King OpenAI router listening on http://{$host}:{$port}/v1\n");
fwrite(STDERR, "CPU model artifact: {$cpuModelPath}\n");
if (isset($models[$gpuModelName])) {
    fwrite(STDERR, "GPU/large model artifact: {$gpuModelPath}\n");
    fwrite(STDERR, "GPU layers: {$gpuLayers}\n");
    fwrite(STDERR, "GPU VRAM reserve: {$gpuVramReserveMb} MB\n");
    fwrite(STDERR, 'GPU thermal source: ' . ($gpuSensorPath !== '' ? $gpuSensorPath : $gpuSensorCommand) . "\n");
} else if (is_file($gpuModelPath) && is_readable($gpuModelPath)) {
    fwrite(STDERR, "GPU/large model not registered because GPU bindings or GPU layers are disabled.\n");
}
fwrite(STDERR, 'Backend: ' . (is_array($backend) ? 'local runner ' . $backend['runner_path'] : $backend) . "\n");

$listenerConfig = [
    'tcp_connect_timeout_ms' => 1000,
];

while (true) {
    try {
        king_http1_server_listen_once(
            $host,
            $port,
            $listenerConfig,
            static fn (array $request): array => king_inference_openai_http_response($models, $request, [
                'owned_by' => 'local-king',
                'read_timeout_ms' => 250,
                'max_events' => 4096,
                'max_idle_events' => 4800,
                'max_chat_messages' => 256,
                'max_response_input_items' => 256,
                'max_completion_prompts' => 128,
                'max_embedding_inputs' => 512,
                'max_embedding_tokens' => 2048,
                'max_embedding_dimensions' => 8192,
            ])
        );
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] ' . $e::class . ': ' . $e->getMessage() . "\n");
        usleep(250000);
    }
}
