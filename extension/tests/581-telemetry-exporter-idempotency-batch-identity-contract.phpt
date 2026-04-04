--TEST--
King telemetry keeps a stable exporter batch identity across retry and restart replay paths
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

$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-idempotency-state-');
if ($statePath === false) {
    throw new RuntimeException('failed to reserve telemetry queue state path');
}
@unlink($statePath);

$collector = king_telemetry_test_start_collector([
    ['status' => 503, 'body' => 'retry-later'],
    ['status' => 503, 'body' => 'retry-later'],
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);

$producerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-idempotency-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-idempotency-consumer-');

file_put_contents($producerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$collector['port']}',
    'exporter_timeout_ms' => 500,
]);
king_telemetry_log('warn', 'first-batch', ['batch' => 'first']);
king_telemetry_flush();
\$status = king_telemetry_get_status();
\$component = king_system_get_component_info('telemetry');
echo json_encode([
    'queue_size' => \$status['queue_size'],
    'export_failure_count' => \$status['export_failure_count'],
    'export_success_count' => \$status['export_success_count'],
    'idempotency_policy' => \$component['configuration']['idempotency_policy'],
], JSON_UNESCAPED_SLASHES);
PHP);

file_put_contents($consumerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$collector['port']}',
    'exporter_timeout_ms' => 500,
]);
\$component = king_system_get_component_info('telemetry');
\$before = king_telemetry_get_status();
king_telemetry_flush();
\$afterFirstFlush = king_telemetry_get_status();
king_telemetry_flush();
\$afterSecondFlush = king_telemetry_get_status();
king_telemetry_log('warn', 'second-batch', ['batch' => 'second']);
king_telemetry_flush();
\$afterThirdFlush = king_telemetry_get_status();
echo json_encode([
    'queue_size_before' => \$before['queue_size'],
    'queue_size_after_first_flush' => \$afterFirstFlush['queue_size'],
    'queue_size_after_second_flush' => \$afterSecondFlush['queue_size'],
    'queue_size_after_third_flush' => \$afterThirdFlush['queue_size'],
    'export_failure_count_after_first_flush' => \$afterFirstFlush['export_failure_count'],
    'export_success_count_after_second_flush' => \$afterSecondFlush['export_success_count'],
    'export_success_count_after_third_flush' => \$afterThirdFlush['export_success_count'],
    'idempotency_policy' => \$component['configuration']['idempotency_policy'],
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

$firstBatchId = $capture[0]['headers']['x-king-telemetry-batch-id'] ?? '';
$replayBatchId = $capture[1]['headers']['x-king-telemetry-batch-id'] ?? '';
$retryBatchId = $capture[2]['headers']['x-king-telemetry-batch-id'] ?? '';
$secondBatchId = $capture[3]['headers']['x-king-telemetry-batch-id'] ?? '';

var_dump($producer['queue_size']);
var_dump($producer['export_failure_count']);
var_dump($producer['export_success_count']);
var_dump($producer['idempotency_policy']);

var_dump($consumer['queue_size_before']);
var_dump($consumer['queue_size_after_first_flush']);
var_dump($consumer['queue_size_after_second_flush']);
var_dump($consumer['queue_size_after_third_flush']);
var_dump($consumer['export_failure_count_after_first_flush']);
var_dump($consumer['export_success_count_after_second_flush']);
var_dump($consumer['export_success_count_after_third_flush']);
var_dump($consumer['idempotency_policy']);

var_dump(count($capture));
var_dump($capture[0]['path']);
var_dump($capture[1]['path']);
var_dump($capture[2]['path']);
var_dump($capture[3]['path']);
var_dump($firstBatchId !== '');
var_dump($firstBatchId === $replayBatchId);
var_dump($firstBatchId === $retryBatchId);
var_dump($secondBatchId !== '');
var_dump($secondBatchId !== $firstBatchId);
var_dump(str_contains($capture[0]['body'], '"first-batch"'));
var_dump(str_contains($capture[1]['body'], '"first-batch"'));
var_dump(str_contains($capture[2]['body'], '"first-batch"'));
var_dump(str_contains($capture[3]['body'], '"second-batch"'));

@unlink($statePath);
?>
--EXPECT--
int(1)
int(1)
int(0)
string(40) "at_least_once_with_stable_batch_identity"
int(1)
int(1)
int(0)
int(0)
int(1)
int(1)
int(2)
string(40) "at_least_once_with_stable_batch_identity"
int(4)
string(8) "/v1/logs"
string(8) "/v1/logs"
string(8) "/v1/logs"
string(8) "/v1/logs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
