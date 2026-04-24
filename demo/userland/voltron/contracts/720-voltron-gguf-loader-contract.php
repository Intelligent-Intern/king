<?php
declare(strict_types=1);

require __DIR__ . '/../../voltron/src/GgufTensorLoader.php';

use King\Voltron\GgufTensorLoader;

echo "=== Voltron GGUF Loader Contract Test ===\n\n";

$passed = 0;
$failed = 0;

function loader_test_case(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function loader_assert_true(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

/**
 * @return callable
 */
function loader_env_guard(array $keys): callable
{
    $snapshot = [];
    foreach ($keys as $key) {
        $snapshot[$key] = getenv($key);
    }

    return static function () use ($snapshot): void {
        foreach ($snapshot as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . (string) $value);
        }
    };
}

/**
 * @return string normalized path
 */
function loader_call_resolve_path(array $params): string
{
    $ref = new ReflectionClass(GgufTensorLoader::class);
    $method = $ref->getMethod('resolveGgufPath');
    $method->setAccessible(true);
    $result = $method->invoke(null, $params);
    if (!is_string($result) || $result === '') {
        throw new RuntimeException('resolveGgufPath returned empty path.');
    }
    return str_replace('\\', '/', $result);
}

loader_test_case('Resolves GGUF from Ollama manifest/blob layout', function (): void {
    $restoreEnv = loader_env_guard([
        'VOLTRON_OLLAMA_MODELS_DIR',
        'VOLTRON_HF_CACHE_ROOT',
        'VOLTRON_HF_REPO',
        'VOLTRON_HF_FILENAME',
        'VOLTRON_GGUF_PATH',
        'VOLTRON_GGUF_OBJECT_ID',
    ]);

    $tmp = sys_get_temp_dir() . '/voltron-gguf-loader-contract-ollama-' . bin2hex(random_bytes(4));
    $manifestDir = $tmp . '/manifests/registry.ollama.ai/library/qwen2.5-coder';
    $blobDir = $tmp . '/blobs';
    @mkdir($manifestDir, 0777, true);
    @mkdir($blobDir, 0777, true);

    $digest = 'sha256:' . str_repeat('1', 64);
    $blobPath = $blobDir . '/sha256-' . str_repeat('1', 64);
    file_put_contents($blobPath, 'GGUF-contract-test');

    $manifestPath = $manifestDir . '/3b';
    $manifest = [
        'schemaVersion' => 2,
        'layers' => [
            [
                'mediaType' => 'application/vnd.ollama.image.model',
                'digest' => $digest,
                'size' => filesize($blobPath),
            ],
        ],
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));

    putenv('VOLTRON_OLLAMA_MODELS_DIR=' . $tmp);
    putenv('VOLTRON_HF_CACHE_ROOT');
    putenv('VOLTRON_HF_REPO');
    putenv('VOLTRON_HF_FILENAME');
    putenv('VOLTRON_GGUF_PATH');
    putenv('VOLTRON_GGUF_OBJECT_ID');

    try {
        $resolved = loader_call_resolve_path(['inference_model_name' => 'qwen2.5-coder:3b']);
        loader_assert_true(
            $resolved === str_replace('\\', '/', $blobPath),
            'Expected ollama blob path resolution.'
        );
    } finally {
        $restoreEnv();
    }
});

loader_test_case('Resolves GGUF from Hugging Face cache snapshot', function (): void {
    $restoreEnv = loader_env_guard([
        'VOLTRON_OLLAMA_MODELS_DIR',
        'VOLTRON_HF_CACHE_ROOT',
        'VOLTRON_HF_REPO',
        'VOLTRON_HF_FILENAME',
        'VOLTRON_GGUF_PATH',
        'VOLTRON_GGUF_OBJECT_ID',
    ]);

    $tmp = sys_get_temp_dir() . '/voltron-gguf-loader-contract-hf-' . bin2hex(random_bytes(4));
    $filePath = $tmp . '/models--foo--bar/snapshots/abc123/model.gguf';
    @mkdir(dirname($filePath), 0777, true);
    file_put_contents($filePath, 'GGUF-contract-test');

    putenv('VOLTRON_OLLAMA_MODELS_DIR=' . $tmp . '/missing');
    putenv('VOLTRON_HF_CACHE_ROOT=' . $tmp);
    putenv('VOLTRON_HF_REPO=foo/bar');
    putenv('VOLTRON_HF_FILENAME=model.gguf');
    putenv('VOLTRON_GGUF_PATH');
    putenv('VOLTRON_GGUF_OBJECT_ID');

    try {
        $resolved = loader_call_resolve_path(['inference_model_name' => 'qwen2.5-coder:3b']);
        loader_assert_true(
            $resolved === str_replace('\\', '/', $filePath),
            'Expected HF cache snapshot path resolution.'
        );
    } finally {
        $restoreEnv();
    }
});

loader_test_case('Q6_K row size matches GGUF block math', function (): void {
    $ref = new ReflectionClass(GgufTensorLoader::class);
    $instance = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('rowSizeForType');
    $method->setAccessible(true);
    $rowSize = $method->invoke($instance, GgufTensorLoader::TYPE_Q6_K, 2048);
    loader_assert_true((int) $rowSize === 1680, 'Q6_K row size should be 1680 for ne0=2048.');
});

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
