--TEST--
King OO Http protocol subclasses preserve the shared pre-cancel contract before dispatch
--FILE--
<?php
$clients = [
    new King\Client\Http1Client(),
    new King\Client\Http2Client(),
    new King\Client\Http3Client(),
];

foreach ($clients as $client) {
    $cancelled = new King\CancelToken();
    $cancelled->cancel();

    var_dump(get_class($client));

    try {
        $client->request('GET', 'http://127.0.0.1:80/', [], '', $cancelled);
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === 'HttpClient::request() received a CancelToken that is already cancelled.');
    }
}
?>
--EXPECT--
string(23) "King\Client\Http1Client"
string(21) "King\RuntimeException"
bool(true)
string(23) "King\Client\Http2Client"
string(21) "King\RuntimeException"
bool(true)
string(23) "King\Client\Http3Client"
string(21) "King\RuntimeException"
bool(true)
