--TEST--
King local server telemetry init tracks request instrumentation on the active session runtime
--FILE--
<?php
$captured = [];
$session = null;

var_dump(king_http2_server_listen(
    '127.0.0.1',
    8444,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];
        $captured['before'] = $request['telemetry'];
        $captured['init'] = king_server_init_telemetry($session, [
            'service_name' => 'edge-api',
            'exporter_endpoint' => 'http://otel.example:4318',
            'exporter_protocol' => 'http/protobuf',
            'metrics_enable' => false,
            'logs_enable' => true,
        ]);
        $captured['error'] = king_get_last_error();

        return ['status' => 201, 'body' => 'created'];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'server_telemetry_active' => $stats['server_telemetry_active'],
    'server_telemetry_init_count' => $stats['server_telemetry_init_count'],
    'server_telemetry_request_count' => $stats['server_telemetry_request_count'],
    'server_telemetry_last_status' => $stats['server_telemetry_last_status'],
    'server_telemetry_service_name' => $stats['server_telemetry_service_name'],
    'server_telemetry_exporter_endpoint' => $stats['server_telemetry_exporter_endpoint'],
    'server_telemetry_exporter_protocol' => $stats['server_telemetry_exporter_protocol'],
    'server_telemetry_metrics_enable' => $stats['server_telemetry_metrics_enable'],
    'server_telemetry_logs_enable' => $stats['server_telemetry_logs_enable'],
    'server_telemetry_last_protocol' => $stats['server_telemetry_last_protocol'],
]);
?>
--EXPECT--
bool(true)
string(0) ""
array(3) {
  ["before"]=>
  array(7) {
    ["enabled"]=>
    bool(true)
    ["initialized"]=>
    bool(false)
    ["service_name"]=>
    string(16) "king_application"
    ["exporter_endpoint"]=>
    string(21) "http://localhost:4317"
    ["exporter_protocol"]=>
    string(4) "grpc"
    ["metrics_enable"]=>
    bool(true)
    ["logs_enable"]=>
    bool(false)
  }
  ["init"]=>
  bool(true)
  ["error"]=>
  string(0) ""
}
array(11) {
  ["state"]=>
  string(6) "closed"
  ["server_telemetry_active"]=>
  bool(true)
  ["server_telemetry_init_count"]=>
  int(1)
  ["server_telemetry_request_count"]=>
  int(1)
  ["server_telemetry_last_status"]=>
  int(201)
  ["server_telemetry_service_name"]=>
  string(8) "edge-api"
  ["server_telemetry_exporter_endpoint"]=>
  string(24) "http://otel.example:4318"
  ["server_telemetry_exporter_protocol"]=>
  string(13) "http/protobuf"
  ["server_telemetry_metrics_enable"]=>
  bool(false)
  ["server_telemetry_logs_enable"]=>
  bool(true)
  ["server_telemetry_last_protocol"]=>
  string(6) "http/2"
}
