--TEST--
King OO Http3Client wrapper uses the active HTTP/3 runtime and returns Response objects
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_QUICHE_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_QUICHE_SERVER must point at a prebuilt quiche-server binary";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_http3_create_oo_fixture(): array
{
    $dir = sys_get_temp_dir() . '/king-http3-oo-fixture-' . bin2hex(random_bytes(5));
    $root = $dir . '/root';
    if (!mkdir($dir) && !is_dir($dir)) {
        throw new RuntimeException('failed to create HTTP/3 OO fixture directory');
    }
    if (!mkdir($root) && !is_dir($root)) {
        throw new RuntimeException('failed to create HTTP/3 OO fixture root');
    }

    file_put_contents($root . '/first.txt', "first-http3\n");
    file_put_contents($root . '/second.txt', "second-http3\n");

    $cert = $dir . '/cert.pem';
    $key = $dir . '/key.pem';
    $command = sprintf(
        'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -sha256 -days 1 -subj %s -addext %s 2>/dev/null',
        escapeshellarg($key),
        escapeshellarg($cert),
        escapeshellarg('/CN=localhost'),
        escapeshellarg('subjectAltName = IP:127.0.0.1,DNS:localhost')
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('failed to create HTTP/3 OO fixture certificate');
    }

    return [
        'dir' => $dir,
        'root' => $root,
        'cert' => $cert,
        'key' => $key,
    ];
}

function king_http3_destroy_oo_fixture(array $fixture): void
{
    @unlink($fixture['root'] . '/first.txt');
    @unlink($fixture['root'] . '/second.txt');
    @rmdir($fixture['root']);
    @unlink($fixture['cert']);
    @unlink($fixture['key']);
    @rmdir($fixture['dir']);
}

function king_http3_start_oo_test_server(string $certFile, string $keyFile, string $rootDir): array
{
    $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve UDP OO test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [$host, $port] = explode(':', $serverName, 2);

    $binary = getenv('KING_QUICHE_SERVER');
    $command = 'RUST_LOG=info '
        . escapeshellarg($binary)
        . ' --listen ' . escapeshellarg($host . ':' . $port)
        . ' --cert ' . escapeshellarg($certFile)
        . ' --key ' . escapeshellarg($keyFile)
        . ' --root ' . escapeshellarg($rootDir)
        . ' --http-version ' . escapeshellarg('HTTP/3')
        . ' --no-retry --disable-gso --disable-pacing';

    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch local HTTP/3 OO test server');
    }

    fclose($pipes[0]);
    unset($pipes[0]);

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
    $startupGraceAt = microtime(true) + 1.0;
    $deadline = microtime(true) + 10.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        $selected = @stream_select($read, $write, $except, 0, 200000);
        if ($selected === false) {
            break;
        }

        if ($selected > 0) {
            foreach ($read as $pipe) {
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk) && $chunk !== '') {
                    $output .= $chunk;
                }
            }
        }

        if (str_contains($output, 'listening on')) {
            return [$process, $pipes, (int) $port];
        }

        if (!$status['running']) {
            break;
        }

        if (microtime(true) >= $startupGraceAt) {
            return [$process, $pipes, (int) $port];
        }
    }

    foreach ($pipes as $pipe) {
        $chunk = stream_get_contents($pipe);
        if (is_string($chunk) && $chunk !== '') {
            $output .= $chunk;
        }
        fclose($pipe);
    }
    proc_terminate($process);
    proc_close($process);
    throw new RuntimeException('local HTTP/3 OO test server failed: ' . trim($output));
}

function king_http3_oo_request_with_retry(callable $callback)
{
    $attempt = 0;
    $lastError = null;

    while ($attempt < 20) {
        try {
            return $callback();
        } catch (Throwable $e) {
            $lastError = $e;
            usleep(100000);
            $attempt++;
        }
    }

    throw $lastError ?? new RuntimeException('HTTP/3 OO request retry exhausted without an exception.');
}

function king_http3_stop_oo_test_server(array $server): void
{
    [$process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
}

$fixture = king_http3_create_oo_fixture();
$server = king_http3_start_oo_test_server($fixture['cert'], $fixture['key'], $fixture['root']);

try {
    $config = new King\Config([
        'tls_default_ca_file' => $fixture['cert'],
    ]);

    $client = new King\Client\Http3Client($config);
    $first = king_http3_oo_request_with_retry(
        static fn () => $client->request('GET', 'https://localhost:' . $server[2] . '/first.txt')
    );
    $second = king_http3_oo_request_with_retry(
        static fn () => $client->request('GET', 'https://localhost:' . $server[2] . '/second.txt')
    );
} finally {
    king_http3_stop_oo_test_server($server);
    king_http3_destroy_oo_fixture($fixture);
}

var_dump($first instanceof King\Response);
var_dump($first->getStatusCode());
var_dump($first->getHeaders()['content-length']);
var_dump($first->getBody());

var_dump($second instanceof King\Response);
var_dump($second->getStatusCode());
var_dump($second->getHeaders()['content-length']);
var_dump($second->getBody());
?>
--EXPECT--
bool(true)
int(200)
string(2) "12"
string(12) "first-http3
"
bool(true)
int(200)
string(2) "13"
string(13) "second-http3
"
