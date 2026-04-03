--TEST--
King HTTP/3 direct and dispatcher paths recover after QUIC flow-control exhaustion on sustained request streams
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
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

$fixture = king_http3_create_fixture([], 'king-http3-flow-control-recovery-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$payload = str_repeat('flow-control-window-body-', 1024);
$expectedBody = 'flow-control-ack:' . strlen($payload);

$cases = [
    [
        'label' => 'direct',
        'attempt' => static function (array $peer, $config, string $payload) {
            return king_http3_request_send(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/flow-control-recovery',
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 5000,
                ]
            );
        },
    ],
    [
        'label' => 'dispatch',
        'attempt' => static function (array $peer, $config, string $payload) {
            return king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/flow-control-recovery',
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 5000,
                ]
            );
        },
    ],
];

try {
    foreach ($cases as $case) {
        $run = king_http3_one_shot_result_with_retry(
            static fn () => king_http3_start_failure_peer(
                'flow_control_recovery',
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static function (array $peer) use ($case, $config, $payload) {
                $start = microtime(true);
                $response = $case['attempt']($peer, $config, $payload);

                return [
                    'response' => $response,
                    'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            },
            static fn (array $result) => ($result['response']['status'] ?? 0) === 200
                && ($result['response']['body'] ?? null) === $expectedBody
        );

        $response = $run['result']['response'];
        $elapsedMs = $run['result']['elapsed_ms'];
        $capture = king_http3_failure_peer_close_capture($run['capture']);

        var_dump($case['label']);
        var_dump($response['status']);
        var_dump($response['body'] === $expectedBody);
        var_dump($response['transport_backend'] === 'quiche_h3');
        var_dump($elapsedMs >= 250);
        var_dump($elapsedMs < 4000);
        var_dump($capture['mode'] === 'flow_control_recovery');
        var_dump($capture['saw_initial'] === true);
        var_dump($capture['saw_established'] === true);
        var_dump($capture['saw_h3_open'] === true);
        var_dump($capture['saw_request_headers'] === true);
        var_dump($capture['saw_request_body'] === true);
        var_dump($capture['saw_request_finished'] === true);
        var_dump($capture['request_body_bytes'] === strlen($payload));
        var_dump($capture['flow_control_pause_observed'] === true);
        var_dump($capture['flow_control_pause_bytes'] >= 4096);
        var_dump($capture['flow_control_resume_observed'] === true);
        var_dump($capture['response_sent'] === true);
        var_dump($capture['response_sent_after_resume'] === true);
        var_dump(($run['capture']['exit_code'] ?? 1) === 0 || ($run['capture']['exit_code'] ?? 1) === 15);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(6) "direct"
int(200)
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
string(8) "dispatch"
int(200)
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
