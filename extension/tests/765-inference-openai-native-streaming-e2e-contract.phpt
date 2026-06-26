--TEST--
King OpenAI-compatible chat completions run end-to-end on native CPU streaming
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
    'name' => 'openai-native-e2e-test',
    'artifact' => [
        'path' => $modelPath,
    ],
    'backend' => 'king_native_cpu',
]);
$models = ['openai-native-e2e-test' => $model];
$options = [
    'read_timeout_ms' => 250,
    'max_events' => 64,
    'max_idle_events' => 16,
];

$basePayload = [
    'model' => 'openai-native-e2e-test',
    'messages' => [
        ['role' => 'system', 'content' => 'Reply briefly.'],
        ['role' => 'user', 'content' => 'Hello world'],
    ],
    'max_tokens' => 1,
    'temperature' => 0.0,
    'top_k' => 1,
    'top_p' => 1.0,
    'seed' => 1,
];

$jsonResponse = king_inference_openai_http_response($models, [
    'method' => 'POST',
    'path' => '/v1/chat/completions',
    'body' => json_encode($basePayload, JSON_UNESCAPED_SLASHES),
], $options);
$jsonPayload = json_decode($jsonResponse['body'], true, 512, JSON_THROW_ON_ERROR);

$streamPayload = $basePayload;
$streamPayload['stream'] = true;
$streamPayload['stream_options'] = ['include_usage' => true];
$streamResponse = King\Inference::openaiHttpResponse($models, [
    'method' => 'POST',
    'path' => '/v1/chat/completions',
    'body' => json_encode($streamPayload, JSON_UNESCAPED_SLASHES),
], $options);

$streamLines = preg_split('/\R/', trim($streamResponse['body']));
$dataLines = array_values(array_filter($streamLines, static fn ($line) => str_starts_with($line, 'data: ')));
$jsonChunks = [];
$doneSeen = false;
foreach ($dataLines as $line) {
    $payload = substr($line, 6);
    if ($payload === '[DONE]') {
        $doneSeen = true;
        continue;
    }
    $jsonChunks[] = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
}
$contentChunks = 0;
$finishChunks = 0;
$usageChunks = 0;
foreach ($jsonChunks as $chunk) {
    $choices = $chunk['choices'] ?? [];
    if ($choices === []) {
        $usageChunks++;
        continue;
    }
    $choice = $choices[0];
    if (isset($choice['delta']['content']) && $choice['delta']['content'] !== '') {
        $contentChunks++;
    }
    if (($choice['finish_reason'] ?? null) === 'stop') {
        $finishChunks++;
    }
}

var_dump($jsonResponse['status']);
var_dump($jsonResponse['headers']['content-type']);
var_dump($jsonPayload['object']);
var_dump($jsonPayload['model']);
var_dump($jsonPayload['choices'][0]['message']['role']);
var_dump(is_string($jsonPayload['choices'][0]['message']['content']));
var_dump($jsonPayload['choices'][0]['message']['content'] !== '');
var_dump($jsonPayload['choices'][0]['finish_reason']);
var_dump(is_array($jsonPayload['usage']));

var_dump($streamResponse['status']);
var_dump($streamResponse['headers']['content-type']);
var_dump($streamResponse['headers']['cache-control']);
var_dump($streamResponse['headers']['x-accel-buffering']);
var_dump($doneSeen);
var_dump(count($jsonChunks) >= 3);
var_dump($contentChunks >= 1);
var_dump($finishChunks === 1);
var_dump($usageChunks === 1);
var_dump($jsonChunks[0]['object']);
var_dump($jsonChunks[0]['model']);
?>
--EXPECT--
int(200)
string(16) "application/json"
string(15) "chat.completion"
string(22) "openai-native-e2e-test"
string(9) "assistant"
bool(true)
bool(true)
string(4) "stop"
bool(true)
int(200)
string(17) "text/event-stream"
string(8) "no-cache"
string(2) "no"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "chat.completion.chunk"
string(22) "openai-native-e2e-test"
