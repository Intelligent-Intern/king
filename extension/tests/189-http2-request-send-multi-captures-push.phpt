--TEST--
King HTTP/2 multi request leaf captures pushed responses and keeps them attached to the originating request
--SKIPIF--
<?php
if (PHP_OS === 'Darwin') {
    die("skip HTTP/2 runtime requires libcurl.so (Linux) - not available on macOS");
}
if (trim((string) shell_exec('command -v node')) === '') {
    die("skip node is required for the local HTTP/2 push fixture");
}
?>
--FILE--
<?php
function king_http2_start_multi_push_test_server(int $expectedStreams = 2): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-multi-push-server-');
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

  stream.pushStream({ ':path': '/sheet.css', ':method': 'GET' }, (err, pushStream) => {
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
        'x-push-kind': 'sheet'
      },
      JSON.stringify({
        connectionId,
        kind: 'push',
        path: '/sheet.css',
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
        throw new RuntimeException('failed to launch local HTTP/2 multi push test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 multi push test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_multi_push_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_multi_push_test_server();
try {
    $responses = king_http2_request_send_multi(
        [
            [
                'url' => 'http://127.0.0.1:' . $server[3] . '/multi',
                'headers' => ['X-Mode' => 'multi'],
            ],
        ],
        [
            'capture_push' => true,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http2_stop_multi_push_test_server($server);
}

$rootResponse = $responses[0];
$root = json_decode($rootResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$pushResponse = $rootResponse['pushes'][0];
$push = json_decode($pushResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(is_array($responses));
var_dump(count($responses));
var_dump($rootResponse['status']);
var_dump($rootResponse['stream_kind']);
var_dump($rootResponse['push_count']);
var_dump(count($rootResponse['pushes']));
var_dump($root['mode']);
var_dump($push['pushedFrom']);
var_dump($pushResponse['promise_headers'][':path']);
var_dump($pushResponse['headers']['x-push-kind']);
var_dump($pushResponse['pushed_from_request_index']);
var_dump($root['connectionId'] === $push['connectionId']);
?>
--EXPECT--
bool(true)
int(1)
int(200)
string(7) "request"
int(1)
int(1)
string(5) "multi"
string(6) "/multi"
string(10) "/sheet.css"
string(5) "sheet"
int(0)
bool(true)
