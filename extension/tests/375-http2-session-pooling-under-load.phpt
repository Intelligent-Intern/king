--TEST--
King HTTP/2 session pools stay reusable and fair across repeated mixed-load bursts
--SKIPIF--
<?php
if (PHP_OS === 'Darwin') {
    die("skip HTTP/2 runtime requires libcurl.so (Linux) - not available on macOS");
}
if (trim((string) shell_exec('command -v node')) === '') {
    die("skip node is required for the local HTTP/2 pooling fixture");
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_http2_start_pooling_test_server(int $expectedRequests = 8): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve HTTP/2 pooling test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-pool-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const expectedRequests = Number(process.argv[3] || 8);
const server = http2.createServer();
const sessionIds = new Map();
const sessionState = new Map();
const burstState = new Map();
let nextConnectionId = 0;
let sessionCount = 0;
let handled = 0;

function getSessionState(session) {
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

function getBurstState(session, burstKey) {
  const connectionId = sessionIds.get(session) || -1;
  const key = `${connectionId}:${burstKey}`;
  let state = burstState.get(key);
  if (!state) {
    state = {
      activeStreams: 0,
      maxActiveStreams: 0,
      finishOrder: 0,
    };
    burstState.set(key, state);
  }

  return state;
}

server.on('session', (session) => {
  nextConnectionId += 1;
  sessionCount += 1;
  sessionIds.set(session, nextConnectionId);
  getSessionState(session);
  session.on('close', () => {
    sessionIds.delete(session);
    sessionState.delete(session);
  });
});

server.on('stream', (stream, headers) => {
  const path = headers[':path'] || '/';
  const segments = String(path).split('/').filter(Boolean);
  const burstKey = segments[0] || 'default';
  const leaf = segments[1] || '';
  const perSession = getSessionState(stream.session);
  const perBurst = getBurstState(stream.session, burstKey);
  let delayMs = 25;

  if (leaf === 'slow') {
    delayMs = 350;
  } else if (leaf === 'fast-b') {
    delayMs = 60;
  } else if (burstKey.startsWith('after-')) {
    delayMs = 10;
  }

  perSession.activeStreams += 1;
  perSession.maxActiveStreams = Math.max(perSession.maxActiveStreams, perSession.activeStreams);
  perBurst.activeStreams += 1;
  perBurst.maxActiveStreams = Math.max(perBurst.maxActiveStreams, perBurst.activeStreams);

  const sessionActiveAtStart = perSession.activeStreams;

  setTimeout(() => {
    handled += 1;
    perBurst.finishOrder += 1;

    const connectionId = sessionIds.get(stream.session) || -1;
    const payload = JSON.stringify({
      connectionId,
      sessionCount,
      burstKey,
      sessionActiveAtStart,
      maxSessionActiveStreams: perSession.maxActiveStreams,
      maxBurstActiveStreams: perBurst.maxActiveStreams,
      burstFinishOrder: perBurst.finishOrder,
      totalHandled: handled,
    });

    stream.respond({
      ':status': 200,
      'content-type': 'application/json',
      'x-connection-id': String(connectionId),
      'x-session-count': String(sessionCount),
      'x-burst-key': burstKey,
      'x-total-handled': String(handled),
    });
    stream.end(payload);

    perBurst.activeStreams -= 1;
    perSession.activeStreams -= 1;

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
        throw new RuntimeException('failed to launch local HTTP/2 pooling test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 pooling test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_pooling_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_pooling_test_server();
try {
    $config = king_new_config([
        'http2.max_concurrent_streams' => 3,
    ]);
    $options = [
        'connection_config' => $config,
        'connect_timeout_ms' => 1000,
        'timeout_ms' => 3000,
    ];

    $burstOne = king_http2_request_send_multi(
        [
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-one/slow'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-one/fast-a'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-one/fast-b'],
        ],
        $options
    );

    $afterOneResponse = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/after-one/check',
        'GET',
        null,
        null,
        $options
    );

    $burstTwo = king_http2_request_send_multi(
        [
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-two/slow'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-two/fast-a'],
            ['url' => 'http://127.0.0.1:' . $server[3] . '/burst-two/fast-b'],
        ],
        $options
    );

    $afterTwoResponse = king_http2_request_send(
        'http://127.0.0.1:' . $server[3] . '/after-two/check',
        'GET',
        null,
        null,
        $options
    );
} finally {
    king_http2_stop_pooling_test_server($server);
}

$oneSlow = json_decode($burstOne[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$oneFastA = json_decode($burstOne[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$oneFastB = json_decode($burstOne[2]['body'], true, flags: JSON_THROW_ON_ERROR);
$afterOne = json_decode($afterOneResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$twoSlow = json_decode($burstTwo[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$twoFastA = json_decode($burstTwo[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$twoFastB = json_decode($burstTwo[2]['body'], true, flags: JSON_THROW_ON_ERROR);
$afterTwo = json_decode($afterTwoResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(count($burstOne));
var_dump(count($burstTwo));
var_dump($burstOne[0]['status']);
var_dump($burstOne[1]['status']);
var_dump($burstOne[2]['status']);
var_dump($afterOneResponse['status']);
var_dump($burstTwo[0]['status']);
var_dump($burstTwo[1]['status']);
var_dump($burstTwo[2]['status']);
var_dump($afterTwoResponse['status']);
var_dump($oneSlow['connectionId'] === $oneFastA['connectionId']);
var_dump($oneFastA['connectionId'] === $oneFastB['connectionId']);
var_dump($afterOne['connectionId'] === $oneSlow['connectionId']);
var_dump($twoSlow['connectionId'] === $oneSlow['connectionId']);
var_dump($twoFastA['connectionId'] === $twoSlow['connectionId']);
var_dump($twoFastB['connectionId'] === $twoSlow['connectionId']);
var_dump($afterTwo['connectionId'] === $oneSlow['connectionId']);
var_dump($oneSlow['sessionCount'] === 1);
var_dump($afterOne['sessionCount'] === 1);
var_dump($twoSlow['sessionCount'] === 1);
var_dump($afterTwo['sessionCount'] === 1);
var_dump($oneSlow['burstFinishOrder'] === 3);
var_dump($oneFastA['burstFinishOrder'] === 1);
var_dump($oneFastB['burstFinishOrder'] === 2);
var_dump($twoSlow['burstFinishOrder'] === 3);
var_dump($twoFastA['burstFinishOrder'] === 1);
var_dump($twoFastB['burstFinishOrder'] === 2);
var_dump($oneFastA['sessionActiveAtStart'] >= 2);
var_dump($oneFastB['sessionActiveAtStart'] >= 2);
var_dump($oneSlow['maxBurstActiveStreams'] >= 3);
var_dump($afterOne['sessionActiveAtStart'] === 1);
var_dump($twoFastA['sessionActiveAtStart'] >= 2);
var_dump($twoFastB['sessionActiveAtStart'] >= 2);
var_dump($twoSlow['maxBurstActiveStreams'] >= 3);
var_dump($afterTwo['sessionActiveAtStart'] === 1);
var_dump($afterTwo['totalHandled'] === 8);
?>
--EXPECT--
int(3)
int(3)
int(200)
int(200)
int(200)
int(200)
int(200)
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
