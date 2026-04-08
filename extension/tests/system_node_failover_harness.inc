<?php

/* Reusable coordinated system node-failover harness for persisted state-root takeover scenarios. */

function king_system_node_failover_harness_cleanup_dir(string $path): void
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

        king_system_node_failover_harness_cleanup_dir($path . '/' . $entry);
    }

    @rmdir($path);
}

function king_system_node_failover_harness_create(): array
{
    $root = sys_get_temp_dir() . '/king-system-node-failover-' . getmypid() . '-' . bin2hex(random_bytes(6));

    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('failed to create the system node-failover harness root directory');
    }

    return [
        'root' => $root,
        'extension_path' => dirname(__DIR__) . '/modules/king.so',
        'scripts' => [],
    ];
}

function king_system_node_failover_harness_write_script(
    array &$harness,
    string $name,
    string $code
): string {
    $path = $harness['root'] . '/' . $name . '.php';
    file_put_contents($path, $code);
    $harness['scripts'][] = $path;

    return $path;
}

function king_system_node_failover_harness_build_argv(
    array $harness,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $harness['extension_path'],
        '-d', 'king.security_allow_config_override=1',
    ];

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

function king_system_node_failover_harness_spawn(
    array $harness,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = king_system_node_failover_harness_build_argv(
        $harness,
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
        throw new RuntimeException('failed to spawn system node-failover harness child process');
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'argv' => $argv,
    ];
}

function king_system_node_failover_harness_finalize_process(array $child): array
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

function king_system_node_failover_harness_crash_process(
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

    return king_system_node_failover_harness_finalize_process($child);
}

function king_system_node_failover_harness_exec(
    array $harness,
    string $scriptPath,
    array $scriptArgs = [],
    array $extraIni = []
): array {
    $argv = king_system_node_failover_harness_build_argv(
        $harness,
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
        throw new RuntimeException('failed to run system node-failover harness child process');
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

function king_system_node_failover_harness_wait_for_file(
    string $path,
    int $attempts = 500,
    int $sleepUs = 10000
): bool {
    for ($i = 0; $i < $attempts; $i++) {
        clearstatcache();
        if (is_file($path)) {
            return true;
        }
        usleep($sleepUs);
    }

    return false;
}

function king_system_node_failover_harness_destroy(array &$harness): void
{
    foreach (($harness['scripts'] ?? []) as $path) {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }

    if (isset($harness['root']) && is_string($harness['root']) && $harness['root'] !== '') {
        king_system_node_failover_harness_cleanup_dir($harness['root']);
    }
}
