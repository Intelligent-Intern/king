<?php
declare(strict_types=1);

function king_release_gate_usage(): never
{
    fwrite(STDERR, <<<TXT
Usage: bin/king-inference-release-gate [options]

Options:
  --matrix PATH              JSON report from bin/king-inference-verify-matrix.
  --golden PATH              JSON report from bin/king-openai-golden-prompts.
  --baseline PATH            JSON report from bin/king-openai-baseline.
  --claims PATH              Optional structured release-claim JSON.
  --min-golden-cases N       Minimum deterministic prompt cases. Default: 100.
  --json                     Print JSON report only.

The gate does not run inference. It evaluates existing evidence reports and
blocks release-ready wording when evidence is missing, skipped, or failed.
TXT);
    exit(64);
}

function king_release_gate_option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = $name . '=';
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        if ($argv[$i] === $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($argv[$i], $prefix)) {
            return substr($argv[$i], strlen($prefix));
        }
    }
    return $default;
}

function king_release_gate_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function king_release_gate_int(array $argv, string $name, int $default, int $min): int
{
    $value = king_release_gate_option($argv, $name);
    if (!is_string($value) || preg_match('/^\d+$/', $value) !== 1) {
        return $default;
    }
    return max($min, (int) $value);
}

function king_release_gate_read_json(string $label, string $path): array
{
    if ($path === '') {
        return ['label' => $label, 'path' => '', 'present' => false, 'json' => null, 'reason' => 'path_not_configured'];
    }
    if (!is_file($path) || !is_readable($path)) {
        return ['label' => $label, 'path' => $path, 'present' => false, 'json' => null, 'reason' => 'file_not_readable'];
    }

    try {
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return ['label' => $label, 'path' => $path, 'present' => false, 'json' => null, 'reason' => 'file_empty'];
        }
        $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            return ['label' => $label, 'path' => $path, 'present' => false, 'json' => null, 'reason' => 'json_not_object'];
        }
        return ['label' => $label, 'path' => $path, 'present' => true, 'json' => $json, 'reason' => 'ok'];
    } catch (Throwable $e) {
        return ['label' => $label, 'path' => $path, 'present' => false, 'json' => null, 'reason' => $e::class . ': ' . $e->getMessage()];
    }
}

function king_release_gate_requirement(string $name, string $status, string $reason, array $evidence = []): array
{
    return ['name' => $name, 'status' => $status, 'reason' => $reason, 'evidence' => $evidence];
}

function king_release_gate_matrix_map(array $matrix): array
{
    $map = [];
    foreach (($matrix['gates'] ?? []) as $gate) {
        if (!is_array($gate) || !is_string($gate['name'] ?? null)) {
            continue;
        }
        $map[$gate['name']] = $gate;
    }
    return $map;
}

function king_release_gate_matrix_requirement(array $matrixReport, string $gate): array
{
    if (!$matrixReport['present']) {
        return king_release_gate_requirement('matrix:' . $gate, 'missing', 'matrix_report_missing', [
            'report' => $matrixReport['path'],
            'reason' => $matrixReport['reason'],
        ]);
    }

    $map = king_release_gate_matrix_map($matrixReport['json']);
    if (!isset($map[$gate])) {
        return king_release_gate_requirement('matrix:' . $gate, 'missing', 'matrix_gate_missing', ['gate' => $gate]);
    }

    $entry = $map[$gate];
    $status = (string) ($entry['status'] ?? 'failed');
    if ($status === 'passed') {
        return king_release_gate_requirement('matrix:' . $gate, 'passed', 'ok', ['gate' => $entry]);
    }

    return king_release_gate_requirement(
        'matrix:' . $gate,
        $status === 'skipped' ? 'missing' : 'failed',
        'matrix_gate_' . $status . ':' . (string) ($entry['reason'] ?? 'unknown'),
        ['gate' => $entry]
    );
}

function king_release_gate_deterministic_prompts(array $goldenReport, int $minimumCases): array
{
    if (!$goldenReport['present']) {
        return king_release_gate_requirement('deterministic_prompt_pack', 'missing', 'golden_report_missing', [
            'report' => $goldenReport['path'],
            'reason' => $goldenReport['reason'],
            'minimum_cases' => $minimumCases,
        ]);
    }

    $golden = $goldenReport['json'];
    $caseCount = (int) ($golden['case_count'] ?? 0);
    $failed = (int) ($golden['failed'] ?? 0);
    $ok = ($golden['ok'] ?? false) === true;
    if ($caseCount < $minimumCases) {
        return king_release_gate_requirement('deterministic_prompt_pack', 'failed', 'not_enough_golden_cases', [
            'case_count' => $caseCount,
            'minimum_cases' => $minimumCases,
            'failed' => $failed,
            'ok' => $ok,
        ]);
    }
    if (!$ok || $failed !== 0) {
        return king_release_gate_requirement('deterministic_prompt_pack', 'failed', 'golden_prompt_failures', [
            'case_count' => $caseCount,
            'failed' => $failed,
            'ok' => $ok,
        ]);
    }

    return king_release_gate_requirement('deterministic_prompt_pack', 'passed', 'ok', [
        'case_count' => $caseCount,
        'minimum_cases' => $minimumCases,
    ]);
}

function king_release_gate_no_silent_fallback(array $baselineReport): array
{
    if (!$baselineReport['present']) {
        return king_release_gate_requirement('no_silent_fallback', 'missing', 'baseline_report_missing', [
            'report' => $baselineReport['path'],
            'reason' => $baselineReport['reason'],
        ]);
    }

    $checked = [];
    $failures = [];
    foreach (($baselineReport['json']['profiles'] ?? []) as $profile) {
        if (!is_array($profile) || ($profile['runtime_profile'] ?? null) !== 'gpu') {
            continue;
        }
        if (($profile['status'] ?? null) !== 'measured') {
            continue;
        }
        $fallback = is_array($profile['fallback_status'] ?? null) ? $profile['fallback_status'] : [];
        $checked[] = [
            'label' => $profile['label'] ?? null,
            'active_device' => $fallback['active_device'] ?? null,
            'silent_cpu_fallback' => $fallback['silent_cpu_fallback'] ?? null,
        ];
        if (($fallback['silent_cpu_fallback'] ?? null) !== false) {
            $failures[] = end($checked);
        }
    }

    if ($checked === []) {
        return king_release_gate_requirement('no_silent_fallback', 'missing', 'no_measured_gpu_profile', [
            'profiles' => array_map(static fn(array $profile): array => [
                'label' => $profile['label'] ?? null,
                'status' => $profile['status'] ?? null,
                'reason' => $profile['reason'] ?? null,
            ], array_filter($baselineReport['json']['profiles'] ?? [], 'is_array')),
        ]);
    }
    if ($failures !== []) {
        return king_release_gate_requirement('no_silent_fallback', 'failed', 'silent_cpu_fallback_detected', ['failures' => $failures]);
    }

    return king_release_gate_requirement('no_silent_fallback', 'passed', 'ok', ['checked' => $checked]);
}

function king_release_gate_real_streaming(array $baselineReport): array
{
    if (!$baselineReport['present']) {
        return king_release_gate_requirement('real_streaming', 'missing', 'baseline_report_missing', [
            'report' => $baselineReport['path'],
            'reason' => $baselineReport['reason'],
        ]);
    }

    $measured = [];
    foreach (($baselineReport['json']['profiles'] ?? []) as $profile) {
        if (!is_array($profile) || ($profile['status'] ?? null) !== 'measured') {
            continue;
        }
        $aggregate = is_array($profile['aggregate'] ?? null) ? $profile['aggregate'] : [];
        $successful = (int) ($aggregate['successful_runs'] ?? 0);
        $tokens = (int) ($aggregate['generated_tokens_estimate_total'] ?? 0);
        if ($successful > 0 && $tokens > 0) {
            $measured[] = [
                'label' => $profile['label'] ?? null,
                'successful_runs' => $successful,
                'generated_tokens_estimate_total' => $tokens,
                'ttfb_ms_p50' => $aggregate['ttfb_ms']['p50'] ?? null,
                'tokens_per_second_p50' => $aggregate['tokens_per_second']['p50'] ?? null,
            ];
        }
    }

    if ($measured === []) {
        return king_release_gate_requirement('real_streaming', 'missing', 'no_measured_streaming_profile', []);
    }

    return king_release_gate_requirement('real_streaming', 'passed', 'ok', ['profiles' => $measured]);
}

function king_release_gate_batch_prefill(array $baselineReport, array $goldenReport): array
{
    $entries = [];
    if ($baselineReport['present']) {
        foreach (($baselineReport['json']['profiles'] ?? []) as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $modelAfter = is_array($profile['model_after'] ?? null) ? $profile['model_after'] : [];
            if (array_key_exists('batch_prefill_admitted', $modelAfter) || array_key_exists('batch_prefill_status', $modelAfter)) {
                $entries[] = [
                    'source' => 'baseline',
                    'label' => $profile['label'] ?? null,
                    'admitted' => $modelAfter['batch_prefill_admitted'] ?? null,
                    'status' => $modelAfter['batch_prefill_status'] ?? null,
                ];
            }
        }
    }
    if ($goldenReport['present']) {
        $modelEntry = is_array($goldenReport['json']['model_entry'] ?? null) ? $goldenReport['json']['model_entry'] : [];
        if (array_key_exists('batch_prefill_admitted', $modelEntry)) {
            $entries[] = [
                'source' => 'golden',
                'label' => $goldenReport['json']['model'] ?? null,
                'admitted' => $modelEntry['batch_prefill_admitted'] ?? null,
                'status' => null,
            ];
        }
    }

    if ($entries === []) {
        return king_release_gate_requirement('batch_prefill_state', 'missing', 'no_batch_prefill_metadata', []);
    }

    $bad = [];
    foreach ($entries as $entry) {
        $admitted = $entry['admitted'] ?? null;
        $status = strtolower((string) ($entry['status'] ?? ''));
        if ($admitted === true && !in_array($status, ['ready', 'green', 'passed', 'stable'], true)) {
            $bad[] = $entry;
        }
    }
    if ($bad !== []) {
        return king_release_gate_requirement('batch_prefill_state', 'failed', 'batch_prefill_admitted_without_green_status', ['entries' => $bad]);
    }

    return king_release_gate_requirement('batch_prefill_state', 'passed', 'ok', ['entries' => $entries]);
}

function king_release_gate_claim_is_ready(array $claimsReport): bool
{
    if (!$claimsReport['present']) {
        return false;
    }
    $claims = $claimsReport['json'];
    if (($claims['native_inference_ready'] ?? null) === true) {
        return true;
    }
    if (is_array($claims['claims'] ?? null) && ($claims['claims']['native_inference_ready'] ?? null) === true) {
        return true;
    }
    return false;
}

if (king_release_gate_flag($argv, '--help') || king_release_gate_flag($argv, '-h')) {
    king_release_gate_usage();
}

$options = [
    'json' => king_release_gate_flag($argv, '--json'),
    'matrix' => king_release_gate_option($argv, '--matrix', getenv('KING_INFERENCE_RELEASE_MATRIX_REPORT') ?: ''),
    'golden' => king_release_gate_option($argv, '--golden', getenv('KING_INFERENCE_RELEASE_GOLDEN_REPORT') ?: ''),
    'baseline' => king_release_gate_option($argv, '--baseline', getenv('KING_INFERENCE_RELEASE_BASELINE_REPORT') ?: ''),
    'claims' => king_release_gate_option($argv, '--claims', getenv('KING_INFERENCE_RELEASE_CLAIMS_REPORT') ?: ''),
    'min_golden_cases' => king_release_gate_int($argv, '--min-golden-cases', 100, 1),
];

$matrixReport = king_release_gate_read_json('matrix', (string) $options['matrix']);
$goldenReport = king_release_gate_read_json('golden', (string) $options['golden']);
$baselineReport = king_release_gate_read_json('baseline', (string) $options['baseline']);
$claimsReport = king_release_gate_read_json('claims', (string) $options['claims']);

$requirements = [];
$requirements[] = king_release_gate_deterministic_prompts($goldenReport, (int) $options['min_golden_cases']);
foreach ([
    'tokenizer',
    'gguf_load',
    'cpu_reference',
    'gpu_smoke',
    'cpu_gpu_match',
    'openai_route_smoke',
    'long_prompt',
    'stop_tokens',
    'cancellation',
    'error_taxonomy',
] as $matrixGate) {
    $requirements[] = king_release_gate_matrix_requirement($matrixReport, $matrixGate);
}
$requirements[] = king_release_gate_no_silent_fallback($baselineReport);
$requirements[] = king_release_gate_real_streaming($baselineReport);
$requirements[] = king_release_gate_batch_prefill($baselineReport, $goldenReport);

$failedOrMissing = array_values(array_filter(
    $requirements,
    static fn(array $requirement): bool => ($requirement['status'] ?? '') !== 'passed'
));

$claimReady = king_release_gate_claim_is_ready($claimsReport);
if ($claimReady && $failedOrMissing !== []) {
    $requirements[] = king_release_gate_requirement('release_claim_consistency', 'failed', 'ready_claim_without_passing_gate', [
        'claims_report' => $claimsReport['path'],
    ]);
    $failedOrMissing[] = end($requirements);
} else {
    $requirements[] = king_release_gate_requirement('release_claim_consistency', 'passed', $claimReady ? 'ready_claim_allowed_by_gate' : 'no_ready_claim_requested', [
        'claims_report' => $claimsReport['present'] ? $claimsReport['path'] : null,
    ]);
}

$counts = ['passed' => 0, 'failed' => 0, 'missing' => 0];
foreach ($requirements as $requirement) {
    $status = (string) ($requirement['status'] ?? 'failed');
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

$ok = $counts['failed'] === 0 && $counts['missing'] === 0;
$report = [
    'schema_version' => 1,
    'generated_at' => date(DATE_ATOM),
    'ok' => $ok,
    'minimum_golden_cases' => (int) $options['min_golden_cases'],
    'release_claim' => $ok ? 'native_inference_ready_for_reported_matrix' : 'native_inference_experimental_hardening',
    'allowed_release_note_claim' => $ok
        ? 'Native inference passed the release gate for the supplied evidence reports. Scope must match the matrix and baseline reports.'
        : 'Native inference remains experimental/hardening; do not claim release-ready native inference beyond the supplied passing gates.',
    'reports' => [
        'matrix' => ['path' => $matrixReport['path'], 'present' => $matrixReport['present'], 'reason' => $matrixReport['reason']],
        'golden' => ['path' => $goldenReport['path'], 'present' => $goldenReport['present'], 'reason' => $goldenReport['reason']],
        'baseline' => ['path' => $baselineReport['path'], 'present' => $baselineReport['present'], 'reason' => $baselineReport['reason']],
        'claims' => ['path' => $claimsReport['path'], 'present' => $claimsReport['present'], 'reason' => $claimsReport['reason']],
    ],
    'summary' => $counts,
    'requirements' => $requirements,
];

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($ok ? 0 : 1);
}

echo "King native inference release gate\n";
echo 'status=' . ($ok ? 'passed' : 'blocked') . "\n";
echo 'minimum_golden_cases=' . $report['minimum_golden_cases'] . "\n";
foreach ($requirements as $requirement) {
    echo '[' . strtoupper((string) $requirement['status']) . '] '
        . $requirement['name'] . ': '
        . $requirement['reason'] . "\n";
}
echo 'summary passed=' . $counts['passed'] . ' missing=' . $counts['missing'] . ' failed=' . $counts['failed'] . "\n";
echo $report['allowed_release_note_claim'] . "\n";

exit($ok ? 0 : 1);
