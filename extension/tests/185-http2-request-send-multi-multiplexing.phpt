--TEST--
King HTTP/2 multi request leaf multiplexes overlapping streams and preserves the per-origin session pool
--SKIPIF--
<?php
if (PHP_OS === 'Darwin') {
    die("skip HTTP/2 runtime requires libcurl.so (Linux) - not available on macOS");
}
if (trim((string) shell_exec('command -v node')) === '') {
    die("skip node is required for the local HTTP/2 multiplex fixture");
}
?>
--FILE--
<?php
function king_http2_start_overlap_test_server(int $expectedRequests = 3): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-overlap-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const expectedRequests = Number(process.argv[3] || 3);
const server = http2.createServer();
const sessionIds = new Map();
const sessionState = new Map();
let nextConnectionId = 0;
let handled = 0;

function getState(session) {
  let state = sessionState.get(session);
  if (!state) {
    state = {
      activeStreams: 0,
      maxActiveStreams: 0,
    };
    sessionState.set(session, state);
  }

  return state;
}

server.on('session', (session) => {
  nextConnectionId += 1;
  sessionIds.set(session, nextConnectionId);
  getState(session);
  session.on('close', () => {
    sessionIds.delete(session);
    sessionState.delete(session);
  });
});

server.on('stream', (stream, headers) => {
  const state = getState(stream.session);
  const path = headers[':path'] || '';

  state.activeStreams += 1;
  state.maxActiveStreams = Math.max(state.maxActiveStreams, state.activeStreams);

  const finish = (status) => {
    handled += 1;
    const connectionId = sessionIds.get(stream.session) || -1;
    const payload = JSON.stringify({
      connectionId,
      path,
      sawSameSession: state.maxActiveStreams >= 2,
      sawOverlap: state.maxActiveStreams >= 2,
      maxActiveStreams: state.maxActiveStreams,
      requestCount: handled
    });

    stream.respond({
      ':status': status,
      'content-type': 'application/json',
      'x-connection-id': String(connectionId),
      'x-request-count': String(handled)
    });
    stream.end(payload);

    state.activeStreams -= 1;

    if (handled >= expectedRequests) {
      setTimeout(() => {
        server.close(() => process.exit(0));
      }, 50);
    }
  };

  if (path === '/after') {
    setTimeout(() => finish(200), 10);
    return;
  }

  if (state.activeStreams === 1) {
    setTimeout(() => finish(state.maxActiveStreams >= 2 ? 200 : 409), 350);
    return;
  }

  setTimeout(() => finish(200), 25);
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
        throw new RuntimeException('failed to launch local HTTP/2 overlap test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 overlap test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_overlap_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_overlap_test_server();
try {
    $responses = king_http2_request_send_multi(
        [
            ['url' => 'http://127.0.0.1:' . $server[3] . '/one'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/two'],
        ],
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );

    $afterResponse = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/after',
        'GET',
        null,
        null,
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http2_stop_overlap_test_server($server);
}

$first = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$second = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$after = json_decode($afterResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(is_array($responses));
var_dump(count($responses));
var_dump($responses[0]['status']);
var_dump($responses[1]['status']);
var_dump($first['connectionId'] === $second['connectionId']);
var_dump($first['sawSameSession']);
var_dump($second['sawSameSession']);
var_dump($first['sawOverlap']);
var_dump($second['sawOverlap']);
var_dump($first['maxActiveStreams']);
var_dump($second['maxActiveStreams']);
var_dump($afterResponse['status']);
var_dump($after['connectionId']);
var_dump($after['requestCount']);
var_dump($after['connectionId'] === $first['connectionId']);
?>
--EXPECT--
bool(true)
int(2)
int(200)
int(200)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(2)
int(200)
int(1)
int(3)
bool(true)
