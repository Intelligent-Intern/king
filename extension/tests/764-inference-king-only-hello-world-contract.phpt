--TEST--
King-only hello-world command performs native prompt-to-token generation
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
$root = dirname(__DIR__, 2);
$script = $root . '/bin/king-native-hello-world';
$extension = $root . '/extension/modules/king.so';
$php = getenv('TEST_PHP_EXECUTABLE') ?: PHP_BINARY;
$env = [
    'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    'PHP_BIN' => $php,
    'KING_EXTENSION' => $extension,
    'KING_INFERENCE_HELLO_MODEL_PATH' => $modelPath,
    'KING_INFERENCE_HELLO_PROMPT' => 'Hello world',
    'KING_INFERENCE_HELLO_TOKENS' => '1',
];
$ldLibraryPath = getenv('LD_LIBRARY_PATH');
if (is_string($ldLibraryPath) && $ldLibraryPath !== '') {
    $env['LD_LIBRARY_PATH'] = $ldLibraryPath;
}

$process = proc_open(
    escapeshellarg($script),
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $root,
    $env
);
if (!is_resource($process)) {
    echo "proc-open-failed\n";
    exit;
}

fclose($pipes[0]);
$stdout = (string) stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[2]);
$exit = proc_close($process);
$combined = strtolower($stdout . $stderr);

var_dump($exit);
var_dump($stderr === '');
var_dump($stdout !== '');
var_dump(!str_contains($combined, 'ollama'));
var_dump(!str_contains($combined, 'vllm'));
var_dump(!str_contains($combined, 'external model server'));
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
