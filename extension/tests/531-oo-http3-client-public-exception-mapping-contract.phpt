--TEST--
King OO Http3Client maps remote QUIC aborts into stable public exception classes
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 failure-peer helper";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([], 'king-http3-oo-public-exception-fixture-');
$config = new King\Config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$assertCapture = static function (array $capture, array $expect): void {
    var_dump($capture['mode'] === $expect['mode']);
    var_dump($capture['saw_initial'] === true);
    var_dump($capture['saw_established'] === true);
    var_dump($capture['peer_error_present'] === false);
    var_dump($capture['local_error_present'] === true);
    var_dump($capture['local_error_is_app'] === $expect['local_error_is_app']);
    var_dump($capture['local_error_code'] === $expect['local_error_code']);
    var_dump($capture['local_error_reason'] === $expect['local_error_reason']);

    if ($expect['mode'] === 'transport_close') {
        var_dump($capture['saw_h3_open'] === false);
        var_dump($capture['saw_request_headers'] === false);
        return;
    }

    var_dump($capture['saw_h3_open'] === true);
    var_dump($capture['saw_request_headers'] === true);
    var_dump($capture['close_trigger'] === 'application_close');
};

$cases = [
    [
        'mode' => 'transport_close',
        'path' => '/transport-close',
        'exception_class' => 'King\\QuicException',
        'expected' => 'King\\Client\\HttpClient::request() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").',
        'local_error_is_app' => false,
        'local_error_code' => 4919,
        'local_error_reason' => 'test transport abort',
    ],
    [
        'mode' => 'application_close',
        'path' => '/application-close',
        'exception_class' => 'King\\ProtocolException',
        'expected' => 'King\\Client\\HttpClient::request() received a protocol close before the HTTP/3 response completed (code 4660, reason "test application abort").',
        'local_error_is_app' => true,
        'local_error_code' => 4660,
        'local_error_reason' => 'test application abort',
    ],
];

try {
    foreach ($cases as $case) {
        $client = new King\Client\Http3Client($config);
        $result = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => $client->request(
                'GET',
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path']
            ),
            $case['exception_class']
        );

        $exception = $result['exception'];
        $capture = king_http3_failure_peer_close_capture($result['capture']);
        var_dump($case['mode']);
        var_dump(get_class($exception));
        var_dump($exception->getMessage() === $case['expected']);
        var_dump(king_get_last_error() === $case['expected']);
        $assertCapture($capture, $case);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(15) "transport_close"
string(18) "King\QuicException"
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
string(17) "application_close"
string(22) "King\ProtocolException"
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
