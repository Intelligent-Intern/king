#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Runs Voltron with two peers on the same machine:
 * - Peer A owns first partition and forwards second partition to Peer B
 * - Peer B owns second partition
 * - Controller submits one remote_peer orchestrator run to Peer A
 */

$repoRoot = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
$prompt = $argv[1] ?? 'Explain AI';
$model = $argv[2] ?? 'qwen2.5-coder:3b';
$controllerExtraArgs = array_slice($argv, 3);

/**
 * @return array{port:int,capture:string}
 */
function reserve_port_and_capture(string $prefix): array
{
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("Failed to reserve local port: {$errstr}");
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    if (preg_match('/:(\d+)$/', $name, $matches) !== 1) {
        throw new RuntimeException("Failed to parse reserved port from '{$name}'.");
    }
    $capture = tempnam(sys_get_temp_dir(), $prefix);
    if ($capture === false) {
        throw new RuntimeException('Failed to allocate capture file.');
    }
    return ['port' => (int) $matches[1], 'capture' => $capture];
}

/**
 * @param array<int,string> $command
 * @return array{process:resource,pipes:array<int,resource>}
 */
function start_process(array $command, string $cwd, ?array $env = null): array
{
    $procEnv = null;
    if (is_array($env)) {
        $baseEnv = getenv();
        $procEnv = is_array($baseEnv) ? $baseEnv : [];
        foreach ($env as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            $procEnv[$k] = is_scalar($v) ? (string) $v : '';
        }
    }

    $proc = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cwd,
        $procEnv
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start process: ' . implode(' ', $command));
    }
    return ['process' => $proc, 'pipes' => $pipes];
}

/**
 * @param array{process:resource,pipes:array<int,resource>} $proc
 * @return array{exit:int,stdout:string,stderr:string}
 */
function close_process(array $proc): array
{
    $stdout = stream_get_contents($proc['pipes'][1]);
    $stderr = stream_get_contents($proc['pipes'][2]);
    foreach ($proc['pipes'] as $pipe) {
        fclose($pipe);
    }
    $exit = proc_close($proc['process']);
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * @return array<string,mixed>
 */
function read_capture_file(string $path): array
{
    $capture = [];
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $capture = $decoded;
        }
        @unlink($path);
    }
    return $capture;
}

/**
 * @return void
 */
function stop_peer(int $port): void
{
    $stop = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 1.0);
    if (is_resource($stop)) {
        fwrite($stop, "STOP\n");
        fflush($stop);
        fclose($stop);
    }
}

try {
    $peerA = reserve_port_and_capture('voltron-peer-a-capture-');
    $peerB = reserve_port_and_capture('voltron-peer-b-capture-');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$sharedObjectRoot = sys_get_temp_dir() . '/voltron-object-store-shared';
if (!is_dir($sharedObjectRoot)) {
    @mkdir($sharedObjectRoot, 0777, true);
}
$peerEnv = ['VOLTRON_OBJECT_STORE_ROOT' => $sharedObjectRoot];

try {
    $peerBProc = start_process([
        PHP_BINARY,
        '-d', 'king.security_allow_config_override=1',
        '-d', 'extension=extension/modules/king.so',
        'demo/userland/voltron/remote_peer_server.php',
        $peerB['capture'],
        (string) $peerB['port'],
        '127.0.0.1',
        'demo/userland/voltron/remote_peer_bootstrap.php',
        'peer-b',
    ], $repoRoot, $peerEnv);

    $peerBReady = fgets($peerBProc['pipes'][1]);
    if ($peerBReady !== "READY\n") {
        $peerBState = close_process($peerBProc);
        throw new RuntimeException('Peer B failed to start: ' . trim($peerBState['stderr']));
    }

    $peerAProc = start_process([
        PHP_BINARY,
        '-d', 'king.security_allow_config_override=1',
        '-d', 'extension=extension/modules/king.so',
        'demo/userland/voltron/remote_peer_server.php',
        $peerA['capture'],
        (string) $peerA['port'],
        '127.0.0.1',
        'demo/userland/voltron/remote_peer_bootstrap.php',
        'peer-a',
        '127.0.0.1',
        (string) $peerB['port'],
    ], $repoRoot, $peerEnv);

    $peerAReady = fgets($peerAProc['pipes'][1]);
    if ($peerAReady !== "READY\n") {
        stop_peer($peerB['port']);
        $peerBState = close_process($peerBProc);
        $peerAState = close_process($peerAProc);
        throw new RuntimeException(
            'Peer A failed to start: ' . trim($peerAState['stderr']) . '; Peer B stderr: ' . trim($peerBState['stderr'])
        );
    }

    $controllerCommand = [
        PHP_BINARY,
        '-d', 'extension=extension/modules/king.so',
        '-d', 'king.orchestrator_execution_backend=remote_peer',
        '-d', 'king.orchestrator_remote_host=127.0.0.1',
        '-d', 'king.orchestrator_remote_port=' . $peerA['port'],
        'demo/userland/voltron/voltron.php',
        $prompt,
        $model,
        '--dag',
        '--backend=remote_peer',
        '--peers=peer-a,peer-b',
    ];
    if ($controllerExtraArgs !== []) {
        $controllerCommand = array_merge($controllerCommand, $controllerExtraArgs);
    }
    $controllerProc = start_process($controllerCommand, $repoRoot);

    $controllerState = close_process($controllerProc);

    stop_peer($peerA['port']);
    stop_peer($peerB['port']);

    $peerAState = close_process($peerAProc);
    $peerBState = close_process($peerBProc);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$peerACapture = read_capture_file($peerA['capture']);
$peerBCapture = read_capture_file($peerB['capture']);

echo "=== Two-Peer Voltron Run (Same Machine) ===\n";
echo "Controller backend: remote_peer\n";
echo "Peer A endpoint: 127.0.0.1:{$peerA['port']}\n";
echo "Peer B endpoint: 127.0.0.1:{$peerB['port']}\n";
echo "Controller exit: {$controllerState['exit']}\n";
echo "Peer A exit: {$peerAState['exit']}\n";
echo "Peer B exit: {$peerBState['exit']}\n\n";

echo "--- Controller Output ---\n";
echo $controllerState['stdout'];
if (trim($controllerState['stderr']) !== '') {
    echo "\n--- Controller STDERR ---\n";
    echo $controllerState['stderr'] . "\n";
}

echo "\n--- Peer Captures ---\n";
$peerAEvents = is_array($peerACapture['events'] ?? null) ? count($peerACapture['events']) : 0;
$peerBEvents = is_array($peerBCapture['events'] ?? null) ? count($peerBCapture['events']) : 0;
echo "Peer A events: {$peerAEvents}\n";
echo "Peer B events: {$peerBEvents}\n";

if ($peerAEvents > 0) {
    $first = $peerACapture['events'][0];
    $steps = is_array($first['pipeline'] ?? null) ? count($first['pipeline']) : 0;
    $trace = is_array($first['trace'] ?? null) ? count($first['trace']) : 0;
    echo "Peer A first run steps: {$steps}\n";
    echo "Peer A first run trace entries: {$trace}\n";
}
if ($peerBEvents > 0) {
    $steps = 0;
    foreach ($peerBCapture['events'] as $event) {
        $steps += is_array($event['pipeline'] ?? null) ? count($event['pipeline']) : 0;
    }
    echo "Peer B total forwarded steps: {$steps}\n";
}

if (trim($peerAState['stderr']) !== '') {
    echo "Peer A stderr: " . trim($peerAState['stderr']) . "\n";
}
if (trim($peerBState['stderr']) !== '') {
    echo "Peer B stderr: " . trim($peerBState['stderr']) . "\n";
}

exit($controllerState['exit'] === 0 ? 0 : $controllerState['exit']);
