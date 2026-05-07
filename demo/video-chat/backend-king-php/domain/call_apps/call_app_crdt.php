<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_launch_tokens.php';

function videochat_call_app_crdt_actor_id(int $userId): string
{
    return 'user_' . hash('sha256', (string) $userId);
}

function videochat_call_app_crdt_decode_json(string $json, mixed $fallback): mixed
{
    $decoded = json_decode($json, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $fallback;
}

function videochat_call_app_crdt_json(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function videochat_call_app_crdt_positive_int(mixed $value, int $default, int $min, int $max): int
{
    $int = is_numeric($value) ? (int) $value : $default;
    return max($min, min($max, $int));
}

function videochat_call_app_crdt_session_for_actor(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId): array
{
    $record = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
    if (!is_array($record)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    if ((string) ($record['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'session_not_active'];
    }
    if (!videochat_call_app_grant_subject_in_call($pdo, (string) ($record['call_id'] ?? ''), 'user', $actorUserId, '')) {
        return ['ok' => false, 'reason' => 'participant_not_in_call'];
    }
    $session = videochat_call_app_fetch_session($pdo, $tenantId, $sessionId, false);
    if (!is_array($session)) {
        return ['ok' => false, 'reason' => 'session_not_found'];
    }
    $grantState = videochat_call_app_launch_user_grant_state($pdo, $tenantId, $record, $actorUserId);
    return ['ok' => true, 'record' => $record, 'session' => $session, 'grant_state' => $grantState];
}

function videochat_call_app_crdt_requires_allowed_grant(array $resolved): ?array
{
    if ((string) ($resolved['grant_state'] ?? 'denied') === 'allowed') {
        return null;
    }
    return [
        'ok' => false,
        'reason' => 'participant_grant_denied',
        'grant_state' => (string) ($resolved['grant_state'] ?? 'denied'),
    ];
}

function videochat_call_app_crdt_presence_payload_types(array $session): array
{
    $appKey = (string) ($session['app_key'] ?? (($session['app'] ?? [])['app_key'] ?? ''));
    if ($appKey !== 'whiteboard') {
        return [];
    }
    return ['cursor.move', 'selection.update', 'tool.preview'];
}

function videochat_call_app_crdt_ensure_document(PDO $pdo, int $tenantId, array $record, array $session): array
{
    $documentId = trim((string) ($record['document_id'] ?? ($session['document_id'] ?? '')));
    if ($documentId === '') {
        $documentId = videochat_call_app_session_document_id(
            (string) ($record['call_id'] ?? ''),
            (string) ($record['app_key'] ?? ''),
            (string) ($record['public_id'] ?? '')
        );
    }

    $select = $pdo->prepare('SELECT * FROM call_app_crdt_documents WHERE tenant_id = :tenant_id AND document_id = :document_id LIMIT 1');
    $select->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
    $row = $select->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return $row;
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_crdt_documents(
    document_id, tenant_id, app_session_id, app_key, app_version, schema_version,
    snapshot_json, snapshot_clock, compacted_through_clock, op_count, created_at, updated_at
) VALUES(
    :document_id, :tenant_id, :app_session_id, :app_key, :app_version, :schema_version,
    :snapshot_json, 0, 0, 0, :created_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':document_id' => $documentId,
        ':tenant_id' => $tenantId,
        ':app_session_id' => (int) ($record['id'] ?? 0),
        ':app_key' => (string) ($record['app_key'] ?? ''),
        ':app_version' => (string) ($record['app_version'] ?? ($session['version'] ?? '')),
        ':schema_version' => (string) ($session['app']['crdt_protocol'] ?? 'king.call_app.crdt.v1'),
        ':snapshot_json' => videochat_call_app_crdt_json(['kind' => 'empty', 'state' => new stdClass()]),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $select->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
    $created = $select->fetch(PDO::FETCH_ASSOC);
    return is_array($created) ? $created : [];
}

function videochat_call_app_crdt_envelope(array $op, array $document, array $session): array
{
    return [
        'app_id' => (string) ($session['app_key'] ?? ''),
        'app_version' => (string) ($session['version'] ?? ''),
        'call_id' => (string) ($session['call_id'] ?? ''),
        'app_session_id' => (string) ($session['id'] ?? ''),
        'document_id' => (string) ($document['document_id'] ?? ''),
        'schema_version' => (string) ($document['schema_version'] ?? 'king.call_app.crdt.v1'),
        'actor_id' => (string) ($op['actor_id'] ?? ''),
        'operation_id' => (string) ($op['operation_id'] ?? ''),
        'logical_clock' => (int) ($op['logical_clock'] ?? 0),
        'causal_dependencies' => videochat_call_app_crdt_decode_json((string) ($op['causal_dependencies_json'] ?? '[]'), []),
        'payload_type' => (string) ($op['payload_type'] ?? ''),
        'payload' => videochat_call_app_crdt_decode_json((string) ($op['payload_json'] ?? '{}'), new stdClass()),
        'server_admission_stamp' => videochat_call_app_crdt_decode_json((string) ($op['server_admission_stamp_json'] ?? '{}'), []),
    ];
}

function videochat_call_app_crdt_fetch_ops(PDO $pdo, int $tenantId, array $document, array $session, int $afterClock, int $limit): array
{
    $boundedLimit = videochat_call_app_crdt_positive_int($limit, 250, 1, 500);
    $statement = $pdo->prepare(
        <<<SQL
SELECT *
FROM call_app_crdt_ops
WHERE tenant_id = :tenant_id
  AND document_row_id = :document_row_id
  AND logical_clock > :after_clock
ORDER BY logical_clock ASC, id ASC
LIMIT {$boundedLimit}
SQL
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':document_row_id' => (int) ($document['id'] ?? 0),
        ':after_clock' => max(0, $afterClock),
    ]);

    $ops = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (is_array($row)) {
            $ops[] = videochat_call_app_crdt_envelope($row, $document, $session);
        }
    }
    return $ops;
}

function videochat_call_app_crdt_bootstrap(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId, int $afterClock = 0): array
{
    $resolved = videochat_call_app_crdt_session_for_actor($pdo, $tenantId, $sessionId, $actorUserId);
    if (!(bool) ($resolved['ok'] ?? false)) {
        return $resolved;
    }
    $grantDenied = videochat_call_app_crdt_requires_allowed_grant($resolved);
    if ($grantDenied !== null) {
        return $grantDenied;
    }
    $document = videochat_call_app_crdt_ensure_document($pdo, $tenantId, $resolved['record'], $resolved['session']);
    $snapshotClock = (int) ($document['snapshot_clock'] ?? 0);
    $replayAfter = max($snapshotClock, max(0, $afterClock));
    return [
        'ok' => true,
        'state' => 'bootstrapped',
        'document' => [
            'document_id' => (string) ($document['document_id'] ?? ''),
            'schema_version' => (string) ($document['schema_version'] ?? ''),
            'snapshot' => videochat_call_app_crdt_decode_json((string) ($document['snapshot_json'] ?? '{}'), []),
            'snapshot_clock' => $snapshotClock,
            'compacted_through_clock' => (int) ($document['compacted_through_clock'] ?? 0),
            'op_count' => (int) ($document['op_count'] ?? 0),
        ],
        'grant_state' => (string) ($resolved['grant_state'] ?? 'denied'),
        'ops' => videochat_call_app_crdt_fetch_ops($pdo, $tenantId, $document, $resolved['session'], $replayAfter, 250),
        'replay_cursor' => ['after_clock' => $replayAfter],
    ];
}

function videochat_call_app_crdt_list_ops(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId, int $afterClock, int $limit): array
{
    $resolved = videochat_call_app_crdt_session_for_actor($pdo, $tenantId, $sessionId, $actorUserId);
    if (!(bool) ($resolved['ok'] ?? false)) {
        return $resolved;
    }
    $grantDenied = videochat_call_app_crdt_requires_allowed_grant($resolved);
    if ($grantDenied !== null) {
        return $grantDenied;
    }
    $document = videochat_call_app_crdt_ensure_document($pdo, $tenantId, $resolved['record'], $resolved['session']);
    return [
        'ok' => true,
        'state' => 'listed',
        'document_id' => (string) ($document['document_id'] ?? ''),
        'ops' => videochat_call_app_crdt_fetch_ops($pdo, $tenantId, $document, $resolved['session'], $afterClock, $limit),
        'replay_cursor' => ['after_clock' => max(0, $afterClock)],
    ];
}

function videochat_call_app_crdt_normalize_append(array $payload, array $session, string $actorId): array
{
    $raw = is_array($payload['operation'] ?? null) ? $payload['operation'] : $payload;
    $operationId = trim((string) ($raw['operation_id'] ?? ''));
    $payloadType = trim((string) ($raw['payload_type'] ?? ''));
    $payloadBody = $raw['payload'] ?? [];
    $causal = is_array($raw['causal_dependencies'] ?? null) ? $raw['causal_dependencies'] : [];

    $errors = [];
    if ($operationId === '' || strlen($operationId) > 160) {
        $errors['operation_id'] = 'required_max_160';
    }
    if ($payloadType === '' || strlen($payloadType) > 120) {
        $errors['payload_type'] = 'required_max_120';
    } elseif (in_array($payloadType, videochat_call_app_crdt_presence_payload_types($session), true)) {
        $errors['payload_type'] = 'presence_must_not_be_persisted';
    }
    if (!is_array($payloadBody) && !is_scalar($payloadBody) && $payloadBody !== null) {
        $errors['payload'] = 'must_be_json_value';
    }
    if ($errors !== []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $errors];
    }

    return [
        'ok' => true,
        'operation_id' => $operationId,
        'actor_id' => $actorId,
        'payload_type' => $payloadType,
        'payload' => $payloadBody,
        'causal_dependencies' => $causal,
        'client_clock' => is_numeric($raw['logical_clock'] ?? null) ? (int) $raw['logical_clock'] : 0,
        'schema_version' => (string) ($session['app']['crdt_protocol'] ?? 'king.call_app.crdt.v1'),
    ];
}

function videochat_call_app_crdt_append_op(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId, array $payload): array
{
    $resolved = videochat_call_app_crdt_session_for_actor($pdo, $tenantId, $sessionId, $actorUserId);
    if (!(bool) ($resolved['ok'] ?? false)) {
        return $resolved;
    }
    $grantDenied = videochat_call_app_crdt_requires_allowed_grant($resolved);
    if ($grantDenied !== null) {
        return $grantDenied;
    }

    $actorId = videochat_call_app_crdt_actor_id($actorUserId);
    $normalized = videochat_call_app_crdt_normalize_append($payload, $resolved['session'], $actorId);
    if (!(bool) ($normalized['ok'] ?? false)) {
        return $normalized;
    }

    $document = videochat_call_app_crdt_ensure_document($pdo, $tenantId, $resolved['record'], $resolved['session']);
    $existing = $pdo->prepare('SELECT * FROM call_app_crdt_ops WHERE document_row_id = :document_row_id AND operation_id = :operation_id LIMIT 1');
    $existing->execute([':document_row_id' => (int) ($document['id'] ?? 0), ':operation_id' => (string) $normalized['operation_id']]);
    $duplicate = $existing->fetch(PDO::FETCH_ASSOC);
    if (is_array($duplicate)) {
        return [
            'ok' => true,
            'state' => 'duplicate',
            'operation' => videochat_call_app_crdt_envelope($duplicate, $document, $resolved['session']),
        ];
    }

    $nextClock = (int) $pdo->query('SELECT COALESCE(MAX(logical_clock), 0) + 1 FROM call_app_crdt_ops WHERE document_row_id = ' . (int) ($document['id'] ?? 0))->fetchColumn();
    $now = gmdate('c');
    $payloadJson = videochat_call_app_crdt_json($normalized['payload']);
    $stamp = [
        'admitted_at' => $now,
        'tenant_id' => $tenantId,
        'app_session_id' => (string) ($resolved['session']['id'] ?? ''),
        'client_clock' => (int) ($normalized['client_clock'] ?? 0),
        'duplicate_policy' => 'ignore_after_first_admission',
    ];
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_crdt_ops(
    public_id, tenant_id, document_row_id, app_session_id, operation_id, actor_id,
    logical_clock, causal_dependencies_json, payload_type, payload_json,
    payload_hash, server_admission_stamp_json, admitted_at
) VALUES(
    :public_id, :tenant_id, :document_row_id, :app_session_id, :operation_id, :actor_id,
    :logical_clock, :causal_dependencies_json, :payload_type, :payload_json,
    :payload_hash, :server_admission_stamp_json, :admitted_at
)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_call_app_session_public_id('cco'),
        ':tenant_id' => $tenantId,
        ':document_row_id' => (int) ($document['id'] ?? 0),
        ':app_session_id' => (int) ($resolved['record']['id'] ?? 0),
        ':operation_id' => (string) $normalized['operation_id'],
        ':actor_id' => $actorId,
        ':logical_clock' => $nextClock,
        ':causal_dependencies_json' => videochat_call_app_crdt_json($normalized['causal_dependencies']),
        ':payload_type' => (string) $normalized['payload_type'],
        ':payload_json' => $payloadJson,
        ':payload_hash' => hash('sha256', $payloadJson),
        ':server_admission_stamp_json' => videochat_call_app_crdt_json($stamp),
        ':admitted_at' => $now,
    ]);
    $pdo->prepare('UPDATE call_app_crdt_documents SET op_count = op_count + 1, updated_at = :updated_at WHERE id = :id')
        ->execute([':updated_at' => $now, ':id' => (int) ($document['id'] ?? 0)]);
    $existing->execute([':document_row_id' => (int) ($document['id'] ?? 0), ':operation_id' => (string) $normalized['operation_id']]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    return ['ok' => true, 'state' => 'admitted', 'operation' => videochat_call_app_crdt_envelope(is_array($row) ? $row : [], $document, $resolved['session'])];
}

function videochat_call_app_crdt_compact_snapshot(PDO $pdo, int $tenantId, string $sessionId, int $actorUserId): array
{
    $resolved = videochat_call_app_crdt_session_for_actor($pdo, $tenantId, $sessionId, $actorUserId);
    if (!(bool) ($resolved['ok'] ?? false)) {
        return $resolved;
    }
    $grantDenied = videochat_call_app_crdt_requires_allowed_grant($resolved);
    if ($grantDenied !== null) {
        return $grantDenied;
    }
    $document = videochat_call_app_crdt_ensure_document($pdo, $tenantId, $resolved['record'], $resolved['session']);
    $maxClock = (int) $pdo->query('SELECT COALESCE(MAX(logical_clock), 0) FROM call_app_crdt_ops WHERE document_row_id = ' . (int) ($document['id'] ?? 0))->fetchColumn();
    $opCount = (int) $pdo->query('SELECT COUNT(*) FROM call_app_crdt_ops WHERE document_row_id = ' . (int) ($document['id'] ?? 0))->fetchColumn();
    $snapshot = [
        'kind' => 'king.call_app.crdt.checkpoint.v1',
        'document_id' => (string) ($document['document_id'] ?? ''),
        'compacted_through_clock' => $maxClock,
        'operation_count' => $opCount,
    ];
    $now = gmdate('c');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_app_crdt_documents
SET snapshot_json = :snapshot_json,
    snapshot_clock = :snapshot_clock,
    compacted_through_clock = :compacted_through_clock,
    op_count = :op_count,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
    )->execute([
        ':snapshot_json' => videochat_call_app_crdt_json($snapshot),
        ':snapshot_clock' => $maxClock,
        ':compacted_through_clock' => $maxClock,
        ':op_count' => $opCount,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':id' => (int) ($document['id'] ?? 0),
    ]);
    return ['ok' => true, 'state' => 'compacted', 'snapshot' => $snapshot, 'snapshot_clock' => $maxClock];
}
