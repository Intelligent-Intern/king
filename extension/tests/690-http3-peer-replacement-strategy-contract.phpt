--TEST--
King HTTP/3 test peer replacement strategy is C/LSQUIC, repo-owned, and non-Cargo
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

$replacements = $strategy['replacements'] ?? null;
if (!is_array($replacements)) {
    king_http3_strategy_fail('replacement map missing');
}

$classified = array_keys($classification);
sort($classified);
$replacementKeys = array_keys($replacements);
sort($replacementKeys);
if ($classified !== $replacementKeys) {
    king_http3_strategy_fail(
        "replacement map does not cover the classified temporary fixtures\n" .
        'classified=' . json_encode($classified, JSON_UNESCAPED_SLASHES) . "\n" .
        'replacements=' . json_encode($replacementKeys, JSON_UNESCAPED_SLASHES)
    );
}

$allowedPaths = [
    'c_lsquic_client_helper' => true,
    'c_lsquic_server_peer' => true,
    'deterministic_shell_build_driver' => true,
    'pinned_lsquic_dependency_lock' => true,
];

$coveredCapabilities = [];
foreach ($replacements as $source => $replacement) {
    if (!is_array($replacement)) {
        king_http3_strategy_fail("{$source} replacement is not an object");
    }

    foreach (['target', 'helper', 'path', 'capabilities'] as $field) {
        if (!array_key_exists($field, $replacement)) {
            king_http3_strategy_fail("{$source} replacement has no {$field}");
        }
    }

    if (!is_string($replacement['target']) || trim($replacement['target']) === '') {
        king_http3_strategy_fail("{$source} replacement target missing");
    }

    if (!is_string($replacement['helper']) || trim($replacement['helper']) === '') {
        king_http3_strategy_fail("{$source} replacement helper missing");
    }

    if (!is_string($replacement['path']) || !isset($allowedPaths[$replacement['path']])) {
        king_http3_strategy_fail("{$source} replacement path is invalid");
    }

    if (!is_array($replacement['capabilities'])) {
        king_http3_strategy_fail("{$source} replacement capabilities are invalid");
    }

    if (str_contains($replacement['target'], 'quiche') || str_contains($replacement['target'], 'Cargo')) {
        king_http3_strategy_fail("{$source} replacement target still points at Quiche/Cargo");
    }

    foreach ($replacement['capabilities'] as $capability) {
        if (!is_string($capability) || trim($capability) === '') {
            king_http3_strategy_fail("{$source} replacement has an invalid capability");
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
