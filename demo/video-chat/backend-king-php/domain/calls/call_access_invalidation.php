<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/call_access_contract.php';

function videochat_call_access_invalidation_state(mixed $value): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['cancelled', 'declined'], true) ? $normalized : '';
}

function videochat_call_access_invalidation_call(PDO $pdo, string $callId, ?int $tenantId = null): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [];
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND tenant_id = :tenant_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT id, {$tenantSelect} room_id, status
FROM calls
WHERE id = :id
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $normalizedCallId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [];
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'room_id' => (string) ($row['room_id'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
    ];
}

function videochat_call_access_invalidation_target_user(PDO $pdo, array $accessLink): ?array
{
    $targetUserId = is_numeric($accessLink['participant_user_id'] ?? null) ? (int) $accessLink['participant_user_id'] : 0;
    if ($targetUserId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, email, password_hash
FROM users
WHERE id = :id
LIMIT 1
SQL
    );
    $query->execute([':id' => $targetUserId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $passwordHash = trim((string) ($row['password_hash'] ?? ''));
    if ($passwordHash === '' && str_starts_with($email, 'guest+') && str_ends_with($email, '@videochat.local')) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
    ];
}

function videochat_call_access_invalidation_session_count(PDO $pdo, string $accessId): int
{
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '' || !videochat_tenant_table_has_column($pdo, 'call_access_sessions', 'access_id')) {
        return 0;
    }

    try {
        $query = $pdo->prepare('SELECT COUNT(*) FROM call_access_sessions WHERE access_id = :access_id');
        $query->execute([':access_id' => $normalizedAccessId]);
        return max(0, (int) ($query->fetchColumn() ?: 0));
    } catch (Throwable) {
        return 0;
    }
}

function videochat_fetch_call_access_links_for_call(PDO $pdo, string $callId, ?int $tenantId = null): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [];
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND tenant_id = :tenant_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    id,
    {$tenantSelect}
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at
FROM call_access_links
WHERE call_id = :call_id
  {$tenantWhere}
ORDER BY created_at ASC, id ASC
SQL
    );
    $params = [':call_id' => $normalizedCallId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);

    $links = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $links[] = [
            'id' => (string) ($row['id'] ?? ''),
            'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
            'call_id' => (string) ($row['call_id'] ?? ''),
            'participant_user_id' => is_numeric($row['participant_user_id'] ?? null) ? (int) $row['participant_user_id'] : null,
            'participant_email' => is_string($row['participant_email'] ?? null) ? strtolower(trim((string) $row['participant_email'])) : null,
            'invite_code_id' => is_string($row['invite_code_id'] ?? null) ? (string) $row['invite_code_id'] : null,
            'created_by_user_id' => is_numeric($row['created_by_user_id'] ?? null) ? (int) $row['created_by_user_id'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'expires_at' => is_string($row['expires_at'] ?? null) ? (string) $row['expires_at'] : null,
            'last_used_at' => is_string($row['last_used_at'] ?? null) ? (string) $row['last_used_at'] : null,
            'consumed_at' => is_string($row['consumed_at'] ?? null) ? (string) $row['consumed_at'] : null,
        ];
    }

    return $links;
}

function videochat_call_access_update_invite_state_for_link(PDO $pdo, array $accessLink, string $nextState): bool
{
    $callId = trim((string) ($accessLink['call_id'] ?? ''));
    $targetUserId = is_numeric($accessLink['participant_user_id'] ?? null) ? (int) $accessLink['participant_user_id'] : 0;
    $participantEmail = videochat_normalize_call_access_email(
        is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
    );
    if ($callId === '' || ($targetUserId <= 0 && $participantEmail === '')) {
        return false;
    }

    if ($targetUserId > 0) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = :next_state,
    left_at = CASE
        WHEN joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = '') THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $update->execute([
            ':next_state' => $nextState,
            ':left_at' => gmdate('c'),
            ':call_id' => $callId,
            ':user_id' => $targetUserId,
        ]);
        return $update->rowCount() > 0;
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :next_state,
    left_at = CASE
        WHEN joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = '') THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND lower(email) = lower(:email)
  AND source = 'external'
SQL
    );
    $update->execute([
        ':next_state' => $nextState,
        ':left_at' => gmdate('c'),
        ':call_id' => $callId,
        ':email' => $participantEmail,
    ]);
    return $update->rowCount() > 0;
}

function videochat_invalidate_call_access_invitation(
    PDO $pdo,
    string $accessId,
    string $nextState = 'cancelled',
    ?int $actorUserId = null,
    array $context = []
): array {
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    $normalizedNextState = videochat_call_access_invalidation_state($nextState);
    if ($normalizedAccessId === '' || $normalizedNextState === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['access_id' => 'invalid'], 'audit_event' => null];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($accessLink) || videochat_call_access_link_kind($accessLink) !== 'personal') {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['access_id' => 'personal_link_not_found'], 'audit_event' => null];
    }

    $currentState = videochat_call_access_participant_invite_state($pdo, $accessLink);
    if ($currentState === '') {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['participant' => 'invite_not_found'], 'audit_event' => null];
    }

    $tenantId = is_numeric($accessLink['tenant_id'] ?? null) ? (int) $accessLink['tenant_id'] : null;
    $call = videochat_call_access_invalidation_call($pdo, (string) ($accessLink['call_id'] ?? ''), $tenantId);
    $targetUser = videochat_call_access_invalidation_target_user($pdo, $accessLink);
    $accessSessionCount = videochat_call_access_invalidation_session_count($pdo, $normalizedAccessId);
    $hadEffect = !in_array($currentState, ['cancelled', 'declined'], true) || $currentState !== $normalizedNextState;

    try {
        $pdo->beginTransaction();
        if ($hadEffect && !videochat_call_access_update_invite_state_for_link($pdo, $accessLink, $normalizedNextState)) {
            throw new RuntimeException('invite_update_failed');
        }
        $audit = videochat_audit_record_call_access_invitation_invalidated($pdo, $accessLink, $call, $targetUser, $actorUserId, [
            ...$context,
            'invite_state' => $normalizedNextState,
            'had_effect' => $hadEffect,
            'access_session_count' => $accessSessionCount,
        ]);
        if (!(bool) ($audit['ok'] ?? false)) {
            throw new RuntimeException('audit_write_failed');
        }
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'audit_failed', 'errors' => [], 'audit_event' => null];
    }

    return [
        'ok' => true,
        'reason' => $hadEffect ? 'invalidated' : 'already_invalidated',
        'errors' => [],
        'access_link' => $accessLink,
        'call' => $call,
        'target_user' => $targetUser,
        'access_session_count' => $accessSessionCount,
        'audit_event' => is_array($audit['event'] ?? null) ? $audit['event'] : null,
    ];
}

function videochat_call_access_record_invitation_invalidations_for_call(
    PDO $pdo,
    string $callId,
    ?int $tenantId = null,
    ?int $actorUserId = null,
    array $context = []
): array {
    $links = videochat_fetch_call_access_links_for_call($pdo, $callId, $tenantId);
    $call = videochat_call_access_invalidation_call($pdo, $callId, $tenantId);
    $events = [];

    foreach ($links as $accessLink) {
        if (videochat_call_access_link_kind($accessLink) !== 'personal' || !videochat_call_access_link_is_invalidated($pdo, $accessLink)) {
            continue;
        }
        $accessId = (string) ($accessLink['id'] ?? '');
        $inviteState = videochat_call_access_participant_invite_state($pdo, $accessLink) ?: 'cancelled';
        $audit = videochat_audit_record_call_access_invitation_invalidated(
            $pdo,
            $accessLink,
            $call,
            videochat_call_access_invalidation_target_user($pdo, $accessLink),
            $actorUserId,
            [
                ...$context,
                'invite_state' => $inviteState,
                'had_effect' => true,
                'access_session_count' => videochat_call_access_invalidation_session_count($pdo, $accessId),
            ]
        );
        if (!(bool) ($audit['ok'] ?? false)) {
            return ['ok' => false, 'reason' => 'audit_failed', 'events' => $events];
        }
        if (is_array($audit['event'] ?? null)) {
            $events[] = $audit['event'];
        }
    }

    return ['ok' => true, 'reason' => 'recorded', 'events' => $events];
}
