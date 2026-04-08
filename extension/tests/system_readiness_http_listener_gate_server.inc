<?php

/* Child harness for proving that on-wire one-shot HTTP accepts fail closed on system readiness gates. */

if ($argc < 4) {
    fwrite(STDERR, "usage: system_readiness_http_listener_gate_server.inc <capture-path> <protocol> <port>\n");
    exit(1);
}

$capturePath = $argv[1];
$protocol = $argv[2];
$port = (int) $argv[3];
$handlerCalled = false;

$listeners = [
    'http1' => 'king_http1_server_listen_once',
    'http2' => 'king_http2_server_listen_once',
    'http3' => 'king_http3_server_listen_once',
];

if (!isset($listeners[$protocol])) {
    fwrite(STDERR, "unsupported protocol: $protocol\n");
    exit(2);
}

$capture = [
    'protocol' => $protocol,
    'init_ok' => king_system_init(['component_timeout_seconds' => 1]),
    'restart_ok' => king_system_restart_component('telemetry'),
];

$status = king_system_get_status();
$capture['lifecycle'] = $status['lifecycle'] ?? null;
$capture['readiness_blocker_count'] = $status['readiness_blocker_count'] ?? null;
$capture['admission'] = $status['admission']['http_listener_accepts'] ?? null;

$listener = $listeners[$protocol];
$capture['result'] = $listener(
    '127.0.0.1',
    $port,
    null,
    static function (array $request) use (&$handlerCalled): array {
        $handlerCalled = true;

        return [
            'status' => 200,
            'headers' => [
                'content-type' => 'text/plain',
            ],
            'body' => 'should-not-run',
        ];
    }
);

$capture['handler_called'] = $handlerCalled;
$capture['error'] = king_get_last_error();

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
