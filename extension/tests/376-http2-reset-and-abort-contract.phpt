--TEST--
King HTTP/2 reset-stream and connection-abort paths expose stable failure semantics and pool recovery
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 failure fixture";
}
?>
--FILE--
<?php
require __DIR__ . '/http2_failure_test_helper.inc';

$server = king_http2_start_failure_test_server();
$base = 'http://127.0.0.1:' . $server[2];

try {
    try {
        king_http2_request_send(
            $base . '/reset-stream',
            'GET',
            null,
            null,
            [
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 1000,
            ]
        );
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
    }

    try {
        king_client_send_request(
            $base . '/reset-stream',
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http2',
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 1000,
            ]
        );
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
    }

    try {
        king_http2_request_send(
            $base . '/connection-abort',
            'GET',
            null,
            null,
            [
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 1000,
            ]
        );
        echo "no-exception-3\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
    }

    try {
        king_client_send_request(
            $base . '/connection-abort',
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http2',
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 1000,
            ]
        );
        echo "no-exception-4\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
    }

    $directOk = king_http2_request_send(
        $base . '/ok',
        'GET',
        null,
        null,
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 1000,
        ]
    );

    $dispatchOk = king_client_send_request(
        $base . '/ok',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http2',
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 1000,
        ]
    );
} finally {
    king_http2_stop_failure_test_server($server);
}

$directEcho = json_decode($directOk['body'], true, flags: JSON_THROW_ON_ERROR);
$dispatchEcho = json_decode($dispatchOk['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($directOk['status']);
var_dump($directOk['protocol']);
var_dump($directOk['transport_backend']);
var_dump($directEcho['path']);
var_dump($directEcho['requestCount']);
var_dump($directEcho['connectionId'] > 0);
var_dump($dispatchOk['status']);
var_dump($dispatchOk['protocol']);
var_dump($dispatchOk['transport_backend']);
var_dump($dispatchEcho['path']);
var_dump($dispatchEcho['requestCount']);
var_dump($dispatchEcho['connectionId'] === $directEcho['connectionId']);
?>
--EXPECT--
string(22) "King\ProtocolException"
bool(true)
bool(true)
string(22) "King\ProtocolException"
bool(true)
bool(true)
string(21) "King\NetworkException"
bool(true)
bool(true)
string(21) "King\NetworkException"
bool(true)
bool(true)
int(200)
string(6) "http/2"
string(11) "libcurl_h2c"
string(3) "/ok"
int(1)
bool(true)
int(200)
string(6) "http/2"
string(11) "libcurl_h2c"
string(3) "/ok"
int(2)
bool(true)
