--TEST--
Repo-local Flow PHP HTTP source streams bytes with replay-and-skip resume
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
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSource.php';

use King\Flow\HttpByteSource;
use King\Flow\SourceCursor;

function king_flow_http_source_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve HTTP source test port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr(strrchr($address, ':'), 1);

    $script = tempnam(sys_get_temp_dir(), 'king-flow-http-source-');
    $stop = tempnam(sys_get_temp_dir(), 'king-flow-http-source-stop-');
    @unlink($stop);
    $payload = 'alpha-beta-gamma';
    $php = <<<'PHP'
<?php
$port = (int) $argv[1];
$stop = $argv[2];
$payload = $argv[3];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "server bind failed: $errstr\n");
    exit(1);
}
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
stream_set_blocking($server, true);
while (!file_exists($stop)) {
    $conn = @stream_socket_accept($server, 1);
    if (!is_resource($conn)) {
        continue;
    }

    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    $response = "HTTP/1.1 200 OK\r\n"
        . "Content-Length: " . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $payload;
    fwrite($conn, $response);
    fflush($conn);
    fclose($conn);
}
fclose($server);
PHP;

    file_put_contents($script, $php);
    $command = [
        PHP_BINARY,
        '-n',
        $script,
        (string) $port,
        $stop,
        $payload,
    ];
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch HTTP source test server');
    }

    $ready = trim((string) fgets($pipes[1]));
    if ($ready !== 'READY') {
        $stderr = stream_get_contents($pipes[2]);
        throw new RuntimeException('HTTP source test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, $stop, $port];
}

function king_flow_http_source_stop_server(array $server): void
{
    touch($server[3]);
    foreach ($server[1] as $pipe) {
        fclose($pipe);
    }
    proc_terminate($server[0]);
    proc_close($server[0]);
    @unlink($server[2]);
    @unlink($server[3]);
}

$server = king_flow_http_source_start_server();
try {
    $adapter = new HttpByteSource(
        'http://127.0.0.1:' . $server[4] . '/payload',
        'GET',
        [],
        null,
        5,
        ['timeout_ms' => 2000]
    );

    $firstChunks = [];
    $firstResult = $adapter->pumpBytes(
        function (string $chunk, SourceCursor $cursor) use (&$firstChunks): bool {
            $firstChunks[] = [$chunk, $cursor->bytesConsumed()];

            return count($firstChunks) < 2;
        }
    );

    $cursor = SourceCursor::fromArray($firstResult->cursor()->toArray());
    $secondChunks = [];
    $secondResult = $adapter->pumpBytes(
        function (string $chunk, SourceCursor $cursor) use (&$secondChunks): bool {
            $secondChunks[] = [$chunk, $cursor->bytesConsumed()];

            return true;
        },
        $cursor
    );

    var_dump($firstResult->complete());
    var_dump($firstChunks);
    var_dump($secondResult->complete());
    var_dump($secondChunks);
    var_dump($secondResult->cursor()->toArray()['resume_strategy']);
    var_dump($secondResult->cursor()->toArray()['state']['next_offset']);
} finally {
    king_flow_http_source_stop_server($server);
}
?>
--EXPECT--
bool(false)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "alpha"
    [1]=>
    int(5)
  }
  [1]=>
  array(2) {
    [0]=>
    string(5) "-beta"
    [1]=>
    int(10)
  }
}
bool(true)
array(2) {
  [0]=>
  array(2) {
    [0]=>
    string(5) "-gamm"
    [1]=>
    int(15)
  }
  [1]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    int(16)
  }
}
string(15) "replay_and_skip"
int(16)
