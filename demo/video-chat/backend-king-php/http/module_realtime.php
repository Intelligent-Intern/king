<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/chat_attachments.php';
require_once __DIR__ . '/../domain/realtime/chat_archive.php';
require_once __DIR__ . '/../domain/realtime/realtime_activity_layout.php';

function videochat_realtime_normalize_ws_path(string $wsPath): string
{
    $normalized = trim($wsPath);
    if ($normalized === '') {
        return '/ws';
    }

    return str_starts_with($normalized, '/') ? $normalized : ('/' . $normalized);
}

function videochat_realtime_connection_has_upgrade_token(string $connectionHeader): bool
{
    $tokens = preg_split('/\s*,\s*/', strtolower(trim($connectionHeader))) ?: [];
    foreach ($tokens as $token) {
        if ($token === 'upgrade') {
            return true;
        }
    }

    return false;
}

function videochat_realtime_waiting_room_id(): string
{
    return 'waiting-room';
}

function videochat_realtime_normalize_call_id(string $callId, string $fallback = ''): string
{
    $normalized = trim($callId);
    if ($normalized !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/', $normalized) === 1) {
        return $normalized;
    }

    $fallbackNormalized = trim($fallback);
    if ($fallbackNormalized !== '' && preg_match('/^[A-Za-z0-9._-]{1,200}$/', $fallbackNormalized) === 1) {
        return $fallbackNormalized;
    }

    return '';
}

function videochat_realtime_normalize_call_invite_state(mixed $value, string $fallback = 'invited'): string
{
    $fallbackNormalized = strtolower(trim($fallback));
    if ($fallbackNormalized !== '' && !in_array($fallbackNormalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        $fallbackNormalized = 'invited';
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        return $normalized;
    }

    return $fallbackNormalized;
}

/**
 * @return array{
 *   call_id: string,
 *   call_role: string,
 *   invite_state: string,
 *   joined_at: string,
 *   left_at: string,
 *   can_moderate: bool
 * }
 */
function videochat_realtime_call_role_context_for_room_user(
    PDO $pdo,
    string $roomId,
    int $userId,
    string $preferredCallId = ''
): array {
    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedPreferredCallId !== '' && $normalizedRoomId !== '' && $userId > 0) {
        $preferredQuery = $pdo->prepare(
            <<<'SQL'
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
  AND calls.status IN ('active', 'scheduled')
  AND (
      calls.owner_user_id = :user_id
      OR cp.user_id IS NOT NULL
  )
LIMIT 1
SQL
        );
        $preferredQuery->execute([
            ':call_id' => $normalizedPreferredCallId,
            ':room_id' => $normalizedRoomId,
            ':user_id' => $userId,
        ]);
        $preferredRow = $preferredQuery->fetch();
        if (is_array($preferredRow)) {
            $callRole = videochat_normalize_call_participant_role((string) ($preferredRow['call_role'] ?? 'participant'));
            if ((int) ($preferredRow['owner_user_id'] ?? 0) === $userId) {
                $callRole = 'owner';
            }

            return [
                'call_id' => (string) ($preferredRow['id'] ?? ''),
                'call_role' => $callRole,
                'invite_state' => videochat_realtime_normalize_call_invite_state($preferredRow['invite_state'] ?? 'invited'),
                'joined_at' => trim((string) ($preferredRow['joined_at'] ?? '')),
                'left_at' => trim((string) ($preferredRow['left_at'] ?? '')),
                'can_moderate' => in_array($callRole, ['owner', 'moderator'], true),
            ];
        }
    }

    return videochat_call_role_context_for_room_user($pdo, $roomId, $userId);
}

/**
 * @return array{
 *   call_id: string,
 *   room_id: string,
 *   user_id: int,
 *   call_role: string
 * }
 */
function videochat_realtime_call_presence_target(array $connection): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = strtolower(trim((string) ($connection['active_call_id'] ?? '')));
    $userId = (int) ($connection['user_id'] ?? 0);
    $callRole = videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant'));

    if ($roomId === '' || $roomId === videochat_realtime_waiting_room_id() || $callId === '' || $userId <= 0) {
        return [
            'call_id' => '',
            'room_id' => '',
            'user_id' => 0,
            'call_role' => 'participant',
        ];
    }

    return [
        'call_id' => $callId,
        'room_id' => $roomId,
        'user_id' => $userId,
        'call_role' => $callRole,
    ];
}

function videochat_realtime_connection_call_id(array $connection): string
{
    $activeCallId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
    if ($activeCallId !== '') {
        return $activeCallId;
    }

    return videochat_realtime_normalize_call_id((string) ($connection['requested_call_id'] ?? ''), '');
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
  AND (
      invite_state IN ('invited', 'declined', 'cancelled')
      OR (
          invite_state IN ('allowed', 'accepted')
          AND joined_at IS NOT NULL
          AND left_at IS NOT NULL
      )
  )
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
        $leftAt = gmdate('c');
        $markLeft = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET left_at = :left_at,
    invite_state = CASE
        WHEN call_role IN ('owner', 'moderator') THEN invite_state
        WHEN invite_state IN ('allowed', 'accepted') THEN 'invited'
        ELSE invite_state
    END
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
        'invite_state' => 'invited',
        'joined_at' => '',
        'left_at' => '',
        'can_moderate' => false,
    ];

    try {
        $pdo = $openDatabase();
        $resolved = videochat_realtime_call_role_context_for_room_user($pdo, $roomId, $userId, $requestedCallId);
        if (!is_array($resolved)) {
            $resolved = $fallbackContext;
        }
    } catch (Throwable) {
        $resolved = $fallbackContext;
    }

    $connection['requested_call_id'] = $requestedCallId;
    $connection['active_call_id'] = (string) ($resolved['call_id'] ?? '');
    $connection['call_role'] = videochat_normalize_call_participant_role((string) ($resolved['call_role'] ?? 'participant'));
    $connection['invite_state'] = videochat_realtime_normalize_call_invite_state($resolved['invite_state'] ?? 'invited');
    $connection['joined_at'] = trim((string) ($resolved['joined_at'] ?? ''));
    $connection['left_at'] = trim((string) ($resolved['left_at'] ?? ''));
    $connection['can_moderate_call'] = (bool) ($resolved['can_moderate'] ?? false);

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

    $joinedAt = trim((string) ($context['joined_at'] ?? ''));
    $leftAt = trim((string) ($context['left_at'] ?? ''));
    return !($joinedAt !== '' && $leftAt !== '');
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

function videochat_realtime_presence_has_room_membership(
    array $presenceState,
    string $roomId,
    int $userId,
    string $sessionId = ''
): bool {
    if ($userId <= 0) {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return false;
    }

    $trimmedSessionId = trim($sessionId);
    foreach (($presenceState['connections'] ?? []) as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        if ((int) ($connection['user_id'] ?? 0) !== $userId) {
            continue;
        }
        if (videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '') !== $normalizedRoomId) {
            continue;
        }
        if ($trimmedSessionId !== '' && trim((string) ($connection['session_id'] ?? '')) !== $trimmedSessionId) {
            continue;
        }

        return true;
    }

    return false;
}

function videochat_realtime_send_lobby_snapshot_to_users(
    array $presenceState,
    array $lobbyState,
    string $roomId,
    array $userIds,
    string $reason = 'admitted',
    ?callable $sender = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $normalizedUserIds = [];
    foreach ($userIds as $userId) {
        $normalizedUserId = (int) $userId;
        if ($normalizedUserId > 0) {
            $normalizedUserIds[$normalizedUserId] = true;
        }
    }
    if ($normalizedUserIds === []) {
        return 0;
    }

    $payload = videochat_lobby_snapshot_payload($lobbyState, $normalizedRoomId, $reason);
    $sentCount = 0;
    foreach (($presenceState['connections'] ?? []) as $connection) {
        if (!is_array($connection)) {
            continue;
        }

        $connectionUserId = (int) ($connection['user_id'] ?? 0);
        if ($connectionUserId <= 0 || !isset($normalizedUserIds[$connectionUserId])) {
            continue;
        }

        if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
            $sentCount++;
        }
    }

    return $sentCount;
}

/**
 * @return array{room_id: string, call_id: string, queue_count: int, admitted_count: int, changed: bool, ok: bool}
 */
function videochat_realtime_sync_lobby_room_from_database(
    array &$lobbyState,
    callable $openDatabase,
    string $roomId,
    string $preferredCallId = '',
    ?int $nowUnixMs = null
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $fallback = [
        'room_id' => $normalizedRoomId,
        'call_id' => '',
        'queue_count' => 0,
        'admitted_count' => 0,
        'changed' => false,
        'ok' => false,
    ];
    if ($normalizedRoomId === '' || $normalizedRoomId === videochat_realtime_waiting_room_id()) {
        return $fallback;
    }

    try {
        $pdo = $openDatabase();

        $callWhere = 'WHERE calls.room_id = :room_id AND calls.status IN (\'active\', \'scheduled\')';
        $callParams = [':room_id' => $normalizedRoomId];
        if ($normalizedPreferredCallId !== '') {
            $callWhere .= ' AND calls.id = :call_id';
            $callParams[':call_id'] = $normalizedPreferredCallId;
        }

        $callQuery = $pdo->prepare(
            <<<SQL
SELECT calls.id
FROM calls
{$callWhere}
ORDER BY
    CASE calls.status
        WHEN 'active' THEN 0
        ELSE 1
    END ASC,
    calls.starts_at ASC,
    calls.created_at ASC
LIMIT 1
SQL
        );
        $callQuery->execute($callParams);
        $callId = (string) ($callQuery->fetchColumn() ?: '');
        if ($callId === '') {
            return $fallback;
        }

        $previousRoomState = is_array($lobbyState['rooms'][$normalizedRoomId] ?? null)
            ? $lobbyState['rooms'][$normalizedRoomId]
            : [];
        $previousQueuedByUser = is_array($previousRoomState['queued_by_user'] ?? null)
            ? $previousRoomState['queued_by_user']
            : [];
        $previousAdmittedByUser = is_array($previousRoomState['admitted_by_user'] ?? null)
            ? $previousRoomState['admitted_by_user']
            : [];

        $rowQuery = $pdo->prepare(
            <<<'SQL'
SELECT
    cp.user_id,
    cp.email,
    cp.display_name AS participant_display_name,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at,
    users.display_name AS user_display_name,
    roles.slug AS role_slug
FROM call_participants cp
LEFT JOIN users ON users.id = cp.user_id
LEFT JOIN roles ON roles.id = users.role_id
WHERE cp.call_id = :call_id
  AND cp.source = 'internal'
  AND cp.user_id IS NOT NULL
  AND cp.invite_state IN ('pending', 'allowed', 'accepted')
ORDER BY
    cp.display_name ASC,
    cp.user_id ASC
SQL
        );
        $rowQuery->execute([':call_id' => $callId]);

        $nowMs = videochat_lobby_now_ms($nowUnixMs);
        $queuedByUser = [];
        $admittedByUser = [];
        while (($row = $rowQuery->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $inviteState = videochat_realtime_normalize_call_invite_state($row['invite_state'] ?? 'invited');
            $displayName = trim((string) ($row['participant_display_name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string) ($row['user_display_name'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = trim((string) ($row['email'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = 'User ' . $userId;
            }

            $role = videochat_normalize_role_slug((string) ($row['role_slug'] ?? 'user'));
            if ($inviteState === 'pending') {
                $previous = is_array($previousQueuedByUser[$userId] ?? null) ? $previousQueuedByUser[$userId] : [];
                $requestedMs = (int) ($previous['requested_unix_ms'] ?? 0);
                if ($requestedMs <= 0) {
                    $requestedMs = $nowMs;
                }
                $queuedByUser[$userId] = [
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'role' => $role,
                    'requested_unix_ms' => $requestedMs,
                    'requested_at' => (string) ($previous['requested_at'] ?? gmdate('c', (int) floor($requestedMs / 1000))),
                ];
                continue;
            }

            $joinedAt = trim((string) ($row['joined_at'] ?? ''));
            $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
            if ($joinedAt !== '' || $callRole === 'owner') {
                continue;
            }

            $previous = is_array($previousAdmittedByUser[$userId] ?? null) ? $previousAdmittedByUser[$userId] : [];
            $admittedMs = (int) ($previous['admitted_unix_ms'] ?? 0);
            if ($admittedMs <= 0) {
                $admittedMs = $nowMs;
            }
            $admittedByUser[$userId] = [
                'user_id' => $userId,
                'display_name' => $displayName,
                'role' => $role,
                'admitted_unix_ms' => $admittedMs,
                'admitted_at' => (string) ($previous['admitted_at'] ?? gmdate('c', (int) floor($admittedMs / 1000))),
                'admitted_by' => is_array($previous['admitted_by'] ?? null) ? $previous['admitted_by'] : [],
            ];
        }

        $nextRoomState = [
            'queued_by_user' => $queuedByUser,
            'admitted_by_user' => $admittedByUser,
        ];
        $changed = $previousRoomState !== $nextRoomState;
        if ($queuedByUser === [] && $admittedByUser === []) {
            unset($lobbyState['rooms'][$normalizedRoomId]);
        } else {
            $lobbyState['rooms'][$normalizedRoomId] = $nextRoomState;
        }

        return [
            'room_id' => $normalizedRoomId,
            'call_id' => $callId,
            'queue_count' => count($queuedByUser),
            'admitted_count' => count($admittedByUser),
            'changed' => $changed,
            'ok' => true,
        ];
    } catch (Throwable) {
        return $fallback;
    }
}

function videochat_realtime_lobby_room_id_for_connection(array $connection): string
{
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');

    return $currentRoomId === videochat_realtime_waiting_room_id() && $pendingRoomId !== ''
        ? $pendingRoomId
        : videochat_presence_normalize_room_id($currentRoomId, 'lobby');
}

function videochat_realtime_lobby_snapshot_signature(array $payload): string
{
    $encoded = json_encode(
        [
            'room_id' => (string) ($payload['room_id'] ?? ''),
            'queue' => is_array($payload['queue'] ?? null) ? $payload['queue'] : [],
            'admitted' => is_array($payload['admitted'] ?? null) ? $payload['admitted'] : [],
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    return is_string($encoded) ? hash('sha1', $encoded) : '';
}

/**
 * @return array{sent: bool, room_id: string, signature: string}
 */
function videochat_realtime_send_synced_lobby_snapshot_to_connection(
    array &$lobbyState,
    array $connection,
    callable $openDatabase,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $roomId = videochat_realtime_lobby_room_id_for_connection($connection);
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        $nowUnixMs
    );

    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);
    $signature = videochat_realtime_lobby_snapshot_signature($payload);
    $sent = videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);

    return [
        'sent' => $sent,
        'room_id' => (string) ($payload['room_id'] ?? $roomId),
        'signature' => $signature,
    ];
}

function videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed(
    array &$lobbyState,
    array $connection,
    callable $openDatabase,
    string &$lastSignature,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): bool {
    $roomId = videochat_realtime_lobby_room_id_for_connection($connection);
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        $nowUnixMs
    );

    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);
    $signature = videochat_realtime_lobby_snapshot_signature($payload);
    if ($signature !== '' && $signature === $lastSignature) {
        return false;
    }

    $sent = videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
    if ($sent && $signature !== '') {
        $lastSignature = $signature;
    }

    return $sent;
}

/**
 * @param array<string, mixed> $request
 * @return array{
 *   ok: bool,
 *   status: int,
 *   code: string,
 *   message: string,
 *   details: array<string, mixed>
 * }
 */
function videochat_realtime_validate_websocket_handshake(array $request, string $wsPath): array
{
    $normalizedPath = videochat_realtime_normalize_ws_path($wsPath);
    $requestPath = $request['path'] ?? null;
    if (!is_string($requestPath) || $requestPath === '') {
        $uri = is_string($request['uri'] ?? null) ? (string) $request['uri'] : '';
        $requestPath = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    }
    $requestPath = trim((string) $requestPath);
    if ($requestPath === '') {
        $requestPath = '/';
    }

    if ($requestPath !== $normalizedPath) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake request path is invalid.',
            'details' => [
                'reason' => 'ws_path_mismatch',
                'expected_path' => $normalizedPath,
                'actual_path' => $requestPath,
            ],
        ];
    }

    $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
    if ($method !== 'GET') {
        return [
            'ok' => false,
            'status' => 405,
            'code' => 'websocket_invalid_method',
            'message' => 'WebSocket handshake requires GET.',
            'details' => [
                'reason' => 'invalid_method',
                'allowed_methods' => ['GET'],
                'method' => $method,
            ],
        ];
    }

    $upgrade = strtolower(videochat_request_header_value($request, 'upgrade'));
    if ($upgrade === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Upgrade header.',
            'details' => [
                'reason' => 'missing_upgrade_header',
                'required_upgrade' => 'websocket',
            ],
        ];
    }
    if ($upgrade !== 'websocket') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Upgrade header is invalid.',
            'details' => [
                'reason' => 'invalid_upgrade_header',
                'required_upgrade' => 'websocket',
                'upgrade' => $upgrade,
            ],
        ];
    }

    $connection = videochat_request_header_value($request, 'connection');
    if (!videochat_realtime_connection_has_upgrade_token($connection)) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Connection header must contain Upgrade.',
            'details' => [
                'reason' => 'missing_connection_upgrade_token',
            ],
        ];
    }

    $websocketKey = videochat_request_header_value($request, 'sec-websocket-key');
    if ($websocketKey === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Sec-WebSocket-Key header.',
            'details' => [
                'reason' => 'missing_sec_websocket_key',
            ],
        ];
    }
    $decodedKey = base64_decode($websocketKey, true);
    if (!is_string($decodedKey) || strlen($decodedKey) !== 16) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Sec-WebSocket-Key is invalid.',
            'details' => [
                'reason' => 'invalid_sec_websocket_key',
            ],
        ];
    }

    $websocketVersion = videochat_request_header_value($request, 'sec-websocket-version');
    if ($websocketVersion === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Sec-WebSocket-Version header.',
            'details' => [
                'reason' => 'missing_sec_websocket_version',
                'supported_versions' => ['13'],
            ],
        ];
    }
    if ($websocketVersion !== '13') {
        return [
            'ok' => false,
            'status' => 426,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake version is not supported.',
            'details' => [
                'reason' => 'unsupported_sec_websocket_version',
                'supported_versions' => ['13'],
                'version' => $websocketVersion,
            ],
        ];
    }

    return [
        'ok' => true,
        'status' => 0,
        'code' => '',
        'message' => '',
        'details' => [],
    ];
}

/**
 * @return array{close_code: int, close_reason: string, close_category: string}
 */
function videochat_realtime_close_descriptor_for_reason(string $reason): array
{
    $normalizedReason = strtolower(trim($reason));
    if ($normalizedReason === 'auth_backend_error') {
        return [
            'close_code' => 1011,
            'close_reason' => 'auth_backend_error',
            'close_category' => 'internal',
        ];
    }

    return [
        'close_code' => 1008,
        'close_reason' => 'session_invalidated',
        'close_category' => 'policy',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_session_probe_request(string $sessionId, string $wsPath): array
{
    $trimmedSessionId = trim($sessionId);
    $path = trim($wsPath) !== '' ? trim($wsPath) : '/ws';

    return [
        'method' => 'GET',
        'uri' => $path,
        'headers' => [
            'Authorization' => 'Bearer ' . $trimmedSessionId,
        ],
    ];
}

/**
 * @return array{ok: bool, reason: string}
 */
function videochat_realtime_validate_session_liveness(
    callable $authenticateRequest,
    string $sessionId,
    string $wsPath
): array {
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
        ];
    }

    $auth = $authenticateRequest(
        videochat_realtime_session_probe_request($trimmedSessionId, $wsPath),
        'websocket'
    );
    if (!is_array($auth)) {
        return [
            'ok' => false,
            'reason' => 'auth_backend_error',
        ];
    }

    return [
        'ok' => (bool) ($auth['ok'] ?? false),
        'reason' => (string) ($auth['reason'] ?? 'invalid_session'),
    ];
}

function videochat_realtime_method_from_request(array $request): string
{
    $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
    return $method === '' ? 'GET' : $method;
}

/**
 * @return array{0: array<string, mixed>|null, 1: string|null}
 */
function videochat_realtime_decode_json_request_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [null, 'invalid_json'];
    }

    return [$decoded, null];
}

function videochat_realtime_content_disposition_filename(string $filename): string
{
    $safe = videochat_chat_attachment_safe_filename($filename, 'txt');
    $ascii = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $safe) ?? 'attachment.txt';
    $ascii = trim($ascii, " .\t\n\r\0\x0B");
    if ($ascii === '') {
        $ascii = 'attachment.txt';
    }

    return 'attachment; filename="' . addcslashes($ascii, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
}

function videochat_realtime_chat_attachment_error_status(string $code, string $reason): int
{
    if ($reason === 'forbidden') {
        return 403;
    }
    if ($reason === 'quota_exceeded' || $code === 'attachment_too_large') {
        return 413;
    }
    if ($reason === 'validation_failed') {
        return 422;
    }
    if ($reason === 'not_found') {
        return 404;
    }

    return 500;
}

function videochat_handle_realtime_routes(
    string $path,
    array $request,
    string $wsPath,
    array &$activeWebsocketsBySession,
    array &$presenceState,
    array &$lobbyState,
    array &$typingState,
    array &$reactionState,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $rbacFailureResponse,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/chat/attachments$#', $path, $uploadMatch) === 1) {
        $method = videochat_realtime_method_from_request($request);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{call_id}/chat/attachments.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $restAuth = $authenticateRequest($request, 'rest');
        if (!(bool) ($restAuth['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($restAuth['reason'] ?? 'invalid_session'));
        }

        $rawBody = $request['body'] ?? '';
        if (is_string($rawBody) && strlen($rawBody) > videochat_chat_attachment_max_upload_body_bytes()) {
            return $errorResponse(413, 'attachment_too_large', 'Chat attachment upload request body exceeds the allowed size.', [
                'max_request_bytes' => videochat_chat_attachment_max_upload_body_bytes(),
            ]);
        }

        [$payload, $decodeError] = videochat_realtime_decode_json_request_body($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'chat_attachment_invalid_request_body', 'Chat attachment upload payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $uploadResult = videochat_chat_attachment_upload(
                $pdo,
                (string) ($uploadMatch[1] ?? ''),
                (int) (($restAuth['user']['id'] ?? 0)),
                (string) (($restAuth['user']['role'] ?? 'user')),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'chat_attachment_upload_failed', 'Could not upload chat attachment.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($uploadResult['ok'] ?? false)) {
            $code = (string) ($uploadResult['code'] ?? 'chat_attachment_upload_failed');
            $reason = (string) ($uploadResult['reason'] ?? 'internal_error');
            return $errorResponse(
                videochat_realtime_chat_attachment_error_status($code, $reason),
                $code,
                (string) ($uploadResult['message'] ?? 'Could not upload chat attachment.'),
                is_array($uploadResult['details'] ?? null) ? $uploadResult['details'] : []
            );
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'uploaded',
                'attachment' => $uploadResult['attachment'] ?? null,
                'limits' => [
                    'inline_max_chars' => videochat_chat_attachment_inline_max_chars(),
                    'inline_max_bytes' => videochat_chat_attachment_inline_max_bytes(),
                    'attachment_max_count' => videochat_chat_attachment_max_count(),
                    'attachment_max_images' => videochat_chat_attachment_max_images(),
                    'attachment_max_image_bytes' => videochat_chat_attachment_max_image_bytes(),
                    'attachment_max_document_bytes' => videochat_chat_attachment_max_document_bytes(),
                    'attachment_max_message_bytes' => videochat_chat_attachment_max_message_bytes(),
                    'attachment_max_upload_body_bytes' => videochat_chat_attachment_max_upload_body_bytes(),
                    'attachment_call_soft_quota_bytes' => videochat_chat_attachment_call_soft_quota_bytes(),
                    'attachment_call_hard_quota_bytes' => videochat_chat_attachment_call_hard_quota_bytes(),
                ],
                'quota' => is_array($uploadResult['details']['quota'] ?? null) ? $uploadResult['details']['quota'] : null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/chat/attachments/([A-Za-z0-9._-]{1,80})$#', $path, $downloadMatch) === 1) {
        $method = videochat_realtime_method_from_request($request);
        $restAuth = $authenticateRequest($request, 'rest');
        if (!(bool) ($restAuth['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($restAuth['reason'] ?? 'invalid_session'));
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_chat_attachment_cancel_draft(
                    $pdo,
                    (string) ($downloadMatch[1] ?? ''),
                    (string) ($downloadMatch[2] ?? ''),
                    (int) (($restAuth['user']['id'] ?? 0)),
                    (string) (($restAuth['user']['role'] ?? 'user'))
                );
            } catch (Throwable) {
                return $errorResponse(500, 'chat_attachment_delete_failed', 'Could not delete chat attachment draft.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($deleteResult['ok'] ?? false)) {
                $code = (string) ($deleteResult['code'] ?? 'chat_attachment_delete_failed');
                $reason = (string) ($deleteResult['reason'] ?? 'internal_error');
                return $errorResponse(
                    videochat_realtime_chat_attachment_error_status($code, $reason),
                    $code,
                    (string) ($deleteResult['message'] ?? 'Could not delete chat attachment draft.'),
                    is_array($deleteResult['details'] ?? null) ? $deleteResult['details'] : []
                );
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'attachment_id' => (string) ($downloadMatch[2] ?? ''),
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or DELETE for /api/calls/{call_id}/chat/attachments/{attachment_id}.', [
                'allowed_methods' => ['GET', 'DELETE'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $attachment = videochat_chat_attachment_fetch_for_download(
                $pdo,
                (string) ($downloadMatch[1] ?? ''),
                (string) ($downloadMatch[2] ?? ''),
                (int) (($restAuth['user']['id'] ?? 0)),
                (string) (($restAuth['user']['role'] ?? 'user'))
            );
        } catch (Throwable) {
            return $errorResponse(500, 'chat_attachment_download_failed', 'Could not load chat attachment metadata.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!is_array($attachment)) {
            return $errorResponse(404, 'chat_attachment_not_found', 'Chat attachment does not exist or is not available to this user.', [
                'attachment_id' => (string) ($downloadMatch[2] ?? ''),
            ]);
        }

        $binary = videochat_chat_attachment_store_get((string) ($attachment['object_key'] ?? ''));
        if (!is_string($binary)) {
            return $errorResponse(500, 'chat_attachment_download_failed', 'Could not read chat attachment from the King Object Store.', [
                'attachment_id' => (string) ($attachment['id'] ?? ''),
            ]);
        }

        return [
            'status' => 200,
            'headers' => [
                'content-type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                'content-length' => (string) strlen($binary),
                'content-disposition' => videochat_realtime_content_disposition_filename((string) ($attachment['original_name'] ?? 'attachment')),
                'x-content-type-options' => 'nosniff',
                'cache-control' => 'private, no-store',
                'access-control-allow-methods' => 'GET,POST,OPTIONS',
                'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id',
                'access-control-max-age' => '600',
                'connection' => 'close',
            ],
            'body' => $binary,
        ];
    }

    if ($path === '/sfu') {
        return videochat_handle_sfu_routes(
            $path,
            $request,
            $presenceState,
            $authenticateRequest,
            $authFailureResponse,
            $rbacFailureResponse,
            $errorResponse,
            $openDatabase
        );
    }

    if ($path === $wsPath) {
        $handshakeValidation = videochat_realtime_validate_websocket_handshake($request, $wsPath);
        if (!(bool) ($handshakeValidation['ok'] ?? false)) {
            return $errorResponse(
                (int) ($handshakeValidation['status'] ?? 400),
                (string) ($handshakeValidation['code'] ?? 'websocket_handshake_invalid'),
                (string) ($handshakeValidation['message'] ?? 'WebSocket handshake is invalid.'),
                is_array($handshakeValidation['details'] ?? null) ? $handshakeValidation['details'] : []
            );
        }

        $websocketAuth = $authenticateRequest($request, 'websocket');
        if (!(bool) ($websocketAuth['ok'] ?? false)) {
            return $authFailureResponse('websocket', (string) ($websocketAuth['reason'] ?? 'invalid_session'));
        }
        $websocketRbacDecision = videochat_authorize_role_for_path((array) ($websocketAuth['user'] ?? []), $path, $wsPath);
        if (!(bool) ($websocketRbacDecision['ok'] ?? false)) {
            return $rbacFailureResponse('websocket', $websocketRbacDecision, $path);
        }

        $authSessionId = is_string($websocketAuth['token'] ?? null)
            ? trim((string) $websocketAuth['token'])
            : '';
        if ($authSessionId === '') {
            $authSessionId = is_string($websocketAuth['session']['id'] ?? null)
                ? trim((string) $websocketAuth['session']['id'])
                : '';
        }

        $session = $request['session'] ?? null;
        $streamId = (int) ($request['stream_id'] ?? 0);
        $websocket = king_server_upgrade_to_websocket($session, $streamId);
        if ($websocket === false) {
            return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
        }

        $requestedRoomId = '';
        $requestedCallId = '';
        $queryParams = videochat_request_query_params($request);
        if (is_string($queryParams['room'] ?? null)) {
            $requestedRoomId = (string) $queryParams['room'];
        }
        if (is_string($queryParams['call_id'] ?? null)) {
            $requestedCallId = (string) $queryParams['call_id'];
        }
        $requestedCallId = videochat_realtime_normalize_call_id($requestedCallId, '');

        $roomResolution = videochat_realtime_resolve_connection_rooms(
            $websocketAuth,
            $requestedRoomId,
            $openDatabase,
            $requestedCallId
        );
        $initialRoomId = videochat_presence_normalize_room_id((string) ($roomResolution['initial_room_id'] ?? 'lobby'));
        $resolvedRequestedRoomId = videochat_presence_normalize_room_id(
            (string) ($roomResolution['requested_room_id'] ?? $initialRoomId)
        );
        $pendingRoomId = videochat_presence_normalize_room_id(
            (string) ($roomResolution['pending_room_id'] ?? ''),
            ''
        );

        $connectionId = videochat_register_active_websocket(
            $activeWebsocketsBySession,
            $authSessionId,
            $websocket
        );
        $presenceConnection = videochat_presence_connection_descriptor(
            (array) ($websocketAuth['user'] ?? []),
            $authSessionId,
            $connectionId,
            $websocket,
            $initialRoomId
        );
        $presenceConnection['requested_room_id'] = $resolvedRequestedRoomId;
        $presenceConnection['pending_room_id'] = $pendingRoomId;
        $presenceConnection['requested_call_id'] = $requestedCallId;
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceJoin = videochat_presence_join_room(
            $presenceState,
            $presenceConnection,
            (string) ($presenceConnection['room_id'] ?? 'lobby')
        );
        $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceState['connections'][$connectionId] = $presenceConnection;
        videochat_realtime_mark_call_participant_joined($openDatabase, $presenceConnection);
        $presenceDetached = false;
        $detachWebsocket = static function () use (
            &$presenceDetached,
            &$activeWebsocketsBySession,
            &$presenceState,
            &$lobbyState,
            &$typingState,
            &$reactionState,
            &$presenceConnection,
            $authSessionId,
            $connectionId,
            $openDatabase
        ): void {
            if ($presenceDetached) {
                return;
            }
            $presenceDetached = true;
            $disconnectedConnection = (array) $presenceConnection;

            $lobbyClear = videochat_lobby_clear_for_connection(
                $lobbyState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
            );
            if (
                (bool) ($lobbyClear['cleared'] ?? false)
                && videochat_presence_normalize_room_id((string) ($disconnectedConnection['room_id'] ?? ''), '') === videochat_realtime_waiting_room_id()
            ) {
                videochat_realtime_mark_call_participant_invite_state(
                    $openDatabase,
                    $disconnectedConnection,
                    'invited',
                    ['pending']
                );
            }
            videochat_typing_clear_for_connection(
                $typingState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
            );
            videochat_reaction_clear_for_connection(
                $reactionState,
                (array) $presenceConnection
            );
            videochat_unregister_active_websocket($activeWebsocketsBySession, $authSessionId, $connectionId);
            videochat_presence_remove_connection($presenceState, $connectionId);
            videochat_realtime_mark_call_participant_left($openDatabase, $disconnectedConnection, $presenceState);
        };

        if ($session !== null && $streamId > 0 && $authSessionId !== '' && $connectionId !== '') {
            king_server_on_cancel(
                $session,
                $streamId,
                static function () use ($detachWebsocket): void {
                    $detachWebsocket();
                }
            );
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/welcome',
                'message' => 'video-chat King websocket presence gateway connected',
                'connection_id' => $connectionId,
                'active_room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                    'call_context' => [
                        'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
                        'call_id' => (string) ($presenceConnection['active_call_id'] ?? ''),
                        'call_role' => (string) ($presenceConnection['call_role'] ?? 'participant'),
                        'invite_state' => (string) ($presenceConnection['invite_state'] ?? 'invited'),
                        'can_moderate' => (bool) ($presenceConnection['can_moderate_call'] ?? false),
                    ],
                'admission' => [
                    'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
                    'requested_room_id' => (string) ($presenceConnection['requested_room_id'] ?? ''),
                    'pending_room_id' => (string) ($presenceConnection['pending_room_id'] ?? ''),
                    'waiting_room_id' => videochat_realtime_waiting_room_id(),
                    'requires_admission' => trim((string) ($presenceConnection['pending_room_id'] ?? '')) !== '',
                ],
                'channels' => [
                    'presence' => [
                        'snapshot' => 'room/snapshot',
                        'joined' => 'room/joined',
                        'left' => 'room/left',
                    ],
                    'chat' => [
                        'send' => 'chat/send',
                        'message' => 'chat/message',
                        'ack' => 'chat/ack',
                    ],
                    'typing' => [
                        'start' => 'typing/start',
                        'stop' => 'typing/stop',
                    ],
                    'reaction' => [
                        'send' => 'reaction/send',
                        'send_batch' => 'reaction/send_batch',
                        'event' => 'reaction/event',
                        'batch' => 'reaction/batch',
                    ],
                    'activity' => [
                        'publish' => 'participant/activity',
                        'event' => 'participant/activity',
                    ],
                    'layout' => [
                        'mode' => 'layout/mode',
                        'strategy' => 'layout/strategy',
                        'selection' => 'layout/selection',
                    ],
                    'lobby' => [
                        'snapshot' => 'lobby/snapshot',
                        'request' => 'lobby/queue/request',
                        'join' => 'lobby/queue/join',
                        'cancel' => 'lobby/queue/cancel',
                        'allow' => 'lobby/allow',
                        'remove' => 'lobby/remove',
                        'allow_all' => 'lobby/allow_all',
                    ],
                    'signaling' => [
                        'offer' => 'call/offer',
                        'answer' => 'call/answer',
                        'ice' => 'call/ice',
                        'hangup' => 'call/hangup',
                        'ack' => 'call/ack',
                    ],
                    'admin_sync' => [
                        'publish' => 'admin/sync/publish',
                        'event' => 'admin/sync',
                    ],
                ],
                'auth' => [
                    'session' => $websocketAuth['session'] ?? null,
                    'user' => $websocketAuth['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]
        );

        $initialLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
            $lobbyState,
            $presenceConnection,
            $openDatabase,
            'joined_room'
        );
        $lastLobbySnapshotSignature = (string) ($initialLobbySnapshot['signature'] ?? '');
        $initialRoomSnapshot = videochat_realtime_send_room_snapshot(
            $presenceState,
            $presenceConnection,
            $openDatabase,
            'db_sync'
        );
        $lastRoomSnapshotSignature = (string) ($initialRoomSnapshot['signature'] ?? '');
        $nextLobbySnapshotPollMs = videochat_lobby_now_ms() + 1000;
        $nextRoomSnapshotPollMs = videochat_lobby_now_ms() + 1000;
        $reactionBrokerDatabase = null;
        $chatBrokerDatabase = null;
        $lastReactionBrokerRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
        $lastReactionBrokerEventId = 0;
        $nextReactionBrokerPollMs = videochat_reaction_broker_now_ms() + 100;
        $nextReactionBrokerCleanupMs = videochat_reaction_broker_now_ms() + 5000;
        $lastChatBrokerRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
        $lastChatBrokerEventId = 0;
        $nextChatBrokerPollMs = videochat_chat_broker_now_ms() + 100;
        $nextChatBrokerCleanupMs = videochat_chat_broker_now_ms() + 5000;
        try {
            $reactionBrokerDatabase = $openDatabase();
            videochat_reaction_broker_bootstrap($reactionBrokerDatabase);
            videochat_chat_broker_bootstrap($reactionBrokerDatabase);
            $chatBrokerDatabase = $reactionBrokerDatabase;
            $lastReactionBrokerEventId = videochat_reaction_broker_latest_event_id(
                $reactionBrokerDatabase,
                $lastReactionBrokerRoomId
            );
            $lastChatBrokerEventId = videochat_chat_broker_latest_event_id(
                $chatBrokerDatabase,
                $lastChatBrokerRoomId
            );
        } catch (Throwable) {
            $reactionBrokerDatabase = null;
            $chatBrokerDatabase = null;
        }

        try {
            while (true) {
                $sessionLiveness = videochat_realtime_validate_session_liveness(
                    $authenticateRequest,
                    $authSessionId,
                    $wsPath
                );
                if (!(bool) ($sessionLiveness['ok'] ?? false)) {
                    $sessionCloseDescriptor = videochat_realtime_close_descriptor_for_reason(
                        (string) ($sessionLiveness['reason'] ?? 'invalid_session')
                    );
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => 'websocket_session_invalidated',
                            'message' => 'Session is no longer valid for realtime commands.',
                            'details' => [
                                'reason' => (string) ($sessionLiveness['reason'] ?? 'invalid_session'),
                                'close' => $sessionCloseDescriptor,
                            ],
                            'time' => gmdate('c'),
                        ]
                    );

                    try {
                        king_client_websocket_close(
                            $websocket,
                            (int) ($sessionCloseDescriptor['close_code'] ?? 1008),
                            (string) ($sessionCloseDescriptor['close_reason'] ?? 'session_invalidated')
                        );
                    } catch (Throwable) {
                        // Best-effort close; detach/cleanup runs in finally.
                    }
                    break;
                }

                $pollNowMs = videochat_lobby_now_ms();
                if ($pollNowMs >= $nextLobbySnapshotPollMs) {
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        $lastLobbySnapshotSignature,
                        'db_sync',
                        null,
                        $pollNowMs
                    );
                    $nextLobbySnapshotPollMs = $pollNowMs + 1000;
                }
                if ($pollNowMs >= $nextRoomSnapshotPollMs) {
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_send_room_snapshot_if_changed(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        $lastRoomSnapshotSignature,
                        'db_sync'
                    );
                    $nextRoomSnapshotPollMs = $pollNowMs + 1000;
                }
                if ($reactionBrokerDatabase instanceof PDO && $pollNowMs >= $nextReactionBrokerPollMs) {
                    try {
                        $currentReactionRoomId = videochat_presence_normalize_room_id(
                            (string) ($presenceConnection['room_id'] ?? 'lobby')
                        );
                        if ($currentReactionRoomId !== $lastReactionBrokerRoomId) {
                            $lastReactionBrokerRoomId = $currentReactionRoomId;
                            $lastReactionBrokerEventId = videochat_reaction_broker_latest_event_id(
                                $reactionBrokerDatabase,
                                $lastReactionBrokerRoomId
                            );
                        }
                        videochat_reaction_broker_poll(
                            $reactionBrokerDatabase,
                            $websocket,
                            $lastReactionBrokerRoomId,
                            (int) ($presenceConnection['user_id'] ?? 0),
                            $lastReactionBrokerEventId
                        );
                        if ($pollNowMs >= $nextReactionBrokerCleanupMs) {
                            videochat_reaction_broker_cleanup($reactionBrokerDatabase);
                            $nextReactionBrokerCleanupMs = $pollNowMs + 5000;
                        }
                    } catch (Throwable) {
                        // Keep the websocket alive; direct local reaction broadcast remains the fallback.
                    }
                    $nextReactionBrokerPollMs = $pollNowMs + 100;
                }
                if ($chatBrokerDatabase instanceof PDO && $pollNowMs >= $nextChatBrokerPollMs) {
                    try {
                        $currentChatRoomId = videochat_presence_normalize_room_id(
                            (string) ($presenceConnection['room_id'] ?? 'lobby')
                        );
                        if ($currentChatRoomId !== $lastChatBrokerRoomId) {
                            $lastChatBrokerRoomId = $currentChatRoomId;
                            $lastChatBrokerEventId = videochat_chat_broker_latest_event_id(
                                $chatBrokerDatabase,
                                $lastChatBrokerRoomId
                            );
                        }
                        videochat_chat_broker_poll(
                            $chatBrokerDatabase,
                            $websocket,
                            $lastChatBrokerRoomId,
                            $lastChatBrokerEventId
                        );
                        if ($pollNowMs >= $nextChatBrokerCleanupMs) {
                            videochat_chat_broker_cleanup($chatBrokerDatabase);
                            $nextChatBrokerCleanupMs = $pollNowMs + 5000;
                        }
                    } catch (Throwable) {
                        // Keep the websocket alive; direct local chat broadcast remains the fallback.
                    }
                    $nextChatBrokerPollMs = $pollNowMs + 100;
                }

                videochat_typing_sweep_expired($typingState, $presenceState);
                $frame = king_client_websocket_receive($websocket, 250);
                if ($frame === false) {
                    $status = function_exists('king_client_websocket_get_status')
                        ? (int) king_client_websocket_get_status($websocket)
                        : 3;
                    if ($status === 3) {
                        break;
                    }

                    continue;
                }

                if (!is_string($frame) || trim($frame) === '') {
                    continue;
                }

                $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                $presenceState['connections'][$connectionId] = $presenceConnection;

                $presenceCommand = videochat_presence_decode_client_frame($frame);
                $commandType = (string) ($presenceCommand['type'] ?? '');
                $commandError = (string) ($presenceCommand['error'] ?? 'invalid_command');

                $chatCommand = null;
                $typingCommand = null;
                $signalingCommand = null;
                $reactionCommand = null;
                $activityCommand = null;
                $layoutCommand = null;
                $lobbyCommand = null;
                $adminSyncCommand = null;
                if (!(bool) ($presenceCommand['ok'] ?? false) && $commandError === 'unsupported_type') {
                    $chatCommand = videochat_chat_decode_client_frame($frame);
                    if ((bool) ($chatCommand['ok'] ?? false)) {
                        $chatBroker = $chatBrokerDatabase instanceof PDO
                            ? static function (string $roomId, array $event) use ($chatBrokerDatabase): bool {
                                return videochat_chat_broker_insert_event($chatBrokerDatabase, $roomId, $event);
                            }
                            : null;
                        $chatPublish = videochat_chat_publish(
                            $presenceState,
                            $presenceConnection,
                            $chatCommand,
                            null,
                            null,
                            $chatBroker,
                            static function (array $attachmentIds, string $roomId, int $senderUserId, string $messageId, array $resolverConnection) use ($openDatabase): array {
                                $callId = videochat_realtime_connection_call_id($resolverConnection);
                                if ($callId === '') {
                                    return [
                                        'ok' => false,
                                        'error' => 'attachment_call_missing',
                                        'attachments' => [],
                                    ];
                                }

                                $pdo = $openDatabase();
                                return videochat_chat_attachment_resolve_for_message(
                                    $pdo,
                                    $attachmentIds,
                                    $callId,
                                    $roomId,
                                    $senderUserId,
                                    $messageId
                                );
                            }
                        );
                        if (!(bool) ($chatPublish['ok'] ?? false)) {
                            videochat_presence_send_frame(
                                $websocket,
                                [
                                    'type' => 'system/error',
                                    'code' => 'chat_publish_failed',
                                    'message' => 'Could not publish chat message.',
                                    'details' => [
                                        'error' => (string) ($chatPublish['error'] ?? 'unknown'),
                                        'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                    ],
                                    'time' => gmdate('c'),
                                ]
                            );
                            continue;
                        }

                        $message = is_array($chatPublish['event']['message'] ?? null)
                            ? $chatPublish['event']['message']
                            : [];
                        $chatRoomId = (string) ($chatPublish['event']['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'));
                        $chatArchiveCallId = videochat_realtime_connection_call_id($presenceConnection);
                        if ($chatArchiveCallId !== '') {
                            try {
                                $archiveResult = videochat_chat_archive_append_message(
                                    $openDatabase(),
                                    $chatArchiveCallId,
                                    $chatRoomId,
                                    is_array($chatPublish['event'] ?? null) ? $chatPublish['event'] : []
                                );
                            } catch (Throwable) {
                                $archiveResult = [
                                    'ok' => false,
                                    'reason' => 'archive_exception',
                                ];
                            }

                            if (!(bool) ($archiveResult['ok'] ?? false)) {
                                videochat_presence_send_frame(
                                    $websocket,
                                    [
                                        'type' => 'system/error',
                                        'code' => 'chat_archive_failed',
                                        'message' => 'Chat message was sent but could not be archived.',
                                        'details' => [
                                            'error' => (string) ($archiveResult['reason'] ?? 'unknown'),
                                            'room_id' => $chatRoomId,
                                        ],
                                        'time' => gmdate('c'),
                                    ]
                                );
                            }
                        }
                        videochat_presence_send_frame(
                            $websocket,
                            videochat_chat_ack_payload(
                                $chatRoomId,
                                $message,
                                (int) ($chatPublish['sent_count'] ?? 0)
                            )
                        );
                        continue;
                    }

                    if ((string) ($chatCommand['error'] ?? '') !== 'unsupported_type') {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => (string) ($chatCommand['error'] ?? 'chat_invalid_payload'),
                                'message' => 'Chat message payload is invalid.',
                                'details' => [
                                    'type' => 'chat/send',
                                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    if ((string) ($chatCommand['error'] ?? '') === 'unsupported_type') {
                        $typingCommand = videochat_typing_decode_client_frame($frame);
                        if ((bool) ($typingCommand['ok'] ?? false)) {
                            $typingResult = videochat_typing_apply_command(
                                $typingState,
                                $presenceState,
                                $presenceConnection,
                                $typingCommand
                            );
                            if (!(bool) ($typingResult['ok'] ?? false)) {
                                videochat_presence_send_frame(
                                    $websocket,
                                    [
                                        'type' => 'system/error',
                                        'code' => 'typing_publish_failed',
                                        'message' => 'Could not publish typing state.',
                                        'details' => [
                                            'error' => (string) ($typingResult['error'] ?? 'unknown'),
                                            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                        ],
                                        'time' => gmdate('c'),
                                    ]
                                );
                            }
                            continue;
                        }

                        if ((string) ($typingCommand['error'] ?? '') === 'unsupported_type') {
                            $signalingCommand = videochat_signaling_decode_client_frame($frame);
                            if ((bool) ($signalingCommand['ok'] ?? false)) {
                                $signalingPublish = videochat_signaling_publish(
                                    $presenceState,
                                    $presenceConnection,
                                    $signalingCommand
                                );
                                if (!(bool) ($signalingPublish['ok'] ?? false)) {
                                    videochat_presence_send_frame(
                                        $websocket,
                                        [
                                            'type' => 'system/error',
                                            'code' => 'signaling_publish_failed',
                                            'message' => 'Could not route signaling message.',
                                            'details' => [
                                                'error' => (string) ($signalingPublish['error'] ?? 'unknown'),
                                                'type' => (string) ($signalingCommand['type'] ?? ''),
                                                'target_user_id' => (int) ($signalingCommand['target_user_id'] ?? 0),
                                                'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                            ],
                                            'time' => gmdate('c'),
                                        ]
                                    );
                                    continue;
                                }

                                $eventSignal = is_array($signalingPublish['event']['signal'] ?? null)
                                    ? $signalingPublish['event']['signal']
                                    : [];
                                videochat_presence_send_frame(
                                    $websocket,
                                    [
                                        'type' => 'call/ack',
                                        'signal_type' => (string) ($signalingCommand['type'] ?? ''),
                                        'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                        'target_user_id' => (int) ($signalingCommand['target_user_id'] ?? 0),
                                        'signal_id' => (string) ($eventSignal['id'] ?? ''),
                                        'server_time' => (string) ($eventSignal['server_time'] ?? gmdate('c')),
                                        'sent_count' => (int) ($signalingPublish['sent_count'] ?? 0),
                                        'time' => gmdate('c'),
                                    ]
                                );
                                continue;
                            }

                            if ((string) ($signalingCommand['error'] ?? '') === 'unsupported_type') {
                                $reactionCommand = videochat_reaction_decode_client_frame($frame);
                                if ((bool) ($reactionCommand['ok'] ?? false)) {
                                    $reactionBroker = $reactionBrokerDatabase instanceof PDO
                                        ? static function (string $roomId, array $event) use ($reactionBrokerDatabase): bool {
                                            return videochat_reaction_broker_insert_event($reactionBrokerDatabase, $roomId, $event);
                                        }
                                        : null;
                                    $reactionPublish = videochat_reaction_publish(
                                        $reactionState,
                                        $presenceState,
                                        $presenceConnection,
                                        $reactionCommand,
                                        null,
                                        null,
                                        $reactionBroker
                                    );
                                    if (!(bool) ($reactionPublish['ok'] ?? false)) {
                                        $details = [
                                            'error' => (string) ($reactionPublish['error'] ?? 'unknown'),
                                            'type' => (string) ($reactionCommand['type'] ?? ''),
                                            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                        ];
                                        $retryAfterMs = (int) ($reactionPublish['retry_after_ms'] ?? 0);
                                        if ($retryAfterMs > 0) {
                                            $details['retry_after_ms'] = $retryAfterMs;
                                        }

                                        videochat_presence_send_frame(
                                            $websocket,
                                            [
                                                'type' => 'system/error',
                                                'code' => 'reaction_publish_failed',
                                                'message' => 'Reaction could not be sent.',
                                                'details' => $details,
                                                'time' => gmdate('c'),
                                            ]
                                        );
                                    }
                                    continue;
                                }

                                if ((string) ($reactionCommand['error'] ?? '') === 'unsupported_type') {
                                    $activityCommand = videochat_activity_decode_client_frame($frame);
                                    if ((bool) ($activityCommand['ok'] ?? false)) {
                                        try {
                                            $activityResult = videochat_activity_apply_command(
                                                $openDatabase(),
                                                $presenceState,
                                                $presenceConnection,
                                                $activityCommand
                                            );
                                        } catch (Throwable) {
                                            $activityResult = [
                                                'ok' => false,
                                                'error' => 'activity_backend_error',
                                            ];
                                        }

                                        if (!(bool) ($activityResult['ok'] ?? false)) {
                                            videochat_presence_send_frame(
                                                $websocket,
                                                [
                                                    'type' => 'system/error',
                                                    'code' => 'activity_publish_failed',
                                                    'message' => 'Could not publish participant activity.',
                                                    'details' => [
                                                        'error' => (string) ($activityResult['error'] ?? 'unknown'),
                                                        'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                                    ],
                                                    'time' => gmdate('c'),
                                                ]
                                            );
                                        }
                                        continue;
                                    }

                                    if ((string) ($activityCommand['error'] ?? '') === 'unsupported_type') {
                                        $layoutCommand = videochat_layout_decode_client_frame($frame);
                                        if ((bool) ($layoutCommand['ok'] ?? false)) {
                                            try {
                                                $layoutResult = videochat_layout_apply_command(
                                                    $openDatabase(),
                                                    $presenceState,
                                                    $presenceConnection,
                                                    $layoutCommand
                                                );
                                            } catch (Throwable) {
                                                $layoutResult = [
                                                    'ok' => false,
                                                    'error' => 'layout_backend_error',
                                                ];
                                            }

                                            if (!(bool) ($layoutResult['ok'] ?? false)) {
                                                videochat_presence_send_frame(
                                                    $websocket,
                                                    [
                                                        'type' => 'system/error',
                                                        'code' => 'layout_command_failed',
                                                        'message' => 'Could not apply layout command.',
                                                        'details' => [
                                                            'error' => (string) ($layoutResult['error'] ?? 'unknown'),
                                                            'type' => (string) ($layoutCommand['type'] ?? ''),
                                                            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                                        ],
                                                        'time' => gmdate('c'),
                                                    ]
                                                );
                                            }
                                            continue;
                                        }

                                        if ((string) ($layoutCommand['error'] ?? '') === 'unsupported_type') {
                                            $lobbyCommand = videochat_lobby_decode_client_frame($frame);
                                            if ((bool) ($lobbyCommand['ok'] ?? false)) {
                                                $lobbyCommandRoomId = videochat_presence_normalize_room_id(
                                                    (string) ($lobbyCommand['room_id'] ?? ''),
                                                    ''
                                                );
                                                if ($lobbyCommandRoomId === '') {
                                                    $lobbyCommandRoomId = videochat_realtime_lobby_room_id_for_connection($presenceConnection);
                                                }
                                                videochat_realtime_sync_lobby_room_from_database(
                                                    $lobbyState,
                                                    $openDatabase,
                                                    $lobbyCommandRoomId,
                                                    videochat_realtime_connection_call_id($presenceConnection)
                                                );

                                                $lobbyResult = videochat_lobby_apply_command(
                                                    $lobbyState,
                                                    $presenceState,
                                                    $presenceConnection,
                                                    $lobbyCommand
                                                );
                                                if (!(bool) ($lobbyResult['ok'] ?? false)) {
                                                    videochat_presence_send_frame(
                                                        $websocket,
                                                        [
                                                            'type' => 'system/error',
                                                            'code' => 'lobby_command_failed',
                                                            'message' => 'Could not apply lobby command.',
                                                            'details' => [
                                                                'error' => (string) ($lobbyResult['error'] ?? 'unknown'),
                                                                'type' => (string) ($lobbyCommand['type'] ?? ''),
                                                                'target_user_id' => (int) ($lobbyCommand['target_user_id'] ?? 0),
                                                                'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                                            ],
                                                            'time' => gmdate('c'),
                                                        ]
                                                    );
                                                } else {
                                                    $lobbyAction = (string) ($lobbyResult['action'] ?? '');
                                                    $lobbyStateName = (string) ($lobbyResult['state'] ?? '');
                                                    $lobbyResultRoomId = videochat_presence_normalize_room_id(
                                                        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
                                                    );

                                                    if (
                                                        $lobbyAction === 'lobby/queue/join'
                                                        && in_array($lobbyStateName, ['queued', 'already_queued'], true)
                                                    ) {
                                                        videochat_realtime_mark_call_participant_pending_for_queue(
                                                            $openDatabase,
                                                            $presenceConnection
                                                        );
                                                        videochat_realtime_sync_lobby_room_from_database(
                                                            $lobbyState,
                                                            $openDatabase,
                                                            $lobbyResultRoomId,
                                                            videochat_realtime_connection_call_id($presenceConnection)
                                                        );
                                                    } elseif ($lobbyAction === 'lobby/queue/cancel') {
                                                        videochat_realtime_mark_call_participant_invite_state(
                                                            $openDatabase,
                                                            $presenceConnection,
                                                            'invited',
                                                            ['pending']
                                                        );
                                                        videochat_realtime_sync_lobby_room_from_database(
                                                            $lobbyState,
                                                            $openDatabase,
                                                            $lobbyResultRoomId,
                                                            videochat_realtime_connection_call_id($presenceConnection)
                                                        );
                                                    }

                                                    if ($lobbyAction === 'lobby/remove') {
                                                        $removedCallId = videochat_realtime_connection_call_id($presenceConnection);
                                                        $removedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
                                                            ? array_values(array_filter(
                                                                array_map('intval', (array) $lobbyResult['affected_user_ids']),
                                                                static fn (int $id): bool => $id > 0
                                                            ))
                                                            : [];
                                                        if ($removedCallId !== '' && $removedUserIds !== []) {
                                                            foreach ($removedUserIds as $removedUserId) {
                                                                videochat_realtime_mark_call_participant_invite_state_by_user_id(
                                                                    $openDatabase,
                                                                    $removedCallId,
                                                                    $removedUserId,
                                                                    'invited',
                                                                    ['pending', 'allowed', 'accepted']
                                                                );
                                                            }
                                                            videochat_realtime_sync_lobby_room_from_database(
                                                                $lobbyState,
                                                                $openDatabase,
                                                                $lobbyResultRoomId,
                                                                $removedCallId
                                                            );
                                                        }
                                                    }

                                                    if (in_array($lobbyAction, ['lobby/allow', 'lobby/allow_all'], true)) {
                                                        $admittedRoomId = videochat_presence_normalize_room_id(
                                                            (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
                                                        );
                                                        $admittedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
                                                            ? array_values(array_filter(
                                                                array_map('intval', (array) $lobbyResult['affected_user_ids']),
                                                                static fn (int $id): bool => $id > 0
                                                            ))
                                                            : [];
                                                        if ($admittedRoomId !== '' && $admittedUserIds !== []) {
                                                            $admittedCallId = videochat_realtime_connection_call_id($presenceConnection);
                                                            if ($admittedCallId !== '') {
                                                                foreach ($admittedUserIds as $admittedUserId) {
                                                                    videochat_realtime_mark_call_participant_invite_state_by_user_id(
                                                                        $openDatabase,
                                                                        $admittedCallId,
                                                                        $admittedUserId,
                                                                        'allowed',
                                                                        ['pending']
                                                                    );
                                                                }
                                                            }
                                                            videochat_realtime_sync_lobby_room_from_database(
                                                                $lobbyState,
                                                                $openDatabase,
                                                                $admittedRoomId,
                                                                $admittedCallId
                                                            );
                                                            videochat_realtime_send_lobby_snapshot_to_users(
                                                                $presenceState,
                                                                $lobbyState,
                                                                $admittedRoomId,
                                                                $admittedUserIds,
                                                                'admitted',
                                                                null
                                                            );
                                                        }
                                                    }
                                                }
                                                continue;
                                            }

                                            if ((string) ($lobbyCommand['error'] ?? '') === 'unsupported_type') {
                                                $adminSyncCommand = videochat_admin_sync_decode_client_frame($frame);
                                                if ((bool) ($adminSyncCommand['ok'] ?? false)) {
                                                    $adminSyncResult = videochat_admin_sync_publish(
                                                        $presenceState,
                                                        $presenceConnection,
                                                        $adminSyncCommand
                                                    );
                                                    if (!(bool) ($adminSyncResult['ok'] ?? false)) {
                                                        videochat_presence_send_frame(
                                                            $websocket,
                                                            [
                                                                'type' => 'system/error',
                                                                'code' => 'admin_sync_publish_failed',
                                                                'message' => 'Could not publish admin sync event.',
                                                                'details' => [
                                                                    'error' => (string) ($adminSyncResult['error'] ?? 'unknown'),
                                                                    'topic' => (string) ($adminSyncCommand['topic'] ?? 'all'),
                                                                    'reason' => (string) ($adminSyncCommand['reason'] ?? 'updated'),
                                                                ],
                                                                'time' => gmdate('c'),
                                                            ]
                                                        );
                                                    }
                                                    continue;
                                                }

                                                $commandType = (string) ($adminSyncCommand['type'] ?? $commandType);
                                                $commandError = (string) ($adminSyncCommand['error'] ?? $commandError);
                                            } else {
                                                $commandType = (string) ($lobbyCommand['type'] ?? $commandType);
                                                $commandError = (string) ($lobbyCommand['error'] ?? $commandError);
                                            }
                                        } else {
                                            $commandType = (string) ($layoutCommand['type'] ?? $commandType);
                                            $commandError = (string) ($layoutCommand['error'] ?? $commandError);
                                        }
                                    } else {
                                        $commandType = (string) ($activityCommand['type'] ?? $commandType);
                                        $commandError = (string) ($activityCommand['error'] ?? $commandError);
                                    }
                                } else {
                                    $commandType = (string) ($reactionCommand['type'] ?? $commandType);
                                    $commandError = (string) ($reactionCommand['error'] ?? $commandError);
                                }
                            } else {
                                $commandType = (string) ($signalingCommand['type'] ?? $commandType);
                                $commandError = (string) ($signalingCommand['error'] ?? $commandError);
                            }
                        } else {
                            $commandType = (string) ($typingCommand['type'] ?? $commandType);
                            $commandError = (string) ($typingCommand['error'] ?? $commandError);
                        }
                    } else {
                        $commandType = (string) ($chatCommand['type'] ?? $commandType);
                        $commandError = (string) ($chatCommand['error'] ?? $commandError);
                    }
                }

                if (!(bool) ($presenceCommand['ok'] ?? false)) {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => 'invalid_websocket_command',
                            'message' => 'WebSocket command is invalid.',
                            'details' => [
                                'error' => $commandError,
                                'type' => $commandType,
                            ],
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                $commandType = (string) ($presenceCommand['type'] ?? '');
                if ($commandType === 'ping') {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/pong',
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                if ($commandType === 'room/snapshot/request') {
                    $requestedRoomSnapshot = videochat_realtime_send_room_snapshot(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        'requested'
                    );
                    $lastRoomSnapshotSignature = (string) ($requestedRoomSnapshot['signature'] ?? $lastRoomSnapshotSignature);
                    $requestedLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        'requested'
                    );
                    $lastLobbySnapshotSignature = (string) ($requestedLobbySnapshot['signature'] ?? $lastLobbySnapshotSignature);
                    continue;
                }

                if ($commandType === 'room/leave') {
                    $leavingConnection = (array) $presenceConnection;
                    $lobbyClear = videochat_lobby_clear_for_connection(
                        $lobbyState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
                    );
                    if (
                        (bool) ($lobbyClear['cleared'] ?? false)
                        && videochat_presence_normalize_room_id((string) ($leavingConnection['room_id'] ?? ''), '') === videochat_realtime_waiting_room_id()
                    ) {
                        videochat_realtime_mark_call_participant_invite_state(
                            $openDatabase,
                            $leavingConnection,
                            'invited',
                            ['pending']
                        );
                    }
                    videochat_typing_clear_for_connection(
                        $typingState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
                    );
                    videochat_reaction_clear_for_connection(
                        $reactionState,
                        $presenceConnection
                    );
                    $presenceConnection['room_id'] = videochat_realtime_waiting_room_id();
                    $presenceConnection['requested_room_id'] = videochat_realtime_waiting_room_id();
                    $presenceConnection['pending_room_id'] = '';
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room(
                        $presenceState,
                        $presenceConnection,
                        videochat_realtime_waiting_room_id()
                    );
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_mark_call_participant_left($openDatabase, $leavingConnection, $presenceState);
                    continue;
                }

                if ($commandType === 'room/join') {
                    $targetRoomId = videochat_presence_normalize_room_id((string) ($presenceCommand['room_id'] ?? ''));
                    try {
                        $pdo = $openDatabase();
                        $targetRoom = videochat_fetch_active_room_context($pdo, $targetRoomId);
                    } catch (Throwable) {
                        $targetRoom = null;
                    }

                    if (!is_array($targetRoom)) {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => 'room_not_found',
                                'message' => 'Requested room is not active.',
                                'details' => [
                                    'room_id' => $targetRoomId,
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    $pendingRoomId = videochat_presence_normalize_room_id(
                        (string) ($presenceConnection['pending_room_id'] ?? ''),
                        ''
                    );
                    $pendingGateActive = $pendingRoomId !== '';
                    $canBypassAdmissionForTargetRoom = videochat_realtime_connection_can_bypass_admission_for_room(
                        $presenceConnection,
                        $targetRoomId,
                        $openDatabase
                    );
                    if ($pendingGateActive && $canBypassAdmissionForTargetRoom) {
                        $presenceConnection['pending_room_id'] = '';
                        $pendingRoomId = '';
                        $pendingGateActive = false;
                    }
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        videochat_realtime_sync_lobby_room_from_database(
                            $lobbyState,
                            $openDatabase,
                            $targetRoomId,
                            videochat_realtime_connection_call_id($presenceConnection)
                        );
                        $isAdmitted = videochat_lobby_is_user_admitted_for_room(
                            $lobbyState,
                            $targetRoomId,
                            (int) ($presenceConnection['user_id'] ?? 0)
                        );
                        if (!$isAdmitted) {
                            videochat_presence_send_frame(
                                $websocket,
                                [
                                    'type' => 'system/error',
                                    'code' => 'room_join_requires_admission',
                                    'message' => 'Call admission is still pending approval.',
                                    'details' => [
                                        'room_id' => $targetRoomId,
                                        'pending_room_id' => $pendingRoomId,
                                    ],
                                    'time' => gmdate('c'),
                                ]
                            );
                            continue;
                        }
                    } elseif (
                        $pendingGateActive
                        && $targetRoomId !== $pendingRoomId
                        && $targetRoomId !== videochat_realtime_waiting_room_id()
                    ) {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => 'room_join_not_allowed',
                                'message' => 'You cannot join this room while admission is pending.',
                                'details' => [
                                    'room_id' => $targetRoomId,
                                    'pending_room_id' => $pendingRoomId,
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    $currentRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
                    $previousConnection = (array) $presenceConnection;
                    if ($currentRoomId !== $targetRoomId) {
                        $lobbyClear = videochat_lobby_clear_for_connection(
                            $lobbyState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
                        if (
                            (bool) ($lobbyClear['cleared'] ?? false)
                            && $currentRoomId === videochat_realtime_waiting_room_id()
                            && $targetRoomId !== $pendingRoomId
                        ) {
                            videochat_realtime_mark_call_participant_invite_state(
                                $openDatabase,
                                $previousConnection,
                                'invited',
                                ['pending']
                            );
                        }
                        videochat_typing_clear_for_connection(
                            $typingState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
                        videochat_reaction_clear_for_connection(
                            $reactionState,
                            $presenceConnection
                        );
                    }
                    $presenceConnection['room_id'] = $targetRoomId;
                    $presenceConnection['requested_room_id'] = $targetRoomId;
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        $presenceConnection['pending_room_id'] = '';
                    }
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, $targetRoomId);
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_mark_call_participant_joined($openDatabase, $presenceConnection);
                    if ($currentRoomId !== $targetRoomId) {
                        videochat_realtime_mark_call_participant_left($openDatabase, $previousConnection, $presenceState);
                    }
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        $removedFromLobby = videochat_lobby_remove_user_from_room(
                            $lobbyState,
                            $targetRoomId,
                            (int) ($presenceConnection['user_id'] ?? 0)
                        );
                        if ($removedFromLobby) {
                            videochat_lobby_broadcast_room_snapshot(
                                $lobbyState,
                                $presenceState,
                                $targetRoomId,
                                'admission_consumed'
                            );
                        }
                    }
                    $joinedLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        'joined_room'
                    );
                    $lastLobbySnapshotSignature = (string) ($joinedLobbySnapshot['signature'] ?? $lastLobbySnapshotSignature);
                    $joinedRoomSnapshot = videochat_realtime_send_room_snapshot(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        'joined_room'
                    );
                    $lastRoomSnapshotSignature = (string) ($joinedRoomSnapshot['signature'] ?? $lastRoomSnapshotSignature);
                    continue;
                }
            }
        } finally {
            $detachWebsocket();
        }

        return [
            'status' => 101,
            'headers' => [],
            'body' => '',
        ];
    }


    return null;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_realtime_db_room_participants(callable $openDatabase, array $connection): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    if ($roomId === '' || $callId === '') {
        return [];
    }

    try {
        $pdo = $openDatabase();
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT
    cp.user_id,
    cp.display_name AS participant_display_name,
    cp.call_role,
    cp.joined_at,
    users.display_name AS user_display_name,
    roles.slug AS role_slug
FROM call_participants cp
LEFT JOIN users ON users.id = cp.user_id
LEFT JOIN roles ON roles.id = users.role_id
WHERE cp.call_id = :call_id
  AND cp.source = 'internal'
  AND cp.user_id IS NOT NULL
  AND cp.joined_at IS NOT NULL
  AND cp.left_at IS NULL
ORDER BY
    cp.display_name ASC,
    cp.user_id ASC
SQL
        );
        $statement->execute([':call_id' => $callId]);
    } catch (Throwable) {
        return [];
    }

    $participants = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $displayName = trim((string) ($row['participant_display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($row['user_display_name'] ?? ''));
        }
        $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
        $participants[] = [
            'connection_id' => 'db:' . $callId . ':' . $userId,
            'room_id' => $roomId,
            'user' => [
                'id' => $userId,
                'display_name' => $displayName !== '' ? $displayName : ('User ' . $userId),
                'role' => videochat_normalize_role_slug((string) ($row['role_slug'] ?? 'user')),
                'call_role' => $callRole,
            ],
            'connected_at' => (string) ($row['joined_at'] ?? ''),
        ];
    }

    return $participants;
}

/**
 * @param array<int, array<string, mixed>> $localParticipants
 * @param array<int, array<string, mixed>> $dbParticipants
 * @return array<int, array<string, mixed>>
 */
function videochat_realtime_merge_room_participants(array $localParticipants, array $dbParticipants): array
{
    $byUserId = [];
    foreach ($dbParticipants as $participant) {
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $byUserId[$userId] = $participant;
        }
    }
    foreach ($localParticipants as $participant) {
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $byUserId[$userId] = $participant;
        }
    }

    $participants = array_values($byUserId);
    usort(
        $participants,
        static function (array $left, array $right): int {
            $leftRoleRank = videochat_presence_role_rank((string) (($left['user'] ?? [])['role'] ?? ''));
            $rightRoleRank = videochat_presence_role_rank((string) (($right['user'] ?? [])['role'] ?? ''));
            if ($leftRoleRank !== $rightRoleRank) {
                return $leftRoleRank <=> $rightRoleRank;
            }
            $leftName = strtolower(trim((string) (($left['user'] ?? [])['display_name'] ?? '')));
            $rightName = strtolower(trim((string) (($right['user'] ?? [])['display_name'] ?? '')));
            if ($leftName !== $rightName) {
                return $leftName <=> $rightName;
            }
            return ((int) (($left['user'] ?? [])['id'] ?? 0)) <=> ((int) (($right['user'] ?? [])['id'] ?? 0));
        }
    );

    return $participants;
}

function videochat_realtime_room_snapshot_payload(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''));
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    $participants = videochat_realtime_merge_room_participants(
        videochat_presence_room_participants($presenceState, $roomId),
        videochat_realtime_db_room_participants($openDatabase, $connection)
    );
    $activityLayout = [
        'layout' => videochat_layout_default_state($callId, $roomId),
        'activity' => [],
    ];
    if ($callId !== '' && $roomId !== '') {
        try {
            $activityLayout = videochat_activity_layout_snapshot($openDatabase(), $callId, $roomId, $participants);
        } catch (Throwable) {
            $activityLayout = [
                'layout' => videochat_layout_default_state($callId, $roomId),
                'activity' => [],
            ];
        }
    }

    return [
        'type' => 'room/snapshot',
        'room_id' => $roomId,
        'participants' => $participants,
        'participant_count' => count($participants),
        'layout' => is_array($activityLayout['layout'] ?? null) ? $activityLayout['layout'] : videochat_layout_default_state($callId, $roomId),
        'activity' => is_array($activityLayout['activity'] ?? null) ? $activityLayout['activity'] : [],
        'viewer' => [
            'user_id' => (int) ($connection['user_id'] ?? 0),
            'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? '')),
            'call_id' => (string) ($connection['active_call_id'] ?? ''),
            'call_role' => videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant')),
            'can_moderate' => (bool) ($connection['can_moderate_call'] ?? false),
        ],
        'reason' => trim($reason) === '' ? 'snapshot' : trim($reason),
        'time' => gmdate('c'),
    ];
}

function videochat_realtime_room_snapshot_signature(array $payload): string
{
    return hash('sha256', json_encode([
        'room_id' => (string) ($payload['room_id'] ?? ''),
        'participants' => $payload['participants'] ?? [],
        'layout' => $payload['layout'] ?? [],
        'activity' => $payload['activity'] ?? [],
        'viewer' => $payload['viewer'] ?? [],
    ], JSON_UNESCAPED_SLASHES) ?: '');
}

function videochat_realtime_send_room_snapshot(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason
): array {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    videochat_presence_send_frame($connection['socket'] ?? null, $payload);
    return [
        'signature' => videochat_realtime_room_snapshot_signature($payload),
        'payload' => $payload,
    ];
}

function videochat_realtime_send_room_snapshot_if_changed(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string &$lastSignature,
    string $reason
): void {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    $signature = videochat_realtime_room_snapshot_signature($payload);
    if ($signature === $lastSignature) {
        return;
    }
    $lastSignature = $signature;
    videochat_presence_send_frame($connection['socket'] ?? null, $payload);
}

function videochat_sfu_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_sfu_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_publishers (
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    user_name TEXT NOT NULL,
    updated_at_ms INTEGER NOT NULL,
    PRIMARY KEY(room_id, publisher_id)
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_tracks (
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    track_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    label TEXT NOT NULL,
    updated_at_ms INTEGER NOT NULL,
    PRIMARY KEY(room_id, publisher_id, track_id)
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_frames (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    publisher_user_id TEXT NOT NULL,
    track_id TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    frame_type TEXT NOT NULL,
    data_json TEXT NOT NULL,
    created_at_ms INTEGER NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_frames_room_id ON sfu_frames(room_id, id)');
}

function videochat_sfu_upsert_publisher(PDO $pdo, string $roomId, string $publisherId, string $userId, string $userName): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_publishers(room_id, publisher_id, user_id, user_name, updated_at_ms)
VALUES(:room_id, :publisher_id, :user_id, :user_name, :updated_at_ms)
ON CONFLICT(room_id, publisher_id) DO UPDATE SET
    user_id = excluded.user_id,
    user_name = excluded.user_name,
    updated_at_ms = excluded.updated_at_ms
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':user_id' => $userId,
        ':user_name' => $userName,
        ':updated_at_ms' => videochat_sfu_now_ms(),
    ]);
}

function videochat_sfu_remove_publisher(PDO $pdo, string $roomId, string $publisherId): void
{
    $deleteTracks = $pdo->prepare('DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id');
    $deleteTracks->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
    $deletePublisher = $pdo->prepare('DELETE FROM sfu_publishers WHERE room_id = :room_id AND publisher_id = :publisher_id');
    $deletePublisher->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
}

function videochat_sfu_upsert_track(PDO $pdo, string $roomId, string $publisherId, string $trackId, string $kind, string $label): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_tracks(room_id, publisher_id, track_id, kind, label, updated_at_ms)
VALUES(:room_id, :publisher_id, :track_id, :kind, :label, :updated_at_ms)
ON CONFLICT(room_id, publisher_id, track_id) DO UPDATE SET
    kind = excluded.kind,
    label = excluded.label,
    updated_at_ms = excluded.updated_at_ms
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':track_id' => $trackId,
        ':kind' => $kind,
        ':label' => $label,
        ':updated_at_ms' => videochat_sfu_now_ms(),
    ]);
}

function videochat_sfu_remove_track(PDO $pdo, string $roomId, string $publisherId, string $trackId): void
{
    $statement = $pdo->prepare(
        'DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id AND track_id = :track_id'
    );
    $statement->execute([':room_id' => $roomId, ':publisher_id' => $publisherId, ':track_id' => $trackId]);
}

/**
 * @return array<int, array{publisher_id: string, user_id: string, user_name: string}>
 */
function videochat_sfu_fetch_publishers(PDO $pdo, string $roomId): array
{
    $statement = $pdo->prepare(
        'SELECT publisher_id, user_id, user_name FROM sfu_publishers WHERE room_id = :room_id ORDER BY publisher_id ASC'
    );
    $statement->execute([':room_id' => $roomId]);
    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'publisher_id' => (string) ($row['publisher_id'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'user_name' => (string) ($row['user_name'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * @return array<int, array{id: string, kind: string, label: string}>
 */
function videochat_sfu_fetch_tracks(PDO $pdo, string $roomId, string $publisherId): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT track_id, kind, label
FROM sfu_tracks
WHERE room_id = :room_id
  AND publisher_id = :publisher_id
ORDER BY track_id ASC
SQL
    );
    $statement->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
    $tracks = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? 'video')));
        $tracks[] = [
            'id' => (string) ($row['track_id'] ?? ''),
            'kind' => in_array($kind, ['audio', 'video'], true) ? $kind : 'video',
            'label' => (string) ($row['label'] ?? ''),
        ];
    }
    return $tracks;
}

function videochat_sfu_latest_frame_id(PDO $pdo, string $roomId): int
{
    $statement = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sfu_frames WHERE room_id = :room_id');
    $statement->execute([':room_id' => $roomId]);
    return (int) ($statement->fetchColumn() ?: 0);
}

function videochat_sfu_insert_frame(
    PDO $pdo,
    string $roomId,
    string $publisherId,
    string $publisherUserId,
    string $trackId,
    int $timestamp,
    string $frameType,
    array $frameData
): void {
    $encodedData = json_encode($frameData, JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedData)) {
        return;
    }
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_frames(room_id, publisher_id, publisher_user_id, track_id, timestamp, frame_type, data_json, created_at_ms)
VALUES(:room_id, :publisher_id, :publisher_user_id, :track_id, :timestamp, :frame_type, :data_json, :created_at_ms)
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':publisher_user_id' => $publisherUserId,
        ':track_id' => $trackId,
        ':timestamp' => $timestamp,
        ':frame_type' => $frameType,
        ':data_json' => $encodedData,
        ':created_at_ms' => videochat_sfu_now_ms(),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_fetch_frames_since(PDO $pdo, string $roomId, int $afterId, string $excludePublisherId, int $limit = 50): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, publisher_id, publisher_user_id, track_id, timestamp, frame_type, data_json
FROM sfu_frames
WHERE room_id = :room_id
  AND id > :after_id
  AND publisher_id <> :exclude_publisher_id
ORDER BY id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', $roomId, PDO::PARAM_STR);
    $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    $statement->bindValue(':exclude_publisher_id', $excludePublisherId, PDO::PARAM_STR);
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $statement->execute();
    $frames = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $frames[] = $row;
        }
    }
    return $frames;
}

function videochat_sfu_cleanup_frames(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM sfu_frames WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_sfu_now_ms() - 15_000]);
}

function videochat_sfu_poll_broker(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $clientId,
    array &$knownPublishers,
    array &$trackSignatures,
    int &$lastFrameId
): void {
    $activePublishers = [];
    foreach (videochat_sfu_fetch_publishers($pdo, $roomId) as $publisher) {
        $publisherId = (string) ($publisher['publisher_id'] ?? '');
        if ($publisherId === '' || $publisherId === $clientId) {
            continue;
        }
        $activePublishers[$publisherId] = true;
        if (!isset($knownPublishers[$publisherId])) {
            $knownPublishers[$publisherId] = true;
            videochat_presence_send_frame($websocket, [
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => [$publisherId],
            ]);
        }

        $tracks = videochat_sfu_fetch_tracks($pdo, $roomId, $publisherId);
        $trackSignature = hash('sha256', json_encode($tracks, JSON_UNESCAPED_SLASHES) ?: '');
        if ($tracks !== [] && ($trackSignatures[$publisherId] ?? '') !== $trackSignature) {
            $trackSignatures[$publisherId] = $trackSignature;
            videochat_presence_send_frame($websocket, [
                'type' => 'sfu/tracks',
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'publisher_user_id' => (string) ($publisher['user_id'] ?? ''),
                'publisher_name' => (string) ($publisher['user_name'] ?? ''),
                'tracks' => $tracks,
            ]);
        }
    }

    foreach (array_keys($knownPublishers) as $publisherId) {
        if (isset($activePublishers[$publisherId])) {
            continue;
        }
        unset($knownPublishers[$publisherId], $trackSignatures[$publisherId]);
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/publisher_left',
            'publisher_id' => $publisherId,
        ]);
    }

    foreach (videochat_sfu_fetch_frames_since($pdo, $roomId, $lastFrameId, $clientId) as $frame) {
        $lastFrameId = max($lastFrameId, (int) ($frame['id'] ?? 0));
        $decodedData = json_decode((string) ($frame['data_json'] ?? '[]'), true);
        if (!is_array($decodedData)) {
            continue;
        }
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/frame',
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'timestamp' => (int) ($frame['timestamp'] ?? 0),
            'data' => $decodedData,
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
        ]);
    }
}

function videochat_handle_sfu_routes(
    string $path,
    array $request,
    array $presenceState,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $rbacFailureResponse,
    callable $errorResponse,
    callable $openDatabase
): array {
    $handshakeValidation = videochat_realtime_validate_websocket_handshake($request, '/sfu');
    if (!(bool) ($handshakeValidation['ok'] ?? false)) {
        return $errorResponse(
            (int) ($handshakeValidation['status'] ?? 400),
            (string) ($handshakeValidation['code'] ?? 'websocket_handshake_invalid'),
            (string) ($handshakeValidation['message'] ?? 'WebSocket handshake is invalid.'),
            is_array($handshakeValidation['details'] ?? null) ? $handshakeValidation['details'] : []
        );
    }

    $websocketAuth = $authenticateRequest($request, 'websocket');
    if (!(bool) ($websocketAuth['ok'] ?? false)) {
        return $authFailureResponse('websocket', (string) ($websocketAuth['reason'] ?? 'invalid_session'));
    }

    $websocketRbacDecision = videochat_authorize_role_for_path((array) ($websocketAuth['user'] ?? []), $path, '/sfu');
    if (!(bool) ($websocketRbacDecision['ok'] ?? false)) {
        return $rbacFailureResponse('websocket', $websocketRbacDecision, $path);
    }

    $queryParams = videochat_request_query_params($request);
    $roomId = videochat_presence_normalize_room_id(
        is_string($queryParams['room'] ?? null) ? (string) $queryParams['room'] : 'lobby'
    );
    $requestedCallId = videochat_realtime_normalize_call_id(
        is_string($queryParams['call_id'] ?? null) ? (string) $queryParams['call_id'] : '',
        ''
    );
    $userId = (int) ($websocketAuth['user']['id'] ?? 0);
    $userRole = (string) ($websocketAuth['user']['role'] ?? 'user');
    $sessionId = trim((string) ($websocketAuth['token'] ?? ($websocketAuth['session']['id'] ?? '')));
    $isAdmittedInRoom = videochat_realtime_presence_has_room_membership(
        $presenceState,
        $roomId,
        $userId,
        $sessionId
    );
    $hasPersistentAdmission = videochat_realtime_user_has_sfu_room_admission(
        $openDatabase,
        $userId,
        $userRole,
        $roomId,
        $requestedCallId
    );
    if (!$isAdmittedInRoom && !$hasPersistentAdmission) {
        return $errorResponse(
            403,
            'sfu_room_admission_required',
            'Join the call room over /ws before connecting to SFU.',
            [
                'room_id' => $roomId,
                'reason' => 'room_admission_required',
            ]
        );
    }

    $session = $request['session'] ?? null;
    $streamId = (int) ($request['stream_id'] ?? 0);
    $websocket = king_server_upgrade_to_websocket($session, $streamId);
    if ($websocket === false) {
        return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
    }

    $userIdString = (string) ($websocketAuth['user']['id'] ?? '');
    $userNameCandidate = $websocketAuth['user']['name'] ?? $websocketAuth['user']['display_name'] ?? null;
    $userName = is_string($userNameCandidate) && trim($userNameCandidate) !== ''
        ? trim($userNameCandidate)
        : 'Anonymous';
    $role = is_string($queryParams['role'] ?? null) ? (string) $queryParams['role'] : 'publisher';

    static $sfuClients = [];
    static $sfuRooms = [];

    if (!isset($sfuRooms[$roomId])) {
        $sfuRooms[$roomId] = [
            'publishers' => [],
            'subscribers' => [],
        ];
    }

    $clientObjectId = is_object($websocket)
        ? (string) spl_object_id($websocket)
        : (is_resource($websocket) ? (string) get_resource_id($websocket) : bin2hex(random_bytes(4)));
    $clientId = 'sfu_' . getmypid() . '_' . $clientObjectId . '_' . bin2hex(random_bytes(4));
    $sfuClients[$clientId] = [
        'websocket' => $websocket,
        'user_id' => $userIdString,
        'user_name' => $userName,
        'room_id' => $roomId,
        'role' => $role,
        'tracks' => [],
    ];

    if ($role === 'publisher') {
        $sfuRooms[$roomId]['publishers'][$clientId] = &$sfuClients[$clientId];
    }
    // A video-call peer both publishes local media and subscribes to remote media.
    $sfuRooms[$roomId]['subscribers'][$clientId] = &$sfuClients[$clientId];

    $sfuDatabase = null;
    try {
        $sfuDatabase = $openDatabase();
        videochat_sfu_bootstrap($sfuDatabase);
        if ($role === 'publisher') {
            videochat_sfu_upsert_publisher($sfuDatabase, $roomId, $clientId, $userIdString, $userName);
        }
    } catch (Throwable) {
        $sfuDatabase = null;
    }

    king_websocket_send($websocket, json_encode([
        'type' => 'sfu/welcome',
        'user_id' => $userIdString,
        'name' => $userName,
        'room_id' => $roomId,
        'server_time' => time(),
    ]));

    $publishersInRoom = array_values(array_filter(
        array_map('strval', array_keys($sfuRooms[$roomId]['publishers'] ?? [])),
        static fn (string $publisherId): bool => $publisherId !== (string) $clientId
    ));
    if (!empty($publishersInRoom)) {
        king_websocket_send($websocket, json_encode([
            'type' => 'sfu/joined',
            'room_id' => $roomId,
            'publishers' => $publishersInRoom,
        ]));
    }

    $joinedPublishers = $role === 'publisher' ? [(string) $clientId] : [];
    if ($joinedPublishers !== []) {
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            if ((string) $subClientId === (string) $clientId) {
                continue;
            }
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => $joinedPublishers,
            ]));
        }
    }

    $knownBrokerPublishers = [];
    $brokerTrackSignatures = [];
    $lastBrokerFrameId = $sfuDatabase instanceof PDO ? videochat_sfu_latest_frame_id($sfuDatabase, $roomId) : 0;
    $nextBrokerPollMs = 0;
    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;

    while (true) {
        if ($sfuDatabase instanceof PDO && videochat_sfu_now_ms() >= $nextBrokerPollMs) {
            try {
                videochat_sfu_poll_broker(
                    $sfuDatabase,
                    $websocket,
                    $roomId,
                    $clientId,
                    $knownBrokerPublishers,
                    $brokerTrackSignatures,
                    $lastBrokerFrameId
                );
                if (videochat_sfu_now_ms() >= $nextBrokerCleanupMs) {
                    videochat_sfu_cleanup_frames($sfuDatabase);
                    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;
                }
            } catch (Throwable) {
                // Keep the live socket up even if a transient SQLite lock hits the broker poll.
            }
            $nextBrokerPollMs = videochat_sfu_now_ms() + 100;
        }

        $frame = @king_client_websocket_receive($websocket, 100);
        if ($frame === null || $frame === false) {
            $status = function_exists('king_client_websocket_get_status')
                ? (int) king_client_websocket_get_status($websocket)
                : 1;
            if ($status === 3) {
                break;
            }
            continue;
        }

        if (!is_string($frame) || trim($frame) === '') {
            continue;
        }

        $messages = preg_split('/\r?\n/', trim($frame)) ?: [];
        foreach ($messages as $msgJson) {
            if (trim($msgJson) === '') {
                continue;
            }

            $msg = json_decode($msgJson, true);
            if (!is_array($msg)) {
                continue;
            }

            $msgType = $msg['type'] ?? '';

            switch ($msgType) {
                case 'sfu/publish':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? uniqid('track_');
                    $kind = $msg['kind'] ?? 'video';
                    $label = $msg['label'] ?? '';

                    $sfuClients[$clientId]['tracks'][$trackId] = [
                        'id' => $trackId,
                        'kind' => $kind,
                        'label' => $label,
                    ];
                    if ($sfuDatabase instanceof PDO) {
                        try {
                            videochat_sfu_upsert_track(
                                $sfuDatabase,
                                $roomId,
                                (string) $clientId,
                                (string) $trackId,
                                (string) $kind,
                                (string) $label
                            );
                        } catch (Throwable) {
                            // best-effort cross-worker track advertisement
                        }
                    }

                    king_websocket_send($websocket, json_encode([
                        'type' => 'sfu/published',
                        'track_id' => $trackId,
                        'server_time' => time(),
                    ]));

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId === (string) $clientId) {
                            continue;
                        }
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $clientId,
                            'publisher_user_id' => $userIdString,
                            'publisher_name' => $userName,
                            'tracks' => array_values($sfuClients[$clientId]['tracks']),
                        ]));
                    }
                    break;

                case 'sfu/subscribe':
                    $publisherId = $msg['publisher_id'] ?? $msg['publisherId'] ?? null;
                    if (isset($sfuRooms[$roomId]['publishers'][$publisherId])) {
                        king_websocket_send($websocket, json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $publisherId,
                            'publisher_user_id' => $sfuClients[$publisherId]['user_id'],
                            'publisher_name' => $sfuClients[$publisherId]['user_name'],
                            'tracks' => array_values($sfuClients[$publisherId]['tracks']),
                        ]));
                    } elseif ($sfuDatabase instanceof PDO && is_string($publisherId) && $publisherId !== '') {
                        try {
                            foreach (videochat_sfu_fetch_publishers($sfuDatabase, $roomId) as $publisher) {
                                if ((string) ($publisher['publisher_id'] ?? '') !== $publisherId) {
                                    continue;
                                }
                                king_websocket_send($websocket, json_encode([
                                    'type' => 'sfu/tracks',
                                    'room_id' => $roomId,
                                    'publisher_id' => $publisherId,
                                    'publisher_user_id' => (string) ($publisher['user_id'] ?? ''),
                                    'publisher_name' => (string) ($publisher['user_name'] ?? ''),
                                    'tracks' => videochat_sfu_fetch_tracks($sfuDatabase, $roomId, $publisherId),
                                ]));
                                break;
                            }
                        } catch (Throwable) {
                            // best-effort cross-worker subscribe
                        }
                    }
                    break;

                case 'sfu/unpublish':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? null;
                    unset($sfuClients[$clientId]['tracks'][$trackId]);
                    if ($sfuDatabase instanceof PDO && is_string($trackId) && $trackId !== '') {
                        try {
                            videochat_sfu_remove_track($sfuDatabase, $roomId, (string) $clientId, $trackId);
                        } catch (Throwable) {
                            // best-effort cross-worker unpublish
                        }
                    }

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId === (string) $clientId) {
                            continue;
                        }
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/unpublished',
                            'publisher_id' => $clientId,
                            'publisher_user_id' => $userIdString,
                            'track_id' => $trackId,
                        ]));
                    }
                    break;

                case 'sfu/frame':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? '';
                    $timestamp = $msg['timestamp'] ?? 0;
                    $frameData = $msg['data'] ?? [];
                    $frameType = $msg['frame_type'] ?? $msg['frameType'] ?? 'delta';
                    if ($sfuDatabase instanceof PDO && is_array($frameData)) {
                        try {
                            videochat_sfu_insert_frame(
                                $sfuDatabase,
                                $roomId,
                                (string) $clientId,
                                $userIdString,
                                (string) $trackId,
                                (int) $timestamp,
                                (string) $frameType,
                                $frameData
                            );
                        } catch (Throwable) {
                            // best-effort cross-worker frame relay
                        }
                    }

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId !== (string) $clientId) {
                            king_websocket_send($subClient['websocket'], json_encode([
                                'type' => 'sfu/frame',
                                'publisher_id' => $clientId,
                                'publisher_user_id' => $userIdString,
                                'track_id' => $trackId,
                                'timestamp' => $timestamp,
                                'data' => $frameData,
                                'frame_type' => $frameType,
                            ]));
                        }
                    }
                    break;

                case 'sfu/leave':
                    break 2;
            }
        }
    }

    unset($sfuClients[$clientId]);
    if ($sfuDatabase instanceof PDO) {
        try {
            videochat_sfu_remove_publisher($sfuDatabase, $roomId, (string) $clientId);
        } catch (Throwable) {
            // best-effort cleanup; stale rows are harmless for the next reconnect.
        }
    }
    if (isset($sfuRooms[$roomId]['publishers'][$clientId])) {
        unset($sfuRooms[$roomId]['publishers'][$clientId]);
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            if ((string) $subClientId === (string) $clientId) {
                continue;
            }
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/publisher_left',
                'publisher_id' => $clientId,
                'publisher_user_id' => $userIdString,
            ]));
        }
    }
    if (isset($sfuRooms[$roomId]['subscribers'][$clientId])) {
        unset($sfuRooms[$roomId]['subscribers'][$clientId]);
    }

    return [
        'status' => 101,
        'headers' => [],
        'body' => '',
    ];
}
