--TEST--
King drain stops new HTTP listener work while preserving already admitted on-wire HTTP/1, HTTP/2, and HTTP/3 requests
--SKIPIF--
<?php
if (
    !function_exists('proc_open')
    || !function_exists('proc_get_status')
    || !function_exists('proc_terminate')
    || !function_exists('stream_socket_server')
) {
    echo "skip proc_open, proc_get_status, proc_terminate, and stream_socket_server are required";
}

if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the on-wire HTTP/3 fixture";
}

$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';
require __DIR__ . '/http2_server_wire_helper.inc';
require __DIR__ . '/http3_test_helper.inc';

function king_system_drain_pick_port(string $transport): int
{
    $flags = $transport === 'udp'
        ? STREAM_SERVER_BIND
        : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
    $server = stream_socket_server($transport . '://127.0.0.1:0', $errno, $errstr, $flags);
    if ($server === false) {
        throw new RuntimeException("failed to reserve $transport test port: $errstr");
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);
    [, $port] = explode(':', $address, 2);

    return (int) $port;
}

function king_system_drain_start_http_listener_child(
    string $protocol,
    int $port,
    string $certFile = '',
    string $keyFile = ''
): array {
    $capturePath = tempnam(sys_get_temp_dir(), 'king-system-drain-http-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        '-n',
        '-d',
        escapeshellarg('extension=' . $extensionPath),
        '-d',
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/system_drain_http_listener_inflight_server.inc'),
        escapeshellarg($capturePath),
        escapeshellarg($protocol),
        (string) $port,
        escapeshellarg($certFile),
        escapeshellarg($keyFile),
    ]);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capturePath);
        throw new RuntimeException("failed to launch $protocol drain child");
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($capturePath);
        throw new RuntimeException(
            "$protocol drain child failed before ready:\n" . trim($stdout . "\n" . $stderr)
        );
    }

    usleep(100000);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capturePath,
    ];
}

function king_system_drain_stop_http_listener_child(array $child): array
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);

    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $exitCode = proc_close($child['process']);

    $capture = [];
    if (is_file($child['capture'])) {
        $capture = json_decode((string) file_get_contents($child['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($child['capture']);
    }

    $capture['stdout'] = $stdout;
    $capture['stderr'] = $stderr;
    $capture['exit_code'] = $exitCode;

    return $capture;
}

function king_system_drain_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_system_drain_assert_common_capture(array $capture, string $protocol): void
{
    king_system_drain_assert(($capture['exit_code'] ?? 1) === 0, "$protocol child exited non-zero");
    king_system_drain_assert(($capture['stderr'] ?? '') === '', "$protocol child wrote stderr");
    king_system_drain_assert(($capture['init_ok'] ?? false) === true, "$protocol system init failed");
    king_system_drain_assert(
        ($capture['initial_status']['lifecycle'] ?? null) === 'ready',
        "$protocol initial lifecycle was not ready"
    );
    king_system_drain_assert(
        ($capture['initial_status']['http_listener_accepts'] ?? null) === true,
        "$protocol initial listener admission was not open"
    );
    king_system_drain_assert(
        ($capture['first_handler_called'] ?? false) === true,
        "$protocol first handler was not invoked"
    );
    king_system_drain_assert(
        ($capture['first_status_before_restart']['lifecycle'] ?? null) === 'ready',
        "$protocol first request was not admitted before drain"
    );
    king_system_drain_assert(
        ($capture['first_status_before_restart']['http_listener_accepts'] ?? null) === true,
        "$protocol first request did not see open listener admission"
    );
    king_system_drain_assert(($capture['restart_ok'] ?? false) === true, "$protocol restart failed");
    king_system_drain_assert(
        ($capture['restart_error'] ?? '') === '',
        "$protocol restart reported an unexpected error"
    );
    king_system_drain_assert(
        ($capture['status_during_first_handler']['lifecycle'] ?? null) === 'draining',
        "$protocol handler did not observe draining after restart"
    );
    king_system_drain_assert(
        ($capture['status_during_first_handler']['http_listener_accepts'] ?? null) === false,
        "$protocol drain left listener admission open during the first handler"
    );
    king_system_drain_assert(
        ($capture['status_during_first_handler']['drain_requested'] ?? null) === true,
        "$protocol drain intent was not requested during the first handler"
    );
    king_system_drain_assert(($capture['first_result'] ?? false) === true, "$protocol first listen_once failed");
    king_system_drain_assert(
        ($capture['first_error'] ?? '') === '',
        "$protocol first listen_once reported an unexpected error"
    );
    king_system_drain_assert(
        ($capture['status_before_second']['lifecycle'] ?? null) === 'draining',
        "$protocol second listener attempt did not happen while draining"
    );
    king_system_drain_assert(
        ($capture['status_before_second']['http_listener_accepts'] ?? null) === false,
        "$protocol second listener attempt still had admission open"
    );
    king_system_drain_assert(
        ($capture['second_result'] ?? true) === false,
        "$protocol second listen_once was not blocked during drain"
    );
    king_system_drain_assert(
        ($capture['second_handler_called'] ?? false) === false,
        "$protocol second handler ran even though drain should block new work"
    );
    king_system_drain_assert(
        str_contains((string) ($capture['second_error'] ?? ''), 'cannot admit http_listener_accepts'),
        "$protocol second listen_once did not fail on http_listener_accepts"
    );
    king_system_drain_assert(
        str_contains((string) ($capture['second_error'] ?? ''), "lifecycle is 'draining'"),
        "$protocol second listen_once did not report draining lifecycle"
    );
}

$fixture = king_http3_create_fixture([]);
$http3Config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    $http1Port = king_system_drain_pick_port('tcp');
    $http1Child = king_system_drain_start_http_listener_child('http1', $http1Port);
    $http1Capture = [];

    try {
        $http1Raw = king_server_http1_wire_request_retry(
            $http1Port,
            "GET /drain?protocol=http1 HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $http1Response = king_server_http1_wire_parse_response($http1Raw);
    } finally {
        $http1Capture = king_system_drain_stop_http_listener_child($http1Child);
    }

    king_system_drain_assert(($http1Response['status'] ?? null) === 200, 'http1 status drifted during drain');
    king_system_drain_assert(
        ($http1Response['headers']['x-drain-proof'] ?? null) === 'in-flight',
        'http1 response lost the drain proof header'
    );
    king_system_drain_assert(
        ($http1Response['body'] ?? null) === 'drain-inflight:http/1.1',
        'http1 response body drifted during drain'
    );
    king_system_drain_assert_common_capture($http1Capture, 'http1');

    $http2Port = king_system_drain_pick_port('tcp');
    $http2Child = king_system_drain_start_http_listener_child('http2', $http2Port);
    $http2Capture = [];

    try {
        $http2Response = king_http2_server_wire_request_retry($http2Port, [
            'method' => 'GET',
            'path' => '/drain?protocol=http2',
            'headers' => [
                'x-drain-proof' => 'client',
            ],
        ]);
    } finally {
        $http2Capture = king_system_drain_stop_http_listener_child($http2Child);
    }

    king_system_drain_assert(($http2Response['status'] ?? null) === 200, 'http2 status drifted during drain');
    king_system_drain_assert(
        ($http2Response['headers']['x-drain-proof'] ?? null) === 'in-flight',
        'http2 response lost the drain proof header'
    );
    king_system_drain_assert(
        ($http2Response['body'] ?? null) === 'drain-inflight:http/2',
        'http2 response body drifted during drain'
    );
    king_system_drain_assert(($http2Response['saw_goaway'] ?? false) === true, 'http2 did not send GOAWAY');
    king_system_drain_assert(($http2Response['peer_closed'] ?? false) === true, 'http2 peer did not close');
    king_system_drain_assert_common_capture($http2Capture, 'http2');

    $http3Port = king_system_drain_pick_port('udp');
    $http3Child = king_system_drain_start_http_listener_child(
        'http3',
        $http3Port,
        $fixture['cert'],
        $fixture['key']
    );
    $http3Capture = [];

    try {
        $http3Response = king_http3_request_with_retry(
            static fn () => king_http3_request_send(
                'https://localhost:' . $http3Port . '/drain?protocol=http3',
                'GET',
                [
                    'x-drain-proof' => 'client',
                ],
                '',
                [
                    'connection_config' => $http3Config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 30000,
                ]
            )
        );
    } finally {
        $http3Capture = king_system_drain_stop_http_listener_child($http3Child);
    }

    king_system_drain_assert(($http3Response['status'] ?? null) === 200, 'http3 status drifted during drain');
    king_system_drain_assert(
        ($http3Response['headers']['x-drain-proof'] ?? null) === 'in-flight',
        'http3 response lost the drain proof header'
    );
    king_system_drain_assert(
        ($http3Response['body'] ?? null) === 'drain-inflight:http/3',
        'http3 response body drifted during drain'
    );
    king_system_drain_assert(
        ($http3Response['response_complete'] ?? false) === true,
        'http3 response did not complete during drain'
    );
    king_system_drain_assert_common_capture($http3Capture, 'http3');
} finally {
    king_http3_destroy_fixture($fixture);
}

echo "OK\n";
?>
--EXPECT--
OK
