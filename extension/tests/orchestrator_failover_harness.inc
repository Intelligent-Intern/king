<?php

require_once __DIR__ . '/orchestrator_remote_peer_helper.inc';

/* Reusable orchestrator failover harness for controller, worker, and remote-peer recovery contracts. */

function king_orchestrator_failover_harness_cleanup_dir(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $entries = scandir($path);
    if (!is_array($entries)) {
        @rmdir($path);
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        king_orchestrator_failover_harness_cleanup_dir($path . '/' . $entry);
    }

    @rmdir($path);
}

function king_orchestrator_failover_harness_create(string $bindHost = '127.0.0.1'): array
{
    $root = sys_get_temp_dir() . '/king-orchestrator-failover-' . getmypid() . '-' . bin2hex(random_bytes(6));

    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('failed to create the orchestrator failover harness root directory');
    }

    $queuePath = $root . '/queue';
    if (!mkdir($queuePath, 0700, true) && !is_dir($queuePath)) {
        king_orchestrator_failover_harness_cleanup_dir($root);
        throw new RuntimeException('failed to create the orchestrator failover queue directory');
    }

    return [
        'root' => $root,
        'bind_host' => $bindHost,
        'extension_path' => dirname(__DIR__) . '/modules/king.so',
        'state_path' => $root . '/orchestrator.state.bin',
        'queue_path' => $queuePath,
        'scripts' => [],
        'remote_peer' => [
            'server' => null,
            'capture_history' => [],
            'port' => null,
            'host' => $bindHost,
        ],
    ];
}

function king_orchestrator_failover_harness_write_script(
    array &$harness,
    string $name,
    string $code
): string {
    $path = $harness['root'] . '/' . $name . '.php';
    file_put_contents($path, $code);
    $harness['scripts'][] = $path;

    return $path;
}

function king_orchestrator_failover_harness_build_argv(
    array $harness,
    string $backend,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $harness['extension_path'],
        '-d', 'king.security_allow_config_override=1',
        '-d', 'king.orchestrator_state_path=' . $harness['state_path'],
    ];

    if ($backend === 'local') {
        $argv[] = '-d';
        $argv[] = 'king.orchestrator_execution_backend=local';
    } elseif ($backend === 'file_worker') {
        $argv[] = '-d';
        $argv[] = 'king.orchestrator_execution_backend=file_worker';
        $argv[] = '-d';
        $argv[] = 'king.orchestrator_worker_queue_path=' . $harness['queue_path'];
    } elseif ($backend === 'remote_peer') {
        $remotePeer = $harness['remote_peer'] ?? null;
        if (
            !is_array($remotePeer)
            || !is_string($remotePeer['host'] ?? null)
            || !is_int($remotePeer['port'] ?? null)
            || $remotePeer['port'] <= 0
        ) {
            throw new RuntimeException('orchestrator failover harness remote peer is not running');
        }

        $argv[] = '-d';
        $argv[] = 'king.orchestrator_execution_backend=remote_peer';
        $argv[] = '-d';
        $argv[] = 'king.orchestrator_remote_host=' . $remotePeer['host'];
        $argv[] = '-d';
        $argv[] = 'king.orchestrator_remote_port=' . $remotePeer['port'];
    } else {
        throw new RuntimeException('unsupported orchestrator failover harness backend: ' . $backend);
    }

    foreach ($extraIni as $key => $value) {
        $argv[] = '-d';
        $argv[] = $key . '=' . $value;
    }

    $argv[] = $scriptPath;
    foreach ($scriptArgs as $scriptArg) {
        $argv[] = (string) $scriptArg;
    }

    return $argv;
}

function king_orchestrator_failover_harness_spawn(
    array $harness,
    string $backend,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = king_orchestrator_failover_harness_build_argv(
        $harness,
        $backend,
        $scriptPath,
        $scriptArgs,
        $extraIni
    );

    $process = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to spawn orchestrator failover harness child process');
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'argv' => $argv,
        'backend' => $backend,
    ];
}

function king_orchestrator_failover_harness_finalize_process(array $child): array
{
    foreach ([1, 2] as $pipeIndex) {
        if (isset($child['pipes'][$pipeIndex]) && is_resource($child['pipes'][$pipeIndex])) {
            stream_set_blocking($child['pipes'][$pipeIndex], false);
        }
    }

    $stdout = isset($child['pipes'][1]) ? stream_get_contents($child['pipes'][1]) : '';
    $stderr = isset($child['pipes'][2]) ? stream_get_contents($child['pipes'][2]) : '';

    foreach (($child['pipes'] ?? []) as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'status' => proc_close($child['process']),
    ];
}

function king_orchestrator_failover_harness_crash_process(
    array $child,
    int $signal = 9
): array {
    if (is_resource($child['process'])) {
        proc_terminate($child['process'], $signal);
    }

    $deadline = microtime(true) + 2.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($child['process']);
        if (!is_array($status) || !$status['running']) {
            break;
        }
        usleep(10000);
    }

    return king_orchestrator_failover_harness_finalize_process($child);
}

function king_orchestrator_failover_harness_exec(
    array $harness,
    string $backend,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = king_orchestrator_failover_harness_build_argv(
        $harness,
        $backend,
        $scriptPath,
        $scriptArgs,
        $extraIni
    );

    $process = proc_open($argv, [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('failed to run orchestrator failover harness child process');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'status' => proc_close($process),
    ];
}

function king_orchestrator_failover_harness_wait_for(
    callable $predicate,
    int $attempts = 500,
    int $sleepUs = 10000
): bool {
    for ($i = 0; $i < $attempts; $i++) {
        if ($predicate()) {
            return true;
        }
        usleep($sleepUs);
    }

    return false;
}

function king_orchestrator_failover_harness_remote_peer_start(
    array &$harness,
    ?string $serverScript = null,
    array $extraArgs = []
): array {
    $remotePeer = &$harness['remote_peer'];
    if (is_array($remotePeer['server'])) {
        throw new RuntimeException('orchestrator failover harness remote peer is already running');
    }

    $server = king_orchestrator_remote_peer_start(
        $remotePeer['port'],
        $remotePeer['host'],
        $serverScript,
        $extraArgs
    );

    $remotePeer['server'] = $server;
    $remotePeer['port'] = (int) $server['port'];

    return $server;
}

function king_orchestrator_failover_harness_remote_peer_crash(
    array &$harness,
    int $signal = 9
): array {
    $remotePeer = &$harness['remote_peer'];
    if (!is_array($remotePeer['server'])) {
        throw new RuntimeException('orchestrator failover harness remote peer is not running');
    }

    $capture = king_orchestrator_remote_peer_crash($remotePeer['server'], $signal);
    $remotePeer['capture_history'][] = [
        'termination' => 'crash',
        'capture' => $capture,
    ];
    $remotePeer['server'] = null;

    return $capture;
}

function king_orchestrator_failover_harness_remote_peer_stop(array &$harness): array
{
    $remotePeer = &$harness['remote_peer'];
    if (!is_array($remotePeer['server'])) {
        throw new RuntimeException('orchestrator failover harness remote peer is not running');
    }

    $capture = king_orchestrator_remote_peer_stop($remotePeer['server']);
    $remotePeer['capture_history'][] = [
        'termination' => 'stop',
        'capture' => $capture,
    ];
    $remotePeer['server'] = null;

    return $capture;
}

function king_orchestrator_failover_harness_remote_peer_history(array $harness): array
{
    return $harness['remote_peer']['capture_history'] ?? [];
}

function king_orchestrator_failover_harness_destroy(array &$harness): void
{
    if (is_array($harness['remote_peer']['server'] ?? null)) {
        king_orchestrator_failover_harness_remote_peer_stop($harness);
    }

    king_orchestrator_failover_harness_cleanup_dir((string) ($harness['root'] ?? ''));
}
