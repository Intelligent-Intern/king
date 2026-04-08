<?php

/* Child harness for proving that admitted on-wire HTTP listener work survives a later system drain. */

if ($argc < 6) {
    fwrite(
        STDERR,
        "usage: system_drain_http_listener_inflight_server.inc <capture-path> <protocol> <port> <cert> <key>\n"
    );
    exit(1);
}

function king_system_drain_http_listener_capture_status(): array
{
    $status = king_system_get_status();

    return [
        'lifecycle' => $status['lifecycle'] ?? null,
        'readiness_blocker_count' => $status['readiness_blocker_count'] ?? null,
        'http_listener_accepts' => $status['admission']['http_listener_accepts'] ?? null,
        'drain_requested' => $status['drain_intent']['requested'] ?? null,
        'drain_active' => $status['drain_intent']['active'] ?? null,
    ];
}

function king_system_drain_http_listener_wait_until_ready(int $maxSeconds = 12): void
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before on-wire drain listener start');
}

$capturePath = $argv[1];
$protocol = $argv[2];
$port = (int) $argv[3];
$certFile = $argv[4];
$keyFile = $argv[5];
$firstHandlerCalled = false;
$secondHandlerCalled = false;

$listeners = [
    'http1' => 'king_http1_server_listen_once',
    'http2' => 'king_http2_server_listen_once',
    'http3' => 'king_http3_server_listen_once',
];

if (!isset($listeners[$protocol])) {
    fwrite(STDERR, "unsupported protocol: $protocol\n");
    exit(2);
}

$host = $protocol === 'http3' ? 'localhost' : '127.0.0.1';
$config = null;
if ($protocol === 'http3') {
    if ($certFile === '' || $keyFile === '') {
        fwrite(STDERR, "http3 requires cert and key paths\n");
        exit(3);
    }

    $config = king_new_config([
        'tls_default_cert_file' => $certFile,
        'tls_default_key_file' => $keyFile,
    ]);
}

$listener = $listeners[$protocol];
$capture = [
    'protocol' => $protocol,
    'init_ok' => king_system_init(['component_timeout_seconds' => 2]),
];
king_system_drain_http_listener_wait_until_ready();
$capture['initial_status'] = king_system_drain_http_listener_capture_status();

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$capture['first_result'] = $listener(
    $host,
    $port,
    $config,
    static function (array $request) use (&$capture, &$firstHandlerCalled): array {
        $firstHandlerCalled = true;
        $capture['first_request'] = [
            'protocol' => $request['protocol'] ?? null,
            'method' => $request['method'] ?? null,
            'uri' => $request['uri'] ?? null,
        ];
        $capture['first_status_before_restart'] = king_system_drain_http_listener_capture_status();
        $capture['restart_ok'] = king_system_restart_component('telemetry');
        $capture['restart_error'] = king_get_last_error();
        usleep(250000);
        $capture['status_during_first_handler'] = king_system_drain_http_listener_capture_status();

        return [
            'status' => 200,
            'headers' => [
                'content-type' => 'text/plain',
                'x-drain-proof' => 'in-flight',
            ],
            'body' => 'drain-inflight:' . ($request['protocol'] ?? 'unknown'),
        ];
    }
);

$capture['first_handler_called'] = $firstHandlerCalled;
$capture['first_error'] = king_get_last_error();
$capture['status_after_first'] = king_system_drain_http_listener_capture_status();
$capture['status_before_second'] = king_system_drain_http_listener_capture_status();

$capture['second_result'] = $listener(
    $host,
    $port,
    $config,
    static function (array $request) use (&$capture, &$secondHandlerCalled): array {
        $secondHandlerCalled = true;
        $capture['second_request'] = [
            'protocol' => $request['protocol'] ?? null,
            'method' => $request['method'] ?? null,
            'uri' => $request['uri'] ?? null,
        ];

        return [
            'status' => 500,
            'headers' => [
                'content-type' => 'text/plain',
            ],
            'body' => 'should-not-run',
        ];
    }
);

$capture['second_handler_called'] = $secondHandlerCalled;
$capture['second_error'] = king_get_last_error();
$capture['status_after_second'] = king_system_drain_http_listener_capture_status();

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
