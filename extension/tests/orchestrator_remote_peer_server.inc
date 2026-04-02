<?php

/* Remote-peer harness server for orchestrator restart, resume and failure contracts. */

$capturePath = $argv[1] ?? null;
$port = isset($argv[2]) ? (int) $argv[2] : 0;
$bindHost = $argv[3] ?? '127.0.0.1';

if (!is_string($capturePath) || $capturePath === '' || $port <= 0) {
    fwrite(STDERR, "usage: php orchestrator_remote_peer_server.inc <capture-path> <port> <bind-host>\n");
    exit(1);
}

$endpoint = strpos($bindHost, ':') !== false && !preg_match('/^\[.*\]$/', $bindHost)
    ? 'tcp://[' . $bindHost . ']:' . $port
    : 'tcp://' . $bindHost . ':' . $port;

$server = @stream_socket_server($endpoint, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "failed to start orchestrator remote peer server: {$errstr}\n");
    exit(1);
}

stream_set_blocking($server, true);

$capture = ['events' => []];

function king_orchestrator_remote_peer_persist_capture(array $capture, string $capturePath): void
{
    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

$encodeError = static fn(string $message): string => "ERR\t" . base64_encode($message) . "\n";
$encodeErrorMeta = static function (
    string $message,
    string $category,
    string $retryDisposition,
    int $stepIndex = -1,
    ?string $backend = null
): string {
    return "ERRMETA\t" . base64_encode(serialize([
        'message' => $message,
        'category' => $category,
        'retry_disposition' => $retryDisposition,
        'step_index' => $stepIndex,
        'backend' => $backend,
    ])) . "\n";
};
$encodeResult = static fn(mixed $value): string => "OK\t" . base64_encode(serialize($value)) . "\n";
$decodeZval = static function (string $payload): mixed {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('invalid base64 payload');
    }

    return unserialize($decoded, ['allowed_classes' => false]);
};

echo "READY\n";
flush();
king_orchestrator_remote_peer_persist_capture($capture, $capturePath);

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
        king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('failed to decode remote orchestrator payload'));
        fclose($client);
        continue;
    }

    $capture['events'][] = &$event;
    king_orchestrator_remote_peer_persist_capture($capture, $capturePath);

    if (!is_array($event['pipeline'])) {
        $event['remote_error'] = 'invalid_pipeline';
        king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('remote peer expected an array pipeline definition'));
        fclose($client);
        unset($event);
        continue;
    }

    $startedAt = hrtime(true);
    $effectiveBudgetMs = 0;
    if ($event['timeout_budget_ms'] > 0 && $event['deadline_budget_ms'] > 0) {
        $effectiveBudgetMs = min($event['timeout_budget_ms'], $event['deadline_budget_ms']);
    } elseif ($event['timeout_budget_ms'] > 0) {
        $effectiveBudgetMs = $event['timeout_budget_ms'];
    } elseif ($event['deadline_budget_ms'] > 0) {
        $effectiveBudgetMs = $event['deadline_budget_ms'];
    }

    $failed = false;
    foreach ($event['pipeline'] as $stepIndex => $step) {
        if (!is_array($step)) {
            continue;
        }

        if (!empty($step['remote_close'])) {
            $event['remote_error'] = 'remote_close';
            $event['failed_step_index'] = $stepIndex;
            king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
            fclose($client);
            $failed = true;
            break;
        }

        if (isset($step['remote_error']) && is_string($step['remote_error'])) {
            $remoteErrorCategory = (
                isset($step['remote_error_category']) && is_string($step['remote_error_category'])
            ) ? $step['remote_error_category'] : 'backend';
            $remoteErrorRetryDisposition = (
                isset($step['remote_error_retry_disposition'])
                && is_string($step['remote_error_retry_disposition'])
            ) ? $step['remote_error_retry_disposition'] : 'caller_managed_retry';
            $remoteErrorBackend = (
                isset($step['remote_error_backend']) && is_string($step['remote_error_backend'])
            ) ? $step['remote_error_backend'] : 'remote_peer';

            $event['remote_error'] = $step['remote_error'];
            $event['failed_step_index'] = $stepIndex;
            king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
            fwrite(
                $client,
                $encodeErrorMeta(
                    $step['remote_error'],
                    $remoteErrorCategory,
                    $remoteErrorRetryDisposition,
                    $stepIndex,
                    $remoteErrorBackend
                )
            );
            $failed = true;
            break;
        }

        $delayMs = isset($step['delay_ms']) ? (int) $step['delay_ms'] : 0;
        while ($delayMs > 0) {
            $sleepMs = min($delayMs, 10);
            usleep($sleepMs * 1000);
            $delayMs -= $sleepMs;

            if ($effectiveBudgetMs > 0) {
                $elapsedMs = (int) ((hrtime(true) - $startedAt) / 1000000);
                if ($elapsedMs >= $effectiveBudgetMs) {
                    $event['remote_error'] = 'king_pipeline_orchestrator_run() exceeded the active orchestrator timeout budget.';
                    $event['failed_step_index'] = $stepIndex;
                    king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
                    fwrite(
                        $client,
                        $encodeErrorMeta(
                            $event['remote_error'],
                            'timeout',
                            'caller_managed_retry',
                            $stepIndex,
                            'remote_peer'
                        )
                    );
                    $failed = true;
                    break 2;
                }
            }
        }
    }

    if (!$failed) {
        $result = $event['initial_data'];
        if (
            is_array($event['pipeline'])
            && isset($event['pipeline'][0])
            && is_array($event['pipeline'][0])
            && !empty($event['pipeline'][0]['remote_object_result'])
        ) {
            $result = (object) ['remote_object' => true];
        }

        fwrite($client, $encodeResult($result));
    }

    king_orchestrator_remote_peer_persist_capture($capture, $capturePath);

    fclose($client);
    unset($event);
}

king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
fclose($server);
