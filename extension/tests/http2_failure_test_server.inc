<?php

$port = (int) ($argv[1] ?? 0);

$script = <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[1] || process.argv[2]);
const server = http2.createServer();
const sessionIds = new Map();
let nextConnectionId = 0;
let handled = 0;

server.on('session', (session) => {
  nextConnectionId += 1;
  sessionIds.set(session, nextConnectionId);
  session.on('close', () => {
    sessionIds.delete(session);
  });
});

server.on('stream', (stream, headers) => {
  const path = headers[':path'] || '/';

  if (path === '/reset-stream') {
    stream.close(http2.constants.NGHTTP2_CANCEL);
    return;
  }

  if (path === '/connection-abort') {
    stream.session.destroy();
    return;
  }

  handled += 1;
  const connectionId = sessionIds.get(stream.session) || -1;
  const payload = JSON.stringify({
    connectionId,
    path,
    requestCount: handled
  });

  stream.respond({
    ':status': 200,
    'content-type': 'application/json',
    'x-connection-id': String(connectionId),
    'x-request-count': String(handled)
  });
  stream.end(payload);
});

server.on('error', (err) => {
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(2);
});

server.listen(port, '127.0.0.1', () => {
  console.log('READY');
});
JS;

$node = trim((string) shell_exec('command -v node'));
$command = escapeshellarg($node) . ' -e ' . escapeshellarg($script) . ' -- ' . (int) $port;
$descriptors = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptors, $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "failed to launch local HTTP/2 failure test server\n");
    exit(2);
}

$ready = fgets($pipes[1]);
if ($ready !== "READY\n") {
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    fwrite(STDERR, 'local HTTP/2 failure test server failed: ' . trim($stderr) . "\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

while (($line = fgets(STDIN)) !== false) {
    if (trim($line) === 'STOP') {
        break;
    }
}

foreach ($pipes as $pipe) {
    fclose($pipe);
}
@proc_terminate($process);
proc_close($process);
