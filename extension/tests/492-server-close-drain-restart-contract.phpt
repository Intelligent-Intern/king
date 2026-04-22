--TEST--
King one-shot server listeners can close, drain, and restart on the same port under real traffic
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

$http1Port = null;
$http2Port = null;
$http3Port = null;
$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    for ($i = 0; $i < 3; $i++) {
        $server = king_server_websocket_wire_start_server('wire', 1, $http1Port);
        $http1Port ??= $server['port'];
        $capture = [];

        try {
            $websocket = king_server_websocket_wire_connect_retry(
                'ws://127.0.0.1:' . $http1Port . '/chat?room=restart-' . $i
            );
            if (king_client_websocket_send($websocket, 'alpha-' . $i) !== true) {
                throw new RuntimeException('HTTP/1 websocket send failed: ' . king_get_last_error());
            }
            if (king_client_websocket_receive($websocket, 1000) !== 'beta') {
                throw new RuntimeException('HTTP/1 websocket reply drifted during restart cycle.');
            }
            if (king_client_websocket_receive($websocket, 1000) !== false) {
                throw new RuntimeException('HTTP/1 websocket did not close cleanly during restart cycle.');
            }
            if (king_client_websocket_get_status($websocket) !== 3) {
                throw new RuntimeException('HTTP/1 websocket did not end closed during restart cycle.');
            }
        } finally {
            $capture = king_server_websocket_wire_stop_server($server);
        }

        if (($capture['listen_result'] ?? null) !== true) {
            throw new RuntimeException('HTTP/1 listener did not complete successfully.');
        }
        if (($capture['post_stats']['state'] ?? null) !== 'closed') {
            throw new RuntimeException('HTTP/1 listener session did not close after restart cycle.');
        }
        if (($capture['post_stats']['transport_has_socket'] ?? null) !== false) {
            throw new RuntimeException('HTTP/1 listener kept a socket attached after close.');
        }
    }

    for ($i = 0; $i < 3; $i++) {
        $server = king_http2_server_wire_start_server($http2Port);
        $http2Port ??= $server['port'];
        $capture = [];

        try {
            $response = king_http2_server_wire_request_retry($http2Port, [
                'method' => 'POST',
                'path' => '/restart?round=' . $i,
                'headers' => [
                    'x-mode' => 'wire-h2c',
                ],
                'body' => 'payload-' . $i,
            ]);
            if (($response['status'] ?? null) !== 201 || ($response['body'] ?? null) !== 'reply:payload-' . $i) {
                throw new RuntimeException('HTTP/2 one-shot response drifted during restart cycle.');
            }
            if (($response['saw_goaway'] ?? null) !== true || ($response['peer_closed'] ?? null) !== true) {
                throw new RuntimeException('HTTP/2 one-shot listener did not drain and close cleanly.');
            }
        } finally {
            $capture = king_http2_server_wire_stop_server($server);
        }

        if (($capture['listen_result'] ?? null) !== true) {
            throw new RuntimeException('HTTP/2 listener did not complete successfully.');
        }
        if (($capture['post_stats']['state'] ?? null) !== 'closed') {
            throw new RuntimeException('HTTP/2 listener session did not close after restart cycle.');
        }
        if (($capture['post_stats']['transport_has_socket'] ?? null) !== false) {
            throw new RuntimeException('HTTP/2 listener kept a socket attached after GOAWAY drain.');
        }
    }

    for ($i = 0; $i < 3; $i++) {
        $run = king_http3_one_shot_result_with_retry(
            static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key'], $http3Port),
            'king_http3_server_wire_stop_server',
            static function (array $server) use (&$http3Port, $cfg, $i) {
                $http3Port ??= $server['port'];

                return king_http3_request_send(
                    'https://localhost:' . $http3Port . '/restart?round=' . $i,
                    'POST',
                    [
                        'x-mode' => 'wire-h3',
                    ],
                    'payload-' . $i,
                    [
                        'connection_config' => $cfg,
                        'connect_timeout_ms' => 10000,
                        'timeout_ms' => 30000,
                    ]
                );
            },
            static fn (array $response) => ($response['status'] ?? null) === 201
                && ($response['body'] ?? null) === 'reply:payload-' . $i
                && ($response['response_complete'] ?? null) === true
        );

        $capture = $run['capture'];
        if (($capture['listen_result'] ?? null) !== true) {
            throw new RuntimeException('HTTP/3 listener did not complete successfully.');
        }
        if (($capture['post_stats']['state'] ?? null) !== 'closed') {
            throw new RuntimeException('HTTP/3 listener session did not close after restart cycle.');
        }
        if (($capture['post_stats']['transport_has_socket'] ?? null) !== false) {
            throw new RuntimeException('HTTP/3 listener kept a socket attached after GOAWAY drain.');
        }
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

echo "OK\n";
?>
--EXPECT--
OK
