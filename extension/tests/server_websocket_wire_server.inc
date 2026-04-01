<?php

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

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $result = king_http1_server_listen_once(
        '127.0.0.1',
        $port,
        $listenerConfig,
        static function (array $request) use (&$capture, $mode, $iteration): array {
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
            $capture['close_ok'] = king_client_websocket_close($websocket, 1001, 'server-done');
            $capture['close_error'] = king_get_last_error();
            if ($capture['close_ok']) {
                $capture['close_success_count']++;
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

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
