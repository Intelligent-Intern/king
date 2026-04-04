<?php

/* Harness child process for repeated request-boundary metric lifecycle checks. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$collectorPort = (int) ($argv[3] ?? 0);
$iterations = max(1, (int) ($argv[4] ?? 1));

$capture = [
    'iterations' => [],
    'listen_results' => [],
    'listen_errors' => [],
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
        static function () use (&$capture, $iteration): array {
            $beforeStatus = king_telemetry_get_status();

            king_telemetry_record_metric('request.counter', (float) ($iteration + 1), null, 'counter');
            king_telemetry_record_metric('request.counter', 10.0, null, 'counter');
            king_telemetry_record_metric('request.gauge', -1.0, null, 'gauge');
            king_telemetry_record_metric('request.gauge', (float) (100 + $iteration), null, 'gauge');

            $capture['iterations'][] = [
                'iteration' => $iteration,
                'before_status' => $beforeStatus,
                'mid_metrics' => king_telemetry_get_metrics(),
                'flush_result' => king_telemetry_flush(),
                'after_status' => king_telemetry_get_status(),
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
