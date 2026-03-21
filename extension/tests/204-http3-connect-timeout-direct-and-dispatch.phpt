--TEST--
King HTTP/3 direct and dispatcher paths expose the active QUIC connect-timeout contract
--SKIPIF--
<?php
$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}
?>
--FILE--
<?php
function king_http3_start_silent_udp_server(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("failed to reserve silent UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($server, false);
    [, $port] = explode(':', $serverName, 2);

    return [$server, (int) $port];
}

function king_http3_stop_silent_udp_server(array $server): void
{
    fclose($server[0]);
}

$server = king_http3_start_silent_udp_server();
try {
    try {
        king_http3_request_send(
            'https://127.0.0.1:' . $server[1] . '/',
            'GET',
            null,
            null,
            [
                'connect_timeout_ms' => 100,
                'timeout_ms' => 200,
            ]
        );
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === 'king_http3_request_send() timed out while establishing the QUIC connection.');
        var_dump(king_get_last_error() === 'king_http3_request_send() timed out while establishing the QUIC connection.');
    }

    try {
        king_client_send_request(
            'https://127.0.0.1:' . $server[1] . '/',
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http3',
                'connect_timeout_ms' => 100,
                'timeout_ms' => 200,
            ]
        );
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === 'king_client_send_request() timed out while establishing the QUIC connection.');
        var_dump(king_get_last_error() === 'king_client_send_request() timed out while establishing the QUIC connection.');
    }
} finally {
    king_http3_stop_silent_udp_server($server);
}
?>
--EXPECT--
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
