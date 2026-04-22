--TEST--
King HTTP/3 temporary Rust helpers are quarantined outside product bootstrap and expire into the removal issue
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$classification = require $testsDir . '/http3_rust_peer_classification.inc';
$strategy = require $testsDir . '/http3_peer_replacement_strategy.inc';

function king_http3_expiry_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

if (($strategy['strategy']['temporary_fixture_expiry_issue'] ?? null) !== '#Q-9') {
    king_http3_expiry_fail('replacement strategy does not hand temporary fixtures to #Q-9');
}

$issues = file_get_contents($root . '/ISSUES.md');
if (!is_string($issues) || $issues === '') {
    king_http3_expiry_fail('could not read ISSUES.md');
}

foreach ([
    '### #Q-9 Remove Quiche From Source, Scripts, And Docs',
    '- Fully remove Quiche as an active dependency.',
    '- [x] Remove or replace Quiche-specific build scripts, locks, and docs.',
] as $needle) {
    if (!str_contains($issues, $needle)) {
        king_http3_expiry_fail('expiry issue #Q-9 is missing required context: ' . $needle);
    }
}

$kindCounts = [
    'rust_test_peer' => 0,
    'cargo_manifest' => 0,
    'cargo_lock' => 0,
];

foreach ($classification as $path => $entry) {
    if (!is_array($entry)) {
        king_http3_expiry_fail("{$path} classification is malformed");
    }

    if (!is_file($root . '/' . $path)) {
        king_http3_expiry_fail("{$path} no longer exists but remains classified");
    }

    $kind = $entry['kind'] ?? null;
    if (!is_string($kind) || !array_key_exists($kind, $kindCounts)) {
        king_http3_expiry_fail("{$path} has invalid kind");
    }
    $kindCounts[$kind]++;

    if (($entry['temporary'] ?? null) !== true) {
        king_http3_expiry_fail("{$path} is not marked temporary");
    }

    if (($entry['product_bootstrap'] ?? null) !== false) {
        king_http3_expiry_fail("{$path} is not excluded from product bootstrap");
    }

    if (($entry['active_product_path'] ?? null) !== false) {
        king_http3_expiry_fail("{$path} is not excluded from the active product path");
    }

    if (($entry['expiry_issue'] ?? null) !== '#Q-9') {
        king_http3_expiry_fail("{$path} does not expire into #Q-9");
    }
}

if ($kindCounts !== ['rust_test_peer' => 5, 'cargo_manifest' => 1, 'cargo_lock' => 1]) {
    king_http3_expiry_fail('temporary Rust/Cargo inventory count drifted: ' . json_encode($kindCounts));
}

$activeHarnessFiles = [
    'extension/tests/http3_new_stack_skip.inc',
    'extension/tests/http3_test_helper/helper_binaries.inc',
    'extension/tests/http3_test_helper/ticket_server_and_capture.inc',
    'extension/tests/http3_test_helper/core_fixture_and_server.inc',
    'extension/tests/http3_server_wire_server.inc',
    'infra/scripts/prebuild-http3-test-helpers.sh',
];

foreach ($activeHarnessFiles as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || $source === '') {
        king_http3_expiry_fail("could not read {$path}");
    }

    foreach (['cargo build', 'command -v cargo', 'quiche/apps', 'quiche/target', '.rs', 'Cargo.toml', 'Cargo.lock'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            king_http3_expiry_fail("{$path} still uses temporary Rust/Cargo fixture path {$forbidden}");
        }
    }
}

if (!str_contains($issues, '- [x] Temporary Rust helpers are not product bootstrap and have an expiry issue.')) {
    king_http3_expiry_fail('ISSUES.md does not mark the temporary Rust helper expiry done');
}

echo "HTTP/3 temporary Rust/Cargo fixtures are quarantined outside product bootstrap and expire into #Q-9.\n";
?>
--EXPECT--
HTTP/3 temporary Rust/Cargo fixtures are quarantined outside product bootstrap and expire into #Q-9.
