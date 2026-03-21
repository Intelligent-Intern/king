--TEST--
King HTTP/3 runtime can perform real local HTTPS roundtrips directly and over the dispatcher
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
function king_http3_create_fixture(): array
{
    $dir = sys_get_temp_dir() . '/king-http3-fixture-' . bin2hex(random_bytes(5));
    $root = $dir . '/root';
    if (!mkdir($dir) && !is_dir($dir)) {
        throw new RuntimeException('failed to create HTTP/3 fixture directory');
    }
    if (!mkdir($root) && !is_dir($root)) {
        throw new RuntimeException('failed to create HTTP/3 fixture root');
    }

    file_put_contents($root . '/direct.txt', "direct-http3\n");
    file_put_contents($root . '/dispatch.txt', "dispatch-http3\n");

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
        throw new RuntimeException('failed to create HTTP/3 fixture certificate');
    }

    return [
        'dir' => $dir,
        'root' => $root,
        'cert' => $cert,
        'key' => $key,
    ];
}

function king_http3_destroy_fixture(array $fixture): void
{
    @unlink($fixture['root'] . '/direct.txt');
    @unlink($fixture['root'] . '/dispatch.txt');
    @rmdir($fixture['root']);
    @unlink($fixture['cert']);
    @unlink($fixture['key']);
    @rmdir($fixture['dir']);
}

function king_http3_start_test_server(string $certFile, string $keyFile, string $rootDir): array
{
    $probe = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve UDP test port: $errstr");
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
        throw new RuntimeException('failed to launch local HTTP/3 test server');
    }

    fclose($pipes[0]);
    unset($pipes[0]);

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $output = '';
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
    throw new RuntimeException('local HTTP/3 test server failed: ' . trim($output));
}

function king_http3_stop_test_server(array $server): void
{
    [$process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
}

$fixture = king_http3_create_fixture();
$server = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);

try {
    $cfg = king_new_config([
        'tls_default_ca_file' => $fixture['cert'],
    ]);

    $directResponse = king_http3_request_send(
        'https://localhost:' . $server[2] . '/direct.txt',
        'GET',
        null,
        null,
        [
            'connection_config' => $cfg,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 5000,
        ]
    );

    $dispatcherResponse = king_client_send_request(
        'https://localhost:' . $server[2] . '/dispatch.txt',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http3',
            'connection_config' => $cfg,
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 5000,
        ]
    );
} finally {
    king_http3_stop_test_server($server);
    king_http3_destroy_fixture($fixture);
}

var_dump($directResponse['status']);
var_dump($directResponse['protocol']);
var_dump($directResponse['transport_backend']);
var_dump($directResponse['response_complete']);
var_dump($directResponse['body']);
var_dump($directResponse['body_bytes']);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherResponse['transport_backend']);
var_dump($dispatcherResponse['response_complete']);
var_dump($dispatcherResponse['body']);
var_dump($dispatcherResponse['body_bytes']);
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
string(13) "direct-http3
"
int(13)
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
string(15) "dispatch-http3
"
int(15)
