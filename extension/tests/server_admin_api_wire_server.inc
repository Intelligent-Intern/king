<?php

/* Harness child process for the bounded on-wire admin API listener contract. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$caFile = $argv[3] ?? '';
$certFile = $argv[4] ?? '';
$keyFile = $argv[5] ?? '';
$acceptTimeoutMs = (int) ($argv[6] ?? 3000);

$capture = [
    'listen_result' => false,
    'error' => '',
    'stats' => null,
];

try {
    $session = king_connect('127.0.0.1', 443);
    $capture['listen_result'] = king_admin_api_listen($session, [
        'enable' => true,
        'bind_host' => '127.0.0.1',
        'port' => $port,
        'auth_mode' => 'mtls',
        'ca_file' => $caFile,
        'cert_file' => $certFile,
        'key_file' => $keyFile,
        'accept_timeout_ms' => $acceptTimeoutMs,
    ]);
    $capture['error'] = king_get_last_error();
    $capture['stats'] = king_get_stats($session);
} catch (Throwable $e) {
    $capture['exception'] = get_class($e);
    $capture['exception_message'] = $e->getMessage();
}

file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT));
