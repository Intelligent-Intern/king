<?php

declare(strict_types=1);

function fail(string $message, int $exitCode = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($exitCode);
}

function section(string $label): void
{
    echo PHP_EOL, "== ", $label, " ==", PHP_EOL;
}

function recordLoad(
    float $cpu,
    int $queueDepth,
    int $requestsPerSecond,
    int $responseTimeMs,
    int $activeConnections
): void {
    king_telemetry_record_metric('autoscaling.cpu_utilization', $cpu, null, 'gauge');
    king_telemetry_record_metric('autoscaling.queue_depth', (float) $queueDepth, null, 'gauge');
    king_telemetry_record_metric('autoscaling.requests_per_second', (float) $requestsPerSecond, null, 'gauge');
    king_telemetry_record_metric('autoscaling.response_time_ms', (float) $responseTimeMs, null, 'gauge');
    king_telemetry_record_metric('autoscaling.active_connections', (float) $activeConnections, null, 'gauge');
}

function printSnapshot(string $label): void
{
    $status = king_autoscaling_get_status();
    $metrics = king_autoscaling_get_metrics();
    $nodes = king_autoscaling_get_nodes();

    $nodeSummary = array_map(
        static fn(array $node): array => [
            'server_id' => $node['server_id'],
            'name' => $node['name'],
            'lifecycle' => $node['lifecycle'],
            'active' => $node['active'],
        ],
        $nodes
    );

    $snapshot = [
        'label' => $label,
        'status' => [
            'provider' => $status['provider'],
            'provider_mode' => $status['provider_mode'],
            'monitoring_active' => $status['monitoring_active'],
            'current_instances' => $status['current_instances'],
            'managed_nodes' => $status['managed_nodes'],
            'active_managed_nodes' => $status['active_managed_nodes'],
            'cooldown_remaining_sec' => $status['cooldown_remaining_sec'],
            'last_action_kind' => $status['last_action_kind'],
            'last_signal_source' => $status['last_signal_source'],
            'last_decision_reason' => $status['last_decision_reason'],
            'last_warning' => $status['last_warning'],
        ],
        'metrics' => [
            'cpu_utilization' => $metrics['cpu_utilization'],
            'requests_per_second' => $metrics['requests_per_second'],
            'response_time_ms' => $metrics['response_time_ms'],
            'queue_depth' => $metrics['queue_depth'],
            'active_connections' => $metrics['active_connections'],
        ],
        'nodes' => array_values($nodeSummary),
    ];

    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
}

if (!extension_loaded('king')) {
    fail(
        "The king extension is not loaded.\n"
        . "Run this example with:\n"
        . "php8.4 -d extension=/home/jochen/projects/king.site/king/extension/modules/king.so "
        . "-d king.security_allow_config_override=1 "
        . "documentation/01-hetzner-self-bootstrapping-edge-cluster/controller_demo.php"
    );
}

try {
    section('Boot');

    king_telemetry_init([]);
    king_autoscaling_init([
        'max_nodes' => 5,
        'max_scale_step' => 2,
        'scale_up_policy' => 'add_nodes:3',
        'cooldown_period_sec' => 1,
        'scale_up_cpu_threshold_percent' => 75,
        'scale_down_cpu_threshold_percent' => 25,
    ]);

    printSnapshot('after init');

    section('High Load Triggers Scale Up');

    recordLoad(
        cpu: 96.0,
        queueDepth: 12,
        requestsPerSecond: 2400,
        responseTimeMs: 280,
        activeConnections: 1500
    );
    king_autoscaling_start_monitoring();
    printSnapshot('after first high-load tick');

    section('Immediate Follow-Up Tick Stays In Cooldown');

    king_autoscaling_start_monitoring();
    printSnapshot('after immediate cooldown tick');

    section('Low Load Triggers Scale Down');

    sleep(1);
    recordLoad(
        cpu: 5.0,
        queueDepth: 0,
        requestsPerSecond: 10,
        responseTimeMs: 9,
        activeConnections: 8
    );
    king_autoscaling_start_monitoring();
    printSnapshot('after low-load tick');

    king_autoscaling_stop_monitoring();
} catch (Throwable $e) {
    fail(
        'Demo failed: ' . $e::class . ': ' . $e->getMessage()
    );
}
