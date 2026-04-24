<?php
declare(strict_types=1);

echo "=== Voltron Native GGUF Contract Test ===\n\n";

$passed = 0;
$failed = 0;

function native_gguf_test(string $name, callable $fn): void
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

function native_gguf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

native_gguf_test('king_native_gguf_tensor_scan is available', function (): void {
    native_gguf_assert(
        function_exists('king_native_gguf_tensor_scan'),
        'Expected native GGUF scan API to be exposed by king.so.'
    );
});

native_gguf_test('native F32 projection returns expected row dot products', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'king-gguf-f32-');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file.');
    }

    try {
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            throw new RuntimeException('Failed to open temp file.');
        }

        fwrite($fh, pack('g*', 1.0, 2.0, 3.0, 4.0));
        fclose($fh);

        $tensor = [
            'absolute_offset' => 0,
            'row_count' => 2,
            'row_size' => 8,
            'ne0' => 2,
            'type' => 0,
        ];

        $result = king_native_gguf_tensor_scan($tmp, $tensor, [10.0, 1.0]);
        native_gguf_assert(is_array($result), 'Expected array result from native scan.');
        native_gguf_assert(count($result) === 2, 'Expected two projected rows.');
        native_gguf_assert(abs(((float) $result[0]) - 12.0) < 0.0001, 'Unexpected first row score.');
        native_gguf_assert(abs(((float) $result[1]) - 34.0) < 0.0001, 'Unexpected second row score.');
    } finally {
        @unlink($tmp);
    }
});

native_gguf_test('native top-k scan preserves row ids', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'king-gguf-topk-');
    if ($tmp === false) {
        throw new RuntimeException('Failed to create temp file.');
    }

    try {
        $fh = fopen($tmp, 'wb');
        if (!is_resource($fh)) {
            throw new RuntimeException('Failed to open temp file.');
        }

        fwrite($fh, pack('g*', 1.0, 0.0, 5.0, 0.0, 3.0, 0.0));
        fclose($fh);

        $tensor = [
            'absolute_offset' => 0,
            'row_count' => 3,
            'row_size' => 8,
            'ne0' => 2,
            'type' => 0,
        ];

        $result = king_native_gguf_tensor_scan($tmp, $tensor, [2.0, 1.0], ['top_k' => 2]);
        native_gguf_assert(is_array($result), 'Expected top-k result array.');
        native_gguf_assert(array_keys($result) === [1, 2], 'Expected top-k rows [1, 2] in descending score order.');
    } finally {
        @unlink($tmp);
    }
});

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
