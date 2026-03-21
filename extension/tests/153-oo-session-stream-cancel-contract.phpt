--TEST--
King Session and Stream propagate bound CancelTokens into the local stream runtime
--FILE--
<?php
$session = new King\Session('127.0.0.1', 443);
$cancelled = new King\CancelToken();
$cancelled->cancel();

try {
    $session->sendRequest('GET', '/demo', [], '', $cancelled);
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$bound = new King\CancelToken();
$stream = $session->sendRequest('GET', '/demo', [], '', $bound);
$bound->cancel();

try {
    $stream->receiveResponse();
    echo "no-exception-2\n";
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
string(21) "King\RuntimeException"
string(72) "Session::sendRequest() received a CancelToken that is already cancelled."
string(27) "King\StreamStoppedException"
string(91) "Stream::receiveResponse() cannot continue because the stream was cancelled via CancelToken."
bool(true)
int(1)
int(0)
string(4) "both"
