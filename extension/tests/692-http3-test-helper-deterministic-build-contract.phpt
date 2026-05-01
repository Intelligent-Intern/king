--TEST--
King HTTP/3 C test helpers build deterministically into ignored cache outputs
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v bash 2>/dev/null')) === '') {
    echo 'skip bash not available';
}
if (trim((string) shell_exec('command -v cc 2>/dev/null')) === '') {
    echo 'skip cc not available';
}
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = $root . '/infra/scripts/build-http3-test-helpers.sh';
$strategy = require __DIR__ . '/http3_peer_replacement_strategy.inc';

function king_http3_build_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

function king_http3_run(array $env, array $command, ?array &$output = null): int
{
    $prefix = '';
    foreach ($env as $key => $value) {
        $prefix .= $key . '=' . escapeshellarg($value) . ' ';
    }

    $line = $prefix . implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1';
    $output = [];
    exec($line, $output, $status);

    return $status;
}

if (!is_file($script) || !is_executable($script)) {
    king_http3_build_fail('build script missing or not executable');
}

$scriptSource = file_get_contents($script);
if (!is_string($scriptSource) || $scriptSource === '') {
    king_http3_build_fail('could not read build script');
}

foreach ([
    'set -euo pipefail',
    'SOURCE_DATE_EPOCH',
    '-ffile-prefix-map=${ROOT_DIR}=.',
    '-fdebug-prefix-map=${ROOT_DIR}=.',
    '-Werror=date-time',
    'mktemp -d',
    'trap',
    'manifest.sha256',
    '.cache/king/http3-test-helpers',
    'bootstrap-lsquic.sh',
    '--verify-lock',
] as $needle) {
    if (!str_contains($scriptSource, $needle)) {
        king_http3_build_fail('build script missing deterministic contract needle: ' . $needle);
    }
}

foreach (['cargo build', 'rustc', 'quiche/target', 'apps/src/bin'] as $forbidden) {
    if (str_contains($scriptSource, $forbidden)) {
        king_http3_build_fail('build script still references forbidden helper build path: ' . $forbidden);
    }
}

$scriptFromStrategy = $root . '/' . ($strategy['strategy']['future_build_script'] ?? '');
if ($scriptFromStrategy !== $script) {
    king_http3_build_fail('replacement strategy does not point at the deterministic build script');
}

$status = king_http3_run([], ['bash', '-n', $script], $output);
if ($status !== 0) {
    king_http3_build_fail("bash syntax check failed:\n" . implode("\n", $output));
}

$status = king_http3_run([], [$script, '--verify-plan'], $output);
if ($status !== 0 || !str_contains(implode("\n", $output), 'cache-scoped')) {
    king_http3_build_fail("verify-plan failed:\n" . implode("\n", $output));
}

$caseRoot = $root . '/.cache/king/http3-test-helper-contract-' . getmypid();
$sourceDir = $caseRoot . '/src';
$outputDir = $caseRoot . '/out';
@mkdir($sourceDir, 0777, true);

$helperSources = [
    'abort_client.c' => 'king-http3-abort-client',
    'delayed_body_client.c' => 'king-http3-delayed-body-client',
    'failure_peer.c' => 'king-http3-failure-peer',
    'multi_peer.c' => 'king-http3-multi-peer',
    'ticket_server.c' => 'king-http3-ticket-server',
];

foreach ($helperSources as $sourceName => $helperName) {
    $source = <<<C
#include <stdio.h>
int main(void) {
    puts("{$helperName}");
    return 0;
}
C;
    if (file_put_contents($sourceDir . '/' . $sourceName, $source) !== strlen($source)) {
        king_http3_build_fail('failed to write fixture source ' . $sourceName);
    }
}

$env = [
    'KING_HTTP3_TEST_HELPER_SOURCE_DIR' => $sourceDir,
    'KING_HTTP3_TEST_HELPER_OUTPUT_DIR' => $outputDir,
    'SOURCE_DATE_EPOCH' => '1700000000',
    'CC' => 'cc',
];

try {
    $status = king_http3_run($env, [$script, '--print-plan'], $output);
    $plan = implode("\n", $output);
    if ($status !== 0 || !str_contains($plan, 'source_dir=' . $sourceDir) || !str_contains($plan, 'manifest=' . $outputDir . '/manifest.sha256')) {
        king_http3_build_fail("print-plan did not include the fixture paths:\n" . $plan);
    }

    $status = king_http3_run($env, [$script], $output);
    if ($status !== 0) {
        king_http3_build_fail("first helper build failed:\n" . implode("\n", $output));
    }

    $manifest = $outputDir . '/manifest.sha256';
    if (!is_file($manifest)) {
        king_http3_build_fail('first helper build did not write manifest.sha256');
    }
    $firstManifest = file_get_contents($manifest);
    if (!is_string($firstManifest) || substr_count(trim($firstManifest), "\n") !== 4) {
        king_http3_build_fail('first helper build manifest does not contain five helper hashes');
    }

    foreach ($helperSources as $sourceName => $helperName) {
        $binary = $outputDir . '/bin/' . $helperName;
        if (!is_file($binary) || !is_executable($binary)) {
            king_http3_build_fail('missing executable helper binary ' . $helperName);
        }
    }

    $status = king_http3_run($env, [$script], $output);
    if ($status !== 0) {
        king_http3_build_fail("second helper build failed:\n" . implode("\n", $output));
    }
    $secondManifest = file_get_contents($manifest);
    if ($firstManifest !== $secondManifest) {
        king_http3_build_fail("helper manifest changed across identical deterministic builds\nfirst={$firstManifest}\nsecond={$secondManifest}");
    }

    $status = king_http3_run([], ['git', '-C', $root, 'status', '--short', '--untracked-files=all', '--', '.cache/king/http3-test-helper-contract-' . getmypid()], $output);
    if ($status !== 0 || $output !== []) {
        king_http3_build_fail("helper build output is visible to git:\n" . implode("\n", $output));
    }

    echo "HTTP/3 helper deterministic build contract passed.\n";
} finally {
    king_http3_run([], ['rm', '-rf', $caseRoot], $cleanupOutput);
}
?>
--EXPECT--
HTTP/3 helper deterministic build contract passed.
