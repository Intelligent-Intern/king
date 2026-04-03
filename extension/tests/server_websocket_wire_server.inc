<?php

/* Harness server for capturing on-wire HTTP/1 and WebSocket one-shot listener behavior. */

if ($argc < 4) {
    fwrite(STDERR, "usage: server_websocket_wire_server.inc <capture-path> <port> <mode> [iterations]\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$mode = $argv[3];
$iterations = isset($argv[4]) ? max(1, (int) $argv[4]) : 1;
$capture = [
    'mode' => $mode,
    'iterations' => $iterations,
    'listen_results' => [],
    'received_payloads' => [],
    'reply_payloads' => [],
    'upgrade_success_count' => 0,
    'close_success_count' => 0,
];
$listenerConfig = null;
$session = null;

if (
    $mode === 'slowloris-accept'
    || $mode === 'slowloris-head'
) {
    $listenerConfig = [
        'tcp_connect_timeout_ms' => 200,
    ];
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

if ($mode === 'oo-runtime') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);

    try {
        $connection = $server->accept();
        $capture['accept_is_object'] = is_object($connection);
        $capture['accept_class'] = is_object($connection) ? get_class($connection) : gettype($connection);
        $capture['accept_info'] = $connection->getInfo();
        $capture['received'] = king_client_websocket_receive($connection, 1000);
        $capture['receive_error'] = king_get_last_error();

        try {
            $connection->send('beta');
            $capture['send_ok'] = true;
            $capture['send_error'] = '';
        } catch (Throwable $e) {
            $capture['send_ok'] = false;
            $capture['send_error'] = $e->getMessage();
        }

        try {
            $connection->close(1001, 'server-done');
            $capture['close_ok'] = true;
            $capture['close_error'] = '';
        } catch (Throwable $e) {
            $capture['close_ok'] = false;
            $capture['close_error'] = $e->getMessage();
        }

        $server->stop();
        $capture['stop_error'] = king_get_last_error();

        try {
            $server->accept();
        } catch (Throwable $e) {
            $capture['accept_after_stop_exception'] = get_class($e);
            $capture['accept_after_stop_error'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        $capture['accept_exception_class'] = get_class($e);
        $capture['accept_exception_message'] = $e->getMessage();
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    return;
}

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $result = king_http1_server_listen_once(
        '127.0.0.1',
        $port,
        $listenerConfig,
        static function (array $request) use (&$capture, &$session, $mode, $iteration): array {
            $capture['handler_called'] = true;
            $session = $request['session'];
            $capture['request_uri'] = $request['uri'];
            $capture['request_path'] = $request['path'] ?? null;
            $capture['stream_id'] = $request['stream_id'];
            $capture['local_address'] = $request['local_address'];
            $capture['remote_address'] = $request['remote_address'];
            $capture['last_iteration'] = $iteration;

            if (
                $mode === 'slowloris-accept'
                || $mode === 'slowloris-head'
            ) {
                return [
                    'status' => 204,
                    'body' => '',
                ];
            }

            if ($mode === 'response-normalization') {
                return [
                    'status' => 202,
                    'headers' => [
                        'Content-Length' => '999',
                        'Connection' => 'keep-alive',
                        'Content-Type' => 'text/plain',
                        'X-Reply-Mode' => 'normalized',
                        'X-Multi' => [
                            'alpha',
                            'beta',
                        ],
                    ],
                    'body' => 'normalized-body',
                ];
            }

            if ($mode === 'cancel-callback') {
                $capture['cancel_handler_ok'] = king_server_on_cancel(
                    $session,
                    $request['stream_id'],
                    static function (int $cancelledStreamId) use (&$capture): void {
                        $capture['cancelled_stream'] = $cancelledStreamId;
                    }
                );
                $capture['cancel_handler_error'] = king_get_last_error();
                usleep(300000);

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'text/plain',
                    ],
                    'body' => str_repeat('late-http1-', 65536),
                ];
            }

            if ($mode === 'plain') {
                $upgrade = king_server_upgrade_to_websocket($session, $request['stream_id']);
                $capture['upgrade_result'] = $upgrade;
                $capture['upgrade_error'] = king_get_last_error();
                $capture['stats'] = king_get_stats($session);

                return [
                    'status' => 426,
                    'headers' => ['X-Mode' => 'plain-http1'],
                    'body' => 'upgrade-required',
                ];
            }

            $replyPayload = null;
            $websocket = king_server_upgrade_to_websocket($session, $request['stream_id']);
            $capture['upgrade_is_resource'] = is_resource($websocket);
            $capture['upgrade_error'] = king_get_last_error();
            if (!is_resource($websocket)) {
                return [
                    'status' => 500,
                    'body' => 'upgrade-failed',
                ];
            }

            $capture['upgrade_success_count']++;
            $capture['upgrade_status_before'] = king_client_websocket_get_status($websocket);
            $capture['received'] = king_client_websocket_receive($websocket, 1000);
            $capture['receive_error'] = king_get_last_error();
            $capture['received_payloads'][] = $capture['received'];
            $replyPayload = $iteration === 0 && $capture['iterations'] === 1
                ? 'beta'
                : 'beta-' . $iteration;
            $capture['reply_payloads'][] = $replyPayload;
            $capture['send_ok'] = king_websocket_send($websocket, $replyPayload);
            $capture['send_error'] = king_get_last_error();

            if ($mode === 'shutdown-drain') {
                $capture['shutdown_extra_send_ok'] = king_websocket_send($websocket, 'gamma');
                $capture['shutdown_extra_send_error'] = king_get_last_error();
            }

            $capture['close_ok'] = king_client_websocket_close($websocket, 1001, 'server-done');
            $capture['close_error'] = king_get_last_error();
            if ($capture['close_ok']) {
                $capture['close_success_count']++;
            }
            if ($mode === 'shutdown-drain') {
                $capture['send_after_close_ok'] = king_websocket_send($websocket, 'after-close');
                $capture['send_after_close_error'] = king_get_last_error();
                $capture['ping_after_close_ok'] = king_client_websocket_ping($websocket, 'p');
                $capture['ping_after_close_error'] = king_get_last_error();
            }
            $capture['upgrade_status_after'] = king_client_websocket_get_status($websocket);
            $capture['stats'] = king_get_stats($session);

            return [
                'status' => 204,
                'body' => '',
            ];
        }
    );

    $capture['listen_result'] = $result;
    $capture['listen_error'] = king_get_last_error();
    $capture['listen_results'][] = $result;

    if ($result !== true) {
        break;
    }
}

if ($session !== null) {
    $capture['post_stats'] = king_get_stats($session);
}

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
