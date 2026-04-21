--TEST--
King admin API rejects self-signed client certificates outside the configured CA trust
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the admin API TLS fixture";
}
if (!extension_loaded('openssl')) {
    echo "skip openssl extension required for TLS";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_admin_api_wire_helper.inc';

$fixture = king_server_admin_api_create_tls_fixture();

try {
    $server = king_server_admin_api_start_server($fixture);
    $rogueAccepted = false;

    try {
        $rogueClient = king_server_admin_api_connect_retry_with_profile(
            $server['port'],
            $fixture,
            'rogue_self_signed'
        );
        @fwrite(
            $rogueClient,
            "GET /health HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
        $rogueResponse = (string) @stream_get_contents($rogueClient);
        fclose($rogueClient);

        $rogueAccepted = king_server_admin_api_parse_response($rogueResponse)['status'] === 200;
    } catch (Throwable $e) {
        $rogueAccepted = false;
    } finally {
        $capture = king_server_admin_api_stop_server($server);
    }

    var_dump($rogueAccepted);
    var_dump($capture['listen_result']);
    var_dump(str_contains((string) $capture['error'], 'failed the admin TLS/mTLS handshake'));
} finally {
    king_server_admin_api_cleanup_tls_fixture($fixture);
}
?>
--EXPECT--
bool(false)
bool(false)
bool(true)
