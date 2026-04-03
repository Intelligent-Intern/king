<?php

/* Harness child process for repeated server incoming trace-context checks. */

$capturePath = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$iterations = max(1, (int) ($argv[3] ?? 1));

$capture = [
    'iterations' => [],
    'listen_results' => [],
    'listen_errors' => [],
];

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

for ($iteration = 0; $iteration < $iterations; $iteration++) {
    $result = king_http1_server_listen_once(
        '127.0.0.1',
        $port,
        null,
        static function (array $request) use (&$capture): array {
            $capture['iterations'][] = [
                'telemetry' => $request['telemetry'] ?? null,
                'headers' => $request['headers'] ?? null,
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
