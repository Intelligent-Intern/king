<?php

/* Harness child process for outgoing trace-context propagation across HTTP/1, HTTP/2, and HTTP/3 clients. */

require __DIR__ . '/http3_test_helper.inc';

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$http1Port = (int) ($argv[3] ?? 0);
$http2Port = (int) ($argv[4] ?? 0);
$http3Port = (int) ($argv[5] ?? 0);
$http3CaFile = $argv[6] ?? '';
$http3Config = null;

$capture = [
    'listen_result' => null,
    'listen_error' => '',
];

if ($http3CaFile !== '') {
    $http3Config = king_new_config([
        'tls_default_ca_file' => $http3CaFile,
    ]);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$result = king_http1_server_listen_once(
    '127.0.0.1',
    $port,
    null,
    static function (array $request) use (
        &$capture,
        $http1Port,
        $http2Port,
        $http3Port,
        $http3Config
    ): array {
        $capture['request_telemetry'] = $request['telemetry'] ?? null;
        $capture['before_context'] = king_telemetry_get_trace_context();

        $rootId = king_telemetry_start_span('server-outgoing-root', [
            'role' => 'root',
            'path' => $request['path'] ?? '',
        ]);
        $capture['root_id'] = $rootId;
        $capture['root_context'] = king_telemetry_get_trace_context();

        $capture['helper_headers'] = king_telemetry_inject_context([
            'x-helper' => '1',
        ]);
        $capture['helper_override_headers'] = king_telemetry_inject_context([
            'TraceParent' => '00-11111111111111111111111111111111-2222222222222222-01',
            'TraceState' => 'override=1',
            'x-helper' => '2',
        ]);

        $http1Response = king_http1_request_send(
            'http://127.0.0.1:' . $http1Port . '/capture-http1',
            'GET',
            ['x-mode' => 'outgoing-http1'],
            null,
            ['connect_timeout_ms' => 5000, 'timeout_ms' => 5000]
        );
        $capture['http1_status'] = $http1Response['status'] ?? null;
        $capture['after_http1_context'] = king_telemetry_get_trace_context();

        $http2Response = king_http2_request_send(
            'http://127.0.0.1:' . $http2Port . '/capture-http2',
            'GET',
            ['x-mode' => 'outgoing-http2'],
            null,
            ['connect_timeout_ms' => 5000, 'timeout_ms' => 5000]
        );
        $capture['http2_status'] = $http2Response['status'] ?? null;
        $capture['after_http2_context'] = king_telemetry_get_trace_context();

        $http3Response = king_http3_request_with_retry(
            static function () use ($http3Port, $http3Config): array {
                return king_http3_request_send(
                    'https://localhost:' . $http3Port . '/capture-http3',
                    'GET',
                    ['x-mode' => 'outgoing-http3'],
                    null,
                    [
                        'connection_config' => $http3Config,
                        'connect_timeout_ms' => 10000,
                        'timeout_ms' => 10000,
                    ]
                );
            }
        );
        $capture['http3_status'] = $http3Response['status'] ?? null;
        $capture['after_http3_context'] = king_telemetry_get_trace_context();

        $capture['root_end_result'] = king_telemetry_end_span($rootId);
        $capture['after_root_context'] = king_telemetry_get_trace_context();

        return [
            'status' => 204,
            'body' => '',
        ];
    }
);

$capture['listen_result'] = $result;
$capture['listen_error'] = king_get_last_error();

$bytesWritten = file_put_contents(
    $capturePath,
    json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
);
if ($bytesWritten === false) {
    fwrite(STDERR, "failed to write outgoing trace-context capture\n");
    exit(2);
}
