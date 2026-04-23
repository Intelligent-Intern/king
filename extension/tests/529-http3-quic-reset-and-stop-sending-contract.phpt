--TEST--
King HTTP/3 direct and dispatcher paths expose QUIC reset and stop-sending lifecycle against real peers
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 failure-peer helper";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([], 'king-http3-quic-reset-stop-fixture-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$stopPayload = str_repeat('stop-body-', 2048);

$assertCapture = static function (array $capture, array $expect): void {
    var_dump($capture['mode'] === $expect['mode']);
    var_dump($capture['saw_initial'] === true);
    var_dump($capture['saw_established'] === true);
    var_dump($capture['saw_h3_open'] === true);
    var_dump($capture['saw_request_headers'] === true);
    var_dump($capture['saw_request_finished'] === false);
    var_dump($capture['close_trigger'] === 'none');
    var_dump($capture['stream_trigger'] === $expect['stream_trigger']);
    var_dump($capture['reset_stream_sent'] === $expect['reset_stream_sent']);
    var_dump($capture['reset_stream_code'] === $expect['reset_stream_code']);
    var_dump($capture['stop_sending_sent'] === $expect['stop_sending_sent']);
    var_dump($capture['stop_sending_code'] === $expect['stop_sending_code']);
    var_dump($capture['peer_error_present'] === false);
    var_dump($capture['local_error_present'] === false);

    if ($expect['expects_request_body']) {
        var_dump($capture['saw_request_body'] === true);
        var_dump($capture['request_body_bytes'] > 0);
        return;
    }

    var_dump($capture['saw_request_body'] === false);
    var_dump($capture['request_body_bytes'] === 0);
};

$cases = [
    [
        'mode' => 'reset_stream',
        'path' => '/reset-stream',
        'method' => 'GET',
        'headers' => null,
        'body' => null,
        'exception_class' => 'King\\ProtocolException',
        'expected_direct' => null,
        'expected_dispatch' => null,
        'accepted_direct_messages' => [
            'king_http3_request_send() received an HTTP/3 stream reset for the active request.',
            'king_http3_request_send() received an HTTP/3 stream reset for the active request (code 66).',
        ],
        'accepted_dispatch_messages' => [
            'king_client_send_request() received an HTTP/3 stream reset for the active request.',
            'king_client_send_request() received an HTTP/3 stream reset for the active request (code 66).',
        ],
        'stream_trigger' => 'reset_stream',
        'reset_stream_sent' => true,
        'reset_stream_code' => 66,
        'stop_sending_sent' => false,
        'stop_sending_code' => 0,
        'expects_request_body' => false,
    ],
    [
        'mode' => 'stop_sending',
        'path' => '/stop-sending',
        'method' => 'POST',
        'headers' => ['content-type' => 'text/plain'],
        'body' => $stopPayload,
        'exception_class' => 'King\\ProtocolException',
        'expected_direct' => 'king_http3_request_send() received QUIC STOP_SENDING while sending the active HTTP/3 request body.',
        'expected_dispatch' => 'king_client_send_request() received QUIC STOP_SENDING while sending the active HTTP/3 request body.',
        'accepted_direct_messages' => [],
        'accepted_dispatch_messages' => [],
        'stream_trigger' => 'stop_sending',
        'reset_stream_sent' => false,
        'reset_stream_code' => 0,
        'stop_sending_sent' => true,
        'stop_sending_code' => 67,
        'expects_request_body' => true,
    ],
];

try {
    foreach ($cases as $case) {
        $direct = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_http3_request_send(
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path'],
                $case['method'],
                $case['headers'],
                $case['body'],
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 8000,
                ]
            ),
            $case['exception_class'],
            $case['expected_direct']
        );
        $e = $direct['exception'];
        $capture = king_http3_failure_peer_close_capture($direct['capture']);
        $acceptedMessages = $case['accepted_direct_messages'] !== []
            ? $case['accepted_direct_messages']
            : [$case['expected_direct']];
        var_dump(get_class($e));
        var_dump(in_array($e->getMessage(), $acceptedMessages, true));
        var_dump(in_array(king_get_last_error(), $acceptedMessages, true));
        $assertCapture($capture, $case);

        $dispatch = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path'],
                $case['method'],
                $case['headers'],
                $case['body'],
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 8000,
                ]
            ),
            $case['exception_class'],
            $case['expected_dispatch']
        );
        $e = $dispatch['exception'];
        $capture = king_http3_failure_peer_close_capture($dispatch['capture']);
        $acceptedMessages = $case['accepted_dispatch_messages'] !== []
            ? $case['accepted_dispatch_messages']
            : [$case['expected_dispatch']];
        var_dump(get_class($e));
        var_dump(in_array($e->getMessage(), $acceptedMessages, true));
        var_dump(in_array(king_get_last_error(), $acceptedMessages, true));
        $assertCapture($capture, $case);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(22) "King\ProtocolException"
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
string(22) "King\ProtocolException"
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
string(22) "King\ProtocolException"
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
string(22) "King\ProtocolException"
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
