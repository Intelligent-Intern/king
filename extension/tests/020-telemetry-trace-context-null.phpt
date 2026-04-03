--TEST--
King telemetry trace-context getter reflects the live active span lifecycle
--INI--
king.security_allow_config_override=1
--FILE--
<?php
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:4318',
]);

var_dump(king_telemetry_get_trace_context());

$rootId = king_telemetry_start_span('root-operation', ['phase' => 'root']);
$rootContext = king_telemetry_get_trace_context();

var_dump(is_array($rootContext));
var_dump($rootContext['operation_name']);
var_dump(strlen($rootContext['trace_id']) === 32);
var_dump(strlen($rootContext['span_id']) === 16);
var_dump($rootContext['span_id'] === $rootId);
var_dump($rootContext['attributes']['phase']);

$childId = king_telemetry_start_span('child-operation', ['phase' => 'child']);
$childContext = king_telemetry_get_trace_context();

var_dump($childContext['trace_id'] === $rootContext['trace_id']);
var_dump($childContext['parent_span_id'] === $rootId);
var_dump($childContext['span_id'] === $childId);
var_dump($childContext['span_id'] !== $rootId);
var_dump($childContext['attributes']['phase']);

var_dump(king_telemetry_end_span($childId, ['closed' => 'child']));
$afterChild = king_telemetry_get_trace_context();
var_dump($afterChild['span_id'] === $rootId);
var_dump($afterChild['operation_name']);

var_dump(king_telemetry_end_span($rootId, ['closed' => 'root']));
var_dump(king_telemetry_get_trace_context());
?>
--EXPECT--
NULL
bool(true)
string(14) "root-operation"
bool(true)
bool(true)
bool(true)
string(4) "root"
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "child"
bool(true)
bool(true)
string(14) "root-operation"
bool(true)
NULL
