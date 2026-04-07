<?php

/* Harness child process for live autoscaling signal capture across real HTTP requests. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$iterations = max(1, (int) ($argv[3] ?? 1));
$plannedPressureRequests = max(1, (int) ($argv[4] ?? 1));

$capture = [
    'iterations' => [],
    'listen_results' => [],
    'listen_errors' => [],
    'monitor_snapshots' => [],
];

$state = [
    'handled_pressure_requests' => 0,
    'planned_pressure_requests' => $plannedPressureRequests,
    'recent_work_timestamps' => [],
    'retained_buffer' => null,
];

function king_autoscaling_real_operation_parse_bytes(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-1') {
        return -1;
    }

    $suffix = strtolower(substr($raw, -1));
    $value = (float) $raw;

    if ($suffix === 'g') {
        return (int) ($value * 1024 * 1024 * 1024);
    }
    if ($suffix === 'm') {
        return (int) ($value * 1024 * 1024);
    }
    if ($suffix === 'k') {
        return (int) ($value * 1024);
    }

    return (int) $value;
}

function king_autoscaling_real_operation_usage_micros(array $usage): float
{
    return (float) (
        (($usage['ru_utime.tv_sec'] ?? 0) * 1000000)
        + ($usage['ru_utime.tv_usec'] ?? 0)
        + (($usage['ru_stime.tv_sec'] ?? 0) * 1000000)
        + ($usage['ru_stime.tv_usec'] ?? 0)
    );
}

function king_autoscaling_real_operation_measure_cpu_percent(callable $work, float &$elapsedMs): float
{
    $usageBefore = getrusage();
    $startedAt = hrtime(true);
    $work();
    $elapsedMs = (hrtime(true) - $startedAt) / 1000000.0;
    $usageAfter = getrusage();

    if ($elapsedMs <= 0.0) {
        return 0.0;
    }

    $cpuMicros = king_autoscaling_real_operation_usage_micros($usageAfter)
        - king_autoscaling_real_operation_usage_micros($usageBefore);
    if ($cpuMicros < 0.0) {
        $cpuMicros = 0.0;
    }

    return min(100.0, ($cpuMicros / ($elapsedMs * 1000.0)) * 100.0);
}

function king_autoscaling_real_operation_burn_cpu(int $targetMs): void
{
    $deadline = microtime(true) + ($targetMs / 1000.0);
    $seed = 1.0;

    while (microtime(true) < $deadline) {
        $seed = sqrt($seed + 17.0);
        if ($seed > 1000.0) {
            $seed = 1.0;
        }
    }
}

function king_autoscaling_real_operation_trim_recent_work(array &$timestamps): void
{
    $cutoff = microtime(true) - 1.0;

    $timestamps = array_values(array_filter(
        $timestamps,
        static fn(float $timestamp): bool => $timestamp >= $cutoff
    ));
}

function king_autoscaling_real_operation_build_work_metrics(string $phase, array &$state): array
{
    $elapsedMs = 0.0;

    if ($phase === 'pressure') {
        $state['handled_pressure_requests']++;
        $cpuPercent = king_autoscaling_real_operation_measure_cpu_percent(
            static function () use (&$state): void {
                king_autoscaling_real_operation_burn_cpu(320);
                $state['retained_buffer'] = str_repeat('k', 6 * 1024 * 1024);
            },
            $elapsedMs
        );
        $queueDepth = max(
            0,
            $state['planned_pressure_requests'] - $state['handled_pressure_requests']
        );
    } else {
        $state['retained_buffer'] = null;
        gc_collect_cycles();
        $cpuPercent = king_autoscaling_real_operation_measure_cpu_percent(
            static function (): void {
                usleep(5000);
            },
            $elapsedMs
        );
        $queueDepth = 0;
    }

    $memoryLimitBytes = king_autoscaling_real_operation_parse_bytes((string) ini_get('memory_limit'));
    $memoryBytes = memory_get_usage(false);
    $memoryPercent = $memoryLimitBytes > 0
        ? min(100.0, ((float) $memoryBytes * 100.0) / (float) $memoryLimitBytes)
        : 0.0;

    $state['recent_work_timestamps'][] = microtime(true);
    king_autoscaling_real_operation_trim_recent_work($state['recent_work_timestamps']);

    return [
        'phase' => $phase,
        'cpu_utilization' => $cpuPercent,
        'memory_utilization' => $memoryPercent,
        'active_connections' => 1,
        'requests_per_second' => count($state['recent_work_timestamps']),
        'response_time_ms' => (int) round($elapsedMs),
        'queue_depth' => $queueDepth,
    ];
}

function king_autoscaling_real_operation_record_metrics(array $metrics): void
{
    king_telemetry_record_metric(
        'autoscaling.cpu_utilization',
        (float) $metrics['cpu_utilization'],
        null,
        'gauge'
    );
    king_telemetry_record_metric(
        'autoscaling.memory_utilization',
        (float) $metrics['memory_utilization'],
        null,
        'gauge'
    );
    king_telemetry_record_metric(
        'autoscaling.active_connections',
        (float) $metrics['active_connections'],
        null,
        'gauge'
    );
    king_telemetry_record_metric(
        'autoscaling.requests_per_second',
        (float) $metrics['requests_per_second'],
        null,
        'gauge'
    );
    king_telemetry_record_metric(
        'autoscaling.response_time_ms',
        (float) $metrics['response_time_ms'],
        null,
        'gauge'
    );
    king_telemetry_record_metric(
        'autoscaling.queue_depth',
        (float) $metrics['queue_depth'],
        null,
        'gauge'
    );
}

king_telemetry_init([]);
king_autoscaling_init([
    'max_nodes' => 4,
    'max_scale_step' => 2,
    'scale_up_policy' => 'add_nodes:2',
    'cooldown_period_sec' => 1,
    'scale_up_cpu_threshold_percent' => 75,
    'scale_down_cpu_threshold_percent' => 25,
]);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $result = king_http1_server_listen_once(
        '127.0.0.1',
        $port,
        null,
        static function (array $request) use (&$capture, &$state): array {
            $path = (string) (parse_url((string) ($request['uri'] ?? '/'), PHP_URL_PATH) ?? '/');
            $query = [];
            parse_str((string) (parse_url((string) ($request['uri'] ?? '/'), PHP_URL_QUERY) ?? ''), $query);

            if ($path === '/monitor') {
                $snapshot = [
                    'monitor_started' => king_autoscaling_start_monitoring(),
                    'status' => king_autoscaling_get_status(),
                    'metrics' => king_autoscaling_get_metrics(),
                ];
                $capture['monitor_snapshots'][] = $snapshot;

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'application/json'],
                    'body' => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                ];
            }

            $phase = ($query['phase'] ?? 'pressure') === 'relief' ? 'relief' : 'pressure';
            $metrics = king_autoscaling_real_operation_build_work_metrics($phase, $state);
            king_autoscaling_real_operation_record_metrics($metrics);
            $capture['iterations'][] = $metrics;

            return [
                'status' => 200,
                'headers' => ['content-type' => 'application/json'],
                'body' => json_encode($metrics, JSON_UNESCAPED_SLASHES),
            ];
        }
    );

    $capture['listen_results'][] = $result;
    $capture['listen_errors'][] = king_get_last_error();
    if ($result !== true) {
        break;
    }
}

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
