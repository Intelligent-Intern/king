<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   call_id: string,
 *   room_id: string,
 *   guest_list_entry: ?array{user_id: int, invite_state: string, call_role: string}
 * }
 */
function videochat_user_can_direct_join_call(
    PDO $pdo,
    string $callId,
    int $authUserId,
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $fallback = [
        'ok' => false,
        'reason' => 'not_on_guest_list',
        'call_id' => trim($callId),
        'room_id' => '',
        'guest_list_entry' => null,
    ];

    if (trim($callId) === '') {
        $fallback['reason'] = 'invalid_call_id';
        return $fallback;
    }
    if ($authUserId <= 0) {
        $fallback['reason'] = 'invalid_user_context';
        return $fallback;
    }

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    if (!is_array($call)) {
        $fallback['reason'] = 'not_found';
        return $fallback;
    }

    $callId = (string) ($call['id'] ?? $callId);
    $roomId = (string) ($call['room_id'] ?? '');
    $status = strtolower(trim((string) ($call['status'] ?? '')));
    if (!in_array($status, ['scheduled', 'active'], true)) {
        return [
            'ok' => false,
            'reason' => 'call_not_joinable_from_status',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if (videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole)) {
        return [
            'ok' => true,
            'reason' => 'system_admin',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if ((int) ($call['owner_user_id'] ?? 0) === $authUserId) {
        return [
            'ok' => true,
            'reason' => 'owner',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if (videochat_user_is_organization_admin_for_call($pdo, $call, $authUserId, $tenantId)) {
        return [
            'ok' => true,
            'reason' => 'organization_admin',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if (videochat_normalize_call_access_mode($call['access_mode'] ?? 'invite_only') === 'free_for_all') {
        return [
            'ok' => true,
            'reason' => 'free_for_all',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    $guestListEntry = $pdo->prepare(
        <<<'SQL'
SELECT user_id, invite_state, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $guestListEntry->execute([
        ':call_id' => $callId,
        ':user_id' => $authUserId,
    ]);
    $entry = $guestListEntry->fetch();
    if (!is_array($entry)) {
        return [
            'ok' => false,
            'reason' => 'not_on_guest_list',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    $inviteState = videochat_normalize_call_invite_state($entry['invite_state'] ?? 'invited');
    $normalizedEntry = [
        'user_id' => (int) ($entry['user_id'] ?? 0),
        'invite_state' => $inviteState,
        'call_role' => videochat_normalize_call_participant_role((string) ($entry['call_role'] ?? 'participant')),
    ];
    if (in_array($inviteState, ['declined', 'cancelled'], true)) {
        return [
            'ok' => false,
            'reason' => 'guest_list_entry_inactive',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => $normalizedEntry,
        ];
    }
    if ($inviteState === 'pending') {
        return [
            'ok' => false,
            'reason' => 'not_on_guest_list',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'guest_list',
        'call_id' => $callId,
        'room_id' => $roomId,
        'guest_list_entry' => $normalizedEntry,
    ];
}

/**
 * @return array{
 *   user_id: int,
 *   email: string,
 *   display_name: string,
 *   invite_state: string,
 *   call_role: string,
 *   joined_at: ?string,
 *   left_at: ?string
 * }|null
 */
function videochat_fetch_call_guest_list_entry(PDO $pdo, string $callId, int $userId): ?array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || $userId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, invite_state, call_role, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
ORDER BY CASE WHEN call_role = 'owner' THEN 0 ELSE 1 END ASC, email ASC
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
    ]);

    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'email' => strtolower(trim((string) ($row['email'] ?? ''))),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'invite_state' => videochat_normalize_call_invite_state($row['invite_state'] ?? 'invited'),
        'call_role' => videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant')),
        'joined_at' => is_string($row['joined_at'] ?? null) && trim((string) $row['joined_at']) !== ''
            ? (string) $row['joined_at']
            : null,
        'left_at' => is_string($row['left_at'] ?? null) && trim((string) $row['left_at']) !== ''
            ? (string) $row['left_at']
            : null,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_fetch_call_guest_list_candidate_rows(PDO $pdo, string $callId, int $userId, string $email): array
{
    $normalizedCallId = trim($callId);
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedCallId === '' || $userId <= 0 || $normalizedEmail === '') {
        return [];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, source, invite_state, call_role, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND (
    (user_id = :user_id AND source = 'internal')
    OR lower(email) = lower(:email)
  )
ORDER BY CASE WHEN user_id = :user_id AND source = 'internal' THEN 0 ELSE 1 END ASC, email ASC
SQL
    );
    $query->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
        ':email' => $normalizedEmail,
    ]);

    $rows = $query->fetchAll();
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>,
 *   entry: ?array<string, mixed>,
 *   audit_event: ?array<string, mixed>
 * }
 */
function videochat_prepare_call_guest_list_mutation(
    PDO $pdo,
    string $callId,
    int $targetUserId,
    int $authUserId,
    string $authRole,
    ?int $tenantId
): array {
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['call_id' => 'required_call_id'],
            'call' => null,
            'entry' => null,
            'audit_event' => null,
        ];
    }
    if ($targetUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'required_target_user_id'],
            'call' => null,
            'entry' => null,
            'audit_event' => null,
        ];
    }
    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'call' => null,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $isSystemAdmin = videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole);
    $call = videochat_fetch_call_for_update($pdo, $normalizedCallId, $isSystemAdmin ? null : $tenantId);
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'call' => null,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    if (!videochat_can_administer_call(
        $pdo,
        (string) ($call['id'] ?? $normalizedCallId),
        $authRole,
        $authUserId,
        (int) ($call['owner_user_id'] ?? 0),
        $tenantId
    )) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['call_id' => 'not_allowed_for_call'],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $callStatus = strtolower(trim((string) ($call['status'] ?? '')));
    if (!in_array($callStatus, ['scheduled', 'active'], true)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['status' => 'immutable_for_guest_list'],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ready',
        'errors' => [],
        'call' => $call,
        'entry' => null,
        'audit_event' => null,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>,
 *   entry: ?array<string, mixed>,
 *   audit_event: ?array<string, mixed>
 * }
 */
function videochat_add_call_guest_list_entry(
    PDO $pdo,
    string $callId,
    int $targetUserId,
    int $authUserId,
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $prepared = videochat_prepare_call_guest_list_mutation($pdo, $callId, $targetUserId, $authUserId, $authRole, $tenantId);
    if (!(bool) ($prepared['ok'] ?? false)) {
        return $prepared;
    }

    $call = (array) $prepared['call'];
    if ((int) ($call['owner_user_id'] ?? 0) === $targetUserId) {
        $entry = videochat_fetch_call_guest_list_entry($pdo, (string) ($call['id'] ?? $callId), $targetUserId);
        return [
            'ok' => true,
            'reason' => 'owner_already_has_access',
            'errors' => [],
            'call' => $call,
            'entry' => $entry,
            'audit_event' => null,
        ];
    }

    $target = videochat_active_user_identity($pdo, $targetUserId);
    if (!is_array($target)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user_id' => 'user_not_found_or_inactive'],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $targetEmail = strtolower(trim((string) ($target['email'] ?? '')));
    if ($targetEmail === '' || filter_var($targetEmail, FILTER_VALIDATE_EMAIL) === false) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'target_user_email_invalid'],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $callId = (string) ($call['id'] ?? $callId);
    $candidateRows = videochat_fetch_call_guest_list_candidate_rows($pdo, $callId, $targetUserId, $targetEmail);
    $hadPriorEntry = $candidateRows !== [];
    $bestInviteState = 'invited';
    $hadActiveEntry = false;
    $callRole = 'participant';
    $joinedAt = null;
    $leftAt = null;
    foreach ($candidateRows as $row) {
        $rowRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
        if ($rowRole === 'owner') {
            $callRole = 'owner';
        } elseif ($rowRole === 'moderator' && $callRole !== 'owner') {
            $callRole = 'moderator';
        }
        $state = videochat_normalize_call_invite_state($row['invite_state'] ?? 'invited');
        if (!in_array($state, ['declined', 'cancelled'], true)) {
            $hadActiveEntry = true;
            if (in_array($state, ['allowed', 'accepted'], true)) {
                $bestInviteState = 'allowed';
            } elseif ($state === 'pending' && $bestInviteState !== 'allowed') {
                $bestInviteState = 'pending';
            }
        }
        if ($joinedAt === null && is_string($row['joined_at'] ?? null) && trim((string) $row['joined_at']) !== '') {
            $joinedAt = (string) $row['joined_at'];
        }
        if ($leftAt === null && is_string($row['left_at'] ?? null) && trim((string) $row['left_at']) !== '') {
            $leftAt = (string) $row['left_at'];
        }
    }

    $reason = $hadPriorEntry ? ($hadActiveEntry ? 'merged' : 'restored') : 'added';
    $inviteState = $hadActiveEntry ? $bestInviteState : 'invited';

    $pdo->beginTransaction();
    try {
        $deleteDuplicates = $pdo->prepare(
            <<<'SQL'
DELETE FROM call_participants
WHERE call_id = :call_id
  AND (
    (user_id = :user_id AND source = 'internal')
    OR lower(email) = lower(:email)
  )
SQL
        );
        $deleteDuplicates->execute([
            ':call_id' => $callId,
            ':user_id' => $targetUserId,
            ':email' => $targetEmail,
        ]);

        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', :call_role, :invite_state, :joined_at, :left_at)
SQL
        );
        $insert->execute([
            ':call_id' => $callId,
            ':user_id' => $targetUserId,
            ':email' => $targetEmail,
            ':display_name' => (string) ($target['display_name'] ?? ''),
            ':call_role' => $callRole === 'owner' ? 'participant' : $callRole,
            ':invite_state' => $inviteState,
            ':joined_at' => $hadActiveEntry ? $joinedAt : null,
            ':left_at' => $hadActiveEntry ? $leftAt : null,
        ]);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $entry = videochat_fetch_call_guest_list_entry($pdo, $callId, $targetUserId);
    $audit = videochat_audit_record_guest_list_entry_change($pdo, $call, $targetUserId, $authUserId, $reason, [
        'call_role' => (string) ($entry['call_role'] ?? 'participant'),
        'invite_state' => (string) ($entry['invite_state'] ?? 'invited'),
        'had_prior_entry' => $hadPriorEntry,
    ]);

    return [
        'ok' => true,
        'reason' => $reason,
        'errors' => [],
        'call' => $call,
        'entry' => $entry,
        'audit_event' => is_array($audit['event'] ?? null) ? $audit['event'] : null,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>,
 *   entry: ?array<string, mixed>,
 *   audit_event: ?array<string, mixed>
 * }
 */
function videochat_remove_call_guest_list_entry(
    PDO $pdo,
    string $callId,
    int $targetUserId,
    int $authUserId,
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $prepared = videochat_prepare_call_guest_list_mutation($pdo, $callId, $targetUserId, $authUserId, $authRole, $tenantId);
    if (!(bool) ($prepared['ok'] ?? false)) {
        return $prepared;
    }

    $call = (array) $prepared['call'];
    if ((int) ($call['owner_user_id'] ?? 0) === $targetUserId) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'cannot_remove_call_owner'],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }

    $callId = (string) ($call['id'] ?? $callId);
    $entry = videochat_fetch_call_guest_list_entry($pdo, $callId, $targetUserId);
    if (!is_array($entry)) {
        return [
            'ok' => true,
            'reason' => 'noop',
            'errors' => [],
            'call' => $call,
            'entry' => null,
            'audit_event' => null,
        ];
    }
    if ((string) ($entry['call_role'] ?? '') === 'owner') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'cannot_remove_call_owner'],
            'call' => $call,
            'entry' => $entry,
            'audit_event' => null,
        ];
    }

    try {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled',
    joined_at = NULL,
    left_at = CASE
      WHEN joined_at IS NOT NULL AND (left_at IS NULL OR trim(left_at) = '') THEN :left_at
      ELSE left_at
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $update->execute([
            ':call_id' => $callId,
            ':user_id' => $targetUserId,
            ':left_at' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => $call,
            'entry' => $entry,
            'audit_event' => null,
        ];
    }

    $updatedEntry = videochat_fetch_call_guest_list_entry($pdo, $callId, $targetUserId);
    $audit = videochat_audit_record_guest_list_entry_change($pdo, $call, $targetUserId, $authUserId, 'removed', [
        'call_role' => (string) (($updatedEntry ?? $entry)['call_role'] ?? 'participant'),
        'invite_state' => (string) (($updatedEntry ?? $entry)['invite_state'] ?? 'cancelled'),
        'had_prior_entry' => true,
    ]);

    return [
        'ok' => true,
        'reason' => 'removed',
        'errors' => [],
        'call' => $call,
        'entry' => $updatedEntry,
        'audit_event' => is_array($audit['event'] ?? null) ? $audit['event'] : null,
    ];
}
