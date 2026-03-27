<?php

if ($argc < 5) {
    fwrite(STDERR, "usage: http3_server_wire_server.inc <capture-path> <port> <cert> <key>\n");
    exit(1);
}

$capturePath = $argv[1];
$port = (int) $argv[2];
$certFile = $argv[3];
$keyFile = $argv[4];
$capture = [];
$session = null;

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$config = king_new_config([
    'tls_default_cert_file' => $certFile,
    'tls_default_key_file' => $keyFile,
]);

$result = king_http3_server_listen_once(
    '127.0.0.1',
    $port,
    $config,
    static function (array $request) use (&$capture, &$session): array {
        $session = $request['session'];
        $stats = king_get_stats($session);

        $capture['request'] = [
            'protocol' => $request['protocol'],
            'scheme' => $request['scheme'],
            'method' => $request['method'],
            'uri' => $request['uri'],
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
