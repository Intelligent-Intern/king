<?php

/* Test helper for starting the on-wire HTTP/3 one-shot listener harness. */

function king_http3_server_wire_start_server(
    string $certFile,
    string $keyFile,
    ?int $fixedPort = null,
    string $mode = 'basic',
    array $modeArgs = []
): array
{
    $bindHost = 'localhost';
    if ($fixedPort !== null) {
        $port = $fixedPort;
    } else {
        $probe = stream_socket_server('udp://' . $bindHost . ':0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($probe === false) {
            throw new RuntimeException("failed to reserve on-wire HTTP/3 test port: $errstr");
        }

        $serverName = stream_socket_get_name($probe, false);
        fclose($probe);
        [, $port] = king_http3_parse_socket_name($serverName);
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-http3-server-wire-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $commandParts = [
        escapeshellarg(PHP_BINARY),
        '-n',
        '-d',
        escapeshellarg('extension=' . $extensionPath),
        '-d',
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/http3_server_wire_server.inc'),
        escapeshellarg($capture),
        (string) (int) $port,
        escapeshellarg($certFile),
        escapeshellarg($keyFile),
        escapeshellarg($bindHost),
        escapeshellarg($mode),
    ];

    if ($mode === 'cors-allowlist') {
        array_splice($commandParts, 6, 0, [
            '-d',
            escapeshellarg('king.security_cors_allowed_origins=https://app.king.test, https://admin.king.test'),
        ]);
    }

    foreach ($modeArgs as $modeArg) {
        $commandParts[] = escapeshellarg((string) $modeArg);
    }

    $command = implode(' ', $commandParts);

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch on-wire HTTP/3 listener');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('on-wire HTTP/3 listener failed: ' . trim($stderr));
    }

    usleep(100000);

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
    ];
}

function king_http3_server_wire_stop_server(array $server): array
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

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}
