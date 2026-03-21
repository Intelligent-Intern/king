--TEST--
King client dispatcher keeps the HTTP/3 response_stream contract stable on both entry points
--FILE--
<?php
try {
    king_client_send_request(
        'https://127.0.0.1:443/',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http3',
            'response_stream' => true,
        ]
    );
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage() === 'HTTP/1 response_stream mode is not available on the active HTTP/3 runtime.');
    var_dump(king_get_last_error() === 'HTTP/1 response_stream mode is not available on the active HTTP/3 runtime.');
}

try {
    king_send_request(
        'https://127.0.0.1:443/',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http3',
            'response_stream' => true,
        ]
    );
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage() === 'HTTP/1 response_stream mode is not available on the active HTTP/3 runtime.');
    var_dump(king_get_last_error() === 'HTTP/1 response_stream mode is not available on the active HTTP/3 runtime.');
}
?>
--EXPECT--
string(22) "King\ProtocolException"
bool(true)
bool(true)
string(22) "King\ProtocolException"
bool(true)
bool(true)
