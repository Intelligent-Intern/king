--TEST--
King inference refuses overheated GPU runs before and during streaming
--INI--
king.security_allow_config_override=1
king.gpu_bindings_enable=1
king.gpu_default_backend=cuda
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $candidate = dirname(__DIR__, 2) . '/var/inference-models/gemma3-1b.gguf';
    $modelPath = is_file($candidate) ? $candidate : '';
}
if ($modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
    return;
}
if (!function_exists('proc_open')) {
    echo "skip proc_open is required\n";
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$extension = $root . '/extension/modules/king.so';
$php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = $root . '/var/inference-models/gemma3-1b.gguf';
}

function thermalGraph(): array
{
    return [
        'inputs' => ['token' => [1]],
        'ops' => [[
            'id' => 'next_token',
            'op' => 'scale',
            'input' => 'token',
            'factor' => 1.0,
        ]],
        'output' => 'next_token',
    ];
}

function thermalModelConfig(string $modelPath, string $sensor): array
{
    return [
        'name' => 'thermal-refusal-contract',
        'artifact' => ['path' => $modelPath],
        'backend' => 'king_native_cpu',
        'gpu' => [
            'enabled' => true,
            'thermal' => [
                'sensor_path' => $sensor,
                'max_temperature_c' => 80.0,
                'allow_unmonitored_gpu' => false,
            ],
        ],
    ];
}

function runPreflightRefusalProcess(string $php, string $extension, string $modelPath): array
{
    $script = tempnam(sys_get_temp_dir(), 'king-thermal-refusal-child-');
    $sensor = tempnam(sys_get_temp_dir(), 'king-thermal-refusal-sensor-');
    if ($script === false || $sensor === false) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'tempnam failed'];
    }

    file_put_contents($sensor, "45000\n");
    $code = <<<'PHP'
<?php
$modelPath = getenv('KING_THERMAL_MODEL_PATH');
$sensor = getenv('KING_THERMAL_SENSOR_PATH');
function thermalGraph(): array
{
    return [
        'inputs' => ['token' => [1]],
        'ops' => [[
            'id' => 'next_token',
            'op' => 'scale',
            'input' => 'token',
            'factor' => 1.0,
        ]],
        'output' => 'next_token',
    ];
}
function thermalModelConfig(string $modelPath, string $sensor): array
{
    return [
        'name' => 'thermal-refusal-preflight-child',
        'artifact' => ['path' => $modelPath],
        'backend' => 'king_native_cpu',
        'gpu' => [
            'enabled' => true,
            'thermal' => [
                'sensor_path' => $sensor,
                'max_temperature_c' => 80.0,
                'allow_unmonitored_gpu' => false,
            ],
        ],
    ];
}
$model = king_inference_model_load(thermalModelConfig($modelPath, $sensor));
file_put_contents($sensor, "91000\n");
try {
    king_inference_stream($model, ['graphs' => [thermalGraph()]], [
        'with_memory' => false,
        'max_native_stream_tokens' => 4,
    ]);
    var_dump('no-exception');
} catch (Throwable $exception) {
    var_dump(get_class($exception));
    var_dump(str_contains($exception->getMessage(), 'refused GPU inference because sensor'));
    var_dump(str_contains($exception->getMessage(), '91.0 C'));
    var_dump(str_contains($exception->getMessage(), '80.0 C'));
}
PHP;
    file_put_contents($script, $code);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'KING_THERMAL_MODEL_PATH' => $modelPath,
        'KING_THERMAL_SENSOR_PATH' => $sensor,
    ];
    $ldLibraryPath = getenv('LD_LIBRARY_PATH');
    if (is_string($ldLibraryPath) && $ldLibraryPath !== '') {
        $env['LD_LIBRARY_PATH'] = $ldLibraryPath;
    }
    $command = escapeshellarg($php)
        . ' -n -d extension=posix -d extension=sockets'
        . ' -d extension=' . escapeshellarg($extension)
        . ' -d king.security_allow_config_override=1'
        . ' -d king.gpu_bindings_enable=1'
        . ' -d king.gpu_default_backend=cuda '
        . escapeshellarg($script);
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $GLOBALS['root'],
        $env
    );
    if (!is_resource($process)) {
        @unlink($script);
        @unlink($sensor);
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    @unlink($script);
    @unlink($sensor);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

$preflight = runPreflightRefusalProcess($php, $extension, $modelPath);
var_dump($preflight['exit']);
var_dump($preflight['stderr'] === '');
var_dump(str_contains($preflight['stdout'], 'string(21) "King\\RuntimeException"'));
var_dump(substr_count($preflight['stdout'], 'bool(true)') === 3);

$sensor = tempnam(sys_get_temp_dir(), 'king-thermal-refusal-sensor-');
if ($sensor === false) {
    echo "sensor-tempnam-failed\n";
    exit;
}

try {
    file_put_contents($sensor, "45000\n");
    $model = king_inference_model_load(thermalModelConfig($modelPath, $sensor));
    $stream = new King\Inference\Stream($model, ['graphs' => [thermalGraph()]], [
        'with_memory' => false,
        'max_native_stream_tokens' => 4,
    ]);
    $start = $stream->next(0);

    var_dump($start['type']);
    var_dump($start['backend']);
    var_dump($start['native_stream']);
    var_dump($start['gpu_thermal_preflight_checked']);
    var_dump($start['gpu_thermal_preflight_temperature_c']);

    file_put_contents($sensor, "91000\n");
    try {
        $stream->next(0);
        var_dump('no-abort');
    } catch (Throwable $exception) {
        var_dump(get_class($exception));
        var_dump(str_contains($exception->getMessage(), 'refused GPU inference because sensor'));
        var_dump(str_contains($exception->getMessage(), '91.0 C'));
        var_dump(str_contains($exception->getMessage(), '80.0 C'));
    }

    $metrics = $stream->getMetrics();
    var_dump($metrics['done']);
    var_dump($metrics['cancelled']);
    var_dump($metrics['gpu_thermal_aborted']);
    var_dump($metrics['gpu_thermal_abort_temperature_c']);
    var_dump($metrics['gpu_thermal_abort_ceiling_c']);
    var_dump($metrics['gpu_thermal_preflight_checked']);
    var_dump($metrics['gpu_thermal_preflight_temperature_available']);
    var_dump($metrics['gpu_thermal_preflight_temperature_c']);
    var_dump($metrics['native_stream']);
} finally {
    @unlink($sensor);
}
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
string(5) "start"
string(15) "king_native_cpu"
bool(true)
bool(true)
float(45)
string(21) "King\RuntimeException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
float(91)
float(80)
bool(true)
bool(true)
float(45)
bool(true)
