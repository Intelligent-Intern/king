<?php

/* Harness server for capturing on-wire HTTP/3 one-shot listener behaviors, including TLS-reload and CORS slices. */

if ($argc < 6) {
    fwrite(STDERR, "usage: http3_server_wire_server.inc <capture-path> <port> <cert> <key> <host> [mode] [mode-args...]\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$certFile = $argv[3];
$keyFile = $argv[4];
$host = $argv[5];
$mode = $argv[6] ?? 'basic';
$modeArgs = array_slice($argv, 7);
$capture = [];
$session = null;

$configOptions = [
    'tls_default_cert_file' => $certFile,
    'tls_default_key_file' => $keyFile,
];

$config = king_new_config($configOptions);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$result = king_http3_server_listen_once(
    $host,
    $port,
    $config,
    static function (array $request) use (&$capture, &$session, $mode, $modeArgs): array {
        $session = $request['session'];
        $stats = king_get_stats($session);

        $capture['request'] = [
            'protocol' => $request['protocol'],
            'scheme' => $request['scheme'],
            'method' => $request['method'],
            'uri' => $request['uri'],
            'path' => $request['path'] ?? null,
            'host' => $request['host'],
            'body' => $request['body'],
            'stream_id' => $request['stream_id'],
            'authority' => $request['headers'][':authority'] ?? null,
            'mode' => $request['headers']['x-mode'] ?? null,
            'session_is_resource' => is_resource($request['session']),
            'capability_is_int' => is_int($request['server_session_capability']),
            'transport_backend_before' => $stats['transport_backend'],
            'alpn_before' => $stats['alpn'],
            'transport_socket_family_before' => $stats['transport_socket_family'],
            'transport_has_socket_before' => $stats['transport_has_socket'],
        ];

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
                    'content-type' => 'text/plain',
                ],
                'body' => str_repeat('late-http3-', 65536),
            ];
        }

        if ($mode === 'tls-reload') {
            $reloadCertFile = (string) ($modeArgs[0] ?? '');
            $reloadKeyFile = (string) ($modeArgs[1] ?? '');

            $capture['tls_reload_ok'] = king_server_reload_tls_config(
                $session,
                $reloadCertFile,
                $reloadKeyFile
            );
            $capture['tls_reload_error'] = king_get_last_error();
            $capture['tls_reload_stats'] = king_get_stats($session);

            return [
                'status' => 200,
                'headers' => [
                    'content-type' => 'text/plain',
                    'x-reply-mode' => 'tls-reload',
                ],
                'body' => 'tls-reload-live',
            ];
        }

        if ($mode === 'cors-allowlist') {
            $capture['request']['headers'] = $request['headers'];
            $capture['request']['cors'] = $request['cors'];
            $capture['cors_stats_before_return'] = king_get_stats($session);

            return [
                'status' => 200,
                'headers' => [
                    'content-type' => 'text/plain',
                    'x-cors-mode' => 'allowlist',
                ],
                'body' => 'cors-http3-body',
            ];
        }

        return [
            'status' => 201,
            'headers' => [
                'content-type' => 'text/plain',
                'x-reply-mode' => 'wire-h3',
            ],
            'body' => 'reply:' . $request['body'],
        ];
    }
);

$capture['listen_result'] = $result;
$capture['listen_error'] = king_get_last_error();
$capture['post_stats'] = $session !== null ? king_get_stats($session) : null;

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
