--TEST--
King HTTP/1 runtime handles bodiless HEAD 304 and interim 103/204 responses on wire
--FILE--
<?php
require __DIR__ . '/http1_bodiless_test_helper.inc';

$server = king_http1_start_bodiless_test_server();
try {
    $baseUrl = 'http://127.0.0.1:' . $server[2];

    $headResponse = king_http1_request_send(
        $baseUrl . '/head-reuse',
        'HEAD',
        null,
        null,
        ['timeout_ms' => 1000]
    );

    $afterHead = king_http1_request_send(
        $baseUrl . '/after-head',
        'GET',
        null,
        null,
        ['timeout_ms' => 1000]
    );

    $afterHeadBody = json_decode($afterHead['body'], true, flags: JSON_THROW_ON_ERROR);

    $notModified = king_client_send_request(
        $baseUrl . '/not-modified',
        'GET',
        null,
        null,
        ['timeout_ms' => 1000]
    );

    $context = king_http1_request_send(
        $baseUrl . '/early-hints-no-content',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 1000,
        ]
    );
    $pending = king_client_early_hints_get_pending($context);
    $response = king_receive_response($context);

    var_dump($headResponse['status']);
    var_dump($headResponse['body']);
    var_dump($headResponse['headers']['content-length']);

    var_dump($afterHead['status']);
    var_dump($afterHeadBody['method']);
    var_dump($afterHeadBody['path']);
    var_dump($afterHeadBody['connection_id'] === $headResponse['headers']['x-connection-id']);

    var_dump($notModified['status']);
    var_dump($notModified['body']);
    var_dump($notModified['headers']['etag']);

    var_dump(count($pending));
    var_dump($pending[0]['url']);
    var_dump($pending[0]['rel']);
    var_dump($pending[0]['as']);

    var_dump($response->getStatusCode());
    var_dump($response->isEndOfBody());
    var_dump($response->getBody());
} finally {
    king_http1_stop_bodiless_test_server($server);
}
?>
--EXPECT--
int(200)
string(0) ""
string(2) "15"
int(200)
string(3) "GET"
string(11) "/after-head"
bool(true)
int(304)
string(0) ""
string(15) ""bodiless-etag""
int(1)
string(8) "/app.css"
string(7) "preload"
string(5) "style"
int(204)
bool(true)
string(0) ""
