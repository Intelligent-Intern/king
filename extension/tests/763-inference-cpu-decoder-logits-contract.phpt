--TEST--
King CPU reference decoder produces logits from a local GGUF model
--SKIPIF--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
if ($modelPath === false || $modelPath === '' || !is_file($modelPath)) {
    echo "skip KING_INFERENCE_TEST_MODEL_PATH must point to a local GGUF model artifact\n";
}
?>
--FILE--
<?php
$modelPath = getenv('KING_INFERENCE_TEST_MODEL_PATH');
$model = king_inference_model_load([
    'name' => 'cpu-decoder-logits-test',
    'artifact' => [
        'path' => $modelPath,
    ],
    'backend' => 'king_native_cpu',
]);
$info = king_inference_model_info($model);
$encoded = king_inference_tokenize($model, 'Hello');
$graph = king_inference_token_decode_graph($model, $encoded, 0, [
    'emit_token' => false,
    'emit_logits' => true,
]);
$result = king_inference_graph_run($model, $graph, [
    'max_vector_values' => 300000,
    'max_operations' => 500000000,
    'return_outputs' => false,
]);

$logits = $result['final']['logits'];
$values = $logits['values'];
$sampleIndexes = [0, 100, $logits['length'] - 1];
$finiteSamples = true;
foreach ($sampleIndexes as $index) {
    $finiteSamples = $finiteSamples && isset($values[$index]) && is_finite($values[$index]);
}

var_dump($info['name']);
var_dump($info['backend']);
var_dump($info['engine']);
var_dump($info['external_runtime']);
var_dump($info['token_generation_ready']);
var_dump($info['gguf']['decoder_ready']);
var_dump($info['backend_capabilities']['native_model_loader']);
var_dump($info['backend_capabilities']['native_token_selection']);
var_dump($info['backend_capabilities']['token_generation']);
var_dump($encoded['token_count'] > 0);
var_dump($encoded['unknown_count'] === 0);
var_dump($graph['output']);
var_dump($graph['terminal']['emits_logits']);
var_dump($graph['terminal']['emits_token']);
var_dump($graph['terminal']['token_selection']);
var_dump($graph['terminal']['output_projection_status'] !== '');
var_dump(count($graph['ops']) > 100);
var_dump($result['output']);
var_dump($result['op_count'] === count($graph['ops']));
var_dump($logits['length'] > 0);
var_dump($logits['length'] === count($values));
var_dump($logits['length'] === $info['native_tokenizer_token_count']);
var_dump($finiteSamples);
var_dump(is_array($result['state']['kv_cache']));
var_dump(count($result['state']['kv_cache']) > 0);
?>
--EXPECT--
string(23) "cpu-decoder-logits-test"
string(15) "king_native_cpu"
string(15) "king_native_cpu"
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(6) "logits"
bool(true)
bool(false)
string(4) "none"
bool(true)
bool(true)
string(6) "logits"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
