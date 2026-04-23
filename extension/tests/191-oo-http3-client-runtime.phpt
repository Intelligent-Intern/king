--TEST--
King OO Http3Client wrapper uses the active LSQUIC HTTP/3 runtime and returns Response objects
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
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
    $firstUrl = king_http3_test_server_url($server, '/first.txt');
    $secondUrl = king_http3_test_server_url($server, '/second.txt');

    $warmup = king_http3_request_with_retry(
        static fn () => king_http3_request_send(
            $firstUrl,
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
    $first = $client->request('GET', $firstUrl);
    $second = $client->request('GET', $secondUrl);
} finally {
    king_http3_stop_test_server($server);
    king_http3_destroy_fixture($fixture);
}

var_dump($warmup['transport_backend']);

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
string(9) "lsquic_h3"
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
