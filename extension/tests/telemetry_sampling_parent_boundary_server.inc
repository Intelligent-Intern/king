<?php

/* Harness child process for unsampled incoming-parent sampling checks on HTTP server requests. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$collectorPort = (int) ($argv[3] ?? 0);

$capture = [
    'listen_result' => null,
    'listen_error' => '',
];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
    'traces_sampler_type' => 'parent_based_probability',
    'traces_sampler_ratio' => 1.0,
]);

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$capture['listen_result'] = king_http1_server_listen_once(
    '127.0.0.1',
    $port,
    null,
    static function (array $request) use (&$capture): array {
        $capture['request_telemetry'] = $request['telemetry'] ?? null;
        $capture['before_context'] = king_telemetry_get_trace_context();

        $rootId = king_telemetry_start_span('server-unsampled-parent-root', [
            'role' => 'root',
            'path' => $request['path'] ?? '',
        ]);
        $capture['root_id'] = $rootId;
        $capture['root_context'] = king_telemetry_get_trace_context();
        $capture['helper_headers'] = king_telemetry_inject_context(['x-helper' => '1']);

        $capture['root_end_result'] = king_telemetry_end_span($rootId);
        $capture['after_root_context'] = king_telemetry_get_trace_context();
        $capture['flush_result'] = king_telemetry_flush();
        $capture['after_flush_status'] = king_telemetry_get_status();

        return [
            'status' => 204,
            'body' => '',
        ];
    }
);
$capture['listen_error'] = king_get_last_error();

$bytesWritten = file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
if ($bytesWritten === false) {
    fwrite(STDERR, "failed to write telemetry sampling parent capture\n");
    exit(2);
}
