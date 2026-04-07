<?php

/* Remote-peer harness server for orchestrator restart, resume and userland-handler contracts. */

$capturePath = $argv[1] ?? null;
$port = isset($argv[2]) ? (int) $argv[2] : 0;
$bindHost = $argv[3] ?? '127.0.0.1';
$handlerBootstrapPath = $argv[4] ?? null;

if (!is_string($capturePath) || $capturePath === '' || $port <= 0) {
    fwrite(
        STDERR,
        "usage: php orchestrator_remote_peer_server.inc <capture-path> <port> <bind-host> [handler-bootstrap]\n"
    );
    exit(1);
}

function king_orchestrator_remote_peer_persist_capture(array $capture, string $capturePath): void
{
    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

final class KingOrchestratorRemotePeerMissingHandlerException extends RuntimeException {}
final class KingOrchestratorRemotePeerBackendException extends RuntimeException {}
final class KingOrchestratorRemotePeerValidationException extends InvalidArgumentException {}

function king_orchestrator_remote_peer_load_handlers(?string $bootstrapPath): array
{
    if (!is_string($bootstrapPath) || $bootstrapPath === '') {
        return [];
    }

    if (!is_file($bootstrapPath)) {
        throw new RuntimeException('remote peer handler bootstrap file does not exist');
    }

    $handlers = require $bootstrapPath;
    if (!is_array($handlers)) {
        throw new RuntimeException('remote peer handler bootstrap must return an array');
    }

    foreach ($handlers as $toolName => $handler) {
        if (!is_string($toolName) || $toolName === '') {
            throw new RuntimeException('remote peer handler bootstrap returned an invalid tool name');
        }
        if (!is_callable($handler)) {
            throw new RuntimeException(
                sprintf("remote peer handler bootstrap returned a non-callable handler for tool '%s'", $toolName)
            );
        }
    }

    return $handlers;
}

function king_orchestrator_remote_peer_handler_boundary_requires_process_registration(mixed $handlerBoundary): bool
{
    return is_array($handlerBoundary) && !empty($handlerBoundary['requires_process_registration']);
}

function king_orchestrator_remote_peer_validate_required_step_refs(array $handlerBoundary): bool
{
    $requiredTools = $handlerBoundary['required_tools'] ?? null;
    $requiredStepRefs = $handlerBoundary['required_step_refs'] ?? null;
    if (!is_array($requiredTools) || !is_array($requiredStepRefs) || $requiredStepRefs === []) {
        return false;
    }

    $toolLookup = [];
    foreach ($requiredTools as $toolName) {
        if (!is_string($toolName) || $toolName === '') {
            return false;
        }
        $toolLookup[$toolName] = true;
    }

    $seenIndexes = [];
    foreach ($requiredStepRefs as $entry) {
        if (!is_array($entry)) {
            return false;
        }

        $index = $entry['index'] ?? null;
        $toolName = $entry['tool_name'] ?? null;
        if (is_string($index) && $index !== '' && ctype_digit($index)) {
            $index = (int) $index;
        }

        if (
            !is_int($index)
            || $index < 0
            || !is_string($toolName)
            || $toolName === ''
            || !isset($toolLookup[$toolName])
            || isset($seenIndexes[$index])
        ) {
            return false;
        }

        $seenIndexes[$index] = true;
    }

    return true;
}

function king_orchestrator_remote_peer_step_uses_registered_handler(
    mixed $handlerBoundary,
    array $step,
    int $stepIndex
): bool {
    $toolName = $step['tool'] ?? null;
    $requiredStepRefs = $handlerBoundary['required_step_refs'] ?? null;

    if (!is_string($toolName) || $toolName === '' || !is_array($requiredStepRefs)) {
        return false;
    }

    foreach ($requiredStepRefs as $entry) {
        if (
            is_array($entry)
            && ($entry['index'] ?? null) === $stepIndex
            && ($entry['tool_name'] ?? null) === $toolName
        ) {
            return true;
        }
    }

    return false;
}

function king_orchestrator_remote_peer_first_required_step_index(mixed $handlerBoundary, string $toolName): int
{
    $requiredStepRefs = $handlerBoundary['required_step_refs'] ?? null;

    if (!is_array($requiredStepRefs)) {
        return -1;
    }

    foreach ($requiredStepRefs as $entry) {
        if (
            is_array($entry)
            && ($entry['tool_name'] ?? null) === $toolName
            && is_int($entry['index'] ?? null)
        ) {
            return $entry['index'];
        }
    }

    return -1;
}

function king_orchestrator_remote_peer_invoke_registered_handler(
    array $registeredHandlers,
    array $toolConfigs,
    string $runId,
    array $step,
    int $stepIndex,
    mixed $currentPayload,
    int $timeoutBudgetMs,
    int $deadlineBudgetMs
): array {
    $toolName = $step['tool'] ?? null;
    if (!is_string($toolName) || $toolName === '') {
        throw new KingOrchestratorRemotePeerValidationException(
            'remote peer step requires a non-empty tool name before registered handler execution'
        );
    }

    if (!array_key_exists($toolName, $registeredHandlers)) {
        throw new KingOrchestratorRemotePeerMissingHandlerException(
            sprintf("remote peer has no registered handler for tool '%s'.", $toolName)
        );
    }

    $toolConfig = $toolConfigs[$toolName] ?? null;
    if (!is_array($toolConfig)) {
        throw new KingOrchestratorRemotePeerBackendException(
            sprintf("remote peer did not receive a durable tool config for registered handler '%s'.", $toolName)
        );
    }

    $handler = $registeredHandlers[$toolName];
    $result = $handler([
        'input' => $currentPayload,
        'run_id' => $runId,
        'cancel' => null,
        'timeout_budget_ms' => $timeoutBudgetMs,
        'deadline_budget_ms' => $deadlineBudgetMs,
        'tool' => [
            'name' => $toolName,
            'config' => $toolConfig,
        ],
        'run' => [
            'run_id' => $runId,
            'attempt_number' => 1,
            'execution_backend' => 'remote_peer',
            'topology_scope' => 'tcp_host_port_execution_peer',
        ],
        'step' => [
            'index' => $stepIndex,
            'tool_name' => $toolName,
            'definition' => $step,
        ],
    ]);

    if (!is_array($result)) {
        throw new RuntimeException(
            sprintf(
                "remote peer registered handler for tool '%s' must return an array result contract.",
                $toolName
            )
        );
    }

    if (!array_key_exists('output', $result) || !is_array($result['output'])) {
        throw new RuntimeException(
            sprintf(
                "remote peer registered handler for tool '%s' must return an array result contract with key 'output'.",
                $toolName
            )
        );
    }

    return $result['output'];
}

function king_orchestrator_remote_peer_classify_handler_failure(Throwable $error): array
{
    if ($error instanceof KingOrchestratorRemotePeerMissingHandlerException) {
        return [
            'category' => 'missing_handler',
            'retry_disposition' => 'caller_managed_retry',
        ];
    }

    if ($error instanceof KingOrchestratorRemotePeerValidationException) {
        return [
            'category' => 'validation',
            'retry_disposition' => 'non_retryable',
        ];
    }

    if ($error instanceof KingOrchestratorRemotePeerBackendException) {
        return [
            'category' => 'backend',
            'retry_disposition' => 'caller_managed_retry',
        ];
    }

    return [
        'category' => 'runtime',
        'retry_disposition' => 'caller_managed_retry',
    ];
}

try {
    $registeredHandlers = king_orchestrator_remote_peer_load_handlers(
        is_string($handlerBootstrapPath) && $handlerBootstrapPath !== '' ? $handlerBootstrapPath : null
    );
} catch (Throwable $e) {
    fwrite(STDERR, "failed to load remote peer handlers: {$e->getMessage()}\n");
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

$capture = [
    'events' => [],
    'registered_handlers' => array_values(array_keys($registeredHandlers)),
];

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
    if (($parts[0] ?? null) !== 'RUN' || (count($parts) !== 7 && count($parts) !== 9)) {
        fwrite($client, $encodeError('invalid remote orchestrator opcode'));
        fclose($client);
        continue;
    }

    if (count($parts) === 9) {
        [$opcode, $runIdB64, $initialB64, $pipelineB64, $optionsB64, $handlerBoundaryB64, $toolConfigsB64, $timeoutBudgetMs, $deadlineBudgetMs] = $parts;
    } else {
        [$opcode, $runIdB64, $initialB64, $pipelineB64, $optionsB64, $timeoutBudgetMs, $deadlineBudgetMs] = $parts;
        $handlerBoundaryB64 = null;
        $toolConfigsB64 = null;
    }

    $event = [
        'remote_address' => $peer,
        'registered_handlers' => array_values(array_keys($registeredHandlers)),
    ];

    try {
        $event['run_id'] = base64_decode($runIdB64, true);
        $event['initial_data'] = $decodeZval($initialB64);
        $event['pipeline'] = $decodeZval($pipelineB64);
        $event['options'] = $decodeZval($optionsB64);
        $event['handler_boundary'] = $handlerBoundaryB64 !== null ? $decodeZval($handlerBoundaryB64) : null;
        $event['tool_configs'] = $toolConfigsB64 !== null ? $decodeZval($toolConfigsB64) : null;
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

    if (king_orchestrator_remote_peer_handler_boundary_requires_process_registration($event['handler_boundary'])) {
        if (
            !is_array($event['handler_boundary'])
            || !king_orchestrator_remote_peer_validate_required_step_refs($event['handler_boundary'])
        ) {
            $event['remote_error'] = 'remote peer received an invalid handler boundary topology.';
            $event['failed_step_index'] = -1;
            king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
            fwrite(
                $client,
                $encodeErrorMeta(
                    $event['remote_error'],
                    'backend',
                    'caller_managed_retry',
                    -1,
                    'remote_peer'
                )
            );
            fclose($client);
            unset($event);
            continue;
        }

        if (!is_array($event['tool_configs'])) {
            $event['remote_error'] = 'missing_tool_configs';
            king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
            fwrite(
                $client,
                $encodeErrorMeta(
                    'remote peer expected durable tool configs for registered handler execution.',
                    'backend',
                    'caller_managed_retry',
                    -1,
                    'remote_peer'
                )
            );
            fclose($client);
            unset($event);
            continue;
        }

        foreach (($event['handler_boundary']['required_tools'] ?? []) as $toolName) {
            if (!is_string($toolName) || $toolName === '') {
                $event['remote_error'] = 'invalid_required_tool';
                king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
                fwrite(
                    $client,
                    $encodeErrorMeta(
                        'remote peer received an invalid required tool name in the handler boundary.',
                        'backend',
                        'caller_managed_retry',
                        -1,
                        'remote_peer'
                    )
                );
                fclose($client);
                unset($event);
                continue 2;
            }

            if (!array_key_exists($toolName, $registeredHandlers)) {
                $event['remote_error'] = sprintf(
                    "remote peer has no registered handler for tool '%s'.",
                    $toolName
                );
                $event['failed_step_index'] = king_orchestrator_remote_peer_first_required_step_index(
                    $event['handler_boundary'],
                    $toolName
                );
                king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
                fwrite(
                    $client,
                    $encodeErrorMeta(
                        $event['remote_error'],
                        'missing_handler',
                        'caller_managed_retry',
                        $event['failed_step_index'],
                        'remote_peer'
                    )
                );
                fclose($client);
                unset($event);
                continue 2;
            }
        }
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
    $currentResult = $event['initial_data'];
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

        if (
            king_orchestrator_remote_peer_handler_boundary_requires_process_registration($event['handler_boundary'])
            && king_orchestrator_remote_peer_step_uses_registered_handler($event['handler_boundary'], $step, $stepIndex)
        ) {
            try {
                $currentResult = king_orchestrator_remote_peer_invoke_registered_handler(
                    $registeredHandlers,
                    $event['tool_configs'],
                    (string) $event['run_id'],
                    $step,
                    $stepIndex,
                    $currentResult,
                    (int) $event['timeout_budget_ms'],
                    (int) $event['deadline_budget_ms']
                );
            } catch (Throwable $e) {
                $classification = king_orchestrator_remote_peer_classify_handler_failure($e);
                $event['remote_error'] = $e->getMessage();
                $event['failed_step_index'] = $stepIndex;
                king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
                fwrite(
                    $client,
                    $encodeErrorMeta(
                        $event['remote_error'],
                        $classification['category'],
                        $classification['retry_disposition'],
                        $stepIndex,
                        'remote_peer'
                    )
                );
                $failed = true;
                break;
            }
        }
    }

    if (!$failed) {
        $result = $currentResult;
        if (
            !king_orchestrator_remote_peer_handler_boundary_requires_process_registration($event['handler_boundary'])
            && is_array($event['pipeline'])
            && isset($event['pipeline'][0])
            && is_array($event['pipeline'][0])
            && !empty($event['pipeline'][0]['remote_object_result'])
        ) {
            $result = (object) ['remote_object' => true];
        }

        $event['result'] = $result;
        fwrite($client, $encodeResult($result));
    }

    king_orchestrator_remote_peer_persist_capture($capture, $capturePath);

    fclose($client);
    unset($event);
}

king_orchestrator_remote_peer_persist_capture($capture, $capturePath);
fclose($server);
