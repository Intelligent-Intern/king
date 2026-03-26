--TEST--
King server shutdown state and active local server controls keep a stable contract
--FILE--
<?php
$shutdownSession = king_connect('127.0.0.1', 443);
$capability = king_get_stats($shutdownSession)['server_session_capability'];

var_dump(king_session_close_server_initiated($shutdownSession, $capability, 11, 'test-shutdown'));

$after = king_get_stats($shutdownSession);
var_dump($after['state']);
var_dump($after['transport_has_socket']);
var_dump($after['server_close_initiated']);
var_dump($after['server_close_error_code']);
var_dump($after['server_close_reason']);

var_dump(king_close($shutdownSession));
var_dump(king_get_last_error());
var_dump(king_get_stats($shutdownSession)['state']);

$session = king_connect('127.0.0.1', 443);
$GLOBALS['king_server_control_cancelled_stream'] = null;

var_dump(king_server_on_cancel(
    $session,
    4,
    static function (int $streamId): void {
        $GLOBALS['king_server_control_cancelled_stream'] = $streamId;
    }
));
var_dump(king_get_last_error());

var_dump(king_server_send_early_hints($session, 4, [
    'Link' => '</app.css>; rel=preload; as=style',
    'X-Hint' => ['alpha', 'beta'],
]));
var_dump(king_get_last_error());

$websocket = king_server_upgrade_to_websocket($session, 6);
var_dump(is_resource($websocket));
var_dump(king_client_websocket_get_status($websocket));
var_dump(king_websocket_send($websocket, 'server-frame'));
var_dump(king_get_last_error());
var_dump(king_client_websocket_receive($websocket));
var_dump(king_get_last_error());
var_dump(king_client_websocket_ping($websocket, 'ok'));
var_dump(king_get_last_error());
var_dump(king_client_websocket_close($websocket, 1001, 'done'));
var_dump(king_get_last_error());

var_dump(king_cancel_stream(4, 'both', $session));
var_dump(king_get_last_error());
var_dump($GLOBALS['king_server_control_cancelled_stream']);

$controlStats = king_get_stats($session);
var_dump($controlStats['server_cancel_handler_count']);
var_dump($controlStats['server_cancel_handler_invocations']);
var_dump($controlStats['server_admin_api_active']);
var_dump($controlStats['server_admin_api_listen_count']);
var_dump($controlStats['server_admin_api_reload_count']);
var_dump($controlStats['server_early_hints_count']);
var_dump($controlStats['server_last_early_hints_hint_count']);
var_dump($controlStats['server_websocket_upgrade_count']);
var_dump($controlStats['server_last_websocket_url']);

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'bind_host' => '127.0.0.1',
    'port' => 2019,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());

$controlStats = king_get_stats($session);
var_dump($controlStats['server_admin_api_active']);
var_dump($controlStats['server_admin_api_listen_count']);
var_dump($controlStats['server_admin_api_reload_count']);
var_dump($controlStats['server_last_admin_api_bind_host']);
var_dump($controlStats['server_last_admin_api_port']);
var_dump($controlStats['server_last_admin_api_auth_mode']);
var_dump($controlStats['server_last_admin_api_mtls_ready']);

$server = king_system_get_component_info('server');
var_dump($server['implementation']);
var_dump($server['configuration']);

var_dump(king_server_reload_tls_config($session, __FILE__, __FILE__));
var_dump(king_get_last_error());

$controlStats = king_get_stats($session);
var_dump($controlStats['server_tls_active']);
var_dump($controlStats['server_tls_apply_count']);
var_dump($controlStats['server_tls_reload_count']);
var_dump($controlStats['server_last_tls_cert_file'] === __FILE__);
var_dump($controlStats['server_last_tls_key_file'] === __FILE__);
var_dump($controlStats['server_last_tls_ticket_key_file'] === '');
var_dump($controlStats['server_last_tls_ticket_key_loaded']);

var_dump(king_server_init_telemetry($session, null));
var_dump(king_get_last_error());

$controlStats = king_get_stats($session);
var_dump($controlStats['server_telemetry_active']);
var_dump($controlStats['server_telemetry_init_count']);
var_dump($controlStats['server_telemetry_request_count']);
var_dump($controlStats['server_telemetry_last_status']);
var_dump($controlStats['server_telemetry_service_name']);
var_dump($controlStats['server_telemetry_exporter_endpoint']);
var_dump($controlStats['server_telemetry_exporter_protocol']);
var_dump($controlStats['server_telemetry_metrics_enable']);
var_dump($controlStats['server_telemetry_logs_enable']);
?>
--EXPECTF--
bool(true)
string(6) "closed"
bool(false)
bool(true)
int(11)
string(13) "test-shutdown"
bool(true)
string(0) ""
string(6) "closed"
bool(true)
string(0) ""
bool(true)
string(0) ""
bool(true)
int(1)
bool(false)
string(97) "king_websocket_send() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
bool(false)
string(107) "king_client_websocket_receive() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
bool(false)
string(104) "king_client_websocket_ping() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
bool(true)
string(0) ""
bool(true)
string(0) ""
int(4)
int(1)
int(1)
bool(false)
int(0)
int(0)
int(1)
int(3)
int(1)
string(27) "ws://127.0.0.1:443/stream/6"
bool(true)
string(0) ""
bool(true)
int(1)
int(0)
string(9) "127.0.0.1"
int(2019)
string(4) "mtls"
bool(true)
string(13) "local_runtime"
array(0) {
}
bool(true)
string(0) ""
bool(true)
int(1)
int(0)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
string(0) ""
bool(true)
int(1)
int(0)
int(0)
string(16) "king_application"
string(21) "http://localhost:4317"
string(4) "grpc"
bool(true)
bool(false)
