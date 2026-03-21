--TEST--
King client dispatcher can capture HTTP/2 server push responses on the active HTTP/2 runtime
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 push fixture";
}
?>
--FILE--
<?php
function king_http2_start_dispatch_push_test_server(int $expectedStreams = 2): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-dispatch-push-server-');
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

  stream.pushStream({ ':path': '/bundle.js', ':method': 'GET' }, (err, pushStream) => {
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
        'x-push-kind': 'bundle'
      },
      JSON.stringify({
        connectionId,
        kind: 'push',
        path: '/bundle.js',
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
        throw new RuntimeException('failed to launch local HTTP/2 dispatcher push test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 dispatcher push test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_dispatch_push_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_dispatch_push_test_server();
try {
    $response = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch',
        'GET',
        ['X-Mode' => 'dispatcher'],
        null,
        [
            'preferred_protocol' => 'http2',
            'capture_push' => true,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http2_stop_dispatch_push_test_server($server);
}

$root = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
$pushResponse = $response['pushes'][0];
$push = json_decode($pushResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($response['transport_backend']);
var_dump($response['stream_kind']);
var_dump($response['push_count']);
var_dump(count($response['pushes']));
var_dump($root['mode']);
var_dump($root['path']);
var_dump($push['pushedFrom']);
var_dump($pushResponse['promise_headers'][':path']);
var_dump($pushResponse['headers']['x-push-kind']);
var_dump($pushResponse['pushed_from_request_index']);
var_dump($pushResponse['body_bytes'] > 0);
var_dump($pushResponse['header_bytes'] > 0);
var_dump($root['connectionId'] === $push['connectionId']);
?>
--EXPECT--
int(200)
string(11) "libcurl_h2c"
string(7) "request"
int(1)
int(1)
string(10) "dispatcher"
string(9) "/dispatch"
string(9) "/dispatch"
string(10) "/bundle.js"
string(6) "bundle"
int(0)
bool(true)
bool(true)
bool(true)
