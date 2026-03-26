--TEST--
King WebSocket runtime keeps multiple clients progressing while peers close and reconnect on the same local server
--FILE--
<?php
require __DIR__ . '/websocket_churn_helper.inc';

$server = king_websocket_churn_start_server(4);
$capture = [];

try {
    $first = king_websocket_churn_connect_retry('ws://127.0.0.1:' . $server['port'] . '/first');
    $second = king_websocket_churn_connect_retry('ws://127.0.0.1:' . $server['port'] . '/second');
    $third = king_websocket_churn_connect_retry('ws://127.0.0.1:' . $server['port'] . '/third');

    var_dump(is_resource($first));
    var_dump(is_resource($second));
    var_dump(is_resource($third));
    var_dump(king_client_websocket_send($first, 'first-1'));
    var_dump(king_client_websocket_send($second, 'second-1'));
    var_dump(king_client_websocket_send($third, 'third-1'));
    var_dump(king_websocket_churn_receive_text($first, 1000));
    var_dump(king_websocket_churn_receive_text($second, 1000));
    var_dump(king_websocket_churn_receive_text($third, 1000));
    var_dump(king_client_websocket_close($second, 1001, 'rotate'));
    var_dump(king_client_websocket_get_status($second));

    $fourth = king_websocket_churn_connect_retry('ws://127.0.0.1:' . $server['port'] . '/fourth');
    var_dump(is_resource($fourth));
    var_dump(king_client_websocket_send($first, 'first-2'));
    var_dump(king_client_websocket_send($third, 'third-2'));
    var_dump(king_client_websocket_send($fourth, 'fourth-1'));
    var_dump(king_websocket_churn_receive_text($fourth, 1000));
    var_dump(king_websocket_churn_receive_text($first, 1000));
    var_dump(king_websocket_churn_receive_text($third, 1000));
    var_dump(king_client_websocket_get_status($first));
    var_dump(king_client_websocket_get_status($third));
    var_dump(king_client_websocket_get_status($fourth));
    var_dump(king_client_websocket_close($first, 1000, 'done'));
    var_dump(king_client_websocket_close($third, 1000, 'done'));
    var_dump(king_client_websocket_close($fourth, 1000, 'done'));
} finally {
    $capture = king_websocket_churn_stop_server($server);
}

$byPath = [];
foreach ($capture['connections'] as $connection) {
    $byPath[$connection['path']] = $connection;
}

var_dump(count($capture['connections']));
var_dump(array_keys($byPath) === ['/first', '/second', '/third', '/fourth']);
var_dump(array_column($byPath['/first']['frames'], 'opcode') === [1, 1, 8]);
var_dump(array_column($byPath['/second']['frames'], 'opcode') === [1, 8]);
var_dump(array_column($byPath['/third']['frames'], 'opcode') === [1, 1, 8]);
var_dump(array_column($byPath['/fourth']['frames'], 'opcode') === [1, 8]);
var_dump($byPath['/first']['frames'][0]['payload'] === 'first-1');
var_dump($byPath['/first']['frames'][1]['payload'] === 'first-2');
var_dump($byPath['/third']['frames'][0]['payload'] === 'third-1');
var_dump($byPath['/third']['frames'][1]['payload'] === 'third-2');
var_dump($byPath['/fourth']['frames'][0]['payload'] === 'fourth-1');
var_dump($byPath['/second']['frames'][1]['close_code'] === 1001);
var_dump($byPath['/second']['frames'][1]['close_reason'] === 'rotate');
var_dump($byPath['/fourth']['frames'][1]['close_code'] === 1000);
var_dump($byPath['/fourth']['frames'][1]['close_reason'] === 'done');
var_dump(count($capture['events']));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(7) "first-1"
string(8) "second-1"
string(7) "third-1"
bool(true)
int(3)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "fourth-1"
string(7) "first-2"
string(7) "third-2"
int(1)
int(1)
int(1)
bool(true)
bool(true)
bool(true)
int(4)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(10)
