--TEST--
King server one-shot listeners invoke registered cancel callbacks under real client abort traffic
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the on-wire HTTP/3 fixture";
}

$cargo = trim((string) shell_exec('command -v cargo'));
if ($cargo === '') {
    echo "skip cargo is required for the HTTP/3 abort fixture";
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

function king_server_cancel_callback_assert_capture(array $capture, int $expectedStreamId, string $label): void
{
    if (($capture['listen_result'] ?? null) !== false) {
        throw new RuntimeException($label . ' listener unexpectedly completed successfully.');
    }

    if (($capture['cancel_handler_ok'] ?? null) !== true) {
        throw new RuntimeException($label . ' failed to register the cancel callback.');
    }

    if (($capture['cancelled_stream'] ?? null) !== $expectedStreamId) {
        throw new RuntimeException(
            $label . ' cancel callback saw stream '
            . json_encode($capture['cancelled_stream'] ?? null)
            . ' instead of '
            . $expectedStreamId
            . '.'
        );
    }

    $stats = $capture['post_stats'] ?? null;
    if (!is_array($stats)) {
        throw new RuntimeException($label . ' did not expose post-close stats.');
    }

    foreach ([
        'server_cancel_handler_count' => 1,
        'server_cancel_handler_invocations' => 1,
        'cancel_calls' => 1,
        'canceled_stream_count' => 1,
        'server_last_cancel_handler_stream_id' => $expectedStreamId,
        'server_last_cancel_invoked_stream_id' => $expectedStreamId,
        'last_canceled_stream_id' => $expectedStreamId,
    ] as $key => $expected) {
        if (($stats[$key] ?? null) !== $expected) {
            throw new RuntimeException(
                $label . ' stat ' . $key . ' mismatch: expected '
                . json_encode($expected)
                . ', got '
                . json_encode($stats[$key] ?? null)
            );
        }
    }
}

$fixture = king_http3_create_fixture([]);
$http1Capture = [];
$http2Capture = [];
$http3Capture = [];

try {
    $http1Server = king_server_websocket_wire_start_server('cancel-callback');
    try {
        king_server_http1_wire_abort_request(
            $http1Server['port'],
            "GET /cancel-http1 HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
    } finally {
        $http1Capture = king_server_websocket_wire_stop_server($http1Server);
    }

    $http2Server = king_http2_server_wire_start_server(null, 'cancel-callback');
    try {
        king_http2_server_wire_abort_request($http2Server['port'], [
            'method' => 'GET',
            'path' => '/cancel-http2',
        ]);
    } finally {
        $http2Capture = king_http2_server_wire_stop_server($http2Server);
    }

    $http3Server = king_http3_server_wire_start_server(
            $fixture['cert'],
            $fixture['key'],
            null,
            'cancel-callback'
    );
    try {
        $abort = king_http3_send_abort_request(
            'https://localhost:' . $http3Server['port'] . '/cancel-http3',
            100
        );
        if (($abort['aborted'] ?? false) !== true) {
            throw new RuntimeException('HTTP/3 abort helper did not confirm the abort request.');
        }
    } finally {
        $http3Capture = king_http3_server_wire_stop_server($http3Server);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

king_server_cancel_callback_assert_capture(
    $http1Capture,
    (int) ($http1Capture['stream_id'] ?? -1),
    'HTTP/1'
);
king_server_cancel_callback_assert_capture(
    $http2Capture,
    (int) ($http2Capture['request']['stream_id'] ?? -1),
    'HTTP/2'
);
king_server_cancel_callback_assert_capture(
    $http3Capture,
    (int) ($http3Capture['request']['stream_id'] ?? -1),
    'HTTP/3'
);

echo "OK\n";
?>
--EXPECT--
OK
