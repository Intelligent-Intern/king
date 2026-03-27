--TEST--
King HTTP/1 runtime enforces cumulative interim-response and pending Early Hints budgets
--FILE--
<?php
require __DIR__ . '/http1_interim_budget_test_helper.inc';

$server = king_http1_start_interim_budget_test_server();
try {
    $baseUrl = 'http://127.0.0.1:' . $server[2];

    try {
        king_http1_request_send(
            $baseUrl . '/interim-size-cap',
            'GET',
            null,
            null,
            ['timeout_ms' => 2000]
        );
        echo "no-size-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_http1_request_send(
            $baseUrl . '/early-hints-cap',
            'GET',
            null,
            null,
            [
                'response_stream' => true,
                'timeout_ms' => 2000,
            ]
        );
        echo "no-hints-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
} finally {
    king_http1_stop_interim_budget_test_server($server);
}
?>
--EXPECTF--
string(22) "King\ProtocolException"
string(%d) "king_http1_request_send() exceeded the HTTP/1 runtime response size cap."
string(22) "King\ProtocolException"
string(%d) "king_http1_request_send() exceeded the HTTP/1 pending Early Hints budget."
