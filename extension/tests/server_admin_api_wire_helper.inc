<?php

/* Test helper for the bounded on-wire admin API listener and mTLS reload contract. */

function king_server_admin_api_create_tls_fixture(): array
{
    $dir = sys_get_temp_dir() . '/king-admin-api-wire-' . bin2hex(random_bytes(5));
    if (!mkdir($dir) && !is_dir($dir)) {
        throw new RuntimeException('failed to create admin API TLS fixture directory');
    }

    $fixture = [
        'dir' => $dir,
        'ca_key' => $dir . '/ca.key',
        'ca_cert' => $dir . '/ca.pem',
        'server_key' => $dir . '/server.key',
        'server_csr' => $dir . '/server.csr',
        'server_cert' => $dir . '/server.pem',
        'reload_key' => $dir . '/reload.key',
        'reload_csr' => $dir . '/reload.csr',
        'reload_cert' => $dir . '/reload.pem',
        'client_key' => $dir . '/client.key',
        'client_csr' => $dir . '/client.csr',
        'client_cert' => $dir . '/client.pem',
        'rogue_client_key' => $dir . '/rogue-client.key',
        'rogue_client_csr' => $dir . '/rogue-client.csr',
        'rogue_client_cert' => $dir . '/rogue-client.pem',
        'server_ext' => $dir . '/server.ext',
        'client_ext' => $dir . '/client.ext',
    ];

    file_put_contents(
        $fixture['server_ext'],
        "subjectAltName=IP:127.0.0.1,DNS:localhost\nextendedKeyUsage=serverAuth\n"
    );
    file_put_contents(
        $fixture['client_ext'],
        "extendedKeyUsage=clientAuth\n"
    );

    $commands = [
        sprintf('openssl genrsa -out %s 2048', escapeshellarg($fixture['ca_key'])),
        sprintf(
            'openssl req -x509 -new -key %s -out %s -sha256 -days 1 -subj %s 2>/dev/null',
            escapeshellarg($fixture['ca_key']),
            escapeshellarg($fixture['ca_cert']),
            escapeshellarg('/CN=KingAdminTestCA')
        ),
        sprintf('openssl genrsa -out %s 2048', escapeshellarg($fixture['server_key'])),
        sprintf(
            'openssl req -new -key %s -out %s -subj %s 2>/dev/null',
            escapeshellarg($fixture['server_key']),
            escapeshellarg($fixture['server_csr']),
            escapeshellarg('/CN=127.0.0.1')
        ),
        sprintf(
            'openssl x509 -req -in %s -CA %s -CAkey %s -CAcreateserial -out %s -sha256 -days 1 -extfile %s 2>/dev/null',
            escapeshellarg($fixture['server_csr']),
            escapeshellarg($fixture['ca_cert']),
            escapeshellarg($fixture['ca_key']),
            escapeshellarg($fixture['server_cert']),
            escapeshellarg($fixture['server_ext'])
        ),
        sprintf('openssl genrsa -out %s 2048', escapeshellarg($fixture['reload_key'])),
        sprintf(
            'openssl req -new -key %s -out %s -subj %s 2>/dev/null',
            escapeshellarg($fixture['reload_key']),
            escapeshellarg($fixture['reload_csr']),
            escapeshellarg('/CN=127.0.0.1-reloaded')
        ),
        sprintf(
            'openssl x509 -req -in %s -CA %s -CAkey %s -CAcreateserial -out %s -sha256 -days 1 -extfile %s 2>/dev/null',
            escapeshellarg($fixture['reload_csr']),
            escapeshellarg($fixture['ca_cert']),
            escapeshellarg($fixture['ca_key']),
            escapeshellarg($fixture['reload_cert']),
            escapeshellarg($fixture['server_ext'])
        ),
        sprintf('openssl genrsa -out %s 2048', escapeshellarg($fixture['client_key'])),
        sprintf(
            'openssl req -new -key %s -out %s -subj %s 2>/dev/null',
            escapeshellarg($fixture['client_key']),
            escapeshellarg($fixture['client_csr']),
            escapeshellarg('/CN=KingAdminClient')
        ),
        sprintf(
            'openssl x509 -req -in %s -CA %s -CAkey %s -CAcreateserial -out %s -sha256 -days 1 -extfile %s 2>/dev/null',
            escapeshellarg($fixture['client_csr']),
            escapeshellarg($fixture['ca_cert']),
            escapeshellarg($fixture['ca_key']),
            escapeshellarg($fixture['client_cert']),
            escapeshellarg($fixture['client_ext'])
        ),
        sprintf('openssl genrsa -out %s 2048', escapeshellarg($fixture['rogue_client_key'])),
        sprintf(
            'openssl req -new -key %s -out %s -subj %s 2>/dev/null',
            escapeshellarg($fixture['rogue_client_key']),
            escapeshellarg($fixture['rogue_client_csr']),
            escapeshellarg('/CN=KingAdminRogue')
        ),
        sprintf(
            'openssl x509 -req -in %s -signkey %s -out %s -sha256 -days 1 -extfile %s 2>/dev/null',
            escapeshellarg($fixture['rogue_client_csr']),
            escapeshellarg($fixture['rogue_client_key']),
            escapeshellarg($fixture['rogue_client_cert']),
            escapeshellarg($fixture['client_ext'])
        ),
    ];

    foreach ($commands as $command) {
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            king_server_admin_api_cleanup_tls_fixture($fixture);
            throw new RuntimeException('failed to create admin API TLS fixture');
        }
    }

    return $fixture;
}

function king_server_admin_api_cleanup_tls_fixture(array $fixture): void
{
    foreach ([
        'client_cert',
        'client_csr',
        'client_key',
        'rogue_client_cert',
        'rogue_client_csr',
        'rogue_client_key',
        'reload_cert',
        'reload_csr',
        'reload_key',
        'server_cert',
        'server_csr',
        'server_key',
        'server_ext',
        'client_ext',
        'ca_cert',
        'ca_key',
    ] as $key) {
        if (isset($fixture[$key])) {
            @unlink($fixture[$key]);
        }
    }

    @unlink(($fixture['dir'] ?? '') . '/ca.srl');
    @rmdir($fixture['dir'] ?? '');
}

function king_server_admin_api_reserve_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve admin API test port: $errstr");
    }

    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $name, 2);
    return (int) $port;
}

function king_server_admin_api_start_server(array $fixture, int $acceptTimeoutMs = 3000): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-admin-api-wire-capture-');
    $port = king_server_admin_api_reserve_port();
    $extensionPath = dirname(__DIR__) . '/modules/king.so';

    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %s %s %s %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/server_admin_api_wire_server.inc'),
        escapeshellarg($capture),
        $port,
        escapeshellarg($fixture['ca_cert']),
        escapeshellarg($fixture['server_cert']),
        escapeshellarg($fixture['server_key']),
        $acceptTimeoutMs
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch admin API wire server');
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_server_admin_api_stop_server(array $server): array
{
    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($server['process']);

    $capture = [];
    if (is_file($server['capture'])) {
        $capture = json_decode((string) file_get_contents($server['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($server['capture']);
    }

    if ($capture === []) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    } else {
        $capture['__stdout'] = $stdout;
        $capture['__stderr'] = $stderr;
        $capture['__exit_code'] = $exitCode;
    }

    return $capture;
}

function king_server_admin_api_connect_retry(
    int $port,
    array $fixture,
    bool $withClientCert = true,
    int $timeoutMs = 3000
) {
    return king_server_admin_api_connect_retry_with_profile(
        $port,
        $fixture,
        $withClientCert ? 'trusted' : 'none',
        $timeoutMs
    );
}

function king_server_admin_api_connect_retry_with_profile(
    int $port,
    array $fixture,
    string $clientProfile = 'trusted',
    int $timeoutMs = 3000
) {
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $errstr = 'connection failed';

    do {
        $sslOptions = [
            'cafile' => $fixture['ca_cert'],
            'verify_peer' => true,
            'verify_peer_name' => false,
            'allow_self_signed' => false,
        ];

        switch ($clientProfile) {
            case 'trusted':
                $sslOptions['local_cert'] = $fixture['client_cert'];
                $sslOptions['local_pk'] = $fixture['client_key'];
                break;
            case 'rogue_self_signed':
                $sslOptions['local_cert'] = $fixture['rogue_client_cert'];
                $sslOptions['local_pk'] = $fixture['rogue_client_key'];
                break;
            case 'none':
                break;
            default:
                throw new RuntimeException('unknown admin API client profile');
        }

        $client = @stream_socket_client(
            'tls://127.0.0.1:' . $port,
            $errno,
            $errstr,
            0.25,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => $sslOptions])
        );
        if (is_resource($client)) {
            stream_set_timeout($client, 2);
            return $client;
        }

        usleep(50000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("failed to connect to admin API wire listener: $errstr");
}

function king_server_admin_api_request(
    int $port,
    array $fixture,
    string $request,
    bool $withClientCert = true,
    int $timeoutMs = 3000
): string
{
    $client = king_server_admin_api_connect_retry($port, $fixture, $withClientCert, $timeoutMs);
    fwrite($client, $request);
    $response = stream_get_contents($client);
    fclose($client);
    return (string) $response;
}

function king_server_admin_api_parse_response(string $response): array
{
    [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $statusLine = array_shift($lines) ?? '';
    $statusParts = explode(' ', $statusLine, 3);
    $headers = [];

    foreach ($lines as $line) {
        if ($line === '' || strpos($line, ':') === false) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [
        'status_line' => $statusLine,
        'status' => isset($statusParts[1]) ? (int) $statusParts[1] : 0,
        'headers' => $headers,
        'body' => $body,
    ];
}
