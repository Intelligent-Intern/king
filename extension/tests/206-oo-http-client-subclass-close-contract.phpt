--TEST--
King OO Http protocol subclasses preserve the shared closed-client contract before dispatch
--FILE--
<?php
$matrix = [
    [new King\Client\Http1Client(), 'http://127.0.0.1:80/'],
    [new King\Client\Http2Client(), 'http://127.0.0.1:80/'],
    [new King\Client\Http3Client(), 'https://127.0.0.1:443/'],
];

foreach ($matrix as [$client, $url]) {
    $client->close();

    var_dump(get_class($client));

    try {
        $client->request('GET', $url);
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === 'HttpClient::request() cannot run on a closed client.');
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
