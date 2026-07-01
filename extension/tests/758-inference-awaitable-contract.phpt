--TEST--
King inference async stream reader resolves through Awaitable
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
$runnerPath = tempnam(sys_get_temp_dir(), 'king-inference-runner-');

if ($modelPath === false || $modelPath === '' || $runnerPath === false) {
    echo "tempfile-failed\n";
    exit;
}

file_put_contents($runnerPath, "#!/bin/sh\nprintf token-from-runner\n");
chmod($runnerPath, 0700);

try {
    foreach ([
        'king_inference_model_load',
        'king_inference_model_info',
        'king_inference_stream',
        'king_inference_next',
        'king_inference_next_async',
        'king_inference_cancel',
    ] as $function) {
        var_dump(function_exists($function));
    }

    $methodReflection = new ReflectionMethod(King\Inference\Stream::class, 'nextAsync');
    var_dump($methodReflection->isPublic());

    $model = king_inference_model_load([
        'name' => 'test-model',
        'artifact' => $modelPath,
        'quantization' => 'q4',
        'backend' => [
            'type' => 'local',
            'runner' => [
                'path' => $runnerPath,
            ],
        ],
    ]);
    var_dump($model instanceof King\Inference\Model);

    $info = king_inference_model_info($model);
    var_dump($info['name']);
    var_dump($info['format']);
    var_dump($info['backend']);
    var_dump($info['backend_capabilities']['streaming']);

    $stream = king_inference_stream($model, [
        'prompt' => 'hello',
        'max_tokens' => 1,
        'temperature' => 0,
    ]);
    var_dump($stream instanceof King\Inference\Stream);

    $startRead = king_inference_next_async($stream, 0);
    var_dump($startRead instanceof King\Awaitable);
    var_dump($startRead->poll(0));
    $start = $startRead->await();
    var_dump($start['type']);

    $token = null;
    for ($i = 0; $i < 20; $i++) {
        $read = $stream->nextAsync(50);
        if (!$read->poll(50)) {
            usleep(10000);
            continue;
        }

        $event = $read->await();
        if (is_array($event) && ($event['type'] ?? null) === 'token') {
            $token = $event['text'];
            break;
        }
        if (is_array($event) && (($event['type'] ?? null) === 'done' || ($event['type'] ?? null) === 'cancelled')) {
            break;
        }
    }

    var_dump($token);
    var_dump(king_inference_cancel($stream));
} finally {
    @unlink($runnerPath);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(10) "test-model"
string(4) "gguf"
string(5) "local"
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "start"
string(17) "token-from-runner"
bool(true)
