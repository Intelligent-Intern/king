--TEST--
King OTLP metric doubles preserve Unix timestamps and IEEE-754 precision on the wire
--INI--
king.security_allow_config_override=1
precision=3
serialize_precision=3
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function requireSameDouble(mixed $actual, float $expected, string $where): void
{
    if ((!is_int($actual) && !is_float($actual))
        || pack('d', (float) $actual) !== pack('d', $expected)) {
        throw new RuntimeException($where . ' lost double precision');
    }
}

$values = [
    1795017673.0, // Production regression: six digits became 1795020000.
    1795017673.125, -1795017673.125, 0.12345678901234566,
    1.0000000000000002, 1.0e-12, PHP_FLOAT_MIN, PHP_FLOAT_MAX,
    5.0e-324, 9007199254740991.0, 0.0, -0.0,
];
$types = ['gauge', 'histogram', 'summary'];
$collector = king_telemetry_test_start_collector([
    ['validate' => 'otlp_reference'],
    [], // Special doubles use ProtoJSON strings rather than bare NaN/INF.
]);
try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collector['port'],
        'service_name' => 'double-roundtrip-proof',
    ]);
    foreach ($types as $type) {
        foreach ($values as $index => $value) {
            king_telemetry_record_metric($type . '_' . $index, $value, null, $type);
        }
    }
    foreach (['histogram', 'summary'] as $type) {
        king_telemetry_record_metric($type . '_aggregate', 1.1234567890123, null, $type);
        king_telemetry_record_metric($type . '_aggregate', 2.9876543210987, null, $type);
    }
    king_telemetry_record_metric('integer_counter', 123456789.0, null, 'counter');
    var_dump(king_telemetry_flush());
    foreach ($types as $type) {
        foreach ([NAN, INF, -INF] as $index => $value) {
            king_telemetry_record_metric($type . '_special_' . $index, $value, null, $type);
        }
    }
    var_dump(king_telemetry_flush());
} finally {
    $capture = king_telemetry_test_stop_collector($collector);
}

if (count($capture) !== 2 || !($capture[0]['validation']['ok'] ?? false)) {
    throw new RuntimeException('collector did not accept both requests');
}
$payload = json_decode($capture[0]['body'], true, 512, JSON_THROW_ON_ERROR);
$metrics = array_column($payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'], null, 'name');
foreach ($types as $type) {
    foreach ($values as $index => $value) {
        $point = $metrics[$type . '_' . $index][$type]['dataPoints'][0];
        if (!is_string($point['timeUnixNano']) || preg_match('/^[0-9]+$/', $point['timeUnixNano']) !== 1) {
            throw new RuntimeException('timeUnixNano must remain an integer string');
        }
        requireSameDouble($point[$type === 'gauge' ? 'asDouble' : 'sum'], $value, $type . '_' . $index);
        if ($type === 'summary') {
            requireSameDouble($point['quantileValues'][0]['value'], $value, 'summary latest value');
        }
    }
}
foreach (['histogram', 'summary'] as $type) {
    $point = $metrics[$type . '_aggregate'][$type]['dataPoints'][0];
    requireSameDouble($point['sum'], 1.1234567890123 + 2.9876543210987, 'aggregated sum');
    if ($point['count'] !== '2') {
        throw new RuntimeException('aggregation count changed');
    }
}
requireSameDouble($metrics['summary_aggregate']['summary']['dataPoints'][0]['quantileValues'][0]['value'],
    2.9876543210987, 'aggregated summary latest value');
if ($metrics['integer_counter']['sum']['dataPoints'][0]['asInt'] !== '123456789') {
    throw new RuntimeException('counter integer encoding changed');
}
echo "finite metric doubles round-trip; timestamps and counters unchanged\n";

$payload = json_decode($capture[1]['body'], true, 512, JSON_THROW_ON_ERROR);
$metrics = array_column($payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'], null, 'name');
foreach ($types as $type) {
    foreach (['NaN', 'Infinity', '-Infinity'] as $index => $expected) {
        $point = $metrics[$type . '_special_' . $index][$type]['dataPoints'][0];
        if ($point[$type === 'gauge' ? 'asDouble' : 'sum'] !== $expected
            || ($type === 'summary' && $point['quantileValues'][0]['value'] !== $expected)) {
            throw new RuntimeException('non-finite double is not canonical ProtoJSON');
        }
    }
}
echo "non-finite metric doubles use valid ProtoJSON strings\n";
?>
--EXPECT--
bool(true)
bool(true)
finite metric doubles round-trip; timestamps and counters unchanged
non-finite metric doubles use valid ProtoJSON strings
