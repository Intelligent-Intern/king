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

$encoded = king_inference_tokenize($model, 'Hello world');
var_dump($encoded['token_count'] > 0);
var_dump($encoded['unknown_count'] === 0);

$graphs = [];
foreach ($encoded['tokens'] as $tokenId) {
    $graphs[] = [
        'inputs' => [
            'token' => [(int) $tokenId],
        ],
        'ops' => [
            [
                'id' => 'next_token',
                'op' => 'scale',
                'input' => 'token',
                'factor' => 1.0,
            ],
        ],
        'output' => 'next_token',
    ];
}

$stream = king_inference_stream($model, [
    'graphs' => $graphs,
], [
    'with_memory' => false,
    'max_native_stream_tokens' => 16,
]);

$text = '';
$events = 0;
while (($event = king_inference_next($stream, 0)) !== null) {
    if (($event['type'] ?? '') === 'token') {
        $events++;
        $text .= $event['text'];
    }
    if (($event['type'] ?? '') === 'done') {
        var_dump($event['exit_code']);
        var_dump($event['chunks'] === $events);
        break;
    }
}

var_dump($events > 0);
var_dump(trim($text) === 'Hello world');
?>
--EXPECT--
string(18) "native-loader-test"
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
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
