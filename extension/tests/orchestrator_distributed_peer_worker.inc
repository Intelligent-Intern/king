<?php

/* Worker process harness for distributed orchestrator remote-peer contracts. */

$workerId = $argv[1] ?? null;

if (!is_string($workerId) || $workerId === '') {
    fwrite(STDERR, "usage: php orchestrator_distributed_peer_worker.inc <worker-id>\n");
    exit(1);
}

$encodeError = static fn(string $message): string => "ERR\t" . base64_encode($message) . "\n";
$encodeResult = static fn(array $value): string => "OK\t" . base64_encode(serialize($value)) . "\n";
$decodePayload = static function (string $payload): array {
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        throw new RuntimeException('invalid base64 worker payload');
    }

    $value = unserialize($decoded, ['allowed_classes' => false]);
    if (!is_array($value)) {
        throw new RuntimeException('invalid worker payload shape');
    }

    return $value;
};

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === 'STOP') {
        break;
    }

    $parts = explode("\t", $line, 2);
    if (count($parts) !== 2 || $parts[0] !== 'STEP') {
        fwrite(STDOUT, $encodeError('invalid distributed worker opcode'));
        fflush(STDOUT);
        continue;
    }

    try {
        $payload = $decodePayload($parts[1]);
        $stepIndex = (int) ($payload['step_index'] ?? -1);
        $step = $payload['step'] ?? null;
        $result = $payload['result'] ?? null;

        if (!is_array($step) || $stepIndex < 0) {
            throw new RuntimeException('invalid distributed worker step payload');
        }

        $delayMs = isset($step['delay_ms']) ? (int) $step['delay_ms'] : 0;
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        if (!is_array($result)) {
            $result = ['value' => $result];
        }
        if (!isset($result['claims']) || !is_array($result['claims'])) {
            $result['claims'] = [];
        }
        if (!isset($result['step_order']) || !is_array($result['step_order'])) {
            $result['step_order'] = [];
        }
        if (!isset($result['workers']) || !is_array($result['workers'])) {
            $result['workers'] = [];
        }

        $claim = [
            'step_index' => $stepIndex,
            'tool' => isset($step['tool']) && is_string($step['tool']) ? $step['tool'] : null,
            'worker' => $workerId,
        ];
        $result['claims'][] = $claim;
        $result['step_order'][] = $stepIndex;
        $result['workers'][] = $workerId;

        fwrite(STDOUT, $encodeResult([
            'worker' => $workerId,
            'step_index' => $stepIndex,
            'result' => $result,
        ]));
        fflush(STDOUT);
    } catch (Throwable $e) {
        fwrite(STDOUT, $encodeError($e->getMessage()));
        fflush(STDOUT);
    }
}
