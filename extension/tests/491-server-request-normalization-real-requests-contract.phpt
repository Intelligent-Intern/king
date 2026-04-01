--TEST--
King server one-shot listeners normalize real request targets into stable uri and path fields
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the on-wire HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';
require __DIR__ . '/http2_server_wire_helper.inc';
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';

$http1Capture = [];
$http2Capture = [];
$http3Capture = [];
$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    $http1Server = king_server_websocket_wire_start_server('plain');
    try {
        $http1Response = king_server_http1_wire_request_retry(
            $http1Server['port'],
            "GET /realtime?room=alpha HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
        if (strpos($http1Response, "HTTP/1.1 426") !== 0) {
            throw new RuntimeException('HTTP/1 normalization fixture did not complete the expected request.');
        }
    } finally {
        $http1Capture = king_server_websocket_wire_stop_server($http1Server);
    }

    $http2Server = king_http2_server_wire_start_server();
    try {
        $http2Response = king_http2_server_wire_request_retry($http2Server['port'], [
            'method' => 'POST',
            'path' => '/wire?room=alpha',
            'headers' => [
                'x-mode' => 'wire-h2c',
            ],
            'body' => 'payload',
        ]);
        if (($http2Response['status'] ?? null) !== 201) {
            throw new RuntimeException('HTTP/2 normalization fixture did not complete the expected request.');
        }
    } finally {
        $http2Capture = king_http2_server_wire_stop_server($http2Server);
    }

    $http3Run = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/wire?room=alpha',
            'POST',
            [
                'x-mode' => 'wire-h3',
            ],
            'payload',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => ($response['status'] ?? null) === 201
    );
    $http3Capture = $http3Run['capture'];
} finally {
    king_http3_destroy_fixture($fixture);
}

if (($http1Capture['request_uri'] ?? null) !== '/realtime?room=alpha') {
    throw new RuntimeException('HTTP/1 request uri was not preserved.');
}
if (($http1Capture['request_path'] ?? null) !== '/realtime') {
    throw new RuntimeException('HTTP/1 request path was not normalized.');
}
if (($http2Capture['request']['uri'] ?? null) !== '/wire?room=alpha') {
    throw new RuntimeException('HTTP/2 request uri was not preserved.');
}
if (($http2Capture['request']['path'] ?? null) !== '/wire') {
    throw new RuntimeException('HTTP/2 request path was not normalized.');
}
if (($http3Capture['request']['uri'] ?? null) !== '/wire?room=alpha') {
    throw new RuntimeException('HTTP/3 request uri was not preserved.');
}
if (($http3Capture['request']['path'] ?? null) !== '/wire') {
    throw new RuntimeException('HTTP/3 request path was not normalized.');
}

echo "OK\n";
?>
--EXPECT--
OK
