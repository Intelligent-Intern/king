--TEST--
King Stream per-call CancelTokens are accepted locally and cancel the stream when tripped
--FILE--
<?php
$session = new King\Session('127.0.0.1', 443);
$stream = $session->sendRequest('GET', '/demo');
$cancel = new King\CancelToken();

var_dump($stream->isClosed());
$cancel->cancel();

try {
    $stream->receiveResponse(null, $cancel);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$stats = $session->stats();
var_dump($stream->isClosed());
var_dump($stats['cancel_calls']);
var_dump($stats['last_canceled_stream_id']);
var_dump($stats['last_cancel_mode']);
?>
--EXPECT--
bool(false)
string(27) "King\StreamStoppedException"
string(91) "Stream::receiveResponse() cannot continue because the stream was cancelled via CancelToken."
bool(true)
int(1)
int(0)
string(4) "both"
