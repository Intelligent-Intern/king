--TEST--
King HTTP/2 runtime can perform a real local h2c roundtrip with per-origin reuse
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

    $script = tempnam(sys_get_temp_dir(), 'king-http2-server-');
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
      body,
      requestCount: handled
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
        throw new RuntimeException('failed to launch local HTTP/2 test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 test server failed: ' . trim($stderr));
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
    $directResponse = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/direct?x=1',
        'POST',
        [
            'X-Mode' => 'direct',
            'Content-Type' => 'text/plain',
        ],
        'payload',
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );

    $dispatcherResponse = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch',
        'GET',
        ['X-Mode' => 'dispatcher'],
        null,
        ['preferred_protocol' => 'http2']
    );
} finally {
    king_http2_stop_test_server($server);
}

$directEcho = json_decode($directResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$dispatcherEcho = json_decode($dispatcherResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($directResponse['status']);
var_dump($directResponse['protocol']);
var_dump($directResponse['transport_backend']);
var_dump($directResponse['headers']['x-connection-id']);
var_dump($directEcho['connectionId']);
var_dump($directEcho['method']);
var_dump($directEcho['path']);
var_dump($directEcho['mode']);
var_dump($directEcho['body']);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherResponse['transport_backend']);
var_dump($dispatcherResponse['headers']['x-request-count']);
var_dump($dispatcherEcho['connectionId']);
var_dump($dispatcherEcho['method']);
var_dump($dispatcherEcho['path']);
var_dump($dispatcherEcho['mode']);
var_dump($dispatcherEcho['requestCount']);
var_dump($dispatcherEcho['connectionId'] === $directEcho['connectionId']);
?>
--EXPECT--
int(200)
string(6) "http/2"
string(11) "libcurl_h2c"
string(1) "1"
int(1)
string(4) "POST"
string(11) "/direct?x=1"
string(6) "direct"
string(7) "payload"
int(200)
string(6) "http/2"
string(11) "libcurl_h2c"
string(1) "2"
int(1)
string(3) "GET"
string(9) "/dispatch"
string(10) "dispatcher"
int(2)
bool(true)
