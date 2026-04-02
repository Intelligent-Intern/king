--TEST--
King admin API proves real mTLS auth, TLS reload, and failure reporting against live clients
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the admin API TLS fixture";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_admin_api_wire_helper.inc';

$fixture = king_server_admin_api_create_tls_fixture();

try {
    $healthServer = king_server_admin_api_start_server($fixture);
    try {
        $healthResponse = king_server_admin_api_request(
            $healthServer['port'],
            $fixture,
            "GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
    } finally {
        $healthCapture = king_server_admin_api_stop_server($healthServer);
    }

    $health = king_server_admin_api_parse_response($healthResponse);
    var_dump($health['status']);
    var_dump($health['body']);
    var_dump($healthCapture['listen_result']);
    var_dump($healthCapture['error']);
    var_dump($healthCapture['stats']['server_admin_api_active']);
    var_dump($healthCapture['stats']['server_last_admin_api_port'] === $healthServer['port']);
    var_dump($healthCapture['stats']['server_last_admin_api_mtls_ready']);
    var_dump(str_contains((string) $healthCapture['stats']['server_peer_cert_subject'], 'CN=KingAdminClient'));

    $authFailureServer = king_server_admin_api_start_server($fixture);
    try {
        $unauthenticatedClient = king_server_admin_api_connect_retry(
            $authFailureServer['port'],
            $fixture,
            false
        );
        @fwrite(
            $unauthenticatedClient,
            "GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
        @stream_get_contents($unauthenticatedClient);
        fclose($unauthenticatedClient);
    } finally {
        $authFailureCapture = king_server_admin_api_stop_server($authFailureServer);
    }

    var_dump($authFailureCapture['listen_result']);
    var_dump(str_contains((string) $authFailureCapture['error'], 'failed the admin TLS/mTLS handshake'));

    $reloadServer = king_server_admin_api_start_server($fixture);
    try {
        $reloadResponse = king_server_admin_api_request(
            $reloadServer['port'],
            $fixture,
            "POST /reload-tls HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n"
            . "X-King-TLS-Cert-File: {$fixture['reload_cert']}\r\n"
            . "X-King-TLS-Key-File: {$fixture['reload_key']}\r\n\r\n"
        );
    } finally {
        $reloadCapture = king_server_admin_api_stop_server($reloadServer);
    }

    $reload = king_server_admin_api_parse_response($reloadResponse);
    var_dump($reload['status']);
    var_dump($reload['body']);
    var_dump($reloadCapture['listen_result']);
    var_dump($reloadCapture['error']);
    var_dump($reloadCapture['stats']['server_tls_active']);
    var_dump($reloadCapture['stats']['server_tls_apply_count'] === 1);
    var_dump($reloadCapture['stats']['server_last_tls_cert_file'] === $fixture['reload_cert']);
    var_dump($reloadCapture['stats']['server_last_tls_key_file'] === $fixture['reload_key']);

    $reloadFailureServer = king_server_admin_api_start_server($fixture);
    try {
        $reloadFailureResponse = king_server_admin_api_request(
            $reloadFailureServer['port'],
            $fixture,
            "POST /reload-tls HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n"
            . "X-King-TLS-Cert-File: /definitely/missing-admin-cert.pem\r\n"
            . "X-King-TLS-Key-File: /definitely/missing-admin-key.pem\r\n\r\n"
        );
    } finally {
        $reloadFailureCapture = king_server_admin_api_stop_server($reloadFailureServer);
    }

    $reloadFailure = king_server_admin_api_parse_response($reloadFailureResponse);
    var_dump($reloadFailure['status']);
    var_dump(str_contains($reloadFailure['body'], 'cert_file_path must be a non-empty readable file path.'));
    var_dump($reloadFailureCapture['listen_result']);
    var_dump($reloadFailureCapture['error']);
    var_dump($reloadFailureCapture['stats']['server_tls_active']);
} finally {
    king_server_admin_api_cleanup_tls_fixture($fixture);
}
?>
--EXPECT--
int(200)
string(21) "admin listener ready
"
bool(true)
string(0) ""
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
int(200)
string(13) "tls reloaded
"
bool(true)
string(0) ""
bool(true)
bool(true)
bool(true)
bool(true)
int(400)
bool(true)
bool(true)
string(0) ""
bool(false)
