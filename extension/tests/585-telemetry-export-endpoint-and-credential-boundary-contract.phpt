--TEST--
King telemetry export endpoints and credential-bearing inputs stay inside safe public boundaries
--INI--
king.otel_exporter_endpoint=http://collector.internal:4318/v1/traces
king.otel_exporter_headers="Authorization: Bearer topsecret"
king.security_allow_config_override=1
--FILE--
<?php
$captured = [];
$session = null;

$component = king_system_get_component_info('telemetry');
var_dump($component['configuration']['exporter_endpoint']);
var_dump(array_key_exists('exporter_headers', $component['configuration']));

var_dump(king_http2_server_listen(
    '127.0.0.1',
    8459,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];
        $captured['before'] = $request['telemetry'];
        $captured['init'] = king_server_init_telemetry($session, null);
        $captured['error'] = king_get_last_error();
        $captured['stats'] = king_get_stats($session);
        $captured['invalid_init'] = king_server_init_telemetry($session, [
            'service_name' => 'edge-api',
            'exporter_endpoint' => 'http://user:secret@collector.internal:4318/v1/traces',
            'exporter_protocol' => 'http/protobuf',
        ]);
        $captured['invalid_error'] = king_get_last_error();

        return ['status' => 204, 'body' => ''];
    }
));
var_dump(king_get_last_error());

var_dump($captured['before']['exporter_endpoint']);
var_dump(array_key_exists('exporter_headers', $captured['before']));
var_dump($captured['init']);
var_dump($captured['error']);
var_dump($captured['stats']['server_telemetry_exporter_endpoint']);
var_dump(array_key_exists('config_otel_exporter_headers', $captured['stats']));
var_dump(array_key_exists('server_telemetry_exporter_headers', $captured['stats']));
var_dump($captured['invalid_init']);
var_dump(str_contains($captured['invalid_error'], 'must not embed credentials in the URL.'));

$invalidEndpointOutcomes = [];
foreach ([
    'credentials' => 'http://user:secret@collector.internal:4318/v1/traces',
    'query' => 'http://collector.internal:4318/v1/traces?token=secret',
    'fragment' => 'http://collector.internal:4318/v1/traces#secret',
] as $label => $endpoint) {
    try {
        king_telemetry_init(['otel_exporter_endpoint' => $endpoint]);
        $invalidEndpointOutcomes[$label] = 'no-exception';
    } catch (Throwable $e) {
        $invalidEndpointOutcomes[$label] = [$e::class, $e->getMessage()];
    }
}

try {
    king_telemetry_init([
        'otel_exporter_headers' => "Authorization: Bearer topsecret\r\nX-Evil: 1",
    ]);
    $invalidHeadersOutcome = 'no-exception';
} catch (Throwable $e) {
    $invalidHeadersOutcome = [$e::class, $e->getMessage()];
}

try {
    new King\Config([
        'otel.exporter_endpoint' => 'http://user:secret@collector.internal:4318/v1/traces',
    ]);
    $invalidConfigEndpointOutcome = 'no-exception';
} catch (Throwable $e) {
    $invalidConfigEndpointOutcome = [$e::class, $e->getMessage()];
}

try {
    new King\Config([
        'otel.exporter_headers' => "Authorization: Bearer topsecret\r\nX-Evil: 1",
    ]);
    $invalidConfigHeadersOutcome = 'no-exception';
} catch (Throwable $e) {
    $invalidConfigHeadersOutcome = [$e::class, $e->getMessage()];
}

var_dump($invalidEndpointOutcomes['credentials'][0]);
var_dump(str_contains($invalidEndpointOutcomes['credentials'][1], 'must not embed credentials in the URL.'));
var_dump($invalidEndpointOutcomes['query'][0]);
var_dump(str_contains($invalidEndpointOutcomes['query'][1], 'must not include query strings or fragments.'));
var_dump($invalidEndpointOutcomes['fragment'][0]);
var_dump(str_contains($invalidEndpointOutcomes['fragment'][1], 'must not include query strings or fragments.'));
var_dump($invalidHeadersOutcome[0]);
var_dump(str_contains($invalidHeadersOutcome[1], 'must stay on one line without CRLF.'));
var_dump($invalidConfigEndpointOutcome[0]);
var_dump(str_contains($invalidConfigEndpointOutcome[1], 'must not embed credentials in the URL.'));
var_dump($invalidConfigHeadersOutcome[0]);
var_dump(str_contains($invalidConfigHeadersOutcome[1], 'must stay on one line without CRLF.'));
?>
--EXPECT--
string(30) "http://collector.internal:4318"
bool(false)
bool(true)
string(0) ""
string(30) "http://collector.internal:4318"
bool(false)
bool(true)
string(0) ""
string(30) "http://collector.internal:4318"
bool(false)
bool(false)
bool(false)
bool(true)
string(24) "InvalidArgumentException"
bool(true)
string(24) "InvalidArgumentException"
bool(true)
string(24) "InvalidArgumentException"
bool(true)
string(24) "InvalidArgumentException"
bool(true)
string(24) "InvalidArgumentException"
bool(true)
string(24) "InvalidArgumentException"
bool(true)
