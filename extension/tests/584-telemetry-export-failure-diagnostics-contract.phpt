--TEST--
King telemetry exposes complete export failure diagnostics across transport TLS HTTP and collector failures
--INI--
king.security_allow_config_override=1
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required";
}
?>
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require_once __DIR__ . '/server_admin_api_wire_helper.inc';

function king_telemetry_start_untrusted_tls_server(array $fixture): array
{
    $port = king_telemetry_test_pick_unused_port();
    $script = tempnam(sys_get_temp_dir(), 'king-telemetry-tls-server-');

    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$cert = $argv[2];
$key = $argv[3];
$server = stream_socket_server(
    'tls://127.0.0.1:' . $port,
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create([
        'ssl' => [
            'local_cert' => $cert,
            'local_pk' => $key,
            'allow_self_signed' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ])
);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(2);
}
fwrite(STDOUT, "READY\n");
$conn = @stream_socket_accept($server, 5);
if (is_resource($conn)) {
    usleep(250000);
    fclose($conn);
}
fclose($server);
PHP
    );

    $process = proc_open(
        [PHP_BINARY, '-n', $script, (string) $port, $fixture['server_cert'], $fixture['server_key']],
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch TLS test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('TLS test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'script' => $script,
        'port' => $port,
    ];
}

function king_telemetry_stop_untrusted_tls_server(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($server['process']);
    @unlink($server['script']);
}

function king_telemetry_recover_retry_queue(array $config): void
{
    king_telemetry_init($config);
    if (!king_telemetry_flush()) {
        throw new RuntimeException('failed to recover telemetry retry queue');
    }

    $status = king_telemetry_get_status();
    if ((int) ($status['queue_size'] ?? -1) !== 0) {
        throw new RuntimeException('telemetry retry queue did not drain during recovery');
    }
}

function king_telemetry_collect_failure_diagnostic(array $config, callable $emitSignal): array
{
    king_telemetry_init($config);
    $emitSignal();
    if (!king_telemetry_flush()) {
        throw new RuntimeException('telemetry flush unexpectedly returned false');
    }

    $status = king_telemetry_get_status();
    if (!isset($status['last_export_diagnostic']) || !is_array($status['last_export_diagnostic'])) {
        throw new RuntimeException('missing last_export_diagnostic surface');
    }

    return $status['last_export_diagnostic'];
}

function king_telemetry_export_diagnostic_checks(
    array $diagnostic,
    string $signal,
    string $stage,
    string $reason,
    string $disposition
): array {
    return [
        ($diagnostic['signal'] ?? '') === $signal,
        ($diagnostic['stage'] ?? '') === $stage,
        ($diagnostic['reason'] ?? '') === $reason,
        ($diagnostic['disposition'] ?? '') === $disposition,
        is_string($diagnostic['batch_id'] ?? null) && strlen($diagnostic['batch_id']) === 32,
        is_string($diagnostic['message'] ?? null) && $diagnostic['message'] !== '',
    ];
}

$component = king_system_get_component_info('telemetry');
var_dump(
    ($component['configuration']['failure_diagnostics_surface'] ?? '')
        === 'king_telemetry_get_status.last_export_diagnostic'
);

$transportDiagnostic = king_telemetry_collect_failure_diagnostic(
    [
        'otel_exporter_endpoint' => 'http://telemetry.invalid:4318',
        'exporter_timeout_ms' => 250,
    ],
    static function (): void {
        king_telemetry_record_metric('transport-failure-metric', 1.0, null, 'counter');
    }
);
foreach (
    array_merge(
        king_telemetry_export_diagnostic_checks(
            $transportDiagnostic,
            'metrics',
            'transport',
            'could_not_resolve_host',
            'drop'
        ),
        [
            (int) ($transportDiagnostic['curl_code'] ?? 0) > 0,
            (int) ($transportDiagnostic['http_status'] ?? -1) === 0,
            (int) ($transportDiagnostic['request_bytes'] ?? 0) > 0,
            (int) ($transportDiagnostic['response_bytes'] ?? -1) === 0,
        ]
    ) as $result
) {
    var_dump($result);
}

$tlsFixture = king_server_admin_api_create_tls_fixture();
$tlsServer = king_telemetry_start_untrusted_tls_server($tlsFixture);
try {
    $tlsDiagnostic = king_telemetry_collect_failure_diagnostic(
        [
            'otel_exporter_endpoint' => 'https://127.0.0.1:' . $tlsServer['port'],
            'exporter_timeout_ms' => 500,
        ],
        static function (): void {
            $spanId = king_telemetry_start_span('tls-failure-span');
            if (!is_string($spanId) || $spanId === '') {
                throw new RuntimeException('failed to create TLS failure span');
            }
            if (!king_telemetry_end_span($spanId, ['phase' => 'tls-failure'])) {
                throw new RuntimeException('failed to end TLS failure span');
            }
        }
    );
} finally {
    king_telemetry_stop_untrusted_tls_server($tlsServer);
    king_server_admin_api_cleanup_tls_fixture($tlsFixture);
}
foreach (
    array_merge(
        king_telemetry_export_diagnostic_checks(
            $tlsDiagnostic,
            'traces',
            'tls',
            'peer_verification_failed',
            'retry'
        ),
        [
            (int) ($tlsDiagnostic['curl_code'] ?? 0) > 0,
            (int) ($tlsDiagnostic['http_status'] ?? -1) === 0,
        ]
    ) as $result
) {
    var_dump($result);
}

$tlsRecoveryCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);
try {
    king_telemetry_recover_retry_queue([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $tlsRecoveryCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);
} finally {
    king_telemetry_test_stop_collector($tlsRecoveryCollector);
}

$httpCollector = king_telemetry_test_start_collector([
    ['status' => 503, 'body' => 'nope'],
]);
try {
    $httpDiagnostic = king_telemetry_collect_failure_diagnostic(
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $httpCollector['port'],
            'exporter_timeout_ms' => 500,
        ],
        static function (): void {
            king_telemetry_log('warn', 'http-failure-log', ['phase' => 'http-failure']);
        }
    );
} finally {
    king_telemetry_test_stop_collector($httpCollector);
}
foreach (
    array_merge(
        king_telemetry_export_diagnostic_checks(
            $httpDiagnostic,
            'logs',
            'http',
            'non_2xx_status',
            'retry'
        ),
        [
            (int) ($httpDiagnostic['curl_code'] ?? -1) === 0,
            (int) ($httpDiagnostic['http_status'] ?? -1) === 503,
        ]
    ) as $result
) {
    var_dump($result);
}

$httpRecoveryCollector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
]);
try {
    king_telemetry_recover_retry_queue([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $httpRecoveryCollector['port'],
        'exporter_timeout_ms' => 500,
    ]);
} finally {
    king_telemetry_test_stop_collector($httpRecoveryCollector);
}

$collectorFailure = king_telemetry_test_start_collector([
    ['status' => 200, 'body_bytes' => (1024 * 1024) + 128],
]);
try {
    $collectorDiagnostic = king_telemetry_collect_failure_diagnostic(
        [
            'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorFailure['port'],
            'exporter_timeout_ms' => 500,
        ],
        static function (): void {
            king_telemetry_log('error', 'collector-response-size-log', ['phase' => 'collector-failure']);
        }
    );
} finally {
    king_telemetry_test_stop_collector($collectorFailure);
}
foreach (
    array_merge(
        king_telemetry_export_diagnostic_checks(
            $collectorDiagnostic,
            'logs',
            'collector',
            'response_size_limit_exceeded',
            'retry'
        ),
        [
            (int) ($collectorDiagnostic['curl_code'] ?? -1) === 0,
            (int) ($collectorDiagnostic['http_status'] ?? -1) === 0,
            (int) ($collectorDiagnostic['request_bytes'] ?? 0) > 0,
            (int) ($collectorDiagnostic['response_bytes'] ?? 0) > (1024 * 1024),
        ]
    ) as $result
) {
    var_dump($result);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
