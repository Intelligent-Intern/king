<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';
require_once __DIR__ . '/call_permanent_lifecycle.php';

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>,
 *   audit_event?: array<string, mixed>|null,
 *   participant_rows?: int,
 *   owner_rows?: int
 * }
 */
function videochat_reactivate_call(PDO $pdo, string $callId, int $authUserId, string $authRole, array $payload = []): array
{
    if (!videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'call' => null,
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }

    $confirm = trim((string) ($payload['confirm'] ?? ''));
    if ($confirm !== 'reactivate_call') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['confirm' => 'must_equal_reactivate_call'],
            'call' => null,
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }

    $existingCall = videochat_fetch_call_for_update($pdo, $callId, null);
    if ($existingCall === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }

    $currentStatus = strtolower(trim((string) ($existingCall['status'] ?? '')));
    if (in_array($currentStatus, ['active', 'scheduled'], true)) {
        if (videochat_is_permanent_call((string) ($existingCall['id'] ?? $callId))) {
            videochat_permanent_call_ensure_active($pdo, (string) ($existingCall['id'] ?? $callId), 'reactivate_guard');
            $existingCall = videochat_fetch_call_for_update($pdo, (string) ($existingCall['id'] ?? $callId), null) ?? $existingCall;
        }
        return [
            'ok' => true,
            'reason' => 'already_' . $currentStatus,
            'errors' => [],
            'call' => videochat_build_call_payload($pdo, $existingCall, $authUserId),
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }
    if (!in_array($currentStatus, ['ended', 'cancelled'], true)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['status' => 'transition_not_allowed'],
            'call' => null,
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }

    $now = gmdate('c');
    $endsAt = trim((string) ($existingCall['ends_at'] ?? ''));
    $endsAtUnix = $endsAt === '' ? false : strtotime($endsAt);
    if ($endsAtUnix === false || $endsAtUnix <= time()) {
        $endsAt = '2099-12-31T23:59:59+00:00';
    }
    if (videochat_is_permanent_call((string) ($existingCall['id'] ?? $callId))) {
        $endsAt = videochat_permanent_call_guard_ends_at();
    }

    $ownerRows = 0;
    $participantRows = 0;
    $auditResult = ['ok' => false, 'event' => null];

    $pdo->beginTransaction();
    try {
        $updateCall = $pdo->prepare(
            <<<'SQL'
UPDATE calls
SET status = 'active',
    cancelled_at = NULL,
    cancel_reason = NULL,
    cancel_message = NULL,
    ends_at = :ends_at,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $updateCall->execute([
            ':ends_at' => $endsAt,
            ':updated_at' => $now,
            ':id' => (string) $existingCall['id'],
        ]);

        $allowOwner = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed',
    left_at = NULL
WHERE call_id = :call_id
  AND source = 'internal'
  AND (
      user_id = :owner_user_id
      OR call_role = 'owner'
  )
SQL
        );
        $allowOwner->execute([
            ':call_id' => (string) $existingCall['id'],
            ':owner_user_id' => (int) $existingCall['owner_user_id'],
        ]);
        $ownerRows = max(0, $allowOwner->rowCount());

        $reopenParticipants = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = CASE
        WHEN invite_state = 'cancelled' THEN 'invited'
        ELSE invite_state
    END,
    left_at = NULL
WHERE call_id = :call_id
  AND NOT (
      source = 'internal'
      AND (
          user_id = :owner_user_id
          OR call_role = 'owner'
      )
  )
  AND (
      invite_state = 'cancelled'
      OR left_at IS NOT NULL
  )
SQL
        );
        $reopenParticipants->execute([
            ':call_id' => (string) $existingCall['id'],
            ':owner_user_id' => (int) $existingCall['owner_user_id'],
        ]);
        $participantRows = max(0, $reopenParticipants->rowCount());

        $callTenantId = is_numeric($existingCall['tenant_id'] ?? null) ? (int) $existingCall['tenant_id'] : null;
        $auditResult = videochat_audit_record_event($pdo, [
            'tenant_id' => $callTenantId,
            'event_type' => 'call_reactivated',
            'actor_user_id' => $authUserId,
            'call_id' => (string) $existingCall['id'],
            'resource_type' => 'call',
            'resource_id' => (string) $existingCall['id'],
            'resource_fingerprint' => videochat_audit_fingerprint((string) $existingCall['id']),
            'payload' => [
                'previous_status' => $currentStatus,
                'next_status' => 'active',
                'owner_rows' => $ownerRows,
                'participant_rows' => $participantRows,
                'cleared_cancel_metadata' => true,
                'raw_access_identifier_logged' => false,
                'raw_session_identifier_logged' => false,
            ],
        ]);
        if (!(bool) ($auditResult['ok'] ?? false)) {
            throw new RuntimeException('call_reactivate_audit_failed');
        }

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => null,
            'audit_event' => null,
            'participant_rows' => 0,
            'owner_rows' => 0,
        ];
    }

    $freshCall = videochat_fetch_call_for_update($pdo, (string) $existingCall['id'], null);

    return [
        'ok' => true,
        'reason' => 'reactivated',
        'errors' => [],
        'call' => is_array($freshCall) ? videochat_build_call_payload($pdo, $freshCall, $authUserId) : null,
        'audit_event' => is_array($auditResult['event'] ?? null) ? $auditResult['event'] : null,
        'participant_rows' => $participantRows,
        'owner_rows' => $ownerRows,
    ];
}
