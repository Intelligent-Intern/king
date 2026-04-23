--TEST--
King server TLS reload keeps a live HTTP/3 request path honest under real traffic
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_lsquic_runtime();
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the live TLS reload fixture";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';
require __DIR__ . '/server_admin_api_wire_helper.inc';

$fixture = king_server_admin_api_create_tls_fixture();
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['ca_cert'],
]);

try {
    $directRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server(
            $fixture['server_cert'],
            $fixture['server_key'],
            null,
            'tls-reload',
            [
                $fixture['reload_cert'],
                $fixture['reload_key'],
            ]
        ),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/tls-reload',
            'POST',
            [
                'x-mode' => 'tls-reload',
            ],
            'reload-now',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['protocol'] === 'http/3'
            && $response['transport_backend'] === 'lsquic_h3'
            && ($response['headers']['x-reply-mode'] ?? null) === 'tls-reload'
            && $response['body'] === 'tls-reload-live'
    );
    $directResponse = $directRun['result'];
    $directCapture = $directRun['capture'];

    $dispatcherRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server(
            $fixture['server_cert'],
            $fixture['server_key'],
            null,
            'tls-reload',
            [
                $fixture['reload_cert'],
                $fixture['reload_key'],
            ]
        ),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_client_send_request(
            'https://localhost:' . $server['port'] . '/tls-reload',
            'POST',
            [
                'x-mode' => 'tls-reload',
            ],
            'reload-now',
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['protocol'] === 'http/3'
            && $response['transport_backend'] === 'lsquic_h3'
            && ($response['headers']['x-reply-mode'] ?? null) === 'tls-reload'
            && $response['body'] === 'tls-reload-live'
    );
    $dispatcherResponse = $dispatcherRun['result'];
    $dispatcherCapture = $dispatcherRun['capture'];
} finally {
    king_server_admin_api_cleanup_tls_fixture($fixture);
}

var_dump($directResponse['status']);
var_dump($directResponse['protocol']);
var_dump($directResponse['transport_backend']);
var_dump($directResponse['headers']['x-reply-mode']);
var_dump($directResponse['body']);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherResponse['transport_backend']);
var_dump($dispatcherResponse['headers']['x-reply-mode']);
var_dump($dispatcherResponse['body']);

var_dump($directCapture['listen_result']);
var_dump($directCapture['listen_error']);
var_dump($directCapture['request']['protocol']);
var_dump($directCapture['request']['scheme']);
var_dump($directCapture['request']['uri']);
var_dump($directCapture['request']['body']);
var_dump($directCapture['tls_reload_ok']);
var_dump($directCapture['tls_reload_error']);
var_dump($directCapture['tls_reload_stats']['server_tls_active']);
var_dump($directCapture['tls_reload_stats']['server_tls_apply_count']);
var_dump($directCapture['tls_reload_stats']['server_tls_reload_count']);
var_dump($directCapture['tls_reload_stats']['server_last_tls_cert_file'] === $fixture['reload_cert']);
var_dump($directCapture['tls_reload_stats']['server_last_tls_key_file'] === $fixture['reload_key']);
var_dump($directCapture['tls_reload_stats']['tls_default_cert_file'] === $fixture['reload_cert']);
var_dump($directCapture['tls_reload_stats']['tls_default_key_file'] === $fixture['reload_key']);
var_dump($directCapture['post_stats']['state']);
var_dump($directCapture['post_stats']['server_tls_active']);
var_dump($directCapture['post_stats']['server_tls_apply_count']);
var_dump($directCapture['post_stats']['server_tls_reload_count']);

var_dump($dispatcherCapture['listen_result']);
var_dump($dispatcherCapture['listen_error']);
var_dump($dispatcherCapture['request']['protocol']);
var_dump($dispatcherCapture['request']['scheme']);
var_dump($dispatcherCapture['request']['uri']);
var_dump($dispatcherCapture['request']['body']);
var_dump($dispatcherCapture['tls_reload_ok']);
var_dump($dispatcherCapture['tls_reload_error']);
var_dump($dispatcherCapture['tls_reload_stats']['server_tls_active']);
var_dump($dispatcherCapture['tls_reload_stats']['server_tls_apply_count']);
var_dump($dispatcherCapture['tls_reload_stats']['server_tls_reload_count']);
var_dump($dispatcherCapture['tls_reload_stats']['server_last_tls_cert_file'] === $fixture['reload_cert']);
var_dump($dispatcherCapture['tls_reload_stats']['server_last_tls_key_file'] === $fixture['reload_key']);
var_dump($dispatcherCapture['tls_reload_stats']['tls_default_cert_file'] === $fixture['reload_cert']);
var_dump($dispatcherCapture['tls_reload_stats']['tls_default_key_file'] === $fixture['reload_key']);
var_dump($dispatcherCapture['post_stats']['state']);
var_dump($dispatcherCapture['post_stats']['server_tls_active']);
var_dump($dispatcherCapture['post_stats']['server_tls_apply_count']);
var_dump($dispatcherCapture['post_stats']['server_tls_reload_count']);
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
string(10) "tls-reload"
string(15) "tls-reload-live"
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
string(10) "tls-reload"
string(15) "tls-reload-live"
bool(true)
string(0) ""
string(6) "http/3"
string(5) "https"
string(11) "/tls-reload"
string(10) "reload-now"
bool(true)
string(0) ""
bool(true)
int(1)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
string(6) "closed"
bool(true)
int(1)
int(0)
bool(true)
string(0) ""
string(6) "http/3"
string(5) "https"
string(11) "/tls-reload"
string(10) "reload-now"
bool(true)
string(0) ""
bool(true)
int(1)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
string(6) "closed"
bool(true)
int(1)
int(0)
