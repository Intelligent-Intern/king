--TEST--
King OO Http3Client wrapper uses the active HTTP/3 runtime and returns Response objects
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_QUICHE_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_QUICHE_SERVER must point at a prebuilt quiche-server binary";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture(
    [
        'first.txt' => "first-http3\n",
        'second.txt' => "second-http3\n",
    ],
    'king-http3-oo-fixture-'
);
$server = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);

try {
    $config = new King\Config([
        'tls_default_ca_file' => $fixture['cert'],
        'tcp_connect_timeout_ms' => 10000,
    ]);

    king_http3_request_with_retry(
        static fn () => king_http3_request_send(
            'https://localhost:' . $server[2] . '/first.txt',
            'GET',
            null,
            null,
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        )
    );

    $client = new King\Client\Http3Client($config);
    $first = $client->request('GET', 'https://localhost:' . $server[2] . '/first.txt');
    $second = $client->request('GET', 'https://localhost:' . $server[2] . '/second.txt');
} finally {
    king_http3_stop_test_server($server);
    king_http3_destroy_fixture($fixture);
}

var_dump($first instanceof King\Response);
var_dump($first->getStatusCode());
var_dump($first->getHeaders()['content-length']);
var_dump($first->getBody());

var_dump($second instanceof King\Response);
var_dump($second->getStatusCode());
var_dump($second->getHeaders()['content-length']);
var_dump($second->getBody());
?>
--EXPECT--
bool(true)
int(200)
string(2) "12"
string(12) "first-http3
"
bool(true)
int(200)
string(2) "13"
string(13) "second-http3
"
