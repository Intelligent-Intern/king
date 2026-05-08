<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';

/**
 * @return array<int, int>
 */
function videochat_call_guest_lifecycle_guest_user_ids_for_call(PDO $pdo, string $callId, ?int $tenantId = null): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [];
    }

    $tenantJoin = is_int($tenantId) && $tenantId > 0
        ? 'INNER JOIN calls ON calls.id = :call_id AND calls.tenant_id = :tenant_id'
        : '';
    $tenantWhere = $tenantJoin === '' ? '' : 'AND calls.id IS NOT NULL';

    $query = $pdo->prepare(
        <<<SQL
SELECT DISTINCT users.id
FROM users
{$tenantJoin}
WHERE users.status = 'active'
  AND users.password_hash IS NULL
  AND lower(users.email) LIKE 'guest+%@videochat.local'
  {$tenantWhere}
  AND (
      EXISTS (
          SELECT 1
          FROM call_participants
          WHERE call_participants.call_id = :call_id
            AND call_participants.user_id = users.id
          LIMIT 1
      )
      OR EXISTS (
          SELECT 1
          FROM call_access_links
          WHERE call_access_links.call_id = :call_id
            AND call_access_links.participant_user_id = users.id
          LIMIT 1
      )
      OR EXISTS (
          SELECT 1
          FROM call_access_sessions
          WHERE call_access_sessions.call_id = :call_id
            AND call_access_sessions.user_id = users.id
          LIMIT 1
      )
  )
ORDER BY users.id ASC
SQL
    );
    $params = [':call_id' => $normalizedCallId];
    if ($tenantJoin !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);

    $ids = [];
    foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array{ok: bool, reason: string, audit_event: array<string, mixed>|null}
 */
function videochat_audit_record_guest_account_cleanup(PDO $pdo, string $callId, ?int $tenantId, array $result): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return ['ok' => false, 'reason' => 'validation_failed', 'audit_event' => null];
    }

    $guestUserIds = array_values(array_filter(array_map(
        static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0,
        (array) ($result['guest_user_ids'] ?? [])
    ), static fn (int $value): bool => $value > 0));

    $audit = videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => 'guest_account_cleanup',
        'call_id' => $normalizedCallId,
        'resource_type' => 'call_guest_accounts',
        'resource_id' => $normalizedCallId,
        'resource_fingerprint' => videochat_audit_fingerprint($normalizedCallId),
        'payload' => [
            'cleanup_scope' => 'call',
            'cleanup_result' => (string) ($result['reason'] ?? 'unknown'),
            'guest_user_count' => count($guestUserIds),
            'invalidated_guest_count' => max(0, (int) ($result['invalidated_guests'] ?? 0)),
            'revoked_session_count' => max(0, (int) ($result['revoked_sessions'] ?? 0)),
            'had_effect' => ((int) ($result['invalidated_guests'] ?? 0) > 0) || ((int) ($result['revoked_sessions'] ?? 0) > 0),
            'idempotent_safe' => true,
            'raw_guest_identifiers_logged' => false,
            'raw_session_identifier_logged' => false,
            'raw_access_identifier_logged' => false,
        ],
    ]);

    return [
        'ok' => (bool) ($audit['ok'] ?? false),
        'reason' => (string) ($audit['reason'] ?? 'audit_write_failed'),
        'audit_event' => is_array($audit['event'] ?? null) ? $audit['event'] : null,
    ];
}

/**
 * @return array{ok: bool, reason: string, guest_user_ids: array<int, int>, invalidated_guests: int, revoked_sessions: int, audit_events: array<int, array<string, mixed>>}
 */
function videochat_invalidate_guest_accounts_for_call(PDO $pdo, string $callId, ?int $tenantId = null): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'guest_user_ids' => [],
            'invalidated_guests' => 0,
            'revoked_sessions' => 0,
            'audit_events' => [],
        ];
    }

    $guestUserIds = videochat_call_guest_lifecycle_guest_user_ids_for_call($pdo, $normalizedCallId, $tenantId);
    if ($guestUserIds === []) {
        $result = [
            'ok' => true,
            'reason' => 'no_guest_accounts',
            'guest_user_ids' => [],
            'invalidated_guests' => 0,
            'revoked_sessions' => 0,
        ];
        $result['audit_events'] = [videochat_audit_record_guest_account_cleanup($pdo, $normalizedCallId, $tenantId, $result)];
        return $result;
    }

    $now = gmdate('c');
    $placeholders = [];
    $params = [
        ':call_id' => $normalizedCallId,
        ':revoked_at' => $now,
        ':updated_at' => $now,
    ];
    foreach ($guestUserIds as $index => $userId) {
        $placeholder = ':guest_user_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $userId;
    }
    $guestSql = implode(', ', $placeholders);

    try {
        $pdo->beginTransaction();

        $revokeParams = $params;
        unset($revokeParams[':updated_at']);
        $revoke = $pdo->prepare(
            <<<SQL
UPDATE sessions
SET revoked_at = :revoked_at
WHERE user_id IN ({$guestSql})
  AND (revoked_at IS NULL OR revoked_at = '')
  AND id IN (
      SELECT session_id
      FROM call_access_sessions
      WHERE call_id = :call_id
  )
SQL
        );
        $revoke->execute($revokeParams);
        $revokedSessions = $revoke->rowCount();

        $disableParams = $params;
        unset($disableParams[':call_id'], $disableParams[':revoked_at']);
        $disable = $pdo->prepare(
            <<<SQL
UPDATE users
SET status = 'disabled',
    updated_at = :updated_at
WHERE id IN ({$guestSql})
  AND status = 'active'
  AND password_hash IS NULL
  AND lower(email) LIKE 'guest+%@videochat.local'
SQL
        );
        $disable->execute($disableParams);
        $invalidatedGuests = $disable->rowCount();

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'guest_user_ids' => $guestUserIds,
            'invalidated_guests' => 0,
            'revoked_sessions' => 0,
            'audit_events' => [],
        ];
    }

    $result = [
        'ok' => true,
        'reason' => 'invalidated',
        'guest_user_ids' => $guestUserIds,
        'invalidated_guests' => $invalidatedGuests,
        'revoked_sessions' => $revokedSessions,
    ];
    $result['audit_events'] = [videochat_audit_record_guest_account_cleanup($pdo, $normalizedCallId, $tenantId, $result)];

    return $result;
}
