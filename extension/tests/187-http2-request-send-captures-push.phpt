--TEST--
King HTTP/2 request_send can capture server push responses with lifecycle metadata
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 push fixture";
}
?>
--FILE--
<?php
function king_http2_start_push_test_server(int $expectedStreams = 2): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-push-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const expectedStreams = Number(process.argv[3] || 2);
const server = http2.createServer();
const sessionIds = new Map();
let nextConnectionId = 0;
let completedStreams = 0;

function maybeClose() {
  if (completedStreams >= expectedStreams) {
    setTimeout(() => {
      server.close(() => process.exit(0));
    }, 50);
  }
}

function finishStream(stream, headers, payload) {
  stream.respond(headers);
  stream.end(payload, () => {
    completedStreams += 1;
    maybeClose();
  });
}

server.on('session', (session) => {
  nextConnectionId += 1;
  sessionIds.set(session, nextConnectionId);
  session.on('close', () => sessionIds.delete(session));
});

server.on('stream', (stream, headers) => {
  const path = headers[':path'] || '';
  const mode = headers['x-mode'] || '';
  const connectionId = sessionIds.get(stream.session) || -1;

  stream.pushStream({ ':path': '/asset.js', ':method': 'GET' }, (err, pushStream) => {
    if (err) {
      console.error(err && err.stack ? err.stack : String(err));
      process.exit(2);
      return;
    }

    finishStream(
      pushStream,
      {
        ':status': 200,
        'content-type': 'application/json',
        'x-connection-id': String(connectionId),
        'x-push-kind': 'asset'
      },
      JSON.stringify({
        connectionId,
        kind: 'push',
        path: '/asset.js',
        pushedFrom: path
      })
    );
  });

  finishStream(
    stream,
    {
      ':status': 200,
      'content-type': 'application/json',
      'x-connection-id': String(connectionId),
      'x-request-kind': 'primary'
    },
    JSON.stringify({
      connectionId,
      kind: 'request',
      path,
      mode
    })
  );
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
    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . (int) $expectedStreams;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/2 push test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 push test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_push_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_push_test_server();
try {
    $response = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/index',
        'GET',
        ['X-Mode' => 'direct'],
        null,
        [
            'capture_push' => true,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http2_stop_push_test_server($server);
}

$root = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
$pushResponse = $response['pushes'][0];
$push = json_decode($pushResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($response['protocol']);
var_dump($response['stream_kind']);
var_dump($response['response_complete']);
var_dump($response['push_count']);
var_dump(count($response['pushes']));
var_dump($response['body_bytes'] > 0);
var_dump($response['header_bytes'] > 0);
var_dump($root['mode']);
var_dump($root['connectionId'] === $push['connectionId']);
var_dump($pushResponse['stream_kind']);
var_dump($pushResponse['response_complete']);
var_dump($pushResponse['pushed_from_request_index']);
var_dump($pushResponse['promise_headers'][':path']);
var_dump($pushResponse['headers']['x-push-kind']);
var_dump(str_ends_with($pushResponse['effective_url'], '/asset.js'));
?>
--EXPECT--
int(200)
string(6) "http/2"
string(7) "request"
bool(true)
int(1)
int(1)
bool(true)
bool(true)
string(6) "direct"
bool(true)
string(4) "push"
bool(true)
int(0)
string(9) "/asset.js"
string(5) "asset"
bool(true)
