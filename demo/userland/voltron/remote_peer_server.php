#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Voltron remote-peer server over King remote-peer wire contract.
 *
 * Usage:
 *   php demo/userland/voltron/remote_peer_server.php <capture-path> <port> <bind-host> [handler-bootstrap] [peer-name] [downstream-host] [downstream-port]
 */

$capturePath = $argv[1] ?? '';
$port = isset($argv[2]) ? (int) $argv[2] : 0;
$bindHost = $argv[3] ?? '127.0.0.1';
$handlerBootstrapPath = $argv[4] ?? null;
$peerName = isset($argv[5]) && is_string($argv[5]) && $argv[5] !== '' ? $argv[5] : 'peer-a';
$downstreamHost = isset($argv[6]) && is_string($argv[6]) && $argv[6] !== '' ? $argv[6] : null;
$downstreamPort = isset($argv[7]) ? (int) $argv[7] : 0;

if (!is_string($capturePath) || $capturePath === '' || $port <= 0) {
    fwrite(
        STDERR,
        "usage: php demo/userland/voltron/remote_peer_server.php <capture-path> <port> <bind-host> [handler-bootstrap] [peer-name] [downstream-host] [downstream-port]\n"
    );
    exit(1);
}

/**
 * @param array<string,mixed> $capture
 */
function voltron_remote_peer_persist_capture(array $capture, string $capturePath): void
{
    file_put_contents($capturePath, json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
}

/**
 * @return array<string,callable>
 */
function voltron_remote_peer_load_handlers(?string $bootstrapPath): array
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
            throw new RuntimeException('remote peer bootstrap returned invalid tool name');
        }
        if (!is_callable($handler)) {
            throw new RuntimeException("remote peer bootstrap returned non-callable handler for tool '{$toolName}'");
        }
    }

    return $handlers;
}

function voltron_sanitize_id(string $value): string
{
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $value);
    return $sanitized === '' ? 'x' : $sanitized;
}

function voltron_object_store_ready(): bool
{
    static $initialized = false;
    static $ready = false;

    if ($initialized) {
        return $ready;
    }
    $initialized = true;

    if (!function_exists('king_object_store_init') || !function_exists('king_object_store_put')) {
        $ready = false;
        return false;
    }

    $root = getenv('VOLTRON_OBJECT_STORE_ROOT');
    if (!is_string($root) || $root === '') {
        $root = sys_get_temp_dir() . '/voltron-object-store';
    }
    if (!is_dir($root)) {
        @mkdir($root, 0777, true);
    }

    try {
        $ready = king_object_store_init([
            'primary_backend' => 'local_fs',
            'storage_root_path' => $root,
            'max_storage_size_bytes' => 2 * 1024 * 1024 * 1024,
            'chunk_size_kb' => 256,
        ]) === true;
    } catch (\Throwable) {
        $ready = false;
    }

    return $ready;
}

/**
 * @param array<string,mixed> $artifact
 */
function voltron_encode_artifact_ref(array $artifact): ?string
{
    if (!function_exists('king_proto_define_schema') || !function_exists('king_proto_encode')) {
        return null;
    }

    if (!function_exists('king_proto_is_schema_defined') || !king_proto_is_schema_defined('VoltronArtifactRef')) {
        king_proto_define_schema('VoltronArtifactRef', [
            'artifact_uri' => ['tag' => 1, 'type' => 'string', 'required' => true],
            'object_id' => ['tag' => 2, 'type' => 'string', 'required' => true],
            'checksum' => ['tag' => 3, 'type' => 'string', 'required' => true],
            'size_bytes' => ['tag' => 4, 'type' => 'int64', 'required' => true],
            'producer_peer' => ['tag' => 5, 'type' => 'string', 'required' => true],
            'step_id' => ['tag' => 6, 'type' => 'string', 'required' => true],
            'direction' => ['tag' => 7, 'type' => 'string', 'required' => true],
        ]);
    }

    try {
        return king_proto_encode('VoltronArtifactRef', $artifact);
    } catch (\Throwable) {
        return null;
    }
}

/**
 * @return array<string,mixed>
 */
function voltron_store_payload_artifact(string $runId, string $stepId, string $direction, string $peerName, mixed $payload): array
{
    $serialized = serialize($payload);
    $checksum = hash('sha256', $serialized);

    $objectId = sprintf(
        'voltron.%s.%s.%s.%s-%s.bin',
        voltron_sanitize_id($runId),
        voltron_sanitize_id($stepId),
        voltron_sanitize_id($peerName),
        voltron_sanitize_id($direction),
        substr($checksum, 0, 16)
    );

    $storedInObjectStore = false;
    if (voltron_object_store_ready()) {
        try {
            $storedInObjectStore = king_object_store_put(
                $objectId,
                $serialized,
                [
                    'content_type' => 'application/x-php-serialized',
                    'object_type' => 'binary_data',
                    'cache_policy' => 'etag',
                    'integrity_sha256' => $checksum,
                ]
            ) === true;
        } catch (\Throwable) {
            $storedInObjectStore = false;
        }
    }

    $artifact = [
        'artifact_uri' => ($storedInObjectStore ? 'object://' : 'memory://') . $objectId,
        'object_id' => $objectId,
        'checksum' => $checksum,
        'size_bytes' => strlen($serialized),
        'producer_peer' => $peerName,
        'step_id' => $stepId,
        'direction' => $direction,
        'stored_in_object_store' => $storedInObjectStore,
    ];
    $encoded = voltron_encode_artifact_ref($artifact);
    if (is_string($encoded) && $encoded !== '') {
        $artifact['iibin_ref_size_bytes'] = strlen($encoded);
    }

    return $artifact;
}

/**
 * @return array<string,string> step_id => owner_peer
 */
function voltron_owner_map_for_pipeline(array $pipeline, array $options): array
{
    $provided = $options['voltron_schedule'] ?? null;
    if (is_array($provided) && $provided !== []) {
        $ownerMap = [];
        foreach ($provided as $stepId => $owner) {
            if (is_string($stepId) && $stepId !== '' && is_string($owner) && $owner !== '') {
                $ownerMap[$stepId] = $owner;
            }
        }
        if ($ownerMap !== []) {
            return $ownerMap;
        }
    }

    $modelStepIds = [];
    foreach ($pipeline as $step) {
        if (!is_array($step)) {
            continue;
        }
        $stepId = $step['id'] ?? null;
        if (is_string($stepId) && str_starts_with($stepId, 'voltron.execute_block.')) {
            $modelStepIds[] = $stepId;
        }
    }

    $owners = [];
    $split = max(1, intdiv(count($modelStepIds), 2));
    foreach ($modelStepIds as $i => $stepId) {
        $owners[$stepId] = $i < $split ? 'peer-a' : 'peer-b';
    }
    foreach ($pipeline as $step) {
        if (!is_array($step)) {
            continue;
        }
        $stepId = $step['id'] ?? null;
        if (is_string($stepId) && !isset($owners[$stepId])) {
            $owners[$stepId] = 'peer-a';
        }
    }

    return $owners;
}

/**
 * @param array<int,array<string,mixed>> $pipeline
 * @param array<string,mixed> $options
 * @param array<string,mixed>|null $handlerBoundary
 * @param array<string,mixed>|null $toolConfigs
 * @return array<string,mixed>
 */
function voltron_remote_peer_request(
    string $host,
    int $port,
    string $runId,
    mixed $initialData,
    array $pipeline,
    array $options,
    ?array $handlerBoundary,
    ?array $toolConfigs,
    int $timeoutBudgetMs,
    int $deadlineBudgetMs
): array {
    $endpoint = 'tcp://' . $host . ':' . $port;
    $socket = @stream_socket_client($endpoint, $errno, $errstr, 3.0);
    if (!is_resource($socket)) {
        throw new RuntimeException("failed to connect to downstream peer {$endpoint}: {$errstr}");
    }

    $parts = [
        'RUN',
        base64_encode($runId),
        base64_encode(serialize($initialData)),
        base64_encode(serialize($pipeline)),
        base64_encode(serialize($options)),
    ];
    if ($handlerBoundary !== null && $toolConfigs !== null) {
        $parts[] = base64_encode(serialize($handlerBoundary));
        $parts[] = base64_encode(serialize($toolConfigs));
    }
    $parts[] = (string) $timeoutBudgetMs;
    $parts[] = (string) $deadlineBudgetMs;

    fwrite($socket, implode("\t", $parts) . "\n");
    fflush($socket);

    $response = fgets($socket);
    fclose($socket);
    if ($response === false) {
        throw new RuntimeException('downstream peer closed connection before response');
    }

    $response = rtrim($response, "\r\n");
    [$opcode, $payload] = array_pad(explode("\t", $response, 2), 2, '');

    if ($opcode === 'OK') {
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new RuntimeException('downstream peer returned invalid base64 OK payload');
        }
        $result = unserialize($decoded, ['allowed_classes' => false]);
        if (!is_array($result)) {
            throw new RuntimeException('downstream peer returned non-array result');
        }
        return $result;
    }

    if ($opcode === 'ERRMETA') {
        $decoded = base64_decode($payload, true);
        $meta = $decoded !== false ? unserialize($decoded, ['allowed_classes' => false]) : null;
        $message = is_array($meta) && is_string($meta['message'] ?? null) ? $meta['message'] : 'downstream peer returned ERRMETA';
        throw new RuntimeException($message);
    }

    if ($opcode === 'ERR') {
        $message = base64_decode($payload, true);
        throw new RuntimeException(is_string($message) && $message !== '' ? $message : 'downstream peer returned ERR');
    }

    throw new RuntimeException("downstream peer returned unknown opcode '{$opcode}'");
}

try {
    $registeredHandlers = voltron_remote_peer_load_handlers(is_string($handlerBootstrapPath) ? $handlerBootstrapPath : null);
} catch (Throwable $e) {
    fwrite(STDERR, "failed to load remote peer handlers: {$e->getMessage()}\n");
    exit(1);
}

$endpoint = 'tcp://' . $bindHost . ':' . $port;
$server = @stream_socket_server($endpoint, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "failed to start remote peer server: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, true);

$capture = [
    'peer_name' => $peerName,
    'downstream' => [
        'host' => $downstreamHost,
        'port' => $downstreamPort,
    ],
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
voltron_remote_peer_persist_capture($capture, $capturePath);

while (true) {
    $client = @stream_socket_accept($server, 0.5);
    if ($client === false) {
        continue;
    }

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
        [, $runIdB64, $initialB64, $pipelineB64, $optionsB64, $handlerBoundaryB64, $toolConfigsB64, $timeoutBudgetMs, $deadlineBudgetMs] = $parts;
    } else {
        [, $runIdB64, $initialB64, $pipelineB64, $optionsB64, $timeoutBudgetMs, $deadlineBudgetMs] = $parts;
        $handlerBoundaryB64 = null;
        $toolConfigsB64 = null;
    }

    $event = [
        'peer' => $peerName,
        'downstream_host' => $downstreamHost,
        'downstream_port' => $downstreamPort,
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
        voltron_remote_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('failed to decode remote orchestrator payload'));
        fclose($client);
        continue;
    }

    if (!is_array($event['pipeline'])) {
        $event['remote_error'] = 'invalid_pipeline';
        $capture['events'][] = $event;
        voltron_remote_peer_persist_capture($capture, $capturePath);
        fwrite($client, $encodeError('remote peer expected an array pipeline definition'));
        fclose($client);
        continue;
    }

    $options = is_array($event['options']) ? $event['options'] : [];
    $handlerBoundary = is_array($event['handler_boundary']) ? $event['handler_boundary'] : [];
    $toolConfigs = is_array($event['tool_configs']) ? $event['tool_configs'] : [];
    $runId = is_string($event['run_id'] ?? null) ? $event['run_id'] : 'run-unknown';

    $ownerMap = voltron_owner_map_for_pipeline($event['pipeline'], $options);
    $forwarded = !empty($options['voltron_forwarded']);

    $trace = [];
    $currentPayload = $event['initial_data'];
    $failed = false;

    foreach ($event['pipeline'] as $stepIndex => $step) {
        if (!is_array($step)) {
            continue;
        }

        $toolName = $step['tool'] ?? null;
        $stepId = is_string($step['id'] ?? null) ? $step['id'] : ('step-' . $stepIndex);
        if (!is_string($toolName) || $toolName === '') {
            continue;
        }
        if (!array_key_exists($toolName, $registeredHandlers)) {
            $event['remote_error'] = "remote peer has no registered handler for tool '{$toolName}'.";
            $event['failed_step_index'] = $stepIndex;
            $failed = true;
            fwrite(
                $client,
                $encodeErrorMeta($event['remote_error'], 'missing_handler', 'caller_managed_retry', $stepIndex, 'remote_peer')
            );
            break;
        }

        $ownerPeer = is_string($ownerMap[$stepId] ?? null) ? $ownerMap[$stepId] : $peerName;
        $dispatchMode = 'local';
        $executedBy = $peerName;

        $inputArtifact = voltron_store_payload_artifact($runId, $stepId, 'input', $peerName, $currentPayload);
        $handoffRequestArtifact = null;
        $handoffResponseArtifact = null;

        $shouldForward = (
            !$forwarded
            && $peerName === 'peer-a'
            && $ownerPeer === 'peer-b'
            && is_string($downstreamHost)
            && $downstreamHost !== ''
            && $downstreamPort > 0
        );

        try {
            if ($shouldForward) {
                $dispatchMode = 'forwarded';
                $executedBy = 'peer-b';

                $handoffRequestArtifact = voltron_store_payload_artifact($runId, $stepId, 'handoff_request', $peerName, $currentPayload);

                $forwardBoundary = [
                    'requires_process_registration' => true,
                    'required_tools' => [$toolName],
                    'required_step_refs' => [
                        ['index' => 0, 'tool_name' => $toolName],
                    ],
                ];
                $forwardToolConfigs = [$toolName => is_array($toolConfigs[$toolName] ?? null) ? $toolConfigs[$toolName] : []];
                $forwardOptions = $options;
                $forwardOptions['voltron_forwarded'] = true;

                $currentPayload = voltron_remote_peer_request(
                    $downstreamHost,
                    $downstreamPort,
                    $runId,
                    $currentPayload,
                    [$step],
                    $forwardOptions,
                    $forwardBoundary,
                    $forwardToolConfigs,
                    (int) $event['timeout_budget_ms'],
                    (int) $event['deadline_budget_ms']
                );

                $handoffResponseArtifact = voltron_store_payload_artifact($runId, $stepId, 'handoff_response', 'peer-b', $currentPayload);
            } else {
                $handler = $registeredHandlers[$toolName];
                $handlerResult = $handler([
                    'input' => $currentPayload,
                    'run_id' => $runId,
                    'cancel' => null,
                    'timeout_budget_ms' => (int) $event['timeout_budget_ms'],
                    'deadline_budget_ms' => (int) $event['deadline_budget_ms'],
                    'tool' => [
                        'name' => $toolName,
                        'config' => is_array($toolConfigs[$toolName] ?? null) ? $toolConfigs[$toolName] : [],
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

                if (!is_array($handlerResult) || !is_array($handlerResult['output'] ?? null)) {
                    throw new RuntimeException("remote handler '{$toolName}' must return ['output' => array].");
                }
                $currentPayload = $handlerResult['output'];
            }
        } catch (Throwable $e) {
            $event['remote_error'] = $e->getMessage();
            $event['failed_step_index'] = $stepIndex;
            $failed = true;
            fwrite($client, $encodeErrorMeta($e->getMessage(), 'runtime', 'caller_managed_retry', $stepIndex, 'remote_peer'));
            break;
        }

        $outputArtifact = voltron_store_payload_artifact($runId, $stepId, 'output', $executedBy, $currentPayload);
        $trace[] = [
            'step_index' => $stepIndex,
            'step_id' => $stepId,
            'tool' => $toolName,
            'owner_peer' => $ownerPeer,
            'executed_by_peer' => $executedBy,
            'dispatch' => $dispatchMode,
            'deps' => is_array($step['deps'] ?? null) ? $step['deps'] : [],
            'input_artifact_uri' => $inputArtifact['artifact_uri'] ?? null,
            'output_artifact_uri' => $outputArtifact['artifact_uri'] ?? null,
            'input_artifact_iibin_size_bytes' => $inputArtifact['iibin_ref_size_bytes'] ?? null,
            'output_artifact_iibin_size_bytes' => $outputArtifact['iibin_ref_size_bytes'] ?? null,
            'handoff_request_artifact_uri' => is_array($handoffRequestArtifact) ? ($handoffRequestArtifact['artifact_uri'] ?? null) : null,
            'handoff_response_artifact_uri' => is_array($handoffResponseArtifact) ? ($handoffResponseArtifact['artifact_uri'] ?? null) : null,
        ];
    }

    if (!$failed) {
        if (is_array($currentPayload)) {
            $currentPayload['voltron_remote_trace'] = $trace;
            $currentPayload['voltron_owner_map'] = $ownerMap;
        }
        $event['result'] = $currentPayload;
        $event['trace'] = $trace;
        fwrite($client, $encodeResult($currentPayload));
    }

    $capture['events'][] = $event;
    voltron_remote_peer_persist_capture($capture, $capturePath);
    fclose($client);
}

voltron_remote_peer_persist_capture($capture, $capturePath);
fclose($server);
