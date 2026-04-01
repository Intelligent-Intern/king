<?php

$capturePath = $argv[1] ?? null;
$port = isset($argv[2]) ? (int) $argv[2] : 0;
$bindHost = $argv[3] ?? '127.0.0.1';
$workerCount = isset($argv[4]) ? max(1, (int) $argv[4]) : 2;

if (!is_string($capturePath) || $capturePath === '' || $port <= 0) {
    fwrite(STDERR, "usage: php orchestrator_distributed_peer_server.inc <capture-path> <port> <bind-host> [worker-count]\n");
    exit(1);
}

$endpoint = strpos($bindHost, ':') !== false && !preg_match('/^\[.*\]$/', $bindHost)
    ? 'tcp://[' . $bindHost . ']:' . $port
    : 'tcp://' . $bindHost . ':' . $port;

$server = @stream_socket_server($endpoint, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "failed to start distributed orchestrator peer server: {$errstr}\n");
    exit(1);
}

stream_set_blocking($server, true);

$capture = [
    'events' => [],
    'workers' => [],
];

function king_orchestrator_distributed_peer_persist_capture(array $capture, string $capturePath): void
{
    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

function king_orchestrator_distributed_peer_cleanup_workers(array &$workers): void
{
    foreach ($workers as &$worker) {
        if (!empty($worker['stopped'])) {
            continue;
        }

        if (isset($worker['pipes'][0]) && is_resource($worker['pipes'][0])) {
            @fwrite($worker['pipes'][0], "STOP\n");
            @fflush($worker['pipes'][0]);
        }

        foreach (($worker['pipes'] ?? []) as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (isset($worker['process']) && is_resource($worker['process'])) {
            proc_close($worker['process']);
        }

        $worker['stopped'] = true;
    }
    unset($worker);
}

$workerScript = __DIR__ . '/orchestrator_distributed_peer_worker.inc';
$workers = [];
for ($i = 0; $i < $workerCount; $i++) {
    $workerId = 'worker-' . ($i + 1);
    $process = proc_open(
        [PHP_BINARY, '-n', $workerScript, $workerId],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        king_orchestrator_distributed_peer_cleanup_workers($workers);
        fclose($server);
        fwrite(STDERR, "failed to start distributed orchestrator worker {$workerId}\n");
        exit(1);
    }

    stream_set_blocking($pipes[0], true);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);

    $workers[] = [
        'id' => $workerId,
        'process' => $process,
        'pipes' => $pipes,
        'stopped' => false,
    ];
    $capture['workers'][] = $workerId;
}

$encodeError = static fn(string $message): string => "ERR\t" . base64_encode($message) . "\n";
$encodeResult = static fn(mixed $value): string => "OK\t" . base64_encode(serialize($value)) . "\n";
$encodeWorkerPayload = static fn(array $value): string => base64_encode(serialize($value));
$decodeWorkerResult = static function (string $payload): array {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('invalid distributed worker response');
    }

    $value = unserialize($decoded, ['allowed_classes' => false]);
    if (!is_array($value)) {
        throw new RuntimeException('invalid distributed worker response shape');
    }

    return $value;
};
$decodeZval = static function (string $payload): mixed {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('invalid base64 payload');
    }

    return unserialize($decoded, ['allowed_classes' => false]);
};

echo "READY\n";
flush();
king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);

while (($client = @stream_socket_accept($server, -1)) !== false) {
    $peer = stream_socket_get_name($client, true);
    $line = fgets($client);

    if ($line === false) {
        fclose($client);
        continue;
    }

    $line = rtrim($line, "\r\n");
    if ($line === 'STOP') {
        fclose($client);
        break;
    }

    $parts = explode("\t", $line);
    if (count($parts) !== 7 || $parts[0] !== 'RUN') {
        fwrite($client, $encodeError('invalid remote orchestrator opcode'));
        fclose($client);
        continue;
    }

    [$opcode, $runIdB64, $initialB64, $pipelineB64, $optionsB64, $timeoutBudgetMs, $deadlineBudgetMs] = $parts;
    $event = [
        'remote_address' => $peer,
        'claims' => [],
    ];

    try {
        $event['run_id'] = base64_decode($runIdB64, true);
        $event['initial_data'] = $decodeZval($initialB64);
        $event['pipeline'] = $decodeZval($pipelineB64);
        $event['options'] = $decodeZval($optionsB64);
        $event['timeout_budget_ms'] = (int) $timeoutBudgetMs;
        $event['deadline_budget_ms'] = (int) $deadlineBudgetMs;
    } catch (Throwable $e) {
        $event['remote_error'] = 'decode_failure';
        $capture['events'][] = $event;
        king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('failed to decode distributed orchestrator payload'));
        fclose($client);
        continue;
    }

    $capture['events'][] = &$event;
    king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);

    if (!is_array($event['pipeline'])) {
        $event['remote_error'] = 'invalid_pipeline';
        king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('distributed peer expected an array pipeline definition'));
        fclose($client);
        unset($event);
        continue;
    }

    $currentResult = $event['initial_data'];
    $failed = false;

    foreach ($event['pipeline'] as $stepIndex => $step) {
        if (!is_array($step)) {
            $event['remote_error'] = 'invalid_step';
            fwrite($client, $encodeError('distributed peer expected array step definitions'));
            $failed = true;
            break;
        }

        $worker = $workers[$stepIndex % count($workers)];
        $command = "STEP\t" . $encodeWorkerPayload([
            'run_id' => $event['run_id'],
            'step_index' => $stepIndex,
            'step' => $step,
            'result' => $currentResult,
        ]) . "\n";

        if (@fwrite($worker['pipes'][0], $command) === false) {
            $event['remote_error'] = 'worker_write_failure';
            fwrite($client, $encodeError('distributed peer failed while dispatching a step to a worker'));
            $failed = true;
            break;
        }
        fflush($worker['pipes'][0]);

        $workerLine = fgets($worker['pipes'][1]);
        if ($workerLine === false) {
            $event['remote_error'] = 'worker_read_failure';
            fwrite($client, $encodeError('distributed peer failed while reading a worker step result'));
            $failed = true;
            break;
        }

        $workerLine = rtrim($workerLine, "\r\n");
        $workerParts = explode("\t", $workerLine, 2);
        if (count($workerParts) !== 2) {
            $event['remote_error'] = 'invalid_worker_response';
            fwrite($client, $encodeError('distributed peer received an invalid worker response'));
            $failed = true;
            break;
        }

        if ($workerParts[0] !== 'OK') {
            $event['remote_error'] = 'worker_step_failure';
            $event['worker_error'] = base64_decode($workerParts[1], true);
            fwrite($client, $encodeError((string) ($event['worker_error'] ?? 'distributed worker failure')));
            $failed = true;
            break;
        }

        try {
            $workerResult = $decodeWorkerResult($workerParts[1]);
        } catch (Throwable $e) {
            $event['remote_error'] = 'invalid_worker_payload';
            fwrite($client, $encodeError($e->getMessage()));
            $failed = true;
            break;
        }

        if (($workerResult['worker'] ?? null) !== $worker['id'] || ($workerResult['step_index'] ?? null) !== $stepIndex) {
            $event['remote_error'] = 'worker_result_mismatch';
            fwrite($client, $encodeError('distributed peer received a mismatched worker result'));
            $failed = true;
            break;
        }

        $currentResult = $workerResult['result'] ?? null;
        $event['claims'][] = [
            'step_index' => $stepIndex,
            'tool' => isset($step['tool']) && is_string($step['tool']) ? $step['tool'] : null,
            'worker' => $worker['id'],
        ];
        king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);
    }

    if (!$failed) {
        $event['result'] = $currentResult;
        fwrite($client, $encodeResult($currentResult));
    }

    king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);

    fclose($client);
    unset($event);
}

king_orchestrator_distributed_peer_persist_capture($capture, $capturePath);
king_orchestrator_distributed_peer_cleanup_workers($workers);
fclose($server);
