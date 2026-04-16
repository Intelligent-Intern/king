--TEST--
King OO Http2Client wrapper uses the active HTTPS HTTP/2 runtime and preserves ALPN-backed reuse
--SKIPIF--
<?php
if (PHP_OS === 'Darwin') {
    die("skip HTTP/2 runtime requires libcurl.so (Linux) - not available on macOS");
}
if (trim((string) shell_exec('command -v node')) === '') {
    die("skip node is required for the local HTTP/2 fixture");
}
if (trim((string) shell_exec('command -v openssl')) === '') {
    die("skip openssl is required for the local HTTPS HTTP/2 fixture");
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_http2_create_tls_fixture(): array
{
    $dir = sys_get_temp_dir() . '/king-http2-oo-tls-' . bin2hex(random_bytes(5));
    if (!mkdir($dir) && !is_dir($dir)) {
        throw new RuntimeException('failed to create HTTPS HTTP/2 OO fixture directory');
    }

    $cert = $dir . '/cert.pem';
    $key = $dir . '/key.pem';
    $command = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -sha256 -days 1 -subj %s -addext %s 2>/dev/null',
        escapeshellarg($key),
        escapeshellarg($cert),
        escapeshellarg('/CN=127.0.0.1'),
        escapeshellarg('subjectAltName = IP:127.0.0.1,DNS:localhost')
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        @unlink($cert);
        @unlink($key);
        @rmdir($dir);
        throw new RuntimeException('failed to create HTTPS HTTP/2 OO fixture certificate');
    }

    return [
        'dir' => $dir,
        'cert' => $cert,
        'key' => $key,
    ];
}

function king_http2_cleanup_tls_fixture(array $fixture): void
{
    @unlink($fixture['cert']);
    @unlink($fixture['key']);
    @rmdir($fixture['dir']);
}

function king_http2_start_secure_test_server(string $certFile, string $keyFile, int $expectedRequests = 2): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-oo-https-server-');
    file_put_contents($script, <<<'JS'
const fs = require('node:fs');
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const certFile = process.argv[3];
const keyFile = process.argv[4];
const expectedRequests = Number(process.argv[5] || 2);
const server = http2.createSecureServer({
  cert: fs.readFileSync(certFile),
  key: fs.readFileSync(keyFile),
  allowHTTP1: false,
});
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
      alpn: stream.session.socket.alpnProtocol || '',
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
    $command = escapeshellarg($node)
        . ' ' . escapeshellarg($script)
        . ' ' . (int) $port
        . ' ' . escapeshellarg($certFile)
        . ' ' . escapeshellarg($keyFile)
        . ' ' . (int) $expectedRequests;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTPS HTTP/2 OO test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTPS HTTP/2 OO test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_secure_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$fixture = king_http2_create_tls_fixture();
$server = king_http2_start_secure_test_server($fixture['cert'], $fixture['key']);

try {
    $cfg = new King\Config([
        'tls_default_ca_file' => $fixture['cert'],
        'tls_default_cert_file' => $fixture['cert'],
        'tls_default_key_file' => $fixture['key'],
    ]);

    $client = new King\Client\Http2Client($cfg);
    $first = $client->request(
        'POST',
        'https://127.0.0.1:' . $server[3] . '/first',
        ['X-Mode' => 'first'],
        'payload'
    );
    $second = $client->request(
        'GET',
        'https://127.0.0.1:' . $server[3] . '/second',
        ['X-Mode' => 'second']
    );
} finally {
    king_http2_stop_secure_test_server($server);
    king_http2_cleanup_tls_fixture($fixture);
}

$firstEcho = json_decode($first->getBody(), true, flags: JSON_THROW_ON_ERROR);
$secondEcho = json_decode($second->getBody(), true, flags: JSON_THROW_ON_ERROR);

var_dump($first instanceof King\Response);
var_dump($first->getStatusCode());
var_dump($first->getHeaders()['x-connection-id']);
var_dump($firstEcho['alpn']);
var_dump($firstEcho['connectionId']);
var_dump($firstEcho['method']);
var_dump($firstEcho['path']);
var_dump($firstEcho['mode']);
var_dump($firstEcho['body']);

var_dump($second instanceof King\Response);
var_dump($second->getStatusCode());
var_dump($second->getHeaders()['x-request-count']);
var_dump($secondEcho['alpn']);
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
string(2) "h2"
int(1)
string(4) "POST"
string(6) "/first"
string(5) "first"
string(7) "payload"
bool(true)
int(200)
string(1) "2"
string(2) "h2"
int(1)
string(3) "GET"
string(7) "/second"
string(6) "second"
int(2)
bool(true)
