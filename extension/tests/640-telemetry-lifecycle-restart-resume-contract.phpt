--TEST--
King telemetry keeps lifecycle memory bounds and trace-context hierarchy intact across restart replay
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_telemetry_lifecycle_run_child_script(string $scriptPath, array $ini = []): array
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
        throw new RuntimeException('child telemetry lifecycle script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('child telemetry lifecycle script did not return JSON');
    }

    return $decoded;
}

$failurePort = king_telemetry_test_pick_unused_port();
$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-lifecycle-state-');
if ($statePath === false) {
    throw new RuntimeException('failed to reserve telemetry lifecycle queue state path');
}
@unlink($statePath);

$producerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-lifecycle-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-lifecycle-consumer-');

file_put_contents($producerScript, <<<PHP
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:{$failurePort}',
    'exporter_timeout_ms' => 50,
    'batch_processor_max_queue_size' => 2,
]);

\$rootSpan = king_telemetry_start_span('resume-root', ['phase' => 'producer']);
\$childSpan = king_telemetry_start_span('resume-child', ['phase' => 'producer']);
king_telemetry_log('warn', 'resume-log', ['phase' => 'producer']);
king_telemetry_end_span(\$childSpan, ['result' => 'child-complete']);
king_telemetry_end_span(\$rootSpan, ['result' => 'root-complete']);
king_telemetry_flush();

\$status = king_telemetry_get_status();
\$component = king_system_get_component_info('telemetry');
echo json_encode([
    'queue_size' => \$status['queue_size'],
    'export_failure_count' => \$status['export_failure_count'],
    'pending_span_count' => \$status['pending_span_count'],
    'pending_log_count' => \$status['pending_log_count'],
    'queue_bytes' => \$status['queue_bytes'],
    'memory_bytes' => \$status['memory_bytes'],
    'memory_byte_limit' => \$status['memory_byte_limit'],
    'queue_high_water_bytes' => \$status['queue_high_water_bytes'],
    'restart_replay' => \$component['configuration']['restart_replay'],
], JSON_UNESCAPED_SLASHES);
PHP);

$collector = king_telemetry_test_start_collector([
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
king_telemetry_flush();
\$after = king_telemetry_get_status();
echo json_encode([
    'queue_size_before' => \$before['queue_size'],
    'queue_size_after' => \$after['queue_size'],
    'export_success_count_after' => \$after['export_success_count'],
    'pending_span_count_after' => \$after['pending_span_count'],
    'pending_log_count_after' => \$after['pending_log_count'],
], JSON_UNESCAPED_SLASHES);
PHP);

try {
    $ini = ['king.otel_queue_state_path' => $statePath];
    $producer = king_telemetry_lifecycle_run_child_script($producerScript, $ini);
    $consumer = king_telemetry_lifecycle_run_child_script($consumerScript, $ini);
} finally {
    @unlink($producerScript);
    @unlink($consumerScript);
    $capture = king_telemetry_test_stop_collector($collector);
}

$tracesEntry = null;
$logsEntry = null;
foreach ($capture as $entry) {
    if (($entry['path'] ?? null) === '/v1/traces') {
        $tracesEntry = $entry;
    } elseif (($entry['path'] ?? null) === '/v1/logs') {
        $logsEntry = $entry;
    }
}

$rootSpan = null;
$childSpan = null;
if ($tracesEntry !== null) {
    $payload = json_decode((string) ($tracesEntry['body'] ?? ''), true);
    $spans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? [];
    if (is_array($spans)) {
        foreach ($spans as $span) {
            if (!is_array($span)) {
                continue;
            }
            if (($span['name'] ?? null) === 'resume-root') {
                $rootSpan = $span;
            } elseif (($span['name'] ?? null) === 'resume-child') {
                $childSpan = $span;
            }
        }
    }
}

var_dump($producer['queue_size']);
var_dump($producer['export_failure_count']);
var_dump($producer['pending_span_count']);
var_dump($producer['pending_log_count']);
var_dump((int) ($producer['queue_bytes'] ?? 0) > 0);
var_dump((int) ($producer['queue_bytes'] ?? PHP_INT_MAX) <= (int) ($producer['memory_byte_limit'] ?? 0));
var_dump((int) ($producer['memory_bytes'] ?? PHP_INT_MAX) <= (int) ($producer['memory_byte_limit'] ?? 0));
var_dump((int) ($producer['queue_high_water_bytes'] ?? PHP_INT_MAX) <= (int) ($producer['memory_byte_limit'] ?? 0));
var_dump($producer['restart_replay']);

var_dump($consumer['queue_size_before']);
var_dump($consumer['queue_size_after']);
var_dump($consumer['export_success_count_after']);
var_dump($consumer['pending_span_count_after']);
var_dump($consumer['pending_log_count_after']);

var_dump(count($capture));
var_dump($tracesEntry !== null);
var_dump($logsEntry !== null);
var_dump($rootSpan !== null);
var_dump($childSpan !== null);
var_dump(strlen((string) ($rootSpan['traceId'] ?? '')) === 32);
var_dump(strlen((string) ($rootSpan['spanId'] ?? '')) === 16);
var_dump(($childSpan['traceId'] ?? null) === ($rootSpan['traceId'] ?? null));
var_dump(($childSpan['parentSpanId'] ?? null) === ($rootSpan['spanId'] ?? null));
var_dump(str_contains((string) ($logsEntry['body'] ?? ''), '"resume-log"'));
var_dump(is_file($statePath));

@unlink($statePath);
?>
--EXPECT--
int(1)
int(1)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "best_effort_supported"
int(1)
int(0)
int(1)
int(0)
int(0)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
