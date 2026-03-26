<?php

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

$encodeError = static fn(string $message): string => "ERR\t" . base64_encode($message) . "\n";
$encodeResult = static fn(mixed $value): string => "OK\t" . base64_encode(serialize($value)) . "\n";
$decodeZval = static function (string $payload): mixed {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('invalid base64 payload');
    }

    return unserialize($decoded);
};

echo "READY\n";
flush();

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
        fwrite($client, $encodeError('failed to decode remote orchestrator payload'));
        fclose($client);
        continue;
    }

    $capture['events'][] = &$event;

    if (!is_array($event['pipeline'])) {
        $event['remote_error'] = 'invalid_pipeline';
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
    foreach ($event['pipeline'] as $step) {
        if (!is_array($step)) {
            continue;
        }

        if (isset($step['remote_error']) && is_string($step['remote_error'])) {
            $event['remote_error'] = $step['remote_error'];
            fwrite($client, $encodeError($step['remote_error']));
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
                    fwrite($client, $encodeError($event['remote_error']));
                    $failed = true;
                    break 2;
                }
            }
        }
    }

    if (!$failed) {
        fwrite($client, $encodeResult($event['initial_data']));
    }

    fclose($client);
    unset($event);
}

file_put_contents($capturePath, json_encode($capture));
fclose($server);
