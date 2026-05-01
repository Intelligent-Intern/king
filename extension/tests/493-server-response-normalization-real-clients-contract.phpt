--TEST--
King server one-shot listeners normalize handler responses into stable client-visible headers across real HTTP/1, HTTP/2, and HTTP/3 clients
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_openssl();
king_http3_skipif_require_lsquic_runtime();
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';
require __DIR__ . '/http2_server_wire_helper.inc';
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';

function king_response_normalization_assert_header_values($actual, array $expected, string $label): void
{
    $values = is_array($actual) ? array_values($actual) : [$actual];
    if ($values !== $expected) {
        throw new RuntimeException(
            $label . ' mismatch: expected '
            . json_encode($expected)
            . ', got '
            . json_encode($values)
        );
    }
}

function king_response_normalization_assert_header_absent(array $headers, string $name, string $label): void
{
    if (array_key_exists($name, $headers)) {
        throw new RuntimeException($label . ' unexpectedly exposed ' . $name . '.');
    }
}

function king_response_normalization_count_header_pairs(array $pairs, string $name): int
{
    $count = 0;
    foreach ($pairs as $pair) {
        if (strcasecmp((string) ($pair['name'] ?? ''), $name) === 0) {
            $count++;
        }
    }

    return $count;
}

function king_response_normalization_assert_http2_lowercase_pairs(array $pairs): void
{
    foreach ($pairs as $pair) {
        $name = (string) ($pair['name'] ?? '');
        if ($name !== '' && $name[0] !== ':' && $name !== strtolower($name)) {
            throw new RuntimeException('HTTP/2 response header names must be normalized to lowercase on wire.');
        }
    }
}

$expectedBody = 'normalized-body';
$expectedLength = (string) strlen($expectedBody);
$http1 = [];
$http2 = [];
$http3 = [];
$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    $http1Server = king_server_websocket_wire_start_server('response-normalization');
    try {
        $http1Response = king_server_http1_wire_request_retry(
            $http1Server['port'],
            "GET /normalize HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
        );
        $http1 = king_server_http1_wire_parse_response($http1Response);
    } finally {
        king_server_websocket_wire_stop_server($http1Server);
    }

    $http2Server = king_http2_server_wire_start_server(null, 'response-normalization');
    try {
        $http2 = king_http2_server_wire_request_retry($http2Server['port'], [
            'method' => 'GET',
            'path' => '/normalize',
        ]);
    } finally {
        king_http2_server_wire_stop_server($http2Server);
    }

    $http3Run = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server(
            $fixture['cert'],
            $fixture['key'],
            null,
            'response-normalization'
        ),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/normalize',
            'GET',
            [],
            '',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => ($response['status'] ?? null) === 202
    );
    $http3 = $http3Run['result'];
} finally {
    king_http3_destroy_fixture($fixture);
}

if (($http1['status'] ?? null) !== 202) {
    throw new RuntimeException('HTTP/1 response status was not normalized correctly.');
}
if (($http1['body'] ?? null) !== $expectedBody) {
    throw new RuntimeException('HTTP/1 response body was not preserved.');
}
king_response_normalization_assert_header_values($http1['headers']['content-type'] ?? null, ['text/plain'], 'HTTP/1 content-type');
king_response_normalization_assert_header_values($http1['headers']['x-reply-mode'] ?? null, ['normalized'], 'HTTP/1 x-reply-mode');
king_response_normalization_assert_header_values($http1['headers']['x-multi'] ?? null, ['alpha', 'beta'], 'HTTP/1 x-multi');
king_response_normalization_assert_header_values($http1['headers']['content-length'] ?? null, [$expectedLength], 'HTTP/1 content-length');
king_response_normalization_assert_header_values($http1['headers']['connection'] ?? null, ['close'], 'HTTP/1 connection');
if (king_response_normalization_count_header_pairs($http1['header_pairs'] ?? [], 'X-Multi') !== 2) {
    throw new RuntimeException('HTTP/1 response did not materialize repeated X-Multi headers.');
}
if (king_response_normalization_count_header_pairs($http1['header_pairs'] ?? [], 'Content-Length') !== 1) {
    throw new RuntimeException('HTTP/1 response should emit one runtime-owned Content-Length header.');
}
if (king_response_normalization_count_header_pairs($http1['header_pairs'] ?? [], 'Connection') !== 1) {
    throw new RuntimeException('HTTP/1 response should emit one runtime-owned Connection header.');
}

if (($http2['status'] ?? null) !== 202) {
    throw new RuntimeException('HTTP/2 response status was not normalized correctly.');
}
if (($http2['body'] ?? null) !== $expectedBody) {
    throw new RuntimeException('HTTP/2 response body was not preserved.');
}
king_response_normalization_assert_header_values($http2['headers']['content-type'] ?? null, ['text/plain'], 'HTTP/2 content-type');
king_response_normalization_assert_header_values($http2['headers']['x-reply-mode'] ?? null, ['normalized'], 'HTTP/2 x-reply-mode');
king_response_normalization_assert_header_values($http2['headers']['x-multi'] ?? null, ['alpha', 'beta'], 'HTTP/2 x-multi');
king_response_normalization_assert_header_absent($http2['headers'], 'content-length', 'HTTP/2 response');
king_response_normalization_assert_header_absent($http2['headers'], 'connection', 'HTTP/2 response');
king_response_normalization_assert_http2_lowercase_pairs($http2['header_pairs'] ?? []);
if (king_response_normalization_count_header_pairs($http2['header_pairs'] ?? [], 'x-multi') !== 2) {
    throw new RuntimeException('HTTP/2 response did not materialize repeated x-multi headers.');
}
if (king_response_normalization_count_header_pairs($http2['header_pairs'] ?? [], 'content-length') !== 0) {
    throw new RuntimeException('HTTP/2 response leaked a handler-owned content-length header.');
}
if (king_response_normalization_count_header_pairs($http2['header_pairs'] ?? [], 'connection') !== 0) {
    throw new RuntimeException('HTTP/2 response leaked a hop-by-hop connection header.');
}

if (($http3['status'] ?? null) !== 202) {
    throw new RuntimeException('HTTP/3 response status was not normalized correctly.');
}
if (($http3['body'] ?? null) !== $expectedBody) {
    throw new RuntimeException('HTTP/3 response body was not preserved.');
}
king_response_normalization_assert_header_values($http3['headers']['content-type'] ?? null, ['text/plain'], 'HTTP/3 content-type');
king_response_normalization_assert_header_values($http3['headers']['x-reply-mode'] ?? null, ['normalized'], 'HTTP/3 x-reply-mode');
king_response_normalization_assert_header_values($http3['headers']['x-multi'] ?? null, ['alpha', 'beta'], 'HTTP/3 x-multi');
king_response_normalization_assert_header_absent($http3['headers'], 'content-length', 'HTTP/3 response');
king_response_normalization_assert_header_absent($http3['headers'], 'connection', 'HTTP/3 response');
if (array_key_exists('X-Reply-Mode', $http3['headers'])) {
    throw new RuntimeException('HTTP/3 response headers should be exposed through normalized lowercase keys.');
}

echo "OK\n";
?>
--EXPECT--
OK
