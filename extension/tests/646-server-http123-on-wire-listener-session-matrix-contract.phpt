--TEST--
King HTTP/1, HTTP/2, and HTTP/3 one-shot listeners preserve on-wire request-response and session lifecycle contracts in one matrix
--SKIPIF--
<?php
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
require __DIR__ . '/http3_server_wire_helper.inc';

function king_server_listener_646_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$http1Capture = [];
$http2Capture = [];
$http3DirectCapture = [];
$http3DispatchCapture = [];

try {
    $http1Server = king_server_websocket_wire_start_server('plain');
    try {
        $http1Raw = king_server_http1_wire_request_retry(
            $http1Server['port'],
            "GET /listener?protocol=http1 HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $http1Response = king_server_http1_wire_parse_response($http1Raw);
    } finally {
        $http1Capture = king_server_websocket_wire_stop_server($http1Server);
    }

    king_server_listener_646_assert(($http1Response['status'] ?? null) === 426, 'http1 status drifted');
    king_server_listener_646_assert(
        ($http1Response['headers']['x-mode'] ?? null) === 'plain-http1',
        'http1 x-mode header drifted'
    );
    king_server_listener_646_assert(
        ($http1Response['body'] ?? null) === 'upgrade-required',
        'http1 response body drifted'
    );
    king_server_listener_646_assert(($http1Capture['listen_result'] ?? false) === true, 'http1 listen result drifted');
    king_server_listener_646_assert(($http1Capture['listen_error'] ?? '') === '', 'http1 listen error drifted');
    king_server_listener_646_assert(
        ($http1Capture['request_uri'] ?? null) === '/listener?protocol=http1',
        'http1 request uri drifted'
    );
    king_server_listener_646_assert(
        ($http1Capture['request_path'] ?? null) === '/listener',
        'http1 request path drifted'
    );
    king_server_listener_646_assert(
        (int) ($http1Capture['stream_id'] ?? -1) === 0,
        'http1 stream id drifted'
    );
    king_server_listener_646_assert(
        ($http1Capture['stats']['transport_backend'] ?? null) === 'server_http1_socket',
        'http1 session transport backend drifted'
    );
    king_server_listener_646_assert(
        ($http1Capture['stats']['transport_has_socket'] ?? false) === true,
        'http1 live session should still own a socket before return'
    );
    king_server_listener_646_assert(
        ($http1Capture['post_stats']['state'] ?? null) === 'closed',
        'http1 post session state was not closed'
    );
    king_server_listener_646_assert(
        ($http1Capture['post_stats']['transport_backend'] ?? null) === 'server_http1_socket',
        'http1 post session transport backend drifted'
    );

    $http2Server = king_http2_server_wire_start_server();
    try {
        $http2Response = king_http2_server_wire_request_retry($http2Server['port'], [
            'method' => 'POST',
            'path' => '/listener?protocol=http2',
            'headers' => [
                'x-mode' => 'wire-h2c',
            ],
            'body' => 'payload-h2',
        ]);
    } finally {
        $http2Capture = king_http2_server_wire_stop_server($http2Server);
    }

    king_server_listener_646_assert(($http2Response['status'] ?? null) === 201, 'http2 status drifted');
    king_server_listener_646_assert(
        ($http2Response['headers']['x-reply-mode'] ?? null) === 'wire-h2c',
        'http2 x-reply-mode header drifted'
    );
    king_server_listener_646_assert(
        ($http2Response['headers']['content-type'] ?? null) === 'text/plain',
        'http2 content-type header drifted'
    );
    king_server_listener_646_assert(
        ($http2Response['body'] ?? null) === 'reply:payload-h2',
        'http2 response body drifted'
    );
    king_server_listener_646_assert(($http2Response['saw_goaway'] ?? false) === true, 'http2 GOAWAY was not observed');
    king_server_listener_646_assert(($http2Response['peer_closed'] ?? false) === true, 'http2 peer did not close cleanly');

    king_server_listener_646_assert(($http2Capture['listen_result'] ?? false) === true, 'http2 listen result drifted');
    king_server_listener_646_assert(($http2Capture['listen_error'] ?? '') === '', 'http2 listen error drifted');
    king_server_listener_646_assert(
        ($http2Capture['request']['protocol'] ?? null) === 'http/2',
        'http2 request protocol drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['scheme'] ?? null) === 'http',
        'http2 request scheme drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['method'] ?? null) === 'POST',
        'http2 request method drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['uri'] ?? null) === '/listener?protocol=http2',
        'http2 request uri drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['path'] ?? null) === '/listener',
        'http2 request path drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['body'] ?? null) === 'payload-h2',
        'http2 request body drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['session_is_resource'] ?? false) === true,
        'http2 request session was not a live resource'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['capability_is_int'] ?? false) === true,
        'http2 server capability was not an int token'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['transport_backend_before'] ?? null) === 'server_http2_socket',
        'http2 session transport backend drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['request']['alpn_before'] ?? null) === 'h2c',
        'http2 ALPN drifted'
    );
    king_server_listener_646_assert(
        ($http2Capture['post_stats']['state'] ?? null) === 'closed',
        'http2 post session state was not closed'
    );
    king_server_listener_646_assert(
        ($http2Capture['post_stats']['transport_backend'] ?? null) === 'server_http2_socket',
        'http2 post session transport backend drifted'
    );

    $http3DirectRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/listener?protocol=http3',
            'POST',
            [
                'x-mode' => 'wire-h3',
            ],
            'payload-h3',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => ($response['status'] ?? 0) === 201
            && ($response['response_complete'] ?? false) === true
    );
    $http3DirectResponse = $http3DirectRun['result'];
    $http3DirectCapture = $http3DirectRun['capture'];

    $http3DispatchRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_client_send_request(
            'https://localhost:' . $server['port'] . '/listener?protocol=http3',
            'POST',
            [
                'x-mode' => 'wire-h3',
            ],
            'payload-h3',
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => ($response['status'] ?? 0) === 201
            && ($response['response_complete'] ?? false) === true
    );
    $http3DispatchResponse = $http3DispatchRun['result'];
    $http3DispatchCapture = $http3DispatchRun['capture'];

    foreach (
        [
            'direct' => [$http3DirectResponse, $http3DirectCapture],
            'dispatch' => [$http3DispatchResponse, $http3DispatchCapture],
        ] as $label => [$response, $capture]
    ) {
        king_server_listener_646_assert(($response['status'] ?? null) === 201, 'http3 ' . $label . ' status drifted');
        king_server_listener_646_assert(
            ($response['protocol'] ?? null) === 'http/3',
            'http3 ' . $label . ' protocol drifted'
        );
        king_server_listener_646_assert(
            ($response['transport_backend'] ?? null) === 'quiche_h3',
            'http3 ' . $label . ' transport backend drifted'
        );
        king_server_listener_646_assert(
            ($response['response_complete'] ?? false) === true,
            'http3 ' . $label . ' response completeness drifted'
        );
        king_server_listener_646_assert(
            ($response['headers']['x-reply-mode'] ?? null) === 'wire-h3',
            'http3 ' . $label . ' x-reply-mode header drifted'
        );
        king_server_listener_646_assert(
            ($response['body'] ?? null) === 'reply:payload-h3',
            'http3 ' . $label . ' response body drifted'
        );

        king_server_listener_646_assert(($capture['listen_result'] ?? false) === true, 'http3 ' . $label . ' listen result drifted');
        king_server_listener_646_assert(($capture['listen_error'] ?? '') === '', 'http3 ' . $label . ' listen error drifted');
        king_server_listener_646_assert(
            ($capture['request']['protocol'] ?? null) === 'http/3',
            'http3 ' . $label . ' request protocol drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['scheme'] ?? null) === 'https',
            'http3 ' . $label . ' request scheme drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['method'] ?? null) === 'POST',
            'http3 ' . $label . ' request method drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['uri'] ?? null) === '/listener?protocol=http3',
            'http3 ' . $label . ' request uri drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['path'] ?? null) === '/listener',
            'http3 ' . $label . ' request path drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['body'] ?? null) === 'payload-h3',
            'http3 ' . $label . ' request body drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['session_is_resource'] ?? false) === true,
            'http3 ' . $label . ' request session was not a live resource'
        );
        king_server_listener_646_assert(
            ($capture['request']['capability_is_int'] ?? false) === true,
            'http3 ' . $label . ' server capability was not an int token'
        );
        king_server_listener_646_assert(
            ($capture['request']['transport_backend_before'] ?? null) === 'server_http3_socket',
            'http3 ' . $label . ' session transport backend drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['alpn_before'] ?? null) === 'h3',
            'http3 ' . $label . ' ALPN drifted'
        );
        king_server_listener_646_assert(
            ($capture['request']['transport_socket_family_before'] ?? null) === 'udp',
            'http3 ' . $label . ' socket family drifted'
        );
        king_server_listener_646_assert(
            ($capture['post_stats']['state'] ?? null) === 'closed',
            'http3 ' . $label . ' post session state was not closed'
        );
        king_server_listener_646_assert(
            ($capture['post_stats']['transport_backend'] ?? null) === 'server_http3_socket',
            'http3 ' . $label . ' post session transport backend drifted'
        );
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

echo "OK\n";
?>
--EXPECT--
OK
