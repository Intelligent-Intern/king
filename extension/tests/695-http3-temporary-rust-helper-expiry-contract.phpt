--TEST--
King HTTP/3 temporary Rust helpers are removed after C/LSQUIC replacement
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

if (($strategy['strategy']['removed_temporary_fixture_issue'] ?? null) !== '#Q-9') {
    king_http3_expiry_fail('replacement strategy does not record temporary fixture removal in #Q-9');
}

if (!is_array($classification) || $classification !== []) {
    king_http3_expiry_fail('temporary Rust/Cargo fixture classification must be empty after removal');
}

$readiness = file_get_contents($root . '/READYNESS_TRACKER.md');
if (!is_string($readiness) || $readiness === '') {
    king_http3_expiry_fail('could not read READYNESS_TRACKER.md');
}

foreach ([
    'Recent QUIC bootstrap closure:',
    'legacy Quiche bootstrap inputs',
    'unlocked Cargo retries',
    'temporary Rust/Cargo HTTP/3 fixtures are removed',
] as $needle) {
    if (!str_contains($readiness, $needle)) {
        king_http3_expiry_fail('temporary Rust/Cargo removal proof is missing required context: ' . $needle);
    }
}

$legacyFixturePaths = array_keys($strategy['completed_replacements'] ?? []);
sort($legacyFixturePaths);
if (count($legacyFixturePaths) !== 7) {
    king_http3_expiry_fail('completed replacement map does not record all removed temporary fixtures');
}

foreach ($legacyFixturePaths as $path) {
    if (is_file($root . '/' . $path)) {
        king_http3_expiry_fail("temporary Rust/Cargo fixture still exists: {$path}");
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $filename = $file->getFilename();
    if (str_ends_with($filename, '.rs') || $filename === 'Cargo.toml' || $filename === 'Cargo.lock') {
        $relative = str_replace($root . '/', '', $file->getPathname());
        king_http3_expiry_fail("temporary Rust/Cargo fixture remains in tests: {$relative}");
    }
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

if (!str_contains($readiness, 'Recent Quiche cleanup closure:')) {
    king_http3_expiry_fail('READYNESS_TRACKER.md does not record the Quiche cleanup closure');
}

echo "HTTP/3 temporary Rust/Cargo fixtures are removed; C/LSQUIC helpers remain active.\n";
?>
--EXPECT--
HTTP/3 temporary Rust/Cargo fixtures are removed; C/LSQUIC helpers remain active.
