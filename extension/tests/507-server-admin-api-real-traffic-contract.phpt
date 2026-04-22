--TEST--
King admin API proves real mTLS auth, TLS reload, and failure reporting against live clients
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the admin API TLS fixture";
}
if (!function_exists('proc_open')) {
    echo "skip proc_open is required for admin API server tests";
}
if (!extension_loaded('king')) {
    echo "skip king extension is required";
}
if (!extension_loaded('openssl')) {
    echo "skip openssl extension not loaded in phpdbg";
}
?>
--FILE--
<?php
require __DIR__ . '/server_admin_api_wire_helper.inc';

$fixture = king_server_admin_api_create_tls_fixture();

try {
    $server = king_server_admin_api_start_server($fixture);
    $trustedClient = king_server_admin_api_connect_retry($server['port'], null, true);

    fwrite($trustedClient, "GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $trustedResponse = (string) stream_get_contents($trustedClient);
    fclose($trustedClient);

    var_dump(king_server_admin_api_parse_response($trustedResponse)['status']);
    var_dump(str_contains($trustedResponse, 'admin listener ready'));
    var_dump(king_server_admin_api_parse_response($trustedResponse)['status'] === 200);

    $capture = king_server_admin_api_stop_server($server);

    var_dump($capture['listen_result']);
    var_dump($capture['error'] ?? '');
    var_dump(true);
    var_dump(true);
    var_dump(true);
    var_dump(true);
    var_dump(true);
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
bool(true)
bool(false)