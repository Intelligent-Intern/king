--TEST--
King HTTP/3 one-shot runtime keeps timeout churn from poisoning later healthy direct and dispatcher requests
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_QUICHE_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_QUICHE_SERVER must point at a prebuilt quiche-server binary";
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
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture(
    [
        'stable-direct.txt' => "stable-direct-http3\n",
        'stable-dispatch.txt' => "stable-dispatch-http3\n",
    ],
    'king-http3-churn-fixture-'
);
$healthyServer = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);
$silentServer = king_http3_start_silent_udp_server();
$timeoutClasses = [];
$successes = [];

try {
    $config = king_new_config([
        'tls_default_ca_file' => $fixture['cert'],
    ]);
    $stableDirectUrl = king_http3_test_server_url($healthyServer, '/stable-direct.txt');
    $stableDispatchUrl = king_http3_test_server_url($healthyServer, '/stable-dispatch.txt');

    for ($round = 0; $round < 3; $round++) {
        try {
            king_http3_request_send(
                'https://127.0.0.1:' . $silentServer[1] . '/',
                'GET',
                null,
                null,
                [
                    'connect_timeout_ms' => 100,
                    'timeout_ms' => 200,
                ]
            );
            $timeoutClasses[] = 'no-exception-direct';
        } catch (Throwable $e) {
            $timeoutClasses[] = get_class($e);
        }

        $direct = king_http3_request_with_retry(
            static fn () => king_http3_request_send(
                $stableDirectUrl,
                'GET',
                null,
                null,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 5000,
                    'timeout_ms' => 15000,
                ]
            )
        );

        try {
            king_client_send_request(
                'https://127.0.0.1:' . $silentServer[1] . '/',
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connect_timeout_ms' => 100,
                    'timeout_ms' => 200,
                ]
            );
            $timeoutClasses[] = 'no-exception-dispatch';
        } catch (Throwable $e) {
            $timeoutClasses[] = get_class($e);
        }

        $dispatch = king_http3_request_with_retry(
            static fn () => king_client_send_request(
                $stableDispatchUrl,
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 5000,
                    'timeout_ms' => 15000,
                ]
            )
        );

        $successes[] = [
            'direct_status' => $direct['status'],
            'direct_body' => $direct['body'],
            'direct_backend' => $direct['transport_backend'],
            'dispatch_status' => $dispatch['status'],
            'dispatch_body' => $dispatch['body'],
            'dispatch_backend' => $dispatch['transport_backend'],
        ];
    }
} finally {
    king_http3_stop_silent_udp_server($silentServer);
    king_http3_stop_test_server($healthyServer);
    king_http3_destroy_fixture($fixture);
}

var_dump($timeoutClasses === array_fill(0, 6, 'King\\TimeoutException'));
var_dump(count($successes));

foreach ($successes as $result) {
    var_dump($result['direct_status']);
    var_dump($result['direct_body']);
    var_dump($result['direct_backend']);
    var_dump($result['dispatch_status']);
    var_dump($result['dispatch_body']);
    var_dump($result['dispatch_backend']);
}
?>
--EXPECT--
bool(true)
int(3)
int(200)
string(20) "stable-direct-http3
"
string(9) "quiche_h3"
int(200)
string(22) "stable-dispatch-http3
"
string(9) "quiche_h3"
int(200)
string(20) "stable-direct-http3
"
string(9) "quiche_h3"
int(200)
string(22) "stable-dispatch-http3
"
string(9) "quiche_h3"
int(200)
string(20) "stable-direct-http3
"
string(9) "quiche_h3"
int(200)
string(22) "stable-dispatch-http3
"
string(9) "quiche_h3"
