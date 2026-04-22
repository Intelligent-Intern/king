--TEST--
King HTTP/3 skip rules cannot count missing new stack as success
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$matrix = require $testsDir . '/http3_behavior_preservation_matrix.inc';
$audit = require $testsDir . '/http3_skip_rule_audit.inc';

function king_http3_skip_audit_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

function king_http3_extract_skipif(string $path, string $source): string
{
    if (!preg_match("/--SKIPIF--\n(.*?)\n--FILE--/s", $source, $matches)) {
        king_http3_skip_audit_fail("{$path} has no parseable SKIPIF block");
    }

    return $matches[1];
}

if (($audit['issue'] ?? null) !== '#Q-8') {
    king_http3_skip_audit_fail('skip audit is not bound to #Q-8');
}

if (($audit['scope'] ?? null) !== 'http3_behavior_preservation_matrix') {
    king_http3_skip_audit_fail('skip audit is not scoped to the HTTP/3 behavior matrix');
}

if (($audit['missing_new_stack_counts_as_success'] ?? true) !== false) {
    king_http3_skip_audit_fail('audit root allows a missing new stack to count as success');
}

$legacySkipLiterals = $audit['legacy_skip_literals'] ?? null;
if (!is_array($legacySkipLiterals) || $legacySkipLiterals === []) {
    king_http3_skip_audit_fail('audit has no legacy skip literals');
}

$requiredFutureGates = array_fill_keys($audit['required_future_gates'] ?? [], true);
foreach (['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC', 'infra/scripts/build-http3-test-helpers.sh build'] as $gate) {
    if (!isset($requiredFutureGates[$gate])) {
        king_http3_skip_audit_fail('audit missing required future gate: ' . $gate);
    }
}

$matrixTests = [];
foreach (($matrix['behaviors'] ?? []) as $behavior => $entry) {
    foreach (($entry['tests'] ?? []) as $test) {
        $path = $test['path'] ?? null;
        if (!is_string($path) || $path === '') {
            king_http3_skip_audit_fail("{$behavior} contains an invalid test path");
        }

        $matrixTests[$path] = true;
    }
}

$auditTests = $audit['tests'] ?? null;
if (!is_array($auditTests)) {
    king_http3_skip_audit_fail('audit missing tests map');
}

$matrixPaths = array_keys($matrixTests);
$auditPaths = array_keys($auditTests);
sort($matrixPaths);
sort($auditPaths);
if ($matrixPaths !== $auditPaths) {
    king_http3_skip_audit_fail(
        "skip audit does not match the behavior matrix\n" .
        'matrix=' . json_encode($matrixPaths, JSON_UNESCAPED_SLASHES) . "\n" .
        'audit=' . json_encode($auditPaths, JSON_UNESCAPED_SLASHES)
    );
}

if (count($auditPaths) !== 16) {
    king_http3_skip_audit_fail('skip audit must cover the 16 preserved HTTP/3 behavior tests');
}

$legacyBlockerCount = 0;
$helperGateCount = 0;
foreach ($auditTests as $path => $entry) {
    if (!is_array($entry)) {
        king_http3_skip_audit_fail("{$path} has malformed audit entry");
    }

    $fullPath = $root . '/' . $path;
    if (!is_file($fullPath)) {
        king_http3_skip_audit_fail("{$path} does not exist");
    }

    $source = file_get_contents($fullPath);
    if (!is_string($source) || $source === '') {
        king_http3_skip_audit_fail("{$path} could not be read");
    }
    $skipif = king_http3_extract_skipif($path, $source);

    $declaredLegacy = $entry['legacy_skip_literals'] ?? null;
    if (!is_array($declaredLegacy)) {
        king_http3_skip_audit_fail("{$path} has invalid declared legacy skip literals");
    }

    $legacyInSource = [];
    foreach ($legacySkipLiterals as $literal) {
        if (!is_string($literal) || $literal === '') {
            king_http3_skip_audit_fail('audit contains an invalid legacy skip literal');
        }

        if (str_contains($skipif, $literal)) {
            $legacyInSource[] = $literal;
        }
    }

    sort($legacyInSource);
    $declaredLegacySorted = $declaredLegacy;
    sort($declaredLegacySorted);
    if ($legacyInSource !== $declaredLegacySorted) {
        king_http3_skip_audit_fail(
            "{$path} legacy skip declaration is stale\n" .
            'source=' . json_encode($legacyInSource, JSON_UNESCAPED_SLASHES) . "\n" .
            'audit=' . json_encode($declaredLegacySorted, JSON_UNESCAPED_SLASHES)
        );
    }

    if ($legacyInSource !== []) {
        $legacyBlockerCount++;
        if (($entry['blocks_done'] ?? false) !== true) {
            king_http3_skip_audit_fail("{$path} does not block the final done checkbox");
        }
    }

    if (($entry['missing_new_stack_counts_as_success'] ?? true) !== false) {
        king_http3_skip_audit_fail("{$path} allows a missing new stack to count as success");
    }

    $futureGates = $entry['future_gates'] ?? null;
    if (!is_array($futureGates) || $futureGates === []) {
        king_http3_skip_audit_fail("{$path} has no future new-stack gate");
    }
    foreach ($futureGates as $gate) {
        if (!isset($requiredFutureGates[$gate])) {
            king_http3_skip_audit_fail("{$path} references an unknown future gate: {$gate}");
        }
    }

    foreach (['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC'] as $gate) {
        if (!in_array($gate, $futureGates, true)) {
            king_http3_skip_audit_fail("{$path} lacks runtime replacement gate {$gate}");
        }
    }

    if (!str_contains($skipif, 'king_http3_skipif_require_lsquic_runtime')) {
        king_http3_skip_audit_fail("{$path} skipif does not require the LSQUIC runtime");
    }

    if (in_array('infra/scripts/build-http3-test-helpers.sh build', $futureGates, true)) {
        $helperGateCount++;
        if (!str_contains($skipif, 'king_http3_skipif_require_c_helpers')) {
            king_http3_skip_audit_fail("{$path} skipif does not require repo-owned C helpers");
        }
    }
}

if ($helperGateCount < 12) {
    king_http3_skip_audit_fail('helper-backed behavior coverage unexpectedly collapsed');
}

$issues = file_get_contents($root . '/ISSUES.md');
if (!is_string($issues) || $issues === '') {
    king_http3_skip_audit_fail('could not read ISSUES.md');
}

$doneCheckbox = preg_quote($audit['done_checkbox'] ?? '', '/');
if ($legacyBlockerCount > 0 && preg_match('/- \[x\]\s+' . $doneCheckbox . '/', $issues) === 1) {
    king_http3_skip_audit_fail('final HTTP/3 done checkbox is checked while legacy skip blockers remain');
}

if ($legacyBlockerCount === 0 && preg_match('/- \[ \]\s+' . $doneCheckbox . '/', $issues) === 1) {
    king_http3_skip_audit_fail('final HTTP/3 done checkbox is still open after legacy skip blockers were removed');
}

echo "HTTP/3 skip audit allows final success with {$legacyBlockerCount} legacy-gated behavior tests.\n";
?>
--EXPECT--
HTTP/3 skip audit allows final success with 0 legacy-gated behavior tests.
