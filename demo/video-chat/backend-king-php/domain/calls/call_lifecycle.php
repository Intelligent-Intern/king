<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/../../support/tenant_migrations.php';
require_once __DIR__ . '/call_guest_lifecycle.php';

function videochat_call_lifecycle_table_available(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (Throwable) {
        return false;
    }

    return true;
}

function videochat_call_lifecycle_count_call_rows(PDO $pdo, string $table, string $callId): int
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || !videochat_call_lifecycle_table_available($pdo, $table)) {
        return 0;
    }

    try {
        $query = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE call_id = :call_id");
        $query->execute([':call_id' => $normalizedCallId]);
        return max(0, (int) ($query->fetchColumn() ?: 0));
    } catch (Throwable) {
        return 0;
    }
}

function videochat_call_lifecycle_revoke_access_sessions(PDO $pdo, string $callId, string $now): int
{
    $normalizedCallId = trim($callId);
    if (
        $normalizedCallId === ''
        || !videochat_call_lifecycle_table_available($pdo, 'sessions')
        || !videochat_call_lifecycle_table_available($pdo, 'call_access_sessions')
    ) {
        return 0;
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE sessions
SET revoked_at = :revoked_at
WHERE (revoked_at IS NULL OR revoked_at = '')
  AND id IN (
      SELECT session_id
      FROM call_access_sessions
      WHERE call_id = :call_id
  )
SQL
    );
    $update->execute([
        ':revoked_at' => $now,
        ':call_id' => $normalizedCallId,
    ]);

    return max(0, $update->rowCount());
}

function videochat_call_lifecycle_delete_access_links(PDO $pdo, string $callId): int
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || !videochat_call_lifecycle_table_available($pdo, 'call_access_links')) {
        return 0;
    }

    $delete = $pdo->prepare('DELETE FROM call_access_links WHERE call_id = :call_id');
    $delete->execute([':call_id' => $normalizedCallId]);

    return max(0, $delete->rowCount());
}

function videochat_call_lifecycle_clear_presence(PDO $pdo, string $callId): int
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || !videochat_call_lifecycle_table_available($pdo, 'realtime_presence_connections')) {
        return 0;
    }

    $delete = $pdo->prepare('DELETE FROM realtime_presence_connections WHERE call_id = :call_id');
    $delete->execute([':call_id' => $normalizedCallId]);

    return max(0, $delete->rowCount());
}

function videochat_call_lifecycle_clear_rescheduled_participants(PDO $pdo, array $call, string $now): int
{
    $callId = trim((string) ($call['id'] ?? ''));
    $ownerUserId = is_numeric($call['owner_user_id'] ?? null) ? (int) $call['owner_user_id'] : 0;
    if ($callId === '') {
        return 0;
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = CASE
        WHEN invite_state IN ('pending', 'allowed', 'accepted') THEN 'invited'
        ELSE invite_state
    END,
    left_at = CASE
        WHEN joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = '') THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND NOT (call_role = 'owner' OR coalesce(user_id, 0) = :owner_user_id)
  AND (
      invite_state IN ('pending', 'allowed', 'accepted')
      OR (joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = ''))
  )
SQL
    );
    $update->execute([
        ':left_at' => $now,
        ':call_id' => $callId,
        ':owner_user_id' => $ownerUserId,
    ]);

    return max(0, $update->rowCount());
}

function videochat_call_lifecycle_cancel_terminal_participants(PDO $pdo, string $callId, string $now): int
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return 0;
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled',
    left_at = CASE
        WHEN joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = '') THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND (
      invite_state <> 'cancelled'
      OR (joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = ''))
  )
SQL
    );
    $update->execute([
        ':left_at' => $now,
        ':call_id' => $normalizedCallId,
    ]);

    return max(0, $update->rowCount());
}

function videochat_call_lifecycle_guest_cleanup_summary(array $guestCleanup): array
{
    return [
        'ok' => (bool) ($guestCleanup['ok'] ?? false),
        'reason' => (string) ($guestCleanup['reason'] ?? 'unknown'),
        'guest_user_count' => count((array) ($guestCleanup['guest_user_ids'] ?? [])),
        'invalidated_guest_count' => max(0, (int) ($guestCleanup['invalidated_guests'] ?? 0)),
    ];
}

function videochat_call_lifecycle_record_audit(
    PDO $pdo,
    array $call,
    string $transition,
    ?int $tenantId,
    ?int $actorUserId,
    array $cleanup
): array {
    $callId = trim((string) ($call['id'] ?? ''));
    $eventType = match ($transition) {
        'rescheduled' => 'call_rescheduled',
        'deleted' => 'call_deleted',
        'ended' => 'call_ended',
        default => 'call_lifecycle_changed',
    };

    return videochat_audit_record_event($pdo, [
        'tenant_id' => $tenantId,
        'event_type' => $eventType,
        'actor_user_id' => $actorUserId,
        'call_id' => $callId,
        'resource_type' => 'call',
        'resource_id' => $callId,
        'resource_fingerprint' => videochat_audit_fingerprint($callId),
        'payload' => [
            'transition' => $transition,
            'link_invalidated_count' => max(0, (int) ($cleanup['invalidated_link_count'] ?? 0)),
            'revoked_access_session_count' => max(0, (int) ($cleanup['revoked_access_session_count'] ?? 0)),
            'lobby_cleared_count' => max(0, (int) ($cleanup['lobby_cleared_count'] ?? 0)),
            'presence_cleared_count' => max(0, (int) ($cleanup['presence_cleared_count'] ?? 0)),
            'guest_cleanup' => videochat_call_lifecycle_guest_cleanup_summary(
                is_array($cleanup['guest_cleanup'] ?? null) ? $cleanup['guest_cleanup'] : []
            ),
            'registered_accounts_deleted' => false,
            'raw_access_identifier_logged' => false,
            'raw_session_identifier_logged' => false,
        ],
    ]);
}

function videochat_call_lifecycle_apply(
    PDO $pdo,
    array $call,
    string $transition,
    ?int $tenantId = null,
    ?int $actorUserId = null
): array {
    $callId = trim((string) ($call['id'] ?? ''));
    if ($callId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'transition' => $transition,
            'invalidated_link_count' => 0,
            'revoked_access_session_count' => 0,
            'lobby_cleared_count' => 0,
            'presence_cleared_count' => 0,
            'guest_cleanup' => ['ok' => false, 'reason' => 'validation_failed'],
            'audit_event' => null,
        ];
    }

    $guestCleanup = videochat_invalidate_guest_accounts_for_call($pdo, $callId, $tenantId);
    $now = gmdate('c');

    try {
        $pdo->beginTransaction();

        $linkCount = videochat_call_lifecycle_count_call_rows($pdo, 'call_access_links', $callId);
        $revokedSessions = videochat_call_lifecycle_revoke_access_sessions($pdo, $callId, $now);
        $revokedSessions += max(0, (int) ($guestCleanup['revoked_sessions'] ?? 0));
        $invalidatedLinks = videochat_call_lifecycle_delete_access_links($pdo, $callId);
        if ($invalidatedLinks === 0 && $linkCount > 0) {
            $invalidatedLinks = $linkCount;
        }

        $clearedLobby = $transition === 'rescheduled'
            ? videochat_call_lifecycle_clear_rescheduled_participants($pdo, $call, $now)
            : videochat_call_lifecycle_cancel_terminal_participants($pdo, $callId, $now);
        $clearedPresence = videochat_call_lifecycle_clear_presence($pdo, $callId);

        $cleanup = [
            'invalidated_link_count' => $invalidatedLinks,
            'revoked_access_session_count' => $revokedSessions,
            'lobby_cleared_count' => $clearedLobby,
            'presence_cleared_count' => $clearedPresence,
            'guest_cleanup' => $guestCleanup,
        ];
        $audit = videochat_call_lifecycle_record_audit($pdo, $call, $transition, $tenantId, $actorUserId, $cleanup);

        $pdo->commit();

        return [
            'ok' => true,
            'reason' => 'applied',
            'transition' => $transition,
            'invalidated_link_count' => $invalidatedLinks,
            'revoked_access_session_count' => $revokedSessions,
            'lobby_cleared_count' => $clearedLobby,
            'presence_cleared_count' => $clearedPresence,
            'guest_cleanup' => $guestCleanup,
            'audit_event' => is_array($audit['event'] ?? null) ? $audit['event'] : null,
        ];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'transition' => $transition,
            'invalidated_link_count' => 0,
            'revoked_access_session_count' => 0,
            'lobby_cleared_count' => 0,
            'presence_cleared_count' => 0,
            'guest_cleanup' => $guestCleanup,
            'audit_event' => null,
        ];
    }
}

function videochat_apply_call_reschedule_lifecycle(PDO $pdo, array $call, ?int $tenantId = null, ?int $actorUserId = null): array
{
    return videochat_call_lifecycle_apply($pdo, $call, 'rescheduled', $tenantId, $actorUserId);
}

function videochat_apply_call_terminal_lifecycle(PDO $pdo, array $call, string $transition, ?int $tenantId = null, ?int $actorUserId = null): array
{
    $normalizedTransition = strtolower(trim($transition));
    if (!in_array($normalizedTransition, ['deleted', 'ended'], true)) {
        $normalizedTransition = 'ended';
    }

    return videochat_call_lifecycle_apply($pdo, $call, $normalizedTransition, $tenantId, $actorUserId);
}
