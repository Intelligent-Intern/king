<?php

declare(strict_types=1);

const VIDEOCHAT_SPUTNIK_DEV_USER_ID_BASE = 880000;
const VIDEOCHAT_SPUTNIK_DEV_USER_ID_MAX = 880099;

function videochat_realtime_sputnik_dev_peers_enabled(): bool
{
    foreach (['VIDEOCHAT_ENABLE_SPUTNIK_PEERS', 'VITE_VIDEOCHAT_ENABLE_SPUTNIK_PEERS'] as $name) {
        $value = getenv($name);
        if (!is_string($value)) {
            continue;
        }
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
    }

    return false;
}

function videochat_realtime_sputnik_controller_allowed(array $authUser): bool
{
    $role = function_exists('videochat_normalize_role_slug')
        ? videochat_normalize_role_slug((string) ($authUser['role'] ?? ''))
        : strtolower(trim((string) ($authUser['role'] ?? '')));

    return in_array($role, ['admin', 'owner', 'moderator'], true);
}

/**
 * @return array{ok: bool, peer_id: string, user_id: int, display_name: string}
 */
function videochat_realtime_sputnik_peer_descriptor_from_query(array $queryParams): array
{
    $rawPeerId = strtolower(trim((string) ($queryParams['dev_sputnik_peer_id'] ?? $queryParams['sputnik_peer_id'] ?? '')));
    $rawPeerId = preg_replace('/[^a-z0-9_-]+/', '', $rawPeerId) ?? '';
    if ($rawPeerId === '') {
        return [
            'ok' => false,
            'peer_id' => '',
            'user_id' => 0,
            'display_name' => '',
        ];
    }

    if ($rawPeerId === 'alice') {
        return [
            'ok' => true,
            'peer_id' => 'alice',
            'user_id' => VIDEOCHAT_SPUTNIK_DEV_USER_ID_BASE,
            'display_name' => 'Alice',
        ];
    }

    if (preg_match('/^sputnik-([1-9][0-9]?)$/', $rawPeerId, $matches) !== 1) {
        return [
            'ok' => false,
            'peer_id' => '',
            'user_id' => 0,
            'display_name' => '',
        ];
    }

    $index = (int) $matches[1];
    $userId = VIDEOCHAT_SPUTNIK_DEV_USER_ID_BASE + $index;
    if ($userId > VIDEOCHAT_SPUTNIK_DEV_USER_ID_MAX) {
        return [
            'ok' => false,
            'peer_id' => '',
            'user_id' => 0,
            'display_name' => '',
        ];
    }

    return [
        'ok' => true,
        'peer_id' => 'sputnik-' . $index,
        'user_id' => $userId,
        'display_name' => 'Sputnik ' . $index,
    ];
}

function videochat_realtime_apply_sputnik_dev_identity(array $queryParams, array $authUser): array
{
    if (!videochat_realtime_sputnik_dev_peers_enabled()) {
        return $authUser;
    }
    if (!videochat_realtime_sputnik_controller_allowed($authUser)) {
        return $authUser;
    }

    $descriptor = videochat_realtime_sputnik_peer_descriptor_from_query($queryParams);
    if (!(bool) ($descriptor['ok'] ?? false)) {
        return $authUser;
    }

    $controllerUserId = (int) ($authUser['id'] ?? 0);
    $peerId = (string) $descriptor['peer_id'];
    $displayName = (string) $descriptor['display_name'];
    $authUser['id'] = (int) $descriptor['user_id'];
    $authUser['email'] = $peerId . '@sputnik.local';
    $authUser['display_name'] = $displayName;
    $authUser['role'] = 'user';
    $authUser['dev_sputnik_peer_id'] = $peerId;
    $authUser['dev_sputnik_controller_user_id'] = $controllerUserId;
    $authUser['dev_sputnik'] = true;

    return $authUser;
}

/**
 * @return array<int, array{peer_id: string, user_id: int, display_name: string}>
 */
function videochat_realtime_sputnik_peer_descriptors_from_payload(array $payload): array
{
    $peers = is_array($payload['peers'] ?? null) ? $payload['peers'] : [];
    $descriptors = [];
    $seen = [];

    foreach ($peers as $peer) {
        if (!is_array($peer)) {
            continue;
        }
        $descriptor = videochat_realtime_sputnik_peer_descriptor_from_query([
            'dev_sputnik_peer_id' => $peer['logical_peer_id'] ?? ($peer['peer_id'] ?? ''),
        ]);
        if (!(bool) ($descriptor['ok'] ?? false)) {
            continue;
        }

        $peerId = (string) $descriptor['peer_id'];
        if (isset($seen[$peerId])) {
            continue;
        }
        $seen[$peerId] = true;
        $displayName = trim((string) ($peer['display_name'] ?? ''));
        if ($displayName !== '') {
            $descriptor['display_name'] = substr($displayName, 0, 80);
        }
        $descriptors[] = [
            'peer_id' => $peerId,
            'user_id' => (int) $descriptor['user_id'],
            'display_name' => (string) $descriptor['display_name'],
        ];
    }

    return $descriptors;
}

/**
 * @return array{ok: bool, reason: string, peers: array<int, array<string, mixed>>, errors?: array<string, mixed>}
 */
function videochat_realtime_prepare_sputnik_dev_peer_sessions(
    PDO $pdo,
    array $authContext,
    array $payload,
    array $request,
    callable $issueSessionId
): array {
    if (!videochat_realtime_sputnik_dev_peers_enabled()) {
        return [
            'ok' => false,
            'reason' => 'sputnik_peers_disabled',
            'peers' => [],
        ];
    }

    $controllerUser = is_array($authContext['user'] ?? null) ? $authContext['user'] : [];
    if (!videochat_realtime_sputnik_controller_allowed($controllerUser)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'peers' => [],
        ];
    }

    $callId = videochat_realtime_normalize_call_id((string) ($payload['call_id'] ?? ''), '');
    $roomId = videochat_presence_normalize_room_id((string) ($payload['room_id'] ?? ''), '');
    if ($callId === '' || $roomId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_call_context',
            'peers' => [],
        ];
    }

    $descriptors = videochat_realtime_sputnik_peer_descriptors_from_payload($payload);
    if ($descriptors === []) {
        return [
            'ok' => false,
            'reason' => 'missing_peers',
            'peers' => [],
        ];
    }

    $callQuery = $pdo->prepare(
        <<<'SQL'
SELECT id, room_id
FROM calls
WHERE id = :call_id
LIMIT 1
SQL
    );
    $callQuery->execute([':call_id' => $callId]);
    $call = $callQuery->fetch();
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'call_not_found',
            'peers' => [],
        ];
    }
    $resolvedRoomId = videochat_presence_normalize_room_id((string) ($call['room_id'] ?? $roomId), $roomId);

    $tenantId = videochat_tenant_id_from_auth_context($authContext);
    if ($tenantId <= 0) {
        $tenantId = videochat_tenant_default_id($pdo);
    }

    $roleMap = [];
    $roleRows = $pdo->query('SELECT id, slug FROM roles');
    foreach ($roleRows ?: [] as $row) {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug !== '') {
            $roleMap[$slug] = (int) ($row['id'] ?? 0);
        }
    }
    $userRoleId = (int) ($roleMap['user'] ?? 0);
    if ($userRoleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'missing_user_role',
            'peers' => [],
        ];
    }

    $selectUser = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $updateUser = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET display_name = :display_name,
    password_hash = :password_hash,
    role_id = :role_id,
    status = 'active',
    time_format = '24h',
    date_format = 'dmy_dot',
    theme = 'dark',
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $upsertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'participant', 'allowed', NULL, NULL)
ON CONFLICT(call_id, email) DO UPDATE SET
    user_id = excluded.user_id,
    display_name = excluded.display_name,
    source = 'internal',
    call_role = CASE
        WHEN call_participants.call_role = 'owner' THEN 'owner'
        ELSE 'participant'
    END,
    invite_state = 'allowed',
    left_at = NULL
SQL
    );

    $clientIp = trim((string) ($request['remote_address'] ?? ''));
    $userAgent = substr(videochat_request_header_value($request, 'user-agent'), 0, 500);
    $prepared = [];

    foreach ($descriptors as $descriptor) {
        $logicalPeerId = (string) $descriptor['peer_id'];
        $email = $logicalPeerId . '@sputnik.local';
        $displayName = (string) $descriptor['display_name'];
        $passwordHash = password_hash('SputnikDev-' . $logicalPeerId . '-local', PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return [
                'ok' => false,
                'reason' => 'password_hash_failed',
                'peers' => [],
            ];
        }

        $selectUser->execute([':email' => $email]);
        $existingUser = $selectUser->fetch();
        if (is_array($existingUser)) {
            $userId = (int) ($existingUser['id'] ?? 0);
            $updateUser->execute([
                ':id' => $userId,
                ':display_name' => $displayName,
                ':password_hash' => $passwordHash,
                ':role_id' => $userRoleId,
                ':updated_at' => gmdate('c'),
            ]);
        } else {
            $insertUser->execute([
                ':email' => $email,
                ':display_name' => $displayName,
                ':password_hash' => $passwordHash,
                ':role_id' => $userRoleId,
                ':updated_at' => gmdate('c'),
            ]);
            $userId = (int) $pdo->lastInsertId();
        }

        if ($userId <= 0) {
            return [
                'ok' => false,
                'reason' => 'user_upsert_failed',
                'peers' => [],
            ];
        }
        if ($tenantId > 0) {
            videochat_tenant_attach_user($pdo, $userId, $tenantId, 'member');
        }

        $upsertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
        ]);

        $sessionResult = videochat_issue_session_for_user(
            $pdo,
            $userId,
            $issueSessionId,
            null,
            $clientIp,
            $userAgent,
            null,
            $tenantId > 0 ? $tenantId : null
        );
        if (!(bool) ($sessionResult['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => 'session_issue_failed',
                'peers' => [],
                'errors' => [
                    'peer_id' => $logicalPeerId,
                    'session_reason' => (string) ($sessionResult['reason'] ?? 'unknown'),
                ],
            ];
        }

        $prepared[] = [
            'logical_peer_id' => $logicalPeerId,
            'user_id' => $userId,
            'peer_id' => (string) $userId,
            'email' => $email,
            'display_name' => $displayName,
            'room_id' => $resolvedRoomId,
            'call_id' => $callId,
            'session' => $sessionResult['session'],
        ];
    }

    return [
        'ok' => true,
        'reason' => 'prepared',
        'peers' => $prepared,
    ];
}
