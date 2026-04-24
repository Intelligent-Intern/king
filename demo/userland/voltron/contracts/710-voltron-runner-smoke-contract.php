<?php
declare(strict_types=1);

/**
 * Contract test: Voltron runner smoke prompts
 *
 * Tests:
 * 1. Runner returns non-empty response for general prompt
 * 2. Runner returns non-empty response for coding prompt
 * 3. Final payload provenance is King/Voltron
 */

require __DIR__ . '/../../voltron/src/VoltronRunner.php';

use King\Voltron\VoltronRunner;

echo "=== Voltron Runner Smoke Contract Test ===\n\n";

if (!function_exists('king_pipeline_orchestrator_run')) {
    echo "[SKIP] King extension is not loaded; run with -d extension=extension/modules/king.so\n";
    exit(0);
}

function voltron_contract_kernel_target_available(): bool
{
    $ggufPath = getenv('VOLTRON_GGUF_PATH');
    if (is_string($ggufPath) && trim($ggufPath) !== '' && is_file($ggufPath)) {
        return true;
    }

    $ggufObject = getenv('VOLTRON_GGUF_OBJECT_ID');
    return is_string($ggufObject) && trim($ggufObject) !== '';
}

if (!voltron_contract_kernel_target_available()) {
    echo "[SKIP] No GGUF source configured. Set VOLTRON_GGUF_PATH or VOLTRON_GGUF_OBJECT_ID.\n";
    exit(0);
}

$passed = 0;
$failed = 0;

function test_case(string $name, callable $fn): void
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

function assert_true(bool $value, string $message): void
{
    if (!$value) {
        throw new \RuntimeException($message);
    }
}

/**
 * @return array<string,mixed>
 */
function run_prompt(string $prompt, string $traceId): array
{
    $runner = new VoltronRunner(
        'qwen2.5-coder:3b',
        false,
        ['trace_id' => $traceId]
    );

    ob_start();
    try {
        return $runner->run($prompt);
    } finally {
        ob_end_clean();
    }
}

test_case('General prompt returns response + provenance', function (): void {
    $result = run_prompt('Explain AI', 'contract-general');
    $dag = $result['dag_result'] ?? [];
    assert_true(is_array($dag), 'dag_result should be an array');
    assert_true(is_string($dag['response'] ?? null) && trim((string) $dag['response']) !== '', 'response should be non-empty');
    assert_true(($dag['source_system'] ?? null) === 'king_voltron', 'source_system should be king_voltron');

    $prov = $dag['response_provenance'] ?? null;
    assert_true(is_array($prov), 'response_provenance should be an array');
    assert_true(($prov['source'] ?? null) === 'king_voltron_handler', 'provenance source mismatch');
    assert_true(($prov['tool'] ?? null) === 'voltron.execute_model_block', 'provenance tool mismatch');
    $kernel = $prov['kernel'] ?? null;
    assert_true(is_array($kernel), 'kernel provenance must be present');
    assert_true(($kernel['engine'] ?? null) === 'king_voltron_block_kernels', 'kernel engine mismatch');
});

test_case('Coding prompt returns response + provenance', function (): void {
    $result = run_prompt(
        'Write a Python function that checks whether a string is a palindrome.',
        'contract-coding'
    );
    $dag = $result['dag_result'] ?? [];
    assert_true(is_array($dag), 'dag_result should be an array');
    assert_true(is_string($dag['response'] ?? null) && trim((string) $dag['response']) !== '', 'response should be non-empty');
    assert_true(($dag['source_system'] ?? null) === 'king_voltron', 'source_system should be king_voltron');

    $prov = $dag['response_provenance'] ?? null;
    assert_true(is_array($prov), 'response_provenance should be an array');
    assert_true(($prov['source'] ?? null) === 'king_voltron_handler', 'provenance source mismatch');
    assert_true(($prov['tool'] ?? null) === 'voltron.execute_model_block', 'provenance tool mismatch');
    $kernel = $prov['kernel'] ?? null;
    assert_true(is_array($kernel), 'kernel provenance must be present');
    assert_true(($kernel['engine'] ?? null) === 'king_voltron_block_kernels', 'kernel engine mismatch');
});

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
