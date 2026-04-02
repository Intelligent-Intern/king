<?php

/* Harness child process for repeated one-shot request-boundary telemetry cleanup checks. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$collectorPort = (int) ($argv[3] ?? 0);
$iterations = max(1, (int) ($argv[4] ?? 1));

$capture = [
    'iterations' => $iterations,
    'listen_results' => [],
    'listen_errors' => [],
    'before_statuses' => [],
    'after_statuses' => [],
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
            $before = king_telemetry_get_status();
            $capture['before_statuses'][] = [
                'iteration' => $iteration,
                'pending_log_count' => $before['pending_log_count'],
                'pending_span_count' => $before['pending_span_count'],
            ];

            if (($iteration % 2) === 0) {
                king_telemetry_start_span('request-stale-' . $iteration);
                king_telemetry_log('warn', 'request-stale-' . $iteration, [
                    'phase' => 'stale',
                    'iteration' => $iteration,
                    'uri' => $request['uri'] ?? '',
                ]);
            } else {
                king_telemetry_log('warn', 'request-fresh-' . $iteration, [
                    'phase' => 'fresh',
                    'iteration' => $iteration,
                    'uri' => $request['uri'] ?? '',
                ]);
                king_telemetry_flush();
            }

            $after = king_telemetry_get_status();
            $capture['after_statuses'][] = [
                'iteration' => $iteration,
                'pending_log_count' => $after['pending_log_count'],
                'pending_span_count' => $after['pending_span_count'],
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

file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
