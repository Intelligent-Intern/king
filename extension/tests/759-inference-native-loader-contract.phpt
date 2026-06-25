--TEST--
King native inference loader exposes GGUF metadata without external runtime
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$model = king_inference_model_load([
    'name' => 'native-loader-test',
    'artifact' => [
        'path' => getenv('KING_INFERENCE_TEST_MODEL_PATH'),
    ],
    'backend' => 'king_native_cpu',
]);

$info = king_inference_model_info($model);
var_dump($info['name']);
var_dump($info['backend']);
var_dump($info['engine']);
var_dump($info['external_runtime']);
var_dump($info['token_generation_ready']);
var_dump($info['backend_capabilities']['native_model_loader']);
var_dump($info['backend_capabilities']['token_generation']);
var_dump($info['gguf']['header_loaded']);
var_dump($info['gguf']['version'] > 0);
var_dump($info['gguf']['tensor_count'] > 0);
var_dump($info['artifact_bytes'] > 24);

try {
    king_inference_stream($model, [
        'prompt' => 'hello',
        'max_tokens' => 1,
    ]);
    echo "stream-started\n";
} catch (Throwable $e) {
    var_dump(str_contains($e->getMessage(), 'native token generation is not wired yet'));
    var_dump(str_contains($e->getMessage(), 'No external inference runtime was used'));
}
?>
--EXPECT--
string(18) "native-loader-test"
string(15) "king_native_cpu"
string(15) "king_native_cpu"
bool(false)
bool(false)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
