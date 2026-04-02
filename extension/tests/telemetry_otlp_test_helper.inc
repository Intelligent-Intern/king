<?php

/* Test helper for starting the local OTLP collector harness for telemetry exports. */

function king_telemetry_test_pick_unused_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve OTLP test port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);
    return (int) $port;
}

function king_telemetry_test_start_collector(array $plan, ?int $port = null): array
{
    $port ??= king_telemetry_test_pick_unused_port();
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-otlp-capture-');
    $planPath = tempnam(sys_get_temp_dir(), 'king-telemetry-otlp-plan-');
    file_put_contents($planPath, json_encode(array_values($plan), JSON_PRETTY_PRINT));

    $command = [
        PHP_BINARY,
        '-n',
        __DIR__ . '/telemetry_otlp_test_server.inc',
        $capture,
        $planPath,
        (string) $port,
        '127.0.0.1',
    ];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        @unlink($planPath);
        throw new RuntimeException('failed to launch OTLP collector test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        @unlink($planPath);
        throw new RuntimeException('OTLP collector test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'plan' => $planPath,
        'port' => $port,
    ];
}

function king_telemetry_test_finalize_collector(array $collector): array
{
    if (isset($collector['pipes'][1]) && is_resource($collector['pipes'][1])) {
        stream_set_blocking($collector['pipes'][1], false);
    }
    if (isset($collector['pipes'][2]) && is_resource($collector['pipes'][2])) {
        stream_set_blocking($collector['pipes'][2], false);
    }

    $stdout = isset($collector['pipes'][1]) ? stream_get_contents($collector['pipes'][1]) : '';
    $stderr = isset($collector['pipes'][2]) ? stream_get_contents($collector['pipes'][2]) : '';

    foreach ($collector['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($collector['process']);

    $capture = [];
    if (is_file($collector['capture'])) {
        $capture = json_decode((string) file_get_contents($collector['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($collector['capture']);
    }

    @unlink($collector['plan']);

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}

function king_telemetry_test_stop_collector(array $collector): array
{
    $stop = @stream_socket_client(
        'tcp://127.0.0.1:' . $collector['port'],
        $errno,
        $errstr,
        0.5
    );
    if (is_resource($stop)) {
        fwrite($stop, "STOP\n");
        fflush($stop);
        fclose($stop);
    }

    return king_telemetry_test_finalize_collector($collector);
}
