--TEST--
King telemetry restart semantics stay non-replaying without a durable queue state path
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_run_child_script(string $scriptPath): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' -n -d extension=' . escapeshellarg(dirname(__DIR__) . '/modules/king.so')
        . ' -d king.security_allow_config_override=1 '
        . escapeshellarg($scriptPath);

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

$producerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-consumer-');

file_put_contents($producerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$failurePort}',
    'exporter_timeout_ms' => 50,
]);
king_telemetry_log('warn', 'restart-log', ['phase' => 'producer']);
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
    'idempotency_policy' => \$component['configuration']['idempotency_policy'],
    'drain_behavior' => \$component['configuration']['drain_behavior'],
], JSON_UNESCAPED_SLASHES);
PHP);

file_put_contents($consumerScript, <<<'PHP'
<?php
$status = king_telemetry_get_status();
$component = king_system_get_component_info('telemetry');
echo json_encode([
    'queue_size' => $status['queue_size'],
    'export_failure_count' => $status['export_failure_count'],
    'export_success_count' => $status['export_success_count'],
    'delivery_contract' => $component['configuration']['delivery_contract'],
    'queue_persistence' => $component['configuration']['queue_persistence'],
    'restart_replay' => $component['configuration']['restart_replay'],
    'idempotency_policy' => $component['configuration']['idempotency_policy'],
    'drain_behavior' => $component['configuration']['drain_behavior'],
], JSON_UNESCAPED_SLASHES);
PHP);

try {
    $producer = king_telemetry_run_child_script($producerScript);
    $consumer = king_telemetry_run_child_script($consumerScript);
} finally {
    @unlink($producerScript);
    @unlink($consumerScript);
}

var_dump($producer['queue_size']);
var_dump($producer['export_failure_count']);
var_dump($producer['export_success_count']);
var_dump($producer['delivery_contract']);
var_dump($producer['queue_persistence']);
var_dump($producer['restart_replay']);
var_dump($producer['idempotency_policy']);
var_dump($producer['drain_behavior']);

var_dump($consumer['queue_size']);
var_dump($consumer['export_failure_count']);
var_dump($consumer['export_success_count']);
var_dump($consumer['delivery_contract']);
var_dump($consumer['queue_persistence']);
var_dump($consumer['restart_replay']);
var_dump($consumer['idempotency_policy']);
var_dump($consumer['drain_behavior']);
?>
--EXPECT--
int(1)
int(1)
int(0)
string(25) "best_effort_bounded_retry"
string(28) "process_local_non_persistent"
string(13) "not_supported"
string(40) "at_least_once_with_stable_batch_identity"
string(22) "single_batch_per_flush"
int(0)
int(0)
int(0)
string(25) "best_effort_bounded_retry"
string(28) "process_local_non_persistent"
string(13) "not_supported"
string(40) "at_least_once_with_stable_batch_identity"
string(22) "single_batch_per_flush"
