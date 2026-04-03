--TEST--
King telemetry inject-context materializes the live current span and preserves explicit caller trace headers
--FILE--
<?php
function king_telemetry_format_traceparent(array $context): string
{
    return sprintf(
        '00-%s-%s-%02x',
        $context['trace_id'],
        $context['span_id'],
        (int) $context['trace_flags']
    );
}

$empty = king_telemetry_inject_context();
if ($empty !== []) {
    throw new RuntimeException('inject-context should return an empty array when no span is active.');
}

$passthrough = king_telemetry_inject_context(['x-test' => '1']);
if ($passthrough !== ['x-test' => '1']) {
    throw new RuntimeException('inject-context should preserve unrelated headers when no span is active.');
}

$rootId = king_telemetry_start_span('inject-root', ['role' => 'root']);
$context = king_telemetry_get_trace_context();
if (!is_array($context) || ($context['span_id'] ?? null) !== $rootId) {
    throw new RuntimeException('inject-context fixture did not create a live root span.');
}

$injected = king_telemetry_inject_context(['x-test' => '1']);
$expectedTraceparent = king_telemetry_format_traceparent($context);
if (($injected['x-test'] ?? null) !== '1') {
    throw new RuntimeException('inject-context lost unrelated headers while adding trace context.');
}
if (($injected['traceparent'] ?? null) !== $expectedTraceparent) {
    throw new RuntimeException('inject-context did not materialize the expected traceparent header.');
}
if (array_key_exists('tracestate', $injected)) {
    throw new RuntimeException('inject-context should not synthesize an empty tracestate header.');
}

$override = king_telemetry_inject_context([
    'TraceParent' => '00-11111111111111111111111111111111-2222222222222222-01',
    'TraceState' => 'override=1',
    'x-test' => '2',
]);
if (($override['TraceParent'] ?? null) !== '00-11111111111111111111111111111111-2222222222222222-01'
    || ($override['TraceState'] ?? null) !== 'override=1'
    || ($override['x-test'] ?? null) !== '2') {
    throw new RuntimeException('inject-context should preserve explicit caller trace headers verbatim.');
}
if (array_key_exists('traceparent', $override) || array_key_exists('tracestate', $override)) {
    throw new RuntimeException('inject-context should not add duplicate lowercase trace headers when caller headers already carry trace context.');
}

if (king_telemetry_end_span($rootId) !== true) {
    throw new RuntimeException('inject-context fixture failed to close its root span.');
}
if (king_telemetry_get_trace_context() !== null) {
    throw new RuntimeException('inject-context fixture leaked an active span after close.');
}

echo "OK\n";
?>
--EXPECT--
OK
