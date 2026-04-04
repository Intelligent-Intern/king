--TEST--
King telemetry replays persisted retry batches after process restart when a durable queue state path is configured
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_run_child_script(string $scriptPath, array $ini = []): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' -n -d extension=' . escapeshellarg(dirname(__DIR__) . '/modules/king.so')
        . ' -d king.security_allow_config_override=1';

    foreach ($ini as $name => $value) {
        $command .= ' -d ' . escapeshellarg($name . '=' . $value);
    }

    $command .= ' ' . escapeshellarg($scriptPath);

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('child telemetry script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('child telemetry script did not return JSON');
    }

    return $decoded;
}

$failurePort = king_telemetry_test_pick_unused_port();
$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-replay-state-');
if ($statePath === false) {
    throw new RuntimeException('failed to reserve telemetry queue state path');
}
@unlink($statePath);

$producerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-consumer-');

file_put_contents($producerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$failurePort}',
    'exporter_timeout_ms' => 50,
]);
\$spanId = king_telemetry_start_span('restart-span', ['phase' => 'producer']);
king_telemetry_record_metric('restart.metric', 42, ['phase' => 'producer']);
king_telemetry_log('warn', 'restart-log', ['phase' => 'producer']);
king_telemetry_end_span(\$spanId, ['result' => 'queued']);
king_telemetry_flush();
\$status = king_telemetry_get_status();
\$component = king_system_get_component_info('telemetry');
echo json_encode([
    'queue_size' => \$status['queue_size'],
    'export_failure_count' => \$status['export_failure_count'],
    'export_success_count' => \$status['export_success_count'],
    'delivery_contract' => \$component['configuration']['delivery_contract'],
    'queue_persistence' => \$component['configuration']['queue_persistence'],
    'restart_replay' => \$component['configuration']['restart_replay'],
    'drain_behavior' => \$component['configuration']['drain_behavior'],
], JSON_UNESCAPED_SLASHES);
PHP);

$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

file_put_contents($consumerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$collector['port']}',
    'exporter_timeout_ms' => 500,
]);
\$before = king_telemetry_get_status();
\$component = king_system_get_component_info('telemetry');
king_telemetry_flush();
\$after = king_telemetry_get_status();
echo json_encode([
    'queue_size_before' => \$before['queue_size'],
    'queue_size_after' => \$after['queue_size'],
    'export_failure_count_after' => \$after['export_failure_count'],
    'export_success_count_after' => \$after['export_success_count'],
    'delivery_contract' => \$component['configuration']['delivery_contract'],
    'queue_persistence' => \$component['configuration']['queue_persistence'],
    'restart_replay' => \$component['configuration']['restart_replay'],
    'drain_behavior' => \$component['configuration']['drain_behavior'],
], JSON_UNESCAPED_SLASHES);
PHP);

try {
    $ini = ['king.otel_queue_state_path' => $statePath];
    $producer = king_telemetry_run_child_script($producerScript, $ini);
    $consumer = king_telemetry_run_child_script($consumerScript, $ini);
} finally {
    @unlink($producerScript);
    @unlink($consumerScript);
    $capture = king_telemetry_test_stop_collector($collector);
}

var_dump($producer['queue_size']);
var_dump($producer['export_failure_count']);
var_dump($producer['export_success_count']);
var_dump($producer['delivery_contract']);
var_dump($producer['queue_persistence']);
var_dump($producer['restart_replay']);
var_dump($producer['drain_behavior']);

var_dump($consumer['queue_size_before']);
var_dump($consumer['queue_size_after']);
var_dump($consumer['export_failure_count_after']);
var_dump($consumer['export_success_count_after']);
var_dump($consumer['delivery_contract']);
var_dump($consumer['queue_persistence']);
var_dump($consumer['restart_replay']);
var_dump($consumer['drain_behavior']);

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump($capture[2]['path']);
var_dump(str_contains($capture[0]['body'], '"restart.metric"'));
var_dump(str_contains($capture[1]['body'], '"restart-span"'));
var_dump(str_contains($capture[2]['body'], '"restart-log"'));
var_dump(is_file($statePath));

@unlink($statePath);
?>
--EXPECT--
int(1)
int(1)
int(0)
string(25) "best_effort_bounded_retry"
string(26) "process_local_durable_file"
string(21) "best_effort_supported"
string(22) "single_batch_per_flush"
int(1)
int(0)
int(0)
int(1)
string(25) "best_effort_bounded_retry"
string(26) "process_local_durable_file"
string(21) "best_effort_supported"
string(22) "single_batch_per_flush"
int(3)
string(11) "/v1/metrics"
string(10) "/v1/traces"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(true)
bool(false)
