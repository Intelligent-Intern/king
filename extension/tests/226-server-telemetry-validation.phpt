--TEST--
King server telemetry init validates session state and inline telemetry config
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

try {
    king_server_init_telemetry('not-a-session', null);
} catch (Throwable $e) {
    var_dump(get_class($e));
}

var_dump(king_server_init_telemetry($session, ['enable' => false]));
var_dump(king_get_last_error());

var_dump(king_server_init_telemetry($session, ['service_name' => '']));
var_dump(king_get_last_error());

var_dump(king_server_init_telemetry($session, ['exporter_protocol' => 'zipkin']));
var_dump(king_get_last_error());

var_dump(king_close($session));
var_dump(king_server_init_telemetry($session, null));
var_dump(king_get_last_error());
?>
--EXPECTF--
string(9) "TypeError"
bool(false)
string(%d) "king_server_init_telemetry() requires telemetry to be enabled."
bool(false)
string(%d) "king_server_init_telemetry() config key 'service_name' must be a non-empty string."
bool(false)
string(%d) "king_server_init_telemetry() config key 'exporter_protocol' must be 'grpc' or 'http/protobuf'."
bool(true)
bool(false)
string(%d) "king_server_init_telemetry() cannot operate on a closed session."
