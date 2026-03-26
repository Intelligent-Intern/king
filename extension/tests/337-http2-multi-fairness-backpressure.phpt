--TEST--
King HTTP/2 multi request runtime keeps fast streams progressing while a slow sibling stream is still active
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 fairness fixture";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_http2_start_fairness_test_server(int $expectedRequests = 4): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve fairness test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-fairness-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const expectedRequests = Number(process.argv[3] || 4);
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
  const path = headers[':path'] || '/';
  let delayMs = 25;

  state.activeStreams += 1;
  state.maxActiveStreams = Math.max(state.maxActiveStreams, state.activeStreams);
  const activeAtStart = state.activeStreams;

  if (path === '/slow') {
    delayMs = 350;
  } else if (path === '/fast-b') {
    delayMs = 50;
  } else if (path === '/after') {
    delayMs = 10;
  }

  setTimeout(() => {
    handled += 1;

    const connectionId = sessionIds.get(stream.session) || -1;
    const payload = JSON.stringify({
      connectionId,
      path,
      activeAtStart,
      maxActiveStreams: state.maxActiveStreams,
      finishOrder: handled,
    });

    stream.respond({
      ':status': 200,
      'content-type': 'application/json',
      'x-connection-id': String(connectionId),
      'x-finish-order': String(handled),
    });
    stream.end(payload);

    state.activeStreams -= 1;

    if (handled >= expectedRequests) {
      setTimeout(() => {
        server.close(() => process.exit(0));
      }, 50);
    }
  }, delayMs);
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
        throw new RuntimeException('failed to launch local HTTP/2 fairness server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 fairness server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_fairness_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_fairness_test_server();
try {
    $config = king_new_config([
        'http2.max_concurrent_streams' => 3,
    ]);

    $responses = king_http2_request_send_multi(
        [
            ['url' => 'http://127.0.0.1:' . $server[3] . '/slow'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/fast-a'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/fast-b'],
        ],
        [
            'connection_config' => $config,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 3000,
        ]
    );

    $afterResponse = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/after',
        'GET',
        null,
        null,
        [
            'connection_config' => $config,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http2_stop_fairness_test_server($server);
}

$slow = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$fastA = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$fastB = json_decode($responses[2]['body'], true, flags: JSON_THROW_ON_ERROR);
$after = json_decode($afterResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(count($responses));
var_dump($responses[0]['status']);
var_dump($responses[1]['status']);
var_dump($responses[2]['status']);
var_dump($slow['connectionId'] === $fastA['connectionId']);
var_dump($fastA['connectionId'] === $fastB['connectionId']);
var_dump($slow['finishOrder'] === 3);
var_dump($fastA['finishOrder'] === 1);
var_dump($fastB['finishOrder'] === 2);
var_dump($fastA['activeAtStart'] >= 2);
var_dump($fastB['activeAtStart'] >= 2);
var_dump($slow['maxActiveStreams'] >= 3);
var_dump($afterResponse['status']);
var_dump($after['connectionId'] === $slow['connectionId']);
var_dump($after['finishOrder'] === 4);
?>
--EXPECT--
int(3)
int(200)
int(200)
int(200)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(200)
bool(true)
bool(true)
