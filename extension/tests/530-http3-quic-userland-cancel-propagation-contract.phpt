--TEST--
King HTTP/3 direct and dispatcher paths propagate userland CancelToken aborts into active QUIC transport state
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_openssl();
king_http3_skipif_require_lsquic_runtime();
king_http3_skipif_require_c_helpers();
if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    king_http3_skipif_skip('pcntl and posix are required for the active cancel fixture');
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

function king_schedule_http3_cancel_signal(King\CancelToken $token, int $delayUs = 250000, int $signal = SIGUSR1): int
{
    pcntl_async_signals(true);
    pcntl_signal($signal, static function () use ($token): void {
        $token->cancel();
    });

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('failed to fork cancel helper');
    }
    if ($pid === 0) {
        usleep($delayUs);
        posix_kill(posix_getppid(), $signal);
        exit(0);
    }

    return $pid;
}

function king_wait_http3_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

$fixture = king_http3_create_fixture([], 'king-http3-quic-cancel-fixture-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$payload = str_repeat('cancel-stream-body-', 16384);
$cancelCode = 0x4b01;
$cancelReason = 'cancelled by userland CancelToken';

$assertCapture = static function (array $capture) use ($cancelCode, $cancelReason): void {
    var_dump($capture['mode'] === 'cancel_observe');
    var_dump($capture['saw_initial'] === true);
    var_dump($capture['saw_established'] === true);
    var_dump($capture['saw_h3_open'] === true);
    var_dump($capture['saw_request_headers'] === true);
    var_dump($capture['saw_request_body'] === true);
    var_dump($capture['request_body_bytes'] > 0);
    var_dump($capture['stream_trigger'] === 'cancel_observe');
    var_dump($capture['close_trigger'] === 'peer_application_close');
    var_dump($capture['is_closed'] === true);
    var_dump($capture['peer_error_present'] === true);
    var_dump($capture['peer_error_is_app'] === true);
    var_dump($capture['peer_error_code'] === $cancelCode);
    var_dump($capture['peer_error_reason'] === $cancelReason);
    var_dump($capture['local_error_present'] === false);
};

$cases = [
    [
        'label' => 'direct',
        'expected' => 'king_http3_request_send() cancelled the active HTTP/3 transport via CancelToken.',
        'attempt' => static function (array $peer, King\CancelToken $cancel) use ($config, $payload) {
            return king_http3_request_send(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/cancel-observe',
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 8000,
                    '__king_cancel_token' => $cancel,
                    '__king_cancel_function_name' => 'king_http3_request_send',
                ]
            );
        },
    ],
    [
        'label' => 'dispatch',
        'expected' => 'king_client_send_request() cancelled the active HTTP/3 transport via CancelToken.',
        'attempt' => static function (array $peer, King\CancelToken $cancel) use ($config, $payload) {
            return king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/cancel-observe',
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 8000,
                    '__king_cancel_token' => $cancel,
                    '__king_cancel_function_name' => 'king_client_send_request',
                ]
            );
        },
    ],
];

try {
    foreach ($cases as $case) {
        $result = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                'cancel_observe',
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static function (array $peer) use ($case) {
                $cancel = new King\CancelToken();
                $cancelPid = king_schedule_http3_cancel_signal($cancel);

                try {
                    return $case['attempt']($peer, $cancel);
                } finally {
                    king_wait_http3_cancel_signal($cancelPid);
                }
            },
            'King\\RuntimeException',
            $case['expected']
        );

        $exception = $result['exception'];
        $capture = king_http3_failure_peer_close_capture($result['capture']);
        var_dump($case['label']);
        var_dump(get_class($exception));
        var_dump($exception->getMessage() === $case['expected']);
        var_dump(king_get_last_error() === $case['expected']);
        $assertCapture($capture);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(6) "direct"
string(21) "King\RuntimeException"
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
string(8) "dispatch"
string(21) "King\RuntimeException"
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
