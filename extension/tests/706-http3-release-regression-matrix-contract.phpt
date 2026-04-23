--TEST--
King HTTP/3 release regression matrix maps #Q-11 to pinned contract evidence
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$matrix = require $testsDir . '/http3_release_regression_matrix.inc';

function king_http3_release_matrix_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

$requiredByIssue = [
    'client_one_shot_request_response',
    'oo_http3_client_exception_matrix',
    'server_one_shot_listener',
    'session_ticket_and_zero_rtt',
    'stream_reset_stop_cancel_timeout',
    'loss_retransmit_congestion_flow_soak',
    'websocket_over_http3',
    'performance_baseline_documented',
];

if (($matrix['issue'] ?? null) !== '#Q-11') {
    king_http3_release_matrix_fail('release regression matrix is not bound to #Q-11');
}

$checklist = $matrix['checklist'] ?? null;
if (!is_array($checklist)) {
    king_http3_release_matrix_fail('release regression matrix missing checklist');
}

$actualChecklist = array_keys($checklist);
sort($actualChecklist);
$expectedChecklist = $requiredByIssue;
sort($expectedChecklist);
if ($actualChecklist !== $expectedChecklist) {
    king_http3_release_matrix_fail(
        "release regression matrix drifted from the sprint checklist\n" .
        'expected=' . json_encode($expectedChecklist, JSON_UNESCAPED_SLASHES) . "\n" .
        'actual=' . json_encode($actualChecklist, JSON_UNESCAPED_SLASHES)
    );
}

$uniqueTests = [];
$documentedPendingItems = [];
foreach ($requiredByIssue as $item) {
    $entry = $checklist[$item] ?? null;
    if (!is_array($entry)) {
        king_http3_release_matrix_fail("{$item} checklist entry missing");
    }
    if (!is_string($entry['label'] ?? null) || $entry['label'] === '') {
        king_http3_release_matrix_fail("{$item} has no label");
    }

    foreach (($entry['tests'] ?? []) as $test) {
        if (!is_array($test)) {
            king_http3_release_matrix_fail("{$item} has malformed test entry");
        }

        $path = $test['path'] ?? null;
        if (!is_string($path) || !str_starts_with($path, 'extension/tests/') || !str_ends_with($path, '.phpt')) {
            king_http3_release_matrix_fail("{$item} has invalid test path");
        }

        $fullPath = $root . '/' . $path;
        if (!is_file($fullPath)) {
            king_http3_release_matrix_fail("{$item} references missing test {$path}");
        }

        $source = file_get_contents($fullPath);
        if (!is_string($source) || $source === '') {
            king_http3_release_matrix_fail("{$item} could not read {$path}");
        }

        foreach (($test['needles'] ?? []) as $needle) {
            if (!is_string($needle) || $needle === '') {
                king_http3_release_matrix_fail("{$item} has invalid assertion needle for {$path}");
            }
            if (!str_contains($source, $needle)) {
                king_http3_release_matrix_fail("{$item} test {$path} is missing assertion needle: {$needle}");
            }
        }

        $uniqueTests[$path] = true;
    }

    foreach (($entry['documents'] ?? []) as $document) {
        if (!is_array($document)) {
            king_http3_release_matrix_fail("{$item} has malformed document entry");
        }

        $path = $document['path'] ?? null;
        if (!is_string($path) || $path === '' || str_starts_with($path, '/')) {
            king_http3_release_matrix_fail("{$item} has invalid document path");
        }

        $fullPath = $root . '/' . $path;
        if (!is_file($fullPath)) {
            king_http3_release_matrix_fail("{$item} references missing document {$path}");
        }

        $source = file_get_contents($fullPath);
        foreach (($document['needles'] ?? []) as $needle) {
            if (!is_string($needle) || $needle === '') {
                king_http3_release_matrix_fail("{$item} has invalid document needle for {$path}");
            }
            if (!str_contains($source, $needle)) {
                king_http3_release_matrix_fail("{$item} document {$path} is missing needle: {$needle}");
            }
        }
    }

    if (($entry['status'] ?? null) === 'pending_measurement') {
        $documentedPendingItems[] = $item;
    } elseif (($entry['tests'] ?? []) === []) {
        king_http3_release_matrix_fail("{$item} has no pinned PHPT evidence");
    }
}

if (count($uniqueTests) < 20) {
    king_http3_release_matrix_fail('release regression matrix collapsed below the expected HTTP/3 breadth');
}

if ($documentedPendingItems !== ['performance_baseline_documented']) {
    king_http3_release_matrix_fail('only the measured performance baseline may be pending in #Q-11');
}

foreach ([
    'extension/tests/488-http3-long-duration-soak-contract.phpt',
    'extension/tests/682-server-websocket-http3-onwire-honesty-contract.phpt',
] as $mustKeep) {
    if (!isset($uniqueTests[$mustKeep])) {
        king_http3_release_matrix_fail("required regression proof missing {$mustKeep}");
    }
}

echo 'HTTP/3 #Q-11 release matrix: ' . count($requiredByIssue) . ' checklist items, ' . count($uniqueTests) . " PHPTs.\n";
?>
--EXPECT--
HTTP/3 #Q-11 release matrix: 8 checklist items, 22 PHPTs.
