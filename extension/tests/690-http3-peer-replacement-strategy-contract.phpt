--TEST--
King HTTP/3 test peer strategy uses C/LSQUIC helpers and no remaining Rust/Cargo fixtures
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$classification = require $testsDir . '/http3_rust_peer_classification.inc';
$strategy = require $testsDir . '/http3_peer_replacement_strategy.inc';

function king_http3_strategy_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

$header = $strategy['strategy'] ?? null;
if (!is_array($header)) {
    king_http3_strategy_fail('strategy header missing');
}

$expectedHeader = [
    'selected_path' => 'c_helper',
    'uses_king_owned_listeners_where_equivalent' => true,
    'uses_ci_binary_artifacts' => false,
    'uses_rust_or_cargo_bootstrap' => false,
    'uses_quiche_workspace' => false,
    'product_bootstrap' => false,
    'dependency_lock' => 'infra/scripts/lsquic-bootstrap.lock',
    'expiry_issue' => '#Q-8',
    'removed_temporary_fixture_issue' => '#Q-9',
];

foreach ($expectedHeader as $field => $expected) {
    if (($header[$field] ?? null) !== $expected) {
        king_http3_strategy_fail("strategy {$field} is not " . var_export($expected, true));
    }
}

foreach (['id', 'summary', 'future_build_script', 'future_source_root', 'future_output_root'] as $field) {
    if (!isset($header[$field]) || !is_string($header[$field]) || trim($header[$field]) === '') {
        king_http3_strategy_fail("strategy {$field} missing");
    }
}

if (!is_file($root . '/' . $header['dependency_lock'])) {
    king_http3_strategy_fail('selected dependency lock is missing');
}

$buildScriptSource = file_get_contents($root . '/' . $header['future_build_script']);
if (!is_string($buildScriptSource) || $buildScriptSource === '') {
    king_http3_strategy_fail('could not read selected helper build script');
}

if (!is_array($classification) || $classification !== []) {
    king_http3_strategy_fail('temporary Rust/Cargo fixture classification must be empty');
}

$activeHelpers = $strategy['active_helpers'] ?? null;
if (!is_array($activeHelpers) || $activeHelpers === []) {
    king_http3_strategy_fail('active helper map missing');
}

$completedReplacements = $strategy['completed_replacements'] ?? null;
if (!is_array($completedReplacements) || count($completedReplacements) !== 7) {
    king_http3_strategy_fail('completed replacement map does not record all removed fixtures');
}

foreach ($completedReplacements as $legacyPath => $helper) {
    if (is_file($root . '/' . $legacyPath)) {
        king_http3_strategy_fail("legacy Rust/Cargo fixture still exists: {$legacyPath}");
    }

    if (!is_string($helper) || !isset($activeHelpers[$helper])) {
        king_http3_strategy_fail("{$legacyPath} does not map to an active helper");
    }
}

$allowedPaths = [
    'c_lsquic_client_helper' => true,
    'c_lsquic_server_peer' => true,
    'deterministic_shell_build_driver' => true,
    'pinned_lsquic_dependency_lock' => true,
];

$coveredCapabilities = [];
foreach ($activeHelpers as $helper => $metadata) {
    if (!is_string($helper) || trim($helper) === '') {
        king_http3_strategy_fail('active helper has invalid id');
    }

    if (!is_array($metadata)) {
        king_http3_strategy_fail("{$helper} metadata is not an object");
    }

    foreach (['target', 'path', 'capabilities'] as $field) {
        if (!array_key_exists($field, $metadata)) {
            king_http3_strategy_fail("{$helper} has no {$field}");
        }
    }

    if (!is_string($metadata['target']) || trim($metadata['target']) === '') {
        king_http3_strategy_fail("{$helper} target missing");
    }

    $sourceName = basename($metadata['target']);
    $isCompiledHelper = in_array($metadata['path'], ['c_lsquic_client_helper', 'c_lsquic_server_peer'], true);
    if ($isCompiledHelper && !str_contains($buildScriptSource, "{$helper}:{$sourceName}")) {
        king_http3_strategy_fail("{$helper} target is not listed in the deterministic helper build plan");
    }

    if (!is_string($metadata['path']) || !isset($allowedPaths[$metadata['path']])) {
        king_http3_strategy_fail("{$helper} path is invalid");
    }

    if (!is_array($metadata['capabilities'])) {
        king_http3_strategy_fail("{$helper} capabilities are invalid");
    }

    if (str_contains($metadata['target'], 'quiche') || str_contains($metadata['target'], 'Cargo')) {
        king_http3_strategy_fail("{$helper} target still points at Quiche/Cargo");
    }

    foreach ($metadata['capabilities'] as $capability) {
        if (!is_string($capability) || trim($capability) === '') {
            king_http3_strategy_fail("{$helper} has an invalid capability");
        }
        $coveredCapabilities[$capability] = true;
    }
}

$requiredCapabilities = $strategy['required_capabilities'] ?? null;
if (!is_array($requiredCapabilities) || $requiredCapabilities === []) {
    king_http3_strategy_fail('required capabilities missing');
}

sort($requiredCapabilities);
$covered = array_keys($coveredCapabilities);
sort($covered);
if ($requiredCapabilities !== $covered) {
    king_http3_strategy_fail(
        "replacement strategy does not preserve the required behavior inventory\n" .
        'required=' . json_encode($requiredCapabilities, JSON_UNESCAPED_SLASHES) . "\n" .
        'covered=' . json_encode($covered, JSON_UNESCAPED_SLASHES)
    );
}

echo 'HTTP/3 peer replacement strategy: ' . $header['id'] . "\n";
?>
--EXPECT--
HTTP/3 peer replacement strategy: tracked_c_lsquic_test_helpers
