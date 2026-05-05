<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

/**
 * @return array{
 *   call_id: string,
 *   call_role: string,
 *   effective_call_role: string,
 *   invite_state: string,
 *   joined_at: string,
 *   left_at: string,
 *   can_moderate: bool,
 *   can_manage_owner: bool
 * }
 */
function videochat_realtime_call_role_context_for_room_user(
    PDO $pdo,
    string $roomId,
    int $userId,
    string $preferredCallId = '',
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $isAdmin = videochat_normalize_role_slug($authRole) === 'admin';
    if ($normalizedPreferredCallId !== '' && $normalizedRoomId !== '' && $userId > 0) {
        $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
            ? '  AND calls.tenant_id = :tenant_id'
            : '';
        $preferredQuery = $pdo->prepare(
            <<<SQL
SELECT
    calls.id,
    calls.owner_user_id,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  AND calls.room_id = :room_id
{$tenantWhere}
  AND calls.status IN ('active', 'scheduled')
  AND (
      CAST(:is_admin AS INTEGER) = 1
      OR
      calls.owner_user_id = :user_id
      OR cp.user_id IS NOT NULL
  )
LIMIT 1
SQL
        );
        $params = [
            ':call_id' => $normalizedPreferredCallId,
            ':room_id' => $normalizedRoomId,
            ':user_id' => $userId,
            ':is_admin' => $isAdmin ? 1 : 0,
        ];
        if ($tenantWhere !== '') {
            $params[':tenant_id'] = $tenantId;
        }
        $preferredQuery->execute($params);
        $preferredRow = $preferredQuery->fetch();
        if (is_array($preferredRow)) {
            $callRole = videochat_normalize_call_participant_role((string) ($preferredRow['call_role'] ?? 'participant'));
            if ((int) ($preferredRow['owner_user_id'] ?? 0) === $userId) {
                $callRole = 'owner';
            }
            $effectiveCallRole = $isAdmin ? 'owner' : $callRole;

            return [
                'call_id' => (string) ($preferredRow['id'] ?? ''),
                'call_role' => $callRole,
                'effective_call_role' => $effectiveCallRole,
                'invite_state' => videochat_realtime_normalize_call_invite_state($preferredRow['invite_state'] ?? 'invited'),
                'joined_at' => trim((string) ($preferredRow['joined_at'] ?? '')),
                'left_at' => trim((string) ($preferredRow['left_at'] ?? '')),
                'can_moderate' => $isAdmin || in_array($callRole, ['owner', 'moderator'], true),
                'can_manage_owner' => $isAdmin || $callRole === 'owner',
            ];
        }
    }

    return videochat_call_role_context_for_room_user($pdo, $roomId, $userId);
}

/**
 * @param array<int, string> $fromStates
 */
function videochat_realtime_mark_call_participant_invite_state_by_user_id(
    callable $openDatabase,
    string $callId,
    int $userId,
    string $nextState,
    array $fromStates = []
): bool {
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    $normalizedNextState = videochat_realtime_normalize_call_invite_state($nextState, '');
    if ($normalizedCallId === '' || $userId <= 0 || $normalizedNextState === '') {
        return false;
    }

    $normalizedFromStates = [];
    foreach ($fromStates as $state) {
        $normalizedState = videochat_realtime_normalize_call_invite_state($state, '');
        if ($normalizedState !== '') {
            $normalizedFromStates[$normalizedState] = $normalizedState;
        }
    }

    try {
        $pdo = $openDatabase();
        $whereFrom = '';
        $params = [
            ':next_state' => $normalizedNextState,
            ':call_id' => $normalizedCallId,
            ':user_id' => $userId,
        ];
        if ($normalizedFromStates !== []) {
            $placeholders = [];
            $index = 0;
            foreach ($normalizedFromStates as $state) {
                $placeholder = ':from_state_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $state;
                $index++;
            }
            $whereFrom = ' AND invite_state IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $pdo->prepare(
            <<<SQL
UPDATE call_participants
SET invite_state = :next_state
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
{$whereFrom}
SQL
        );
        $statement->execute($params);

        return $statement->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<int, string> $fromStates
 */
function videochat_realtime_mark_call_participant_invite_state(
    callable $openDatabase,
    array $connection,
    string $nextState,
    array $fromStates = []
): bool {
    return videochat_realtime_mark_call_participant_invite_state_by_user_id(
        $openDatabase,
        videochat_realtime_connection_call_id($connection),
        (int) ($connection['user_id'] ?? 0),
        $nextState,
        $fromStates
    );
}

function videochat_realtime_mark_call_participant_pending_for_queue(
    callable $openDatabase,
    array $connection
): bool {
    $callId = videochat_realtime_connection_call_id($connection);
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($callId === '' || $userId <= 0) {
        return false;
    }

    try {
        $pdo = $openDatabase();
        $statement = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending',
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
  AND invite_state IN ('invited', 'declined', 'cancelled')
SQL
        );
        $statement->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

function videochat_realtime_mark_call_participant_joined(callable $openDatabase, array $connection): void
{
    $target = videochat_realtime_call_presence_target($connection);
    $callId = (string) ($target['call_id'] ?? '');
    $userId = (int) ($target['user_id'] ?? 0);
    if ($callId === '' || $userId <= 0) {
        return;
    }

    $joinedAt = gmdate('c');
    $callRole = videochat_normalize_call_participant_role((string) ($target['call_role'] ?? 'participant'));

    try {
        $pdo = $openDatabase();
        videochat_realtime_presence_db_bootstrap($pdo);
        $updateParticipant = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET joined_at = :joined_at,
    left_at = NULL,
    invite_state = CASE
        WHEN invite_state IN ('invited', 'pending', 'accepted', 'declined', 'cancelled') THEN 'allowed'
        ELSE invite_state
    END,
    call_role = CASE
        WHEN call_role = 'owner' THEN 'owner'
        WHEN :call_role = 'owner' THEN 'owner'
        ELSE :call_role
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $updateParticipant->execute([
            ':joined_at' => $joinedAt,
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':call_role' => $callRole,
        ]);

        if ($updateParticipant->rowCount() > 0) {
            videochat_realtime_presence_db_upsert($pdo, $connection);
            return;
        }

        $existingParticipant = $pdo->prepare(
            <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
        );
        $existingParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
        ]);
        if ((int) ($existingParticipant->fetchColumn() ?: 0) > 0) {
            videochat_realtime_presence_db_upsert($pdo, $connection);
            return;
        }

        $identityQuery = $pdo->prepare(
            <<<'SQL'
SELECT email, display_name
FROM users
WHERE id = :user_id
LIMIT 1
SQL
        );
        $identityQuery->execute([
            ':user_id' => $userId,
        ]);
        $identity = $identityQuery->fetch();
        if (!is_array($identity)) {
            return;
        }

        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        if ($email === '') {
            return;
        }
        $displayName = trim((string) ($identity['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $email;
        }

        $upsertParticipant = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(
    call_id,
    user_id,
    email,
    display_name,
    source,
    call_role,
    invite_state,
    joined_at,
    left_at
) VALUES (
    :call_id,
    :user_id,
    :email,
    :display_name,
    'internal',
    :call_role,
    'allowed',
    :joined_at,
    NULL
)
ON CONFLICT(call_id, email) DO UPDATE SET
    user_id = excluded.user_id,
    display_name = excluded.display_name,
    source = 'internal',
    call_role = CASE
        WHEN call_participants.call_role = 'owner' THEN 'owner'
        WHEN excluded.call_role = 'owner' THEN 'owner'
        ELSE excluded.call_role
    END,
    invite_state = 'allowed',
    joined_at = excluded.joined_at,
    left_at = NULL
SQL
        );
        $upsertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':call_role' => $callRole,
            ':joined_at' => $joinedAt,
        ]);
        videochat_realtime_presence_db_upsert($pdo, $connection);
    } catch (Throwable) {
        return;
    }
}

function videochat_realtime_mark_call_participant_left(
    callable $openDatabase,
    array $connection,
    array $presenceState
): void {
    $target = videochat_realtime_call_presence_target($connection);
    $callId = (string) ($target['call_id'] ?? '');
    $roomId = (string) ($target['room_id'] ?? '');
    $userId = (int) ($target['user_id'] ?? 0);
    if ($callId === '' || $roomId === '' || $userId <= 0) {
        return;
    }

    if (videochat_realtime_presence_has_room_membership($presenceState, $roomId, $userId)) {
        return;
    }

    try {
        $pdo = $openDatabase();
        videochat_realtime_presence_db_bootstrap($pdo);
        if (videochat_realtime_presence_db_has_room_membership($pdo, $roomId, $callId, $userId)) {
            return;
        }

        $leftAt = gmdate('c');
        $markLeft = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET left_at = :left_at
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
  AND joined_at IS NOT NULL
  AND left_at IS NULL
SQL
        );
        $markLeft->execute([
            ':left_at' => $leftAt,
            ':call_id' => $callId,
            ':user_id' => $userId,
        ]);
    } catch (Throwable) {
        return;
    }
}

function videochat_realtime_connection_with_call_context(array $connection, callable $openDatabase): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $requestedCallId = videochat_realtime_normalize_call_id((string) ($connection['requested_call_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    if ($roomId === videochat_realtime_waiting_room_id() && $pendingRoomId !== '') {
        $roomId = $pendingRoomId;
    }
    $userId = (int) ($connection['user_id'] ?? 0);
    $fallbackContext = [
        'call_id' => '',
        'call_role' => 'participant',
        'effective_call_role' => 'participant',
        'invite_state' => 'invited',
        'joined_at' => '',
        'left_at' => '',
        'can_moderate' => false,
        'can_manage_owner' => false,
    ];

    try {
        $pdo = $openDatabase();
        $resolved = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $roomId,
            $userId,
            $requestedCallId,
            (string) ($connection['role'] ?? 'user')
        );
        if (!is_array($resolved)) {
            $resolved = $fallbackContext;
        }
    } catch (Throwable) {
        $resolved = $fallbackContext;
    }

    $connection['requested_call_id'] = $requestedCallId;
    $connection['active_call_id'] = (string) ($resolved['call_id'] ?? '');
    $connection['call_role'] = videochat_normalize_call_participant_role((string) ($resolved['call_role'] ?? 'participant'));
    $connection['effective_call_role'] = videochat_normalize_call_participant_role(
        (string) ($resolved['effective_call_role'] ?? $connection['call_role'])
    );
    $connection['invite_state'] = videochat_realtime_normalize_call_invite_state($resolved['invite_state'] ?? 'invited');
    $connection['joined_at'] = trim((string) ($resolved['joined_at'] ?? ''));
    $connection['left_at'] = trim((string) ($resolved['left_at'] ?? ''));
    $connection['can_moderate_call'] = videochat_normalize_role_slug((string) ($connection['role'] ?? '')) === 'admin'
        || (bool) ($resolved['can_moderate'] ?? false);
    $connection['can_manage_call_owner'] = videochat_normalize_role_slug((string) ($connection['role'] ?? '')) === 'admin'
        || (bool) ($resolved['can_manage_owner'] ?? false);

    return $connection;
}

function videochat_realtime_call_context_allows_admission_bypass(array $context): bool
{
    if ((bool) ($context['can_moderate'] ?? false)) {
        return true;
    }

    $inviteState = videochat_realtime_normalize_call_invite_state($context['invite_state'] ?? 'invited');
    if (!in_array($inviteState, ['allowed', 'accepted'], true)) {
        return false;
    }

    return true;
}

function videochat_realtime_is_user_moderator_for_room(
    callable $openDatabase,
    int $userId,
    string $role,
    string $roomId,
    string $requestedCallId = ''
): bool {
    if ($userId <= 0) {
        return false;
    }

    if (videochat_normalize_role_slug($role) === 'admin') {
        return true;
    }

    try {
        $pdo = $openDatabase();
        $context = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $roomId,
            $userId,
            videochat_realtime_normalize_call_id($requestedCallId, '')
        );
    } catch (Throwable) {
        return false;
    }

    return (bool) ($context['can_moderate'] ?? false);
}

function videochat_realtime_user_has_sfu_room_admission(
    callable $openDatabase,
    int $userId,
    string $role,
    string $roomId,
    string $requestedCallId = '',
    ?int $tenantId = null
): bool {
    if ($userId <= 0) {
        return false;
    }

    if (videochat_normalize_role_slug($role) === 'admin') {
        return true;
    }

    try {
        $pdo = $openDatabase();
        $context = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $roomId,
            $userId,
            videochat_realtime_normalize_call_id($requestedCallId, ''),
            $role,
            $tenantId
        );
    } catch (Throwable) {
        return false;
    }

    return videochat_realtime_call_context_allows_admission_bypass($context);
}

function videochat_realtime_connection_can_bypass_admission_for_room(
    array $connection,
    string $roomId,
    callable $openDatabase
): bool {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '' || $normalizedRoomId === videochat_realtime_waiting_room_id()) {
        return false;
    }

    $connectionRole = videochat_normalize_role_slug((string) ($connection['role'] ?? ''));
    if ($connectionRole === 'admin') {
        return true;
    }

    $connectionCallRole = videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant'));
    $requestedCallId = videochat_realtime_normalize_call_id((string) ($connection['requested_call_id'] ?? ''), '');
    $requestedRoomId = videochat_presence_normalize_room_id((string) ($connection['requested_room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    if (
        in_array($connectionCallRole, ['owner', 'moderator'], true)
        && ($requestedRoomId === $normalizedRoomId || $pendingRoomId === $normalizedRoomId)
    ) {
        return true;
    }

    $connectionInviteState = videochat_realtime_normalize_call_invite_state($connection['invite_state'] ?? 'invited');
    if (
        videochat_realtime_call_context_allows_admission_bypass([
            'invite_state' => $connectionInviteState,
            'joined_at' => trim((string) ($connection['joined_at'] ?? '')),
            'left_at' => trim((string) ($connection['left_at'] ?? '')),
            'can_moderate' => false,
        ])
        && ($requestedRoomId === $normalizedRoomId || $pendingRoomId === $normalizedRoomId)
    ) {
        return true;
    }

    $connectionUserId = (int) ($connection['user_id'] ?? 0);
    if ($connectionUserId <= 0) {
        return false;
    }

    try {
        $pdo = $openDatabase();
        $context = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $normalizedRoomId,
            $connectionUserId,
            $requestedCallId
        );
    } catch (Throwable) {
        return false;
    }

    return videochat_realtime_call_context_allows_admission_bypass($context);
}

/**
 * @return array{initial_room_id: string, requested_room_id: string, pending_room_id: string}
 */
function videochat_realtime_resolve_connection_rooms(
    array $websocketAuth,
    string $requestedRoomId,
    callable $openDatabase,
    string $requestedCallId = ''
): array {
    $resolvedRequestedRoomId = videochat_presence_normalize_room_id($requestedRoomId);
    $normalizedRequestedCallId = videochat_realtime_normalize_call_id($requestedCallId, '');
    $requestedRoomInput = videochat_presence_normalize_room_id($requestedRoomId, '');
    try {
        $pdo = $openDatabase();
        $resolvedRoom = videochat_fetch_active_room_context($pdo, $resolvedRequestedRoomId);
        if ($resolvedRoom === null) {
            $resolvedRoom = videochat_fetch_active_room_context($pdo, 'lobby');
        }
        if (is_array($resolvedRoom) && is_string($resolvedRoom['id'] ?? null)) {
            $resolvedRequestedRoomId = videochat_presence_normalize_room_id((string) $resolvedRoom['id']);
        }
    } catch (Throwable) {
        $resolvedRequestedRoomId = 'lobby';
    }

    $user = is_array($websocketAuth['user'] ?? null) ? $websocketAuth['user'] : [];
    $userId = (int) ($user['id'] ?? 0);
    $userRole = (string) ($user['role'] ?? 'user');
    $sessionId = videochat_realtime_session_id_from_auth($websocketAuth);
    if ($sessionId !== '') {
        try {
            $pdo = $openDatabase();
            $accessBinding = videochat_fetch_call_access_session_binding($pdo, $sessionId);
            if (is_array($accessBinding)) {
                $boundRoomId = videochat_presence_normalize_room_id((string) ($accessBinding['room_id'] ?? ''), '');
                $boundCallId = videochat_realtime_normalize_call_id((string) ($accessBinding['call_id'] ?? ''), '');
                $boundUserId = (int) ($accessBinding['user_id'] ?? 0);
                $roomMismatch = $requestedRoomInput !== '' && $requestedRoomInput !== $boundRoomId;
                $callMismatch = $normalizedRequestedCallId !== '' && $normalizedRequestedCallId !== $boundCallId;
                $userMismatch = $userId > 0 && $boundUserId > 0 && $userId !== $boundUserId;

                if ($boundRoomId === '' || $boundCallId === '' || $roomMismatch || $callMismatch || $userMismatch) {
                    return [
                        'initial_room_id' => videochat_realtime_waiting_room_id(),
                        'requested_room_id' => '',
                        'pending_room_id' => '',
                        'access_session_binding' => 'mismatch',
                    ];
                }

                $resolvedRequestedRoomId = $boundRoomId;
                $normalizedRequestedCallId = $boundCallId;
            }
        } catch (Throwable) {
            return [
                'initial_room_id' => videochat_realtime_waiting_room_id(),
                'requested_room_id' => '',
                'pending_room_id' => '',
                'access_session_binding' => 'unavailable',
            ];
        }
    }

    $canBypassLobby = videochat_normalize_role_slug($userRole) === 'admin';
    if (!$canBypassLobby && $userId > 0) {
        try {
            $pdo = $openDatabase();
            $context = videochat_realtime_call_role_context_for_room_user(
                $pdo,
                $resolvedRequestedRoomId,
                $userId,
                $normalizedRequestedCallId
            );
            $canBypassLobby = videochat_realtime_call_context_allows_admission_bypass($context);
        } catch (Throwable) {
            $canBypassLobby = false;
        }
    }

    if ($canBypassLobby) {
        return [
            'initial_room_id' => $resolvedRequestedRoomId,
            'requested_room_id' => $resolvedRequestedRoomId,
            'pending_room_id' => '',
        ];
    }

    return [
        'initial_room_id' => videochat_realtime_waiting_room_id(),
        'requested_room_id' => $resolvedRequestedRoomId,
        'pending_room_id' => $resolvedRequestedRoomId,
    ];
}
