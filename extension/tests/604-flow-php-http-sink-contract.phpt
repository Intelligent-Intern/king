--TEST--
Repo-local Flow PHP HTTP sink streams request bodies and returns terminal response state
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSink.php';

use King\Flow\HttpByteSink;

function king_flow_http_sink_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve HTTP sink test port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr(strrchr($address, ':'), 1);

    $script = tempnam(sys_get_temp_dir(), 'king-flow-http-sink-');
    $capture = tempnam(sys_get_temp_dir(), 'king-flow-http-sink-body-');
    $php = <<<'PHP'
<?php
$port = (int) $argv[1];
$capture = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "server bind failed: $errstr\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);

$conn = @stream_socket_accept($server, 5);
if (!is_resource($conn)) {
    fwrite(STDERR, "accept failed\n");
    exit(2);
}

$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $chunk = fread($conn, 8192);
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $request .= $chunk;
}

[$headerBlock, $body] = explode("\r\n\r\n", $request, 2) + ['', ''];
$contentLength = 0;
if (preg_match('/^content-length:\s*(\d+)\s*$/mi', $headerBlock, $matches)) {
    $contentLength = (int) $matches[1];
}

while (strlen($body) < $contentLength) {
    $chunk = fread($conn, $contentLength - strlen($body));
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $body .= $chunk;
}

file_put_contents($capture, $body);

$response = "HTTP/1.1 201 Created\r\n"
    . "Content-Length: 0\r\n"
    . "Connection: close\r\n\r\n";
fwrite($conn, $response);
fclose($conn);
fclose($server);
PHP;

    file_put_contents($script, $php);
    $process = proc_open(
        [PHP_BINARY, '-n', $script, (string) $port, $capture],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch HTTP sink test server');
    }

    $ready = trim((string) fgets($pipes[1]));
    if ($ready !== 'READY') {
        $stderr = stream_get_contents($pipes[2]);
        throw new RuntimeException('HTTP sink test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, $capture, $port];
}

function king_flow_http_sink_stop_server(array $server): void
{
    foreach ($server[1] as $pipe) {
        fclose($pipe);
    }

    proc_close($server[0]);
    @unlink($server[2]);
    @unlink($server[3]);
}

$server = king_flow_http_sink_start_server();
try {
    $session = new King\Session('127.0.0.1', $server[4]);
    $adapter = new HttpByteSink(
        $session,
        'POST',
        '/sink?mode=stream',
        ['content-type' => 'application/octet-stream'],
        ['response_timeout_ms' => 2000]
    );

    $first = $adapter->write('alpha');
    $second = $adapter->write('-beta');
    $complete = $adapter->complete('-omega');

    var_dump($first->failure());
    var_dump($second->failure());
    var_dump($complete->complete());
    var_dump($complete->transportCommitted());
    var_dump($complete->details()['response_status']);
    var_dump($complete->cursor()->toArray()['resume_strategy']);
    var_dump(file_get_contents($server[3]));
} finally {
    king_flow_http_sink_stop_server($server);
}
?>
--EXPECT--
NULL
NULL
bool(true)
bool(true)
int(201)
string(15) "restart_request"
string(16) "alpha-beta-omega"
