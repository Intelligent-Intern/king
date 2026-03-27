--TEST--
King HTTP/1 runtime maps early socket aborts and truncated bodies to stable protocol errors
--FILE--
<?php
require __DIR__ . '/http1_abort_test_helper.inc';

$server = king_http1_start_abort_test_server();
try {
    $baseUrl = 'http://127.0.0.1:' . $server[2];

    try {
        king_http1_request_send($baseUrl . '/header-abort');
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_client_send_request($baseUrl . '/header-abort');
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_http1_request_send($baseUrl . '/length-abort');
        echo "no-exception-3\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    $context = king_http1_request_send(
        $baseUrl . '/length-abort',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 1000,
        ]
    );
    try {
        $response = king_receive_response($context);
        $response->getBody();
        echo "no-exception-4\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_http1_request_send($baseUrl . '/chunked-abort');
        echo "no-exception-5\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
} finally {
    king_http1_stop_abort_test_server($server);
}
?>
--EXPECTF--
string(22) "King\ProtocolException"
string(%d) "king_http1_request_send() did not receive a complete HTTP/1 response header block."
string(22) "King\ProtocolException"
string(%d) "king_client_send_request() did not receive a complete HTTP/1 response header block."
string(22) "King\ProtocolException"
string(%d) "king_http1_request_send() peer closed the HTTP/1 body stream before Content-Length bytes were received."
string(22) "King\ProtocolException"
string(%d) "Response::getBody() peer closed the HTTP/1 body stream before Content-Length bytes were received."
string(22) "King\ProtocolException"
string(%d) "king_http1_request_send() peer closed the HTTP/1 chunked body stream before the terminating chunk was received."
