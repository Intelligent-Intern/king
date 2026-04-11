<?php

/* Harness server for capturing on-wire HTTP/1 one-shot listener behavior, including websocket, interim-response, CORS, and multi-peer load slices. */

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
        $capture['received'] = $connection->receive(1000);
        $capture['receive_error'] = king_get_last_error();
        $capture['received_after_drain'] = $connection->receive(0);
        $capture['received_after_drain_is_null'] = $capture['received_after_drain'] === null;

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
        try {
            $connection->receive(0);
        } catch (Throwable $e) {
            $capture['receive_after_close_exception'] = get_class($e);
            $capture['receive_after_close_error'] = $e->getMessage();
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

if ($mode === 'oo-registry') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);

    try {
        $first = $server->accept();
        $second = $server->accept();

        $capture['first_class'] = get_class($first);
        $capture['second_class'] = get_class($second);
        $capture['first_info'] = $first->getInfo();
        $capture['second_info'] = $second->getInfo();
        $capture['registry_after_accept'] = $server->getConnections();
        $capture['registry_count_after_accept'] = count($capture['registry_after_accept']);
        $capture['registry_ids'] = array_keys($capture['registry_after_accept']);

        try {
            $server->send($capture['first_info']['connection_id'], 'to-first');
            $capture['send_first_ok'] = true;
            $capture['send_first_error'] = '';
        } catch (Throwable $e) {
            $capture['send_first_ok'] = false;
            $capture['send_first_error'] = $e->getMessage();
        }

        try {
            $server->sendBinary($capture['second_info']['connection_id'], 'to-second');
            $capture['send_second_ok'] = true;
            $capture['send_second_error'] = '';
        } catch (Throwable $e) {
            $capture['send_second_ok'] = false;
            $capture['send_second_error'] = $e->getMessage();
        }

        try {
            $server->send('missing-connection', 'miss');
        } catch (Throwable $e) {
            $capture['missing_send_exception'] = get_class($e);
            $capture['missing_send_error'] = $e->getMessage();
        }

        $first->close(1001, 'first-done');
        $capture['registry_after_first_close'] = $server->getConnections();
        $capture['registry_count_after_first_close'] = count($capture['registry_after_first_close']);

        $second->close(1001, 'second-done');
        $capture['registry_after_second_close'] = $server->getConnections();
        $capture['registry_count_after_second_close'] = count($capture['registry_after_second_close']);
    } catch (Throwable $e) {
        $capture['registry_exception_class'] = get_class($e);
        $capture['registry_exception_message'] = $e->getMessage();
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    return;
}

if ($mode === 'oo-broadcast-shutdown') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);

    try {
        $first = $server->accept();
        $second = $server->accept();

        $capture['first_info'] = $first->getInfo();
        $capture['second_info'] = $second->getInfo();
        $capture['registry_before_broadcast'] = $server->getConnections();
        $capture['registry_count_before_broadcast'] = count($capture['registry_before_broadcast']);

        try {
            $server->broadcast('fanout-text');
            $capture['broadcast_ok'] = true;
            $capture['broadcast_error'] = '';
        } catch (Throwable $e) {
            $capture['broadcast_ok'] = false;
            $capture['broadcast_error'] = $e->getMessage();
        }

        try {
            $server->broadcastBinary('fanout-binary');
            $capture['broadcast_binary_ok'] = true;
            $capture['broadcast_binary_error'] = '';
        } catch (Throwable $e) {
            $capture['broadcast_binary_ok'] = false;
            $capture['broadcast_binary_error'] = $e->getMessage();
        }

        $server->stop();
        $capture['stop_error'] = king_get_last_error();
        $capture['registry_after_stop'] = $server->getConnections();
        $capture['registry_count_after_stop'] = count($capture['registry_after_stop']);

        try {
            $server->broadcast('after-stop');
        } catch (Throwable $e) {
            $capture['broadcast_after_stop_exception'] = get_class($e);
            $capture['broadcast_after_stop_error'] = $e->getMessage();
        }

        try {
            $server->send($capture['first_info']['connection_id'], 'after-stop-target');
        } catch (Throwable $e) {
            $capture['send_after_stop_exception'] = get_class($e);
            $capture['send_after_stop_error'] = $e->getMessage();
        }
    } catch (Throwable $e) {
        $capture['broadcast_exception_class'] = get_class($e);
        $capture['broadcast_exception_message'] = $e->getMessage();
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    return;
}

if ($mode === 'oo-scheduling-load') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);
    $peerCount = max(3, $iterations);
    $rounds = 4;
    $connections = [];

    try {
        for ($i = 0; $i < $peerCount; $i++) {
            $connections[$i] = $server->accept();
            $capture['accepted_info'][$i] = $connections[$i]->getInfo();
        }

        $capture['registry_after_accept'] = $server->getConnections();
        $capture['registry_count_after_accept'] = count($capture['registry_after_accept']);

        foreach ($connections as $i => $connection) {
            $capture['ready_messages'][$i] = king_client_websocket_receive($connection, 1000);
            $capture['ready_errors'][$i] = king_get_last_error();
        }

        for ($round = 0; $round < $rounds; $round++) {
            foreach ($connections as $i => $connection) {
                $connectionId = (string) ($capture['accepted_info'][$i]['connection_id'] ?? '');
                $payload = 'slot-' . $round . '-peer-' . $i;

                $server->send($connectionId, $payload);
                $capture['scheduled_payloads'][] = $payload;
            }

            $fanout = 'fanout-' . $round;
            $server->broadcast($fanout);
            $capture['broadcast_payloads'][] = $fanout;
        }

        $capture['registry_after_send'] = $server->getConnections();
        $capture['registry_count_after_send'] = count($capture['registry_after_send']);

        foreach ($connections as $connection) {
            $connection->close(1000, 'load-done');
        }

        $capture['registry_after_close'] = $server->getConnections();
        $capture['registry_count_after_close'] = count($capture['registry_after_close']);
        $server->stop();
        $capture['stop_error'] = king_get_last_error();
    } catch (Throwable $e) {
        $capture['scheduling_exception_class'] = get_class($e);
        $capture['scheduling_exception_message'] = $e->getMessage();
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    return;
}

if ($mode === 'oo-competitive-fairness') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);
    $peerCount = max(3, $iterations);
    $connections = [];

    try {
        for ($i = 0; $i < $peerCount; $i++) {
            $connections[$i] = $server->accept();
            $capture['accepted_info'][$i] = $connections[$i]->getInfo();
        }

        $capture['registry_after_accept'] = $server->getConnections();
        $capture['registry_count_after_accept'] = count($capture['registry_after_accept']);

        foreach ($connections as $i => $connection) {
            $capture['ready_messages'][$i] = king_client_websocket_receive($connection, 1000);
            $capture['ready_errors'][$i] = king_get_last_error();
        }

        $noisyConnectionId = (string) ($capture['accepted_info'][0]['connection_id'] ?? '');
        $server->send($noisyConnectionId, 'noisy-00');
        $capture['noisy_payloads'][] = 'noisy-00';

        foreach (range(1, $peerCount - 1) as $i) {
            $connectionId = (string) ($capture['accepted_info'][$i]['connection_id'] ?? '');
            $payload = 'fair-' . $i . '-round-1';

            $server->send($connectionId, $payload);
            $capture['fair_payloads'][] = $payload;
        }

        foreach (range(1, $peerCount - 1) as $i) {
            $connectionId = (string) ($capture['accepted_info'][$i]['connection_id'] ?? '');
            $payload = 'fair-' . $i . '-round-2';

            $server->send($connectionId, $payload);
            $capture['fair_payloads'][] = $payload;
        }

        foreach (range(1, 11) as $suffix) {
            $payload = sprintf('noisy-%02d', $suffix);

            $server->send($noisyConnectionId, $payload);
            $capture['noisy_payloads'][] = $payload;
        }

        $capture['registry_after_send'] = $server->getConnections();
        $capture['registry_count_after_send'] = count($capture['registry_after_send']);

        foreach ($connections as $connection) {
            $connection->close(1000, 'fairness-done');
        }

        $capture['registry_after_close'] = $server->getConnections();
        $capture['registry_count_after_close'] = count($capture['registry_after_close']);
        $server->stop();
        $capture['stop_error'] = king_get_last_error();
    } catch (Throwable $e) {
        $capture['fairness_exception_class'] = get_class($e);
        $capture['fairness_exception_message'] = $e->getMessage();
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
    return;
}

if ($mode === 'oo-abort-cleanup') {
    $server = new King\WebSocket\Server('127.0.0.1', $port);
    $capture['server_class'] = get_class($server);
    $connections = [];

    try {
        for ($i = 0; $i < 3; $i++) {
            $connections[$i] = $server->accept();
            $capture['accepted_info'][$i] = $connections[$i]->getInfo();
        }

        $capture['registry_after_accept'] = $server->getConnections();
        $capture['registry_count_after_accept'] = count($capture['registry_after_accept']);

        foreach ($connections as $i => $connection) {
            $capture['ready_messages'][$i] = king_client_websocket_receive($connection, 1000);
            $capture['ready_errors'][$i] = king_get_last_error();
        }

        usleep(200000);

        try {
            $server->broadcast('survivor-broadcast');
            $capture['broadcast_ok'] = true;
            $capture['broadcast_error'] = '';
        } catch (Throwable $e) {
            $capture['broadcast_ok'] = false;
            $capture['broadcast_error'] = $e->getMessage();
            $capture['broadcast_exception_class'] = get_class($e);
        }

        foreach ([0, 2] as $i) {
            $connectionId = (string) ($capture['accepted_info'][$i]['connection_id'] ?? '');

            try {
                $server->send($connectionId, 'survivor-target-' . $i);
                $capture['survivor_send_ok'][$i] = true;
                $capture['survivor_send_error'][$i] = '';
            } catch (Throwable $e) {
                $capture['survivor_send_ok'][$i] = false;
                $capture['survivor_send_error'][$i] = $e->getMessage();
                $capture['survivor_send_exception_class'][$i] = get_class($e);
            }
        }

        $capture['registry_after_abort'] = $server->getConnections();
        $capture['registry_count_after_abort'] = count($capture['registry_after_abort']);

        $abortedConnectionId = (string) ($capture['accepted_info'][1]['connection_id'] ?? '');
        try {
            $server->send($abortedConnectionId, 'after-abort-target');
        } catch (Throwable $e) {
            $capture['aborted_send_exception'] = get_class($e);
            $capture['aborted_send_error'] = $e->getMessage();
        }

        foreach ([0, 2] as $i) {
            try {
                $connections[$i]->close(1000, 'abort-cleanup-done');
                $capture['close_ok'][$i] = true;
                $capture['close_error'][$i] = '';
            } catch (Throwable $e) {
                $capture['close_ok'][$i] = false;
                $capture['close_error'][$i] = $e->getMessage();
                $capture['close_exception_class'][$i] = get_class($e);
            }
        }

        $capture['registry_after_close'] = $server->getConnections();
        $capture['registry_count_after_close'] = count($capture['registry_after_close']);
        $server->stop();
        $capture['stop_error'] = king_get_last_error();
    } catch (Throwable $e) {
        $capture['abort_cleanup_exception_class'] = get_class($e);
        $capture['abort_cleanup_exception_message'] = $e->getMessage();
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

            if ($mode === 'early-hints') {
                $capture['early_hints_ok'] = king_server_send_early_hints(
                    $session,
                    $request['stream_id'],
                    [
                        'Link' => '</assets/app.css>; rel=preload; as=style',
                        ['X-Trace', 'edge-1'],
                    ]
                );
                $capture['early_hints_error'] = king_get_last_error();
                $capture['early_hints_stats_before_return'] = king_get_stats($session);

                return [
                    'status' => 200,
                    'headers' => [
                        'Content-Type' => 'text/plain',
                        'X-Mode' => 'early-hints',
                    ],
                    'body' => 'early-hints-body',
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

            if ($mode === 'cors-allowlist') {
                $capture['request_headers'] = $request['headers'];
                $capture['request_cors'] = $request['cors'];
                $capture['cors_stats_before_return'] = king_get_stats($session);

                $headers = [
                    'Content-Type' => 'text/plain',
                    'X-Cors-Mode' => 'allowlist',
                ];

                if (($request['cors']['preflight'] ?? false) === true) {
                    $requestedMethod = $request['headers']['access-control-request-method'] ?? 'GET';
                    $requestedHeaders = $request['headers']['access-control-request-headers'] ?? null;

                    $headers['Access-Control-Allow-Methods'] = $requestedMethod;
                    if (is_string($requestedHeaders) && $requestedHeaders !== '') {
                        $headers['Access-Control-Allow-Headers'] = $requestedHeaders;
                    }

                    return [
                        'status' => 204,
                        'headers' => $headers,
                        'body' => '',
                    ];
                }

                return [
                    'status' => 200,
                    'headers' => $headers,
                    'body' => 'cors-http1-body',
                ];
            }

            if ($mode === 'capture-headers') {
                $capture['request_headers'] = $request['headers'];

                return [
                    'status' => 200,
                    'body' => 'captured',
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
