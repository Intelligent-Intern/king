<?php

/* Harness child process for repeated request-boundary span lifecycle checks. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$collectorPort = (int) ($argv[3] ?? 0);
$iterations = max(1, (int) ($argv[4] ?? 1));

$capture = [
    'iterations' => $iterations,
    'listen_results' => [],
    'listen_errors' => [],
    'iteration_captures' => [],
];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $result = king_http1_server_listen_once(
        '127.0.0.1',
        $port,
        null,
        static function (array $request) use (&$capture, $iteration): array {
            $beforeContext = king_telemetry_get_trace_context();
            $rootId = king_telemetry_start_span('request-root-' . $iteration, [
                'boundary' => 'request',
                'role' => 'root',
                'iteration' => $iteration,
                'uri' => $request['uri'] ?? '',
            ]);
            $rootContext = king_telemetry_get_trace_context();

            $childId = king_telemetry_start_span('request-child-' . $iteration, [
                'boundary' => 'request',
                'role' => 'child',
                'iteration' => $iteration,
            ]);
            $childContext = king_telemetry_get_trace_context();

            $capture['iteration_captures'][] = [
                'iteration' => $iteration,
                'before_context' => $beforeContext,
                'root_id' => $rootId,
                'root_context' => $rootContext,
                'child_id' => $childId,
                'child_context' => $childContext,
                'child_end_result' => king_telemetry_end_span($childId, ['closed' => 'child']),
                'after_child_context' => king_telemetry_get_trace_context(),
                'root_end_result' => king_telemetry_end_span($rootId, ['closed' => 'root']),
                'after_root_context' => king_telemetry_get_trace_context(),
                'flush_result' => king_telemetry_flush(),
                'after_flush_status' => king_telemetry_get_status(),
            ];

            return [
                'status' => 204,
                'body' => '',
            ];
        }
    );

    $capture['listen_results'][] = $result;
    $capture['listen_errors'][] = king_get_last_error();
    if ($result !== true) {
        break;
    }
}

$bytesWritten = file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
if ($bytesWritten === false) {
    fwrite(STDERR, "failed to write span lifecycle capture\n");
    exit(2);
}
