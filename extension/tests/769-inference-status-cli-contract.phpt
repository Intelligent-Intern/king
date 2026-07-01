--TEST--
King inference status CLI reports local readiness and hard GPU requirements
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
$script = $root . '/bin/king-inference-status';
$extension = $root . '/extension/modules/king.so';
$php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = $root . '/var/inference-models/gemma3-1b.gguf';
}

$ini = tempnam(sys_get_temp_dir(), 'king-status-cli-');
if ($ini === false) {
    echo "ini-tempnam-failed\n";
    exit;
}

file_put_contents($ini, implode("\n", [
    'king.gpu_bindings_enable=1',
    'king.gpu_default_backend=cuda',
    'king.inference_preferred_model_profile=cpu',
    'king.inference_cpu_model_name=status-cli-cpu',
    'king.inference_cpu_model_artifact=' . $modelPath,
    'king.inference_gpu_model_name=status-cli-gpu',
    'king.inference_gpu_model_artifact=' . $modelPath,
    'king.inference_gpu_allow_unmonitored=1',
    'king.inference_gpu_thermal_max_temperature_c=95',
    '',
]));

function runStatusCli(string $script, string $php, string $extension, string $ini, array $args): array
{
    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'PHP_BIN' => $php,
        'KING_EXTENSION' => $extension,
        'KING_INFERENCE_PHP_INI' => $ini,
    ];
    $ldLibraryPath = getenv('LD_LIBRARY_PATH');
    if (is_string($ldLibraryPath) && $ldLibraryPath !== '') {
        $env['LD_LIBRARY_PATH'] = $ldLibraryPath;
    }
    $command = escapeshellarg($script);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname($script, 2),
        $env
    );
    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

try {
    $ready = runStatusCli($script, $php, $extension, $ini, ['--json']);
    $readyPayload = json_decode($ready['stdout'], true, 512, JSON_THROW_ON_ERROR);

    var_dump($ready['exit']);
    var_dump($ready['stderr'] === '');
    var_dump($readyPayload['ready']);
    var_dump($readyPayload['exit_code']);
    var_dump($readyPayload['runtime']['requested_profile']);
    var_dump($readyPayload['runtime']['selected_profile']);
    var_dump($readyPayload['runtime']['backend']);
    var_dump($readyPayload['runtime']['model']);
    var_dump($readyPayload['model']['loaded']);
    var_dump($readyPayload['model']['generation_ready']);
    var_dump($readyPayload['router']['model_listing_ready']);
    var_dump($readyPayload['router']['openai_chat_ready']);
    var_dump($readyPayload['require_gpu']);

    $requireGpu = runStatusCli($script, $php, $extension, $ini, ['--require-gpu', '--json']);
    $requireGpuPayload = json_decode($requireGpu['stdout'], true, 512, JSON_THROW_ON_ERROR);

    var_dump($requireGpu['exit']);
    var_dump($requireGpu['stderr'] === '');
    var_dump($requireGpuPayload['ready']);
    var_dump($requireGpuPayload['exit_code']);
    var_dump($requireGpuPayload['reason']);
    var_dump($requireGpuPayload['refusal_reasons']);
    var_dump($requireGpuPayload['runtime']['backend']);
    var_dump($requireGpuPayload['require_gpu']);
} finally {
    @unlink($ini);
}
?>
--EXPECT--
int(0)
bool(true)
bool(true)
int(0)
string(3) "cpu"
string(3) "cpu"
string(15) "king_native_cpu"
string(14) "status-cli-cpu"
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
int(2)
bool(true)
bool(false)
int(2)
string(24) "gpu_profile_not_selected"
array(1) {
  [0]=>
  string(24) "gpu_profile_not_selected"
}
string(15) "king_native_cpu"
bool(true)
