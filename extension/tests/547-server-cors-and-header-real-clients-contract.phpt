--TEST--
King server CORS metadata and response headers stay honest against real HTTP/1 HTTP/2 and HTTP/3 clients
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_openssl();
king_http3_skipif_require_lsquic_runtime();
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';
require __DIR__ . '/http2_server_wire_helper.inc';
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';

function king_server_cors_assert_header_values($actual, array $expected, string $label): void
{
    $values = is_array($actual) ? array_values($actual) : [$actual];
    if ($values !== $expected) {
        throw new RuntimeException(
            $label . ' mismatch: expected '
            . json_encode($expected)
            . ', got '
            . json_encode($values)
        );
    }
}

$http1 = [];
$http2 = [];
$http3 = [];
$http1Capture = [];
$http2Capture = [];
$http3Capture = [];
$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    $http1Server = king_server_websocket_wire_start_server('cors-allowlist');
    try {
        $http1Response = king_server_http1_wire_request_retry(
            $http1Server['port'],
            "OPTIONS /cors HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Origin: https://app.king.test\r\n"
            . "Access-Control-Request-Method: POST\r\n"
            . "Access-Control-Request-Headers: X-Token, Content-Type\r\n"
            . "Connection: close\r\n\r\n"
        );
        $http1 = king_server_http1_wire_parse_response($http1Response);
    } finally {
        $http1Capture = king_server_websocket_wire_stop_server($http1Server);
    }

    $http2Server = king_http2_server_wire_start_server(null, 'cors-allowlist');
    try {
        $http2 = king_http2_server_wire_request_retry($http2Server['port'], [
            'method' => 'GET',
            'path' => '/cors',
            'headers' => [
                'origin' => 'https://admin.king.test',
            ],
        ]);
    } finally {
        $http2Capture = king_http2_server_wire_stop_server($http2Server);
    }

    $http3Run = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server(
            $fixture['cert'],
            $fixture['key'],
            null,
            'cors-allowlist'
        ),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/cors',
            'GET',
            [
                'origin' => 'https://admin.king.test',
            ],
            '',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => ($response['status'] ?? null) === 200
    );
    $http3 = $http3Run['result'];
    $http3Capture = $http3Run['capture'];
} finally {
    king_http3_destroy_fixture($fixture);
}

if (($http1['status'] ?? null) !== 204) {
    throw new RuntimeException('HTTP/1 CORS preflight did not return 204.');
}
king_server_cors_assert_header_values(
    $http1['headers']['access-control-allow-origin'] ?? null,
    ['https://app.king.test'],
    'HTTP/1 access-control-allow-origin'
);
king_server_cors_assert_header_values(
    $http1['headers']['access-control-allow-methods'] ?? null,
    ['POST'],
    'HTTP/1 access-control-allow-methods'
);
king_server_cors_assert_header_values(
    $http1['headers']['access-control-allow-headers'] ?? null,
    ['X-Token, Content-Type'],
    'HTTP/1 access-control-allow-headers'
);
king_server_cors_assert_header_values(
    $http1['headers']['vary'] ?? null,
    ['Origin'],
    'HTTP/1 vary'
);
if (($http1Capture['request_cors']['origin'] ?? null) !== 'https://app.king.test') {
    throw new RuntimeException('HTTP/1 request cors origin was not materialized.');
}
if (($http1Capture['request_cors']['allow_origin'] ?? null) !== 'https://app.king.test') {
    throw new RuntimeException('HTTP/1 request cors allow_origin was not materialized.');
}
if (($http1Capture['request_cors']['preflight'] ?? null) !== true) {
    throw new RuntimeException('HTTP/1 request was not marked as preflight.');
}
if (($http1Capture['request_headers']['origin'] ?? null) !== 'https://app.king.test') {
    throw new RuntimeException('HTTP/1 normalized origin header was not preserved.');
}
if (($http1Capture['cors_stats_before_return']['server_last_cors_allow_origin'] ?? null) !== 'https://app.king.test') {
    throw new RuntimeException('HTTP/1 cors stats did not preserve the allowed origin.');
}
if (($http1Capture['cors_stats_before_return']['server_last_cors_preflight'] ?? null) !== true) {
    throw new RuntimeException('HTTP/1 cors stats did not preserve preflight state.');
}

if (($http2['status'] ?? null) !== 200 || ($http2['body'] ?? null) !== 'cors-http2-body') {
    throw new RuntimeException('HTTP/2 CORS request did not complete normally.');
}
king_server_cors_assert_header_values(
    $http2['headers']['access-control-allow-origin'] ?? null,
    ['https://admin.king.test'],
    'HTTP/2 access-control-allow-origin'
);
king_server_cors_assert_header_values(
    $http2['headers']['vary'] ?? null,
    ['Origin'],
    'HTTP/2 vary'
);
king_server_cors_assert_header_values(
    $http2['headers']['x-cors-mode'] ?? null,
    ['allowlist'],
    'HTTP/2 x-cors-mode'
);
if (($http2Capture['request']['cors']['origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/2 request cors origin was not materialized.');
}
if (($http2Capture['request']['cors']['allow_origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/2 request cors allow_origin was not materialized.');
}
if (($http2Capture['request']['cors']['preflight'] ?? null) !== false) {
    throw new RuntimeException('HTTP/2 request should not be marked as preflight.');
}
if (($http2Capture['cors_stats_before_return']['server_last_cors_allow_origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/2 cors stats did not preserve the allowed origin.');
}

if (($http3['status'] ?? null) !== 200 || ($http3['body'] ?? null) !== 'cors-http3-body') {
    throw new RuntimeException('HTTP/3 CORS request did not complete normally.');
}
king_server_cors_assert_header_values(
    $http3['headers']['access-control-allow-origin'] ?? null,
    ['https://admin.king.test'],
    'HTTP/3 access-control-allow-origin'
);
king_server_cors_assert_header_values(
    $http3['headers']['vary'] ?? null,
    ['Origin'],
    'HTTP/3 vary'
);
king_server_cors_assert_header_values(
    $http3['headers']['x-cors-mode'] ?? null,
    ['allowlist'],
    'HTTP/3 x-cors-mode'
);
if (($http3Capture['request']['cors']['origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/3 request cors origin was not materialized.');
}
if (($http3Capture['request']['cors']['allow_origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/3 request cors allow_origin was not materialized.');
}
if (($http3Capture['request']['cors']['preflight'] ?? null) !== false) {
    throw new RuntimeException('HTTP/3 request should not be marked as preflight.');
}
if (($http3Capture['cors_stats_before_return']['server_last_cors_allow_origin'] ?? null) !== 'https://admin.king.test') {
    throw new RuntimeException('HTTP/3 cors stats did not preserve the allowed origin.');
}

echo "OK\n";
?>
--EXPECT--
OK
