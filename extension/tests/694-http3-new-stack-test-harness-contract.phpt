--TEST--
King HTTP/3 behavior tests prove the LSQUIC/C-helper stack without Quiche or Cargo bootstrap
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$matrix = require $testsDir . '/http3_behavior_preservation_matrix.inc';

function king_http3_new_stack_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

function king_http3_new_stack_skipif(string $path, string $source): string
{
    if (!preg_match("/--SKIPIF--\n(.*?)\n--FILE--/s", $source, $matches)) {
        king_http3_new_stack_fail("{$path} has no parseable SKIPIF block");
    }

    return $matches[1];
}

$testPaths = [];
foreach (($matrix['behaviors'] ?? []) as $behavior => $entry) {
    foreach (($entry['tests'] ?? []) as $test) {
        $path = $test['path'] ?? null;
        if (!is_string($path) || $path === '') {
            king_http3_new_stack_fail("{$behavior} has invalid test path");
        }

        $testPaths[$path] = true;
    }
}

if (count($testPaths) !== 16) {
    king_http3_new_stack_fail('new-stack proof must cover the 16 preserved behavior tests');
}

$helperBacked = 0;
$runtimeOnly = 0;
foreach (array_keys($testPaths) as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || $source === '') {
        king_http3_new_stack_fail("could not read {$path}");
    }

    $skipif = king_http3_new_stack_skipif($path, $source);
    foreach (['KING_QUICHE_LIBRARY', 'KING_QUICHE_SERVER', 'command -v cargo'] as $forbidden) {
        if (str_contains($skipif, $forbidden)) {
            king_http3_new_stack_fail("{$path} still has legacy skip gate {$forbidden}");
        }
    }

    foreach ([
        "require __DIR__ . '/http3_new_stack_skip.inc';",
        'king_http3_skipif_require_lsquic_runtime',
    ] as $required) {
        if (!str_contains($skipif, $required)) {
            king_http3_new_stack_fail("{$path} skipif missing {$required}");
        }
    }

    $needsFixtureCertificate = str_contains($source, 'king_http3_create_fixture');
    if ($needsFixtureCertificate && !str_contains($skipif, 'king_http3_skipif_require_openssl')) {
        king_http3_new_stack_fail("{$path} creates TLS fixtures without an openssl skip gate");
    }

    $usesHelperBinary = str_contains($source, 'king_http3_start_failure_peer')
        || str_contains($source, 'king_http3_start_multi_peer')
        || str_contains($source, 'king_http3_start_ticket_test_server');
    if ($usesHelperBinary) {
        $helperBacked++;
        if (!str_contains($skipif, 'king_http3_skipif_require_c_helpers')) {
            king_http3_new_stack_fail("{$path} uses helper binaries without the C-helper build gate");
        }
    } else {
        $runtimeOnly++;
    }
}

if ($helperBacked < 12 || $runtimeOnly < 2) {
    king_http3_new_stack_fail('new-stack test split lost helper-backed or runtime-only coverage');
}

$helperSources = [
    'extension/tests/http3_new_stack_skip.inc',
    'extension/tests/http3_test_helper/helper_binaries.inc',
    'extension/tests/http3_test_helper/ticket_server_and_capture.inc',
    'extension/tests/http3_test_helper/core_fixture_and_server.inc',
    'extension/tests/http3_server_wire_server.inc',
    'infra/scripts/prebuild-http3-test-helpers.sh',
];

foreach ($helperSources as $path) {
    $source = file_get_contents($root . '/' . $path);
    if (!is_string($source) || $source === '') {
        king_http3_new_stack_fail("could not read {$path}");
    }

    foreach (['cargo build', 'quiche/apps', 'quiche/target', 'KING_QUICHE_SERVER', 'KING_QUICHE_LIBRARY'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            king_http3_new_stack_fail("{$path} still references legacy bootstrap path {$forbidden}");
        }
    }
}

$helperBinaries = (string) file_get_contents($root . '/extension/tests/http3_test_helper/helper_binaries.inc');
foreach ([
    'build-http3-test-helpers.sh',
    '.cache/king/http3-test-helpers',
    'king-http3-failure-peer',
    'king-http3-delayed-body-client',
    'king-http3-abort-client',
    'king-http3-multi-peer',
] as $needle) {
    if (!str_contains($helperBinaries, $needle)) {
        king_http3_new_stack_fail('helper binary resolver missing ' . $needle);
    }
}

$ticketServer = (string) file_get_contents($root . '/extension/tests/http3_test_helper/ticket_server_and_capture.inc');
if (!str_contains($ticketServer, "king_http3_helper_binary('king-http3-ticket-server')")) {
    king_http3_new_stack_fail('ticket server helper does not resolve through the C-helper build output');
}

$coreServer = (string) file_get_contents($root . '/extension/tests/http3_test_helper/core_fixture_and_server.inc');
foreach (['http3_server_wire_helper.inc', 'king_http3_server_wire_start_server', "'static-root'"] as $needle) {
    if (!str_contains($coreServer, $needle)) {
        king_http3_new_stack_fail('shared test server does not use the King LSQUIC wire listener: ' . $needle);
    }
}

$readiness = (string) file_get_contents($root . '/READYNESS_TRACKER.md');
if (!str_contains($readiness, 'Recent LSQUIC migration sprint closure: the active HTTP/3 product path targets LSQUIC plus BoringSSL instead of Quiche')) {
    king_http3_new_stack_fail('READYNESS_TRACKER.md does not record the new-stack proof');
}

echo "HTTP/3 behavior harness proves LSQUIC/C-helper gates across 16 tests without Quiche or Cargo bootstrap.\n";
?>
--EXPECT--
HTTP/3 behavior harness proves LSQUIC/C-helper gates across 16 tests without Quiche or Cargo bootstrap.
