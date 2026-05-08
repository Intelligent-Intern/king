<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';
require_once __DIR__ . '/../calls/call_management_contract.php';
require_once __DIR__ . '/../calls/call_access_decision.php';
require_once __DIR__ . '/../calls/invite_code_contract.php';
require_once __DIR__ . '/realtime_connection_contract.php';
require_once __DIR__ . '/realtime_presence.php';
require_once __DIR__ . '/realtime_call_roles.php';
require_once __DIR__ . '/realtime_lobby_participants.php';

function videochat_realtime_connection_tenant_id(array $connection): ?int
{
    $tenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : 0;
    return $tenantId > 0 ? $tenantId : null;
}

function videochat_realtime_auth_tenant_id(array $authContext): ?int
{
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    if ($tenantId <= 0) {
        $user = is_array($authContext['user'] ?? null) ? $authContext['user'] : [];
        $userTenant = is_array($user['tenant'] ?? null) ? $user['tenant'] : [];
        $tenantId = (int) ($userTenant['id'] ?? ($userTenant['tenant_id'] ?? 0));
    }

    return $tenantId > 0 ? $tenantId : null;
}

function videochat_realtime_room_has_active_call(
    PDO $pdo,
    string $roomId,
    string $preferredCallId = '',
    ?int $tenantId = null
): bool {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '' || $normalizedRoomId === videochat_realtime_waiting_room_id()) {
        return false;
    }

    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
        ? '  AND calls.tenant_id = :tenant_id'
        : '';
    $callWhere = $normalizedPreferredCallId !== '' ? '  AND calls.id = :call_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT 1
FROM calls
WHERE calls.room_id = :room_id
{$tenantWhere}
{$callWhere}
  AND calls.status IN ('active', 'scheduled')
LIMIT 1
SQL
    );
    $params = [':room_id' => $normalizedRoomId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    if ($callWhere !== '') {
        $params[':call_id'] = $normalizedPreferredCallId;
    }
    $query->execute($params);

    return (bool) $query->fetchColumn();
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

function videochat_realtime_mark_call_participant_removed_from_active_call(
    callable $openDatabase,
    string $callId,
    int $userId
): bool {
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedCallId === '' || $userId <= 0) {
        return false;
    }

    try {
        $pdo = $openDatabase();
        $leftAt = gmdate('c');
        $statement = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'invited',
    left_at = CASE
        WHEN joined_at IS NOT NULL AND left_at IS NULL THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $statement->execute([
            ':left_at' => $leftAt,
            ':call_id' => $normalizedCallId,
            ':user_id' => $userId,
        ]);

        return $statement->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}

function videochat_realtime_mark_call_participant_pending_for_queue(
    callable $openDatabase,
    array $connection
): bool {
    return videochat_realtime_upsert_pending_lobby_participant($openDatabase, $connection);
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
            (string) ($connection['role'] ?? 'user'),
            videochat_realtime_connection_tenant_id($connection)
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

function videochat_realtime_connection_removed_from_active_call(array $connection): bool
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    if ($roomId === '' || $roomId === 'lobby' || $roomId === videochat_realtime_waiting_room_id()) {
        return false;
    }

    if (videochat_realtime_connection_call_id($connection) === '') {
        return false;
    }

    $inviteState = videochat_realtime_normalize_call_invite_state($connection['invite_state'] ?? 'invited');
    $leftAt = trim((string) ($connection['left_at'] ?? ''));
    return $leftAt !== '' && !in_array($inviteState, ['allowed', 'accepted'], true);
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
    string $requestedCallId = '',
    ?int $tenantId = null
): bool {
    if ($userId <= 0) {
        return false;
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
    $requestedCallId = videochat_realtime_normalize_call_id((string) ($connection['requested_call_id'] ?? ''), '');
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
            $requestedCallId,
            $connectionRole,
            videochat_realtime_connection_tenant_id($connection)
        );
    } catch (Throwable) {
        return false;
    }

    return videochat_realtime_call_context_allows_admission_bypass($context);
}

function videochat_realtime_connection_can_join_call_scoped_room(
    array $connection,
    string $roomId,
    callable $openDatabase
): bool {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return false;
    }
    if ($normalizedRoomId === 'lobby' || $normalizedRoomId === videochat_realtime_waiting_room_id()) {
        return true;
    }

    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    if ($currentRoomId === $normalizedRoomId) {
        return true;
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_realtime_connection_tenant_id($connection);
        if (!is_array(videochat_fetch_active_room_context($pdo, $normalizedRoomId, $tenantId))) {
            return false;
        }

        if (!videochat_realtime_room_has_active_call($pdo, $normalizedRoomId, '', $tenantId)) {
            return true;
        }
    } catch (Throwable) {
        return false;
    }

    return videochat_realtime_connection_can_bypass_admission_for_room($connection, $normalizedRoomId, $openDatabase);
}

function videochat_realtime_room_resolution_requires_authoritative_backfill(string $roomId, string $callId): bool
{
    return videochat_presence_normalize_room_id($roomId, '') !== ''
        || videochat_realtime_normalize_call_id($callId, '') !== '';
}

/**
 * @return array{ok: false, initial_room_id: string, requested_room_id: string, pending_room_id: string, reason: string, retryable: bool}
 */
function videochat_realtime_room_resolution_backfill_unavailable(string $reason = 'realtime_backfill_unavailable'): array
{
    return [
        'ok' => false,
        'initial_room_id' => videochat_realtime_waiting_room_id(),
        'requested_room_id' => '',
        'pending_room_id' => '',
        'reason' => trim($reason) === '' ? 'realtime_backfill_unavailable' : trim($reason),
        'retryable' => true,
    ];
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
    $tenantId = videochat_realtime_auth_tenant_id($websocketAuth);
    $requiresAuthoritativeBackfill = videochat_realtime_room_resolution_requires_authoritative_backfill(
        $requestedRoomInput,
        $normalizedRequestedCallId
    );
    try {
        $pdo = $openDatabase();
        $resolvedRoom = videochat_fetch_active_room_context($pdo, $resolvedRequestedRoomId, $tenantId);
        if ($resolvedRoom === null) {
            $resolvedRoom = videochat_fetch_active_room_context($pdo, 'lobby', $tenantId);
        }
        if (is_array($resolvedRoom) && is_string($resolvedRoom['id'] ?? null)) {
            $resolvedRequestedRoomId = videochat_presence_normalize_room_id((string) $resolvedRoom['id']);
        }
    } catch (Throwable) {
        if ($requiresAuthoritativeBackfill) {
            return videochat_realtime_room_resolution_backfill_unavailable();
        }
        $resolvedRequestedRoomId = 'lobby';
    }

    $user = is_array($websocketAuth['user'] ?? null) ? $websocketAuth['user'] : [];
    $userId = (int) ($user['id'] ?? 0);
    $userRole = (string) ($user['role'] ?? 'user');
    $sessionId = videochat_realtime_session_id_from_auth($websocketAuth);
    $boundOpenAccessSession = false;
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
                $boundOpenAccessSession = (string) ($accessBinding['link_kind'] ?? '') === 'open';
                $tenantId = null;
            }
        } catch (Throwable) {
            return videochat_realtime_room_resolution_backfill_unavailable('access_session_binding_unavailable') + ['access_session_binding' => 'unavailable'];
        }
    }

    $canBypassLobby = false;
    if ($userId > 0) {
        try {
            $pdo = $openDatabase();
            $context = videochat_realtime_call_role_context_for_room_user(
                $pdo,
                $resolvedRequestedRoomId,
                $userId,
                $normalizedRequestedCallId,
                $userRole,
                $tenantId
            );
            $canBypassLobby = videochat_realtime_call_context_allows_admission_bypass($context);
            if ($boundOpenAccessSession) {
                $decision = videochat_decide_call_access_for_user($pdo, $normalizedRequestedCallId, $userId, $userRole, $tenantId);
                $directSources = ['system_admin', 'owner', 'organization_admin', 'internal_participant'];
                $canBypassLobby = $canBypassLobby && in_array((string) ($decision['source'] ?? ''), $directSources, true);
            }
        } catch (Throwable) {
            if ($requiresAuthoritativeBackfill) {
                return videochat_realtime_room_resolution_backfill_unavailable();
            }
            $canBypassLobby = false;
        }
    }

    if ($canBypassLobby) {
        return [
            'ok' => true,
            'initial_room_id' => $resolvedRequestedRoomId,
            'requested_room_id' => $resolvedRequestedRoomId,
            'pending_room_id' => '',
        ];
    }

    return [
        'ok' => true,
        'initial_room_id' => videochat_realtime_waiting_room_id(),
        'requested_room_id' => $resolvedRequestedRoomId,
        'pending_room_id' => $resolvedRequestedRoomId,
    ];
}
