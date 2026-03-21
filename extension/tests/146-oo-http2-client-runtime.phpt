--TEST--
King OO Http2Client wrapper uses the active HTTP/2 runtime and returns Response objects
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 fixture";
}
?>
--FILE--
<?php
function king_http2_start_test_server(int $expectedRequests = 2): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-oo-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const expectedRequests = Number(process.argv[3] || 2);
const server = http2.createServer();
const sessionIds = new Map();
let nextConnectionId = 0;
let handled = 0;

server.on('session', (session) => {
  nextConnectionId += 1;
  sessionIds.set(session, nextConnectionId);
  session.on('close', () => sessionIds.delete(session));
});

server.on('stream', (stream, headers) => {
  let body = '';
  stream.setEncoding('utf8');
  stream.on('data', (chunk) => {
    body += chunk;
  });
  stream.on('end', () => {
    handled += 1;
    const connectionId = sessionIds.get(stream.session) || -1;
    const payload = JSON.stringify({
      connectionId,
      method: headers[':method'] || '',
      path: headers[':path'] || '',
      mode: headers['x-mode'] || '',
      requestCount: handled,
      body
    });

    stream.respond({
      ':status': 200,
      'content-type': 'application/json',
      'x-connection-id': String(connectionId),
      'x-request-count': String(handled)
    });
    stream.end(payload);

    if (handled >= expectedRequests) {
      setTimeout(() => {
        server.close(() => process.exit(0));
      }, 50);
    }
  });
});

server.on('error', (err) => {
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(2);
});

server.listen(port, '127.0.0.1', () => {
  console.log('READY');
});
JS);

    $node = trim((string) shell_exec('command -v node'));
    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . (int) $expectedRequests;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/2 OO test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 OO test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_test_server();
try {
    $client = new King\Client\Http2Client();
    $first = $client->request(
        'POST',
        'http://127.0.0.1:' . $server[3] . '/first',
        ['X-Mode' => 'first'],
        'payload'
    );
    $second = $client->request(
        'GET',
        'http://127.0.0.1:' . $server[3] . '/second',
        ['X-Mode' => 'second']
    );
} finally {
    king_http2_stop_test_server($server);
}

$firstEcho = json_decode($first->getBody(), true, flags: JSON_THROW_ON_ERROR);
$secondEcho = json_decode($second->getBody(), true, flags: JSON_THROW_ON_ERROR);

var_dump($first instanceof King\Response);
var_dump($first->getStatusCode());
var_dump($first->getHeaders()['x-connection-id']);
var_dump($firstEcho['connectionId']);
var_dump($firstEcho['method']);
var_dump($firstEcho['path']);
var_dump($firstEcho['mode']);
var_dump($firstEcho['body']);

var_dump($second instanceof King\Response);
var_dump($second->getStatusCode());
var_dump($second->getHeaders()['x-request-count']);
var_dump($secondEcho['connectionId']);
var_dump($secondEcho['method']);
var_dump($secondEcho['path']);
var_dump($secondEcho['mode']);
var_dump($secondEcho['requestCount']);
var_dump($secondEcho['connectionId'] === $firstEcho['connectionId']);
?>
--EXPECT--
bool(true)
int(200)
string(1) "1"
int(1)
string(4) "POST"
string(6) "/first"
string(5) "first"
string(7) "payload"
bool(true)
int(200)
string(1) "2"
int(1)
string(3) "GET"
string(7) "/second"
string(6) "second"
int(2)
bool(true)
