<?php

declare(strict_types=1);

const KING_BENCHMARK_SCHEMA_VERSION = 1;
const KING_BENCHMARK_BUDGET_SCHEMA_VERSION = 1;
const KING_BENCHMARK_DEFAULT_MAX_SLOWDOWN = 1.35;

main($argv);

function main(array $argv): void
{
    if (!extension_loaded('king')) {
        fail('The king extension is not loaded. Use ./benchmarks/run-canonical.sh.');
    }

    $cases = build_case_definitions();
    $options = parse_options($argv, array_keys($cases));

    if ($options['list_cases']) {
        foreach ($cases as $caseName => $definition) {
            echo $caseName . "\t" . $definition['description'] . PHP_EOL;
        }
        return;
    }

    $selectedCaseNames = $options['cases'];
    $results = [];

    foreach ($selectedCaseNames as $caseName) {
        $definition = $cases[$caseName];
        $iterations = $options['iterations'] ?? $definition['default_iterations'];
        $warmupIterations = $options['warmup'] ?? default_warmup_iterations($iterations);

        $results[$caseName] = run_case(
            $caseName,
            $definition,
            $iterations,
            $warmupIterations,
            $options['samples']
        );
    }

    $payload = [
        'schema_version' => KING_BENCHMARK_SCHEMA_VERSION,
        'generated_at' => gmdate('c'),
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'hostname' => php_uname('n'),
        'selected_cases' => $selectedCaseNames,
        'max_slowdown' => $options['max_slowdown'],
        'samples' => $options['samples'],
        'sample_strategy' => 'median',
        'cases' => $results,
    ];

    $comparison = null;
    $budgetComparison = null;
    if ($options['baseline'] !== null) {
        $comparison = compare_against_baseline(
            $payload,
            load_baseline($options['baseline']),
            $options['max_slowdown']
        );
        $payload['comparison'] = $comparison;
    }

    if ($options['budget_file'] !== null) {
        $budgetComparison = compare_against_budget(
            $payload,
            load_budget_file($options['budget_file'])
        );
        $payload['budget_comparison'] = $budgetComparison;
    }

    if ($options['write_baseline'] !== null) {
        write_json_file($options['write_baseline'], $payload);
    }

    if ($options['json']) {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        print_human_report($payload, $comparison, $budgetComparison, $options);
    }

    if ($comparison !== null && !$comparison['all_passed']) {
        exit(2);
    }

    if ($budgetComparison !== null && !$budgetComparison['all_passed']) {
        exit(2);
    }
}

function build_case_definitions(): array
{
    return [
        'session' => [
            'description' => 'connect/stats/poll/cancel/close lifecycle',
            'default_iterations' => 20000,
            'operations_per_iteration' => 5,
            'bootstrap' => static function (): array {
                return [
                    'run' => static function (int $iteration): int {
                        $session = king_connect('127.0.0.1', 443, [
                            'sni' => 'bench.example',
                            'alpn' => ['h3'],
                        ]);

                        if (!is_resource($session)) {
                            throw new RuntimeException('king_connect() did not return a session resource.');
                        }

                        $stats = king_get_stats($session);
                        if (!is_array($stats) || ($stats['state'] ?? null) !== 'open') {
                            throw new RuntimeException('king_get_stats() did not return an open session state.');
                        }

                        if (!king_poll($session, 0)) {
                            throw new RuntimeException('king_poll() failed during the session benchmark.');
                        }

                        if (!king_cancel_stream($iteration + 1, 'read', $session)) {
                            throw new RuntimeException('king_cancel_stream() failed during the session benchmark.');
                        }

                        if (!king_close($session)) {
                            throw new RuntimeException('king_close() failed during the session benchmark.');
                        }

                        $closedStats = king_get_stats($session);
                        if (!is_array($closedStats) || ($closedStats['state'] ?? null) !== 'closed') {
                            throw new RuntimeException('Closed-session stats were not returned as expected.');
                        }

                        return strlen((string) ($closedStats['state'] ?? ''))
                            + (int) ($closedStats['poll_calls'] ?? 0)
                            + (int) ($closedStats['cancel_calls'] ?? 0);
                    },
                ];
            },
        ],
        'proto' => [
            'description' => 'schema-defined encode/decode roundtrip',
            'default_iterations' => 500000,
            'operations_per_iteration' => 2,
            'bootstrap' => static function (): array {
                $schemaName = 'BenchUser_' . getmypid() . '_' . bin2hex(random_bytes(4));
                if (!king_proto_define_schema($schemaName, [
                    'name' => ['tag' => 3, 'type' => 'string'],
                    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
                    'enabled' => ['tag' => 2, 'type' => 'bool'],
                ])) {
                    throw new RuntimeException('king_proto_define_schema() failed during benchmark setup.');
                }

                return [
                    'run' => static function (int $iteration) use ($schemaName): int {
                        $payload = [
                            'name' => 'bench-' . ($iteration % 1000),
                            'id' => $iteration + 1,
                            'enabled' => ($iteration % 2) === 0,
                        ];

                        $encoded = king_proto_encode($schemaName, $payload);
                        $decoded = king_proto_decode($schemaName, $encoded);

                        if (!is_array($decoded) || ($decoded['id'] ?? null) !== ($iteration + 1)) {
                            throw new RuntimeException('Proto roundtrip verification failed.');
                        }

                        return strlen($encoded)
                            + (int) ($decoded['id'] ?? 0)
                            + (int) (!empty($decoded['enabled']));
                    },
                ];
            },
        ],
        'object_store' => [
            'description' => 'object-store put/get/cache/delete cycle',
            'default_iterations' => 4000,
            'operations_per_iteration' => 4,
            'bootstrap' => static function (): array {
                $root = benchmark_temp_path('king_bench_store');
                ensure_directory($root);

                king_object_store_init([
                    'storage_root_path' => $root,
                    'max_storage_size_bytes' => 32 * 1024 * 1024,
                    'cdn_config' => [
                        'enabled' => true,
                        'default_ttl_seconds' => 300,
                    ],
                ]);

                return [
                    'run' => static function (int $iteration) use ($root): int {
                        $objectId = sprintf('bench-%06d', $iteration);
                        $payload = str_repeat(chr(65 + ($iteration % 26)), 1024);

                        if (!king_object_store_put($objectId, $payload)) {
                            throw new RuntimeException('king_object_store_put() failed during the benchmark.');
                        }

                        $stored = king_object_store_get($objectId);
                        if ($stored !== $payload) {
                            throw new RuntimeException('king_object_store_get() did not return the stored payload.');
                        }

                        if (!king_cdn_cache_object($objectId)) {
                            throw new RuntimeException('king_cdn_cache_object() failed during the benchmark.');
                        }

                        if (!king_object_store_delete($objectId)) {
                            throw new RuntimeException('king_object_store_delete() failed during the benchmark.');
                        }

                        return strlen($stored) + strlen($root);
                    },
                    'cleanup' => static function () use ($root): void {
                        delete_flat_directory($root);
                    },
                ];
            },
        ],
        'semantic_dns' => [
            'description' => 'real listener bootstrap on the active v1 init surface plus steady-state register/discover/route/topology',
            'default_iterations' => 50000,
            'operations_per_iteration' => 4,
            'bootstrap' => static function (): array {
                $stateDir = '/tmp/king_semantic_dns_state';
                $dnsPort = 18053 + (getmypid() % 1000);
                $config = [
                    'enabled' => true,
                    'bind_address' => '127.0.0.1',
                    'dns_port' => $dnsPort,
                    'default_record_ttl_sec' => 120,
                    'service_discovery_max_ips_per_response' => 5,
                    'semantic_mode_enable' => true,
                    'mothernode_uri' => 'mother://bench-node',
                    'routing_policies' => ['mode' => 'local'],
                ];
                delete_flat_directory($stateDir);

                if (!king_semantic_dns_init($config)) {
                    throw new RuntimeException('king_semantic_dns_init() failed during benchmark setup.');
                }

                if (!king_semantic_dns_start_server()) {
                    throw new RuntimeException('king_semantic_dns_start_server() failed during benchmark setup.');
                }

                return [
                    'run' => static function (int $iteration): int {
                        $serviceId = 'api-bench';
                        $serviceName = 'api-bench';

                        if (!king_semantic_dns_register_service([
                            'service_id' => $serviceId,
                            'service_name' => $serviceName,
                            'service_type' => 'pipeline_orchestrator',
                            'status' => 'healthy',
                            'hostname' => 'api.internal',
                            'port' => 8443,
                            'current_load_percent' => 12,
                            'active_connections' => 3,
                        ])) {
                            throw new RuntimeException('king_semantic_dns_register_service() failed during the benchmark.');
                        }

                        $discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
                        $route = king_semantic_dns_get_optimal_route($serviceName, [
                            'location' => [
                                'latitude' => 52.52 + (($iteration % 100) / 1000),
                                'longitude' => 13.405 + (($iteration % 100) / 1000),
                            ],
                        ]);
                        $topology = king_semantic_dns_get_service_topology();

                        if (($discovery['service_count'] ?? 0) < 1) {
                            throw new RuntimeException('Semantic-DNS discovery returned no services during the benchmark.');
                        }

                        if (($route['service_id'] ?? null) !== $serviceId) {
                            throw new RuntimeException('Semantic-DNS routing did not return the freshly registered service.');
                        }

                        if (($topology['statistics']['healthy_services'] ?? 0) < 1) {
                            throw new RuntimeException('Semantic-DNS topology did not report a healthy service.');
                        }

                        return (int) ($discovery['service_count'] ?? 0)
                            + (int) ($topology['statistics']['healthy_services'] ?? 0)
                            + strlen($serviceId);
                    },
                    'cleanup' => static function () use ($stateDir, $config): void {
                        delete_flat_directory($stateDir);
                    },
                ];
            },
        ],
    ];
}

function benchmark_temp_path(string $prefix): string
{
    $base = benchmark_temp_root();

    return $base
        . DIRECTORY_SEPARATOR
        . $prefix
        . '_'
        . getmypid()
        . '_'
        . bin2hex(random_bytes(4));
}

function benchmark_temp_root(): string
{
    $preferred = '/dev/shm';

    if (is_dir($preferred) && is_writable($preferred)) {
        return $preferred;
    }

    return sys_get_temp_dir();
}

function parse_options(array $argv, array $availableCases): array
{
    $options = [
        'cases' => $availableCases,
        'iterations' => null,
        'warmup' => null,
        'baseline' => null,
        'budget_file' => null,
        'write_baseline' => null,
        'max_slowdown' => KING_BENCHMARK_DEFAULT_MAX_SLOWDOWN,
        'samples' => 1,
        'json' => false,
        'list_cases' => false,
    ];

    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        $arg = $argv[$i];

        if ($arg === '--help' || $arg === '-h') {
            print_help($availableCases);
            exit(0);
        }

        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }

        if ($arg === '--list-cases') {
            $options['list_cases'] = true;
            continue;
        }

        [$flag, $value] = split_flag($argv, $i);

        switch ($flag) {
            case '--case':
                $requestedCases = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
                if ($requestedCases === []) {
                    fail('The --case flag requires at least one case name.');
                }
                foreach ($requestedCases as $caseName) {
                    if (!in_array($caseName, $availableCases, true)) {
                        fail(sprintf('Unknown benchmark case "%s".', $caseName));
                    }
                }
                $options['cases'] = array_values(array_unique($requestedCases));
                break;

            case '--iterations':
                $options['iterations'] = parse_positive_int($value, '--iterations');
                break;

            case '--warmup':
                $options['warmup'] = parse_non_negative_int($value, '--warmup');
                break;

            case '--samples':
                $options['samples'] = parse_positive_int($value, '--samples');
                break;

            case '--baseline':
                $options['baseline'] = $value;
                break;

            case '--budget-file':
                $options['budget_file'] = $value;
                break;

            case '--write-baseline':
                $options['write_baseline'] = $value;
                break;

            case '--max-slowdown':
                if (!is_numeric($value) || (float) $value < 1.0) {
                    fail('The --max-slowdown flag requires a numeric ratio >= 1.0.');
                }
                $options['max_slowdown'] = (float) $value;
                break;

            default:
                fail(sprintf('Unknown option "%s". Use --help for usage.', $arg));
        }
    }

    return $options;
}

function split_flag(array $argv, int &$index): array
{
    $arg = $argv[$index];
    $equalsPosition = strpos($arg, '=');

    if ($equalsPosition !== false) {
        return [substr($arg, 0, $equalsPosition), substr($arg, $equalsPosition + 1)];
    }

    if (!isset($argv[$index + 1])) {
        fail(sprintf('Missing value for %s.', $arg));
    }

    $index++;
    return [$arg, $argv[$index]];
}

function parse_positive_int(string $value, string $flag): int
{
    if (!ctype_digit($value) || (int) $value <= 0) {
        fail(sprintf('%s requires a positive integer.', $flag));
    }

    return (int) $value;
}

function parse_non_negative_int(string $value, string $flag): int
{
    if (!ctype_digit($value)) {
        fail(sprintf('%s requires a non-negative integer.', $flag));
    }

    return (int) $value;
}

function default_warmup_iterations(int $iterations): int
{
    return max(5, min(50, (int) ceil($iterations * 0.1)));
}

function run_case(
    string $caseName,
    array $definition,
    int $iterations,
    int $warmupIterations,
    int $sampleCount
): array
{
    $samples = [];

    for ($sampleIndex = 0; $sampleIndex < $sampleCount; $sampleIndex++) {
        $samples[] = run_case_sample(
            $caseName,
            $definition,
            $iterations,
            $warmupIterations,
            $sampleIndex
        );
    }

    usort(
        $samples,
        static fn (array $left, array $right): int => $left['elapsed_ns'] <=> $right['elapsed_ns']
    );

    $selectedIndex = (int) floor(count($samples) / 2);
    $selected = $samples[$selectedIndex];

    $selected['sample_count'] = $sampleCount;
    $selected['selected_sample_index'] = $selected['sample_index'];
    $selected['sample_strategy'] = 'median';
    $selected['sample_elapsed_ns'] = array_map(
        static fn (array $sample): int => (int) $sample['elapsed_ns'],
        $samples
    );
    unset($selected['sample_index']);

    return $selected;
}

function run_case_sample(
    string $caseName,
    array $definition,
    int $iterations,
    int $warmupIterations,
    int $sampleIndex
): array
{
    $bootstrap = $definition['bootstrap'];
    $context = $bootstrap();
    $runner = $context['run'];
    $cleanup = $context['cleanup'] ?? null;
    $checksum = 0;

    try {
        for ($i = 0; $i < $warmupIterations; $i++) {
            $checksum = accumulate_checksum($checksum, (int) $runner($i));
        }

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $checksum = accumulate_checksum($checksum, (int) $runner($i + $warmupIterations));
        }
        $elapsedNs = hrtime(true) - $start;
    } catch (Throwable $throwable) {
        if (is_callable($cleanup)) {
            $cleanup();
        }
        fail(sprintf('%s benchmark failed: %s', $caseName, $throwable->getMessage()));
    }

    if (is_callable($cleanup)) {
        $cleanup();
    }

    $seconds = $elapsedNs / 1_000_000_000;
    $totalOperations = $iterations * (int) $definition['operations_per_iteration'];

    return [
        'description' => $definition['description'],
        'iterations' => $iterations,
        'warmup_iterations' => $warmupIterations,
        'sample_index' => $sampleIndex,
        'operations_per_iteration' => (int) $definition['operations_per_iteration'],
        'total_operations' => $totalOperations,
        'elapsed_ns' => $elapsedNs,
        'elapsed_ms' => round($elapsedNs / 1_000_000, 3),
        'ns_per_iteration' => $elapsedNs / $iterations,
        'us_per_iteration' => ($elapsedNs / $iterations) / 1000,
        'iterations_per_second' => $iterations / $seconds,
        'operations_per_second' => $totalOperations / $seconds,
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'checksum' => $checksum,
    ];
}

function accumulate_checksum(int $checksum, int $value): int
{
    return ($checksum + $value) % 1000000007;
}

function compare_against_baseline(array $currentPayload, array $baselinePayload, float $maxSlowdown): array
{
    if (($baselinePayload['schema_version'] ?? null) !== KING_BENCHMARK_SCHEMA_VERSION) {
        fail('The provided baseline uses an unsupported schema version.');
    }

    $comparisons = [];
    $allPassed = true;

    foreach ($currentPayload['selected_cases'] as $caseName) {
        if (!isset($baselinePayload['cases'][$caseName])) {
            fail(sprintf('Baseline file does not include case "%s".', $caseName));
        }

        $currentCase = $currentPayload['cases'][$caseName];
        $baselineCase = $baselinePayload['cases'][$caseName];

        $currentNs = case_ns_per_iteration($currentCase);
        $baselineNs = case_ns_per_iteration($baselineCase);
        if ($baselineNs <= 0.0) {
            fail(sprintf('Baseline case "%s" has an invalid ns_per_iteration metric.', $caseName));
        }

        $slowdownRatio = $currentNs / $baselineNs;
        $passed = $slowdownRatio <= $maxSlowdown;
        if (!$passed) {
            $allPassed = false;
        }

        $comparisons[$caseName] = [
            'baseline_ns_per_iteration' => $baselineNs,
            'current_ns_per_iteration' => $currentNs,
            'slowdown_ratio' => $slowdownRatio,
            'passed' => $passed,
        ];
    }

    return [
        'baseline_generated_at' => $baselinePayload['generated_at'] ?? null,
        'baseline_php_version' => $baselinePayload['php_version'] ?? null,
        'max_slowdown' => $maxSlowdown,
        'all_passed' => $allPassed,
        'cases' => $comparisons,
    ];
}

function compare_against_budget(array $currentPayload, array $budgetPayload): array
{
    if (($budgetPayload['schema_version'] ?? null) !== KING_BENCHMARK_BUDGET_SCHEMA_VERSION) {
        fail('The provided benchmark budget file uses an unsupported schema version.');
    }

    if (!isset($budgetPayload['cases']) || !is_array($budgetPayload['cases'])) {
        fail('The provided benchmark budget file does not define any cases.');
    }

    $caseResults = [];
    $allPassed = true;

    foreach ($currentPayload['selected_cases'] as $caseName) {
        if (!isset($budgetPayload['cases'][$caseName]) || !is_array($budgetPayload['cases'][$caseName])) {
            fail(sprintf('The benchmark budget file is missing case "%s".', $caseName));
        }

        $currentNs = case_ns_per_iteration($currentPayload['cases'][$caseName]);
        $maxNs = (float) ($budgetPayload['cases'][$caseName]['max_ns_per_iteration'] ?? 0.0);

        if ($maxNs <= 0.0) {
            fail(sprintf('The benchmark budget for case "%s" must define a positive max_ns_per_iteration.', $caseName));
        }

        $passed = $currentNs <= $maxNs;
        $allPassed = $allPassed && $passed;

        $caseResults[$caseName] = [
            'current_ns_per_iteration' => $currentNs,
            'max_ns_per_iteration' => $maxNs,
            'utilization_ratio' => $currentNs / $maxNs,
            'passed' => $passed,
        ];
    }

    return [
        'budget_generated_at' => $budgetPayload['generated_at'] ?? null,
        'budget_target' => $budgetPayload['target'] ?? null,
        'cases' => $caseResults,
        'all_passed' => $allPassed,
    ];
}

function case_ns_per_iteration(array $case): float
{
    if (isset($case['elapsed_ns'], $case['iterations']) && (int) $case['iterations'] > 0) {
        return (float) $case['elapsed_ns'] / (int) $case['iterations'];
    }

    if (isset($case['ns_per_iteration'])) {
        return (float) $case['ns_per_iteration'];
    }

    return 0.0;
}

function load_baseline(string $path): array
{
    if (!is_file($path)) {
        fail(sprintf('Baseline file not found: %s', $path));
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail(sprintf('Baseline file is not valid JSON: %s', $path));
    }

    return $decoded;
}

function load_budget_file(string $path): array
{
    if (!is_file($path)) {
        fail(sprintf('Benchmark budget file does not exist: %s', $path));
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail(sprintf('Benchmark budget file is not valid JSON: %s', $path));
    }

    return $decoded;
}

function write_json_file(string $path, array $payload): void
{
    $directory = dirname($path);
    ensure_directory($directory);

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded . PHP_EOL) === false) {
        fail(sprintf('Failed to write benchmark output to %s.', $path));
    }
}

function print_human_report(array $payload, ?array $comparison, ?array $budgetComparison, array $options): void
{
    echo 'King benchmark results' . PHP_EOL;
    echo 'generated_at: ' . $payload['generated_at'] . PHP_EOL;
    echo 'php_version:  ' . $payload['php_version'] . PHP_EOL;
    echo 'selected:     ' . implode(', ', $payload['selected_cases']) . PHP_EOL;
    echo 'samples:      ' . (string) ($payload['samples'] ?? 1) . ' (median)' . PHP_EOL;
    echo PHP_EOL;

    $headers = ['case', 'iters', 'ops/iter', 'ms total', 'us/iter', 'iter/s', 'ops/s', 'checksum'];
    $rows = [];

    foreach ($payload['cases'] as $caseName => $case) {
        $rows[] = [
            $caseName,
            (string) $case['iterations'],
            (string) $case['operations_per_iteration'],
            number_format((float) $case['elapsed_ms'], 3, '.', ''),
            number_format((float) $case['us_per_iteration'], 3, '.', ''),
            number_format((float) $case['iterations_per_second'], 3, '.', ''),
            number_format((float) $case['operations_per_second'], 3, '.', ''),
            (string) $case['checksum'],
        ];
    }

    echo format_table($headers, $rows) . PHP_EOL;

    if ($options['write_baseline'] !== null) {
        echo PHP_EOL . 'baseline_written: ' . $options['write_baseline'] . PHP_EOL;
    }

    if ($comparison !== null) {
        echo PHP_EOL;
        echo 'Baseline comparison' . PHP_EOL;
        echo 'baseline_generated_at: ' . ($comparison['baseline_generated_at'] ?? 'unknown') . PHP_EOL;
        echo 'baseline_php_version:  ' . ($comparison['baseline_php_version'] ?? 'unknown') . PHP_EOL;
        echo 'max_slowdown:          ' . number_format((float) $comparison['max_slowdown'], 2, '.', '') . 'x' . PHP_EOL;

        $comparisonRows = [];
        foreach ($comparison['cases'] as $caseName => $caseComparison) {
            $comparisonRows[] = [
                $caseName,
                number_format((float) $caseComparison['baseline_ns_per_iteration'], 3, '.', ''),
                number_format((float) $caseComparison['current_ns_per_iteration'], 3, '.', ''),
                number_format((float) $caseComparison['slowdown_ratio'], 3, '.', '') . 'x',
                $caseComparison['passed'] ? 'pass' : 'regression',
            ];
        }

        echo format_table(
            ['case', 'baseline ns/iter', 'current ns/iter', 'ratio', 'status'],
            $comparisonRows
        ) . PHP_EOL;
    }

    if ($budgetComparison !== null) {
        echo PHP_EOL;
        echo 'Budget comparison' . PHP_EOL;
        echo 'budget_generated_at: ' . ($budgetComparison['budget_generated_at'] ?? 'unknown') . PHP_EOL;
        echo 'budget_target:       ' . ($budgetComparison['budget_target'] ?? 'unknown') . PHP_EOL;

        $budgetRows = [];
        foreach ($budgetComparison['cases'] as $caseName => $caseBudget) {
            $budgetRows[] = [
                $caseName,
                number_format((float) $caseBudget['max_ns_per_iteration'], 3, '.', ''),
                number_format((float) $caseBudget['current_ns_per_iteration'], 3, '.', ''),
                number_format((float) $caseBudget['utilization_ratio'], 3, '.', '') . 'x',
                $caseBudget['passed'] ? 'pass' : 'regression',
            ];
        }

        echo format_table(
            ['case', 'max ns/iter', 'current ns/iter', 'utilization', 'status'],
            $budgetRows
        ) . PHP_EOL;
    }
}

function format_table(array $headers, array $rows): string
{
    $widths = array_map('strlen', $headers);

    foreach ($rows as $row) {
        foreach ($row as $index => $cell) {
            $widths[$index] = max($widths[$index], strlen($cell));
        }
    }

    $lines = [];
    $lines[] = format_table_row($headers, $widths);
    $lines[] = format_table_row(array_map(static fn (int $width): string => str_repeat('-', $width), $widths), $widths);

    foreach ($rows as $row) {
        $lines[] = format_table_row($row, $widths);
    }

    return implode(PHP_EOL, $lines);
}

function format_table_row(array $row, array $widths): string
{
    $cells = [];

    foreach ($row as $index => $cell) {
        $cells[] = str_pad($cell, $widths[$index]);
    }

    return implode('  ', $cells);
}

function print_help(array $availableCases): void
{
    echo 'Usage: ./benchmarks/run-canonical.sh [options]' . PHP_EOL;
    echo PHP_EOL;
    echo 'Options:' . PHP_EOL;
    echo '  --case=<list>            Comma-separated subset of cases (' . implode(', ', $availableCases) . ')' . PHP_EOL;
    echo '  --iterations=<n>         Override iterations for all selected cases' . PHP_EOL;
    echo '  --warmup=<n>             Override warmup iterations for all selected cases' . PHP_EOL;
    echo '  --samples=<n>            Run each case n times and keep the median sample' . PHP_EOL;
    echo '  --baseline=<path>        Compare against a previous JSON baseline' . PHP_EOL;
    echo '  --budget-file=<path>     Enforce per-case ns/iteration budgets from a JSON file' . PHP_EOL;
    echo '  --write-baseline=<path>  Write the current run to a JSON baseline file' . PHP_EOL;
    echo '  --max-slowdown=<ratio>   Allowed slowdown ratio before exiting non-zero (default 1.35)' . PHP_EOL;
    echo '  --json                   Print JSON instead of the human report' . PHP_EOL;
    echo '  --list-cases             Print available cases and exit' . PHP_EOL;
    echo '  --help                   Show this help text' . PHP_EOL;
}

function ensure_directory(string $directory): void
{
    if ($directory === '' || $directory === '.') {
        return;
    }

    if (is_dir($directory)) {
        return;
    }

    if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
        fail(sprintf('Failed to create directory %s.', $directory));
    }
}

function delete_flat_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
