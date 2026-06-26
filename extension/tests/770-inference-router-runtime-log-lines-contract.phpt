--TEST--
King local inference router log helpers distinguish configured, admitted, and executing states
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $candidate = dirname(__DIR__, 2) . '/var/inference-models/gemma3-1b.gguf';
    $modelPath = is_file($candidate) ? $candidate : '';
}
if ($modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
require_once $root . '/infra/inference/runtime-logging.php';

$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '') {
    $modelPath = $root . '/var/inference-models/gemma3-1b.gguf';
}

$model = king_inference_model_load([
    'name' => 'router-log-test',
    'artifact' => ['path' => $modelPath],
    'backend' => 'king_native_cpu',
]);

$log = fopen('php://temp', 'w+');
if (!is_resource($log)) {
    echo "log-open-failed\n";
    exit;
}

king_inference_runtime_log_configured([
    'host' => '127.0.0.1',
    'port' => 8080,
    'profile' => 'cpu',
    'backend' => 'king_native_cpu',
    'cpu_model' => 'router-log-test',
    'cpu_artifact_readable' => true,
    'gpu_enabled' => false,
], $log);
king_inference_runtime_log_model_admitted('router-log-test', $model, $log);
king_inference_runtime_log_request_executing([
    'method' => 'POST',
    'path' => '/v1/chat/completions',
    'body' => json_encode([
        'model' => 'router-log-test',
        'messages' => [
            ['role' => 'user', 'content' => 'do not log this prompt'],
        ],
    ], JSON_UNESCAPED_SLASHES),
], ['router-log-test' => $model], $log);

rewind($log);
$output = (string) stream_get_contents($log);
$lines = array_values(array_filter(explode("\n", trim($output))));

var_dump(count($lines));
var_dump(str_contains($lines[0], 'king-inference state=configured'));
var_dump(str_contains($lines[0], 'profile=cpu'));
var_dump(str_contains($lines[0], 'cpu_artifact_readable=yes'));
var_dump(str_contains($lines[1], 'king-inference state=admitted'));
var_dump(str_contains($lines[1], 'model=router-log-test'));
var_dump(str_contains($lines[1], 'backend=king_native_cpu'));
var_dump(str_contains($lines[1], 'admitted=yes'));
var_dump(str_contains($lines[1], 'generation_ready=yes'));
var_dump(str_contains($lines[2], 'king-inference state=executing'));
var_dump(str_contains($lines[2], 'method=POST'));
var_dump(str_contains($lines[2], 'path=/v1/chat/completions'));
var_dump(str_contains($lines[2], 'requested_model=router-log-test'));
var_dump(!str_contains($output, 'do not log this prompt'));
?>
--EXPECT--
int(3)
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
bool(true)
bool(true)
