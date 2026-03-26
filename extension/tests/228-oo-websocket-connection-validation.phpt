--TEST--
King WebSocket Connection OO wrapper validates construction and frame semantics on a live socket
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

try {
    new King\WebSocket\Connection('http://example.test/socket');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$invalidConfig = fopen('php://memory', 'r');

try {
    new King\WebSocket\Connection(
        'ws://127.0.0.1/chat',
        null,
        ['connection_config' => $invalidConfig]
    );
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

fclose($invalidConfig);

$server = king_websocket_test_start_server();

try {
    $websocket = new King\WebSocket\Connection(
        'ws://127.0.0.1:' . $server['port'] . '/chat',
        null,
        ['max_payload_size' => 4]
    );

    try {
        $websocket->send('hello');
        echo "no-exception-3\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        $websocket->ping(str_repeat('a', 126));
        echo "no-exception-4\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        $websocket->close(999);
        echo "no-exception-5\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    $websocket->close(1000, 'done');

    try {
        $websocket->send('ok');
        echo "no-exception-6\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    var_dump(king_client_websocket_get_last_error());
} finally {
    king_websocket_test_stop_server($server);
}
?>
--EXPECTF--
string(24) "King\ValidationException"
string(%d) "WebSocket\Connection::__construct() currently supports only absolute ws:// and wss:// URLs."
string(24) "King\ValidationException"
string(%d) "WebSocket\Connection::__construct() option 'connection_config' must be a King\Config resource or object."
string(31) "King\WebSocketProtocolException"
string(%d) "WebSocket\Connection::send() payload size 5 exceeds max_payload_size 4."
string(31) "King\WebSocketProtocolException"
string(%d) "WebSocket\Connection::ping() ping payload cannot exceed 125 bytes."
string(24) "King\ValidationException"
string(%d) "WebSocket\Connection::close() close status code must be between 1000 and 4999."
string(29) "King\WebSocketClosedException"
string(%d) "WebSocket\Connection::send() cannot run on a closed WebSocket connection."
string(%d) "WebSocket\Connection::send() cannot run on a closed WebSocket connection."
