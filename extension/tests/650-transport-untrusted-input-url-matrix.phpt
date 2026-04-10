--TEST--
King transport URL validators reject untrusted fragment and line-break inputs before dialing
--FILE--
<?php
$expectTransportFailure = static function (callable $operation, callable $errorReader, string $needle): bool {
    try {
        $result = $operation();
        if ($result !== false) {
            return false;
        }
    } catch (Throwable $e) {
        return str_contains($e->getMessage(), $needle);
    }

    return str_contains((string) $errorReader(), $needle);
};

var_dump($expectTransportFailure(
    static fn() => king_client_websocket_connect('ws://example.test/socket#fragment'),
    static fn() => king_client_websocket_get_last_error(),
    'does not support URL fragments in the connection URL.'
));

var_dump($expectTransportFailure(
    static fn() => king_client_websocket_connect("ws://example.test/socket\r\nbad"),
    static fn() => king_client_websocket_get_last_error(),
    'URL path contains invalid line breaks.'
));

var_dump($expectTransportFailure(
    static fn() => king_client_websocket_connect("ws://example.test/socket?x=1\r\nbad"),
    static fn() => king_client_websocket_get_last_error(),
    'URL query contains invalid line breaks.'
));

var_dump($expectTransportFailure(
    static fn() => king_http2_request_send('ftp://example.test/resource'),
    static fn() => king_get_last_error(),
    'currently supports only absolute http:// and https:// URLs.'
));

var_dump($expectTransportFailure(
    static fn() => king_http2_request_send('https://user:pass@example.test/resource'),
    static fn() => king_get_last_error(),
    'does not support embedding credentials in the request URL.'
));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
