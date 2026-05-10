<?php

declare(strict_types=1);

$contract = 'iam11-17-call-access-edge-proof-contract';

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam1117_assert(bool $condition, string $message): void
{
    global $contract;
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$contract}] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam1117_count(PDO $pdo, string $sql, array $params = []): int
{
    $query = $pdo->prepare($sql);
    $query->execute($params);
    return max(0, (int) ($query->fetchColumn() ?: 0));
}

function videochat_iam1117_user_status(PDO $pdo, int $userId): string
{
    $query = $pdo->prepare('SELECT status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    return (string) ($query->fetchColumn() ?: '');
}

function videochat_iam1117_invite_state(PDO $pdo, string $callId, int $userId): string
{
    $query = $pdo->prepare('SELECT invite_state FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $query->execute([':call_id' => $callId, ':user_id' => $userId]);
    return videochat_realtime_normalize_call_invite_state($query->fetchColumn() ?: 'invited');
}

function videochat_iam1117_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, array $participants = [], string $accessMode = 'invite_only'): array
{
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => videochat_normalize_call_access_mode($accessMode),
        'starts_at' => gmdate('c', time() - 60),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participants,
        'external_participants' => [],
    ], $tenantId);
    videochat_iam1117_assert((bool) ($created['ok'] ?? false), "{$title}: call should be created");

    $call = is_array($created['call'] ?? null) ? $created['call'] : [];
    $callId = (string) ($call['id'] ?? '');
    $roomId = (string) ($call['room_id'] ?? '');
    videochat_iam1117_assert($callId !== '' && $roomId !== '', "{$title}: call and room ids should be present");

    return ['call_id' => $callId, 'room_id' => $roomId];
}

function videochat_iam1117_create_access(PDO $pdo, string $callId, int $ownerUserId, int $tenantId, array $options, string $label): string
{
    $link = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', $options, $tenantId);
    videochat_iam1117_assert((bool) ($link['ok'] ?? false), "{$label}: access link should be created");
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam1117_assert($accessId !== '', "{$label}: access id should be present");
    return $accessId;
}

function videochat_iam1117_issue_access_session(PDO $pdo, string $accessId, string $sessionId, array $options = []): array
{
    return videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'iam11-17-call-access-edge-proof-contract'],
        $options
    );
}

function videochat_iam1117_auth(PDO $pdo, string $sessionId, string $roomId = '', string $callId = ''): array
{
    $query = '/ws?session=' . rawurlencode($sessionId);
    if ($roomId !== '') {
        $query .= '&room=' . rawurlencode($roomId);
    }
    if ($callId !== '') {
        $query .= '&call_id=' . rawurlencode($callId);
    }

    return videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => $query, 'headers' => ['Authorization' => 'Bearer ' . $sessionId]],
        'websocket'
    );
}

function videochat_iam1117_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $role,
    string $suffix,
    int $tenantId,
    string $sessionId,
    bool $waiting
): array {
    $initialRoomId = $waiting ? videochat_realtime_waiting_room_id() : $roomId;
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $role,
            'tenant' => ['id' => $tenantId],
        ],
        $sessionId,
        'conn-' . $suffix,
        'socket-' . $suffix,
        $initialRoomId
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_room_id'] = $roomId;
    $connection['requested_call_id'] = $callId;
    if ($waiting) {
        $connection['pending_room_id'] = $roomId;
    }
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $join = videochat_presence_join_room($presenceState, $connection, $initialRoomId);
    $connection = (array) ($join['connection'] ?? $connection);
    $connection['tenant_id'] = $tenantId;
    $connection['requested_room_id'] = $roomId;
    $connection['requested_call_id'] = $callId;
    if ($waiting) {
        $connection['pending_room_id'] = $roomId;
    }
    $connection = videochat_realtime_connection_with_call_context($connection, static fn (): PDO => $pdo);
    $presenceState['connections'][(string) ($connection['connection_id'] ?? ('conn-' . $suffix))] = $connection;

    return $connection;
}

function videochat_iam1117_lobby_command(string $type, string $roomId, int $targetUserId = 0): array
{
    $payload = ['type' => $type, 'room_id' => $roomId];
    if ($targetUserId > 0) {
        $payload['target_user_id'] = $targetUserId;
    }
    $command = videochat_lobby_decode_client_frame(json_encode($payload, JSON_UNESCAPED_SLASHES));
    videochat_iam1117_assert((bool) ($command['ok'] ?? false), "{$type}: lobby command should decode");
    return $command;
}

function videochat_iam1117_apply_lobby_command(
    PDO $pdo,
    array &$lobbyState,
    array &$presenceState,
    array $connection,
    string $type,
    string $roomId,
    int $targetUserId = 0
): array {
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key] ??= [];
        $frames[$key][] = $payload;
        return true;
    };
    $openDatabase = static fn (): PDO => $pdo;

    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        null,
        videochat_realtime_connection_tenant_id($connection)
    );
    $result = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $connection,
        videochat_iam1117_lobby_command($type, $roomId, $targetUserId),
        $sender
    );
    if ((bool) ($result['ok'] ?? false)) {
        videochat_realtime_apply_successful_lobby_command($result, $lobbyState, $presenceState, $connection, $openDatabase);
    }
    $result['frames'] = $frames;
    return $result;
}

function videochat_iam1117_assert_stale_access_denied(PDO $pdo, string $accessId, string $label): void
{
    $resolve = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam1117_assert(!(bool) ($resolve['ok'] ?? true), "{$label}: stale link should not resolve");
    videochat_iam1117_assert((string) ($resolve['reason'] ?? '') === 'not_found', "{$label}: stale link should fail as not_found");

    $sessionId = 'sess_iam1117_stale_' . preg_replace('/[^a-z0-9_]+/i', '_', $label);
    $lateSession = videochat_iam1117_issue_access_session($pdo, $accessId, $sessionId, ['guest_name' => 'IAM11-17 Stale Guest']);
    videochat_iam1117_assert(!(bool) ($lateSession['ok'] ?? true), "{$label}: stale link should not issue late session");
    videochat_iam1117_assert((string) ($lateSession['reason'] ?? '') === 'not_found', "{$label}: stale session denial should be not_found");
    videochat_iam1117_assert(
        videochat_iam1117_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id', [':id' => $sessionId]) === 0,
        "{$label}: stale access must not persist a session"
    );
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-' . $contract . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $ownerUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $registeredUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam1117_assert($tenantId > 0 && $ownerUserId > 0 && $registeredUserId > 0, 'seed tenant, owner, and registered user should exist');

    $tempCall = videochat_iam1117_create_call($pdo, $ownerUserId, $tenantId, 'IAM11-17 Temp Kick Rejoin', [], 'free_for_all');
    $tempCallId = $tempCall['call_id'];
    $tempRoomId = $tempCall['room_id'];
    $tempAccessId = videochat_iam1117_create_access($pdo, $tempCallId, $ownerUserId, $tenantId, ['link_kind' => 'open'], 'temp open');
    $tempSessionId = 'sess_iam1117_temp_guest';
    $tempSession = videochat_iam1117_issue_access_session($pdo, $tempAccessId, $tempSessionId, ['guest_name' => 'IAM11-17 Temp Guest']);
    videochat_iam1117_assert((bool) ($tempSession['ok'] ?? false), 'temp guest session should issue');
    $tempUserId = (int) (($tempSession['user'] ?? [])['id'] ?? 0);
    videochat_iam1117_assert($tempUserId > 0 && (bool) (($tempSession['user'] ?? [])['is_guest'] ?? false), 'temp session should allocate a guest user');

    $tempAuth = videochat_iam1117_auth($pdo, $tempSessionId, $tempRoomId, $tempCallId);
    videochat_iam1117_assert((bool) ($tempAuth['ok'] ?? false), 'temp guest auth should pass before kick');
    $openDatabase = static fn (): PDO => $pdo;
    $initialTempResolution = videochat_realtime_resolve_connection_rooms($tempAuth, $tempRoomId, $openDatabase, $tempCallId);
    videochat_iam1117_assert((string) ($initialTempResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'temp guest should wait before approval');
    videochat_iam1117_assert((string) ($initialTempResolution['pending_room_id'] ?? '') === $tempRoomId, 'temp guest pending room should stay call-bound');

    $tempPresenceState = videochat_presence_state_init();
    $tempLobbyState = videochat_lobby_state_init();
    $ownerConnection = videochat_iam1117_connection($pdo, $tempPresenceState, $tempRoomId, $tempCallId, $ownerUserId, 'Call Owner', 'admin', 'iam1117-owner', $tenantId, 'sess-iam1117-owner', false);
    $tempWaitingConnection = videochat_iam1117_connection($pdo, $tempPresenceState, $tempRoomId, $tempCallId, $tempUserId, 'IAM11-17 Temp Guest', 'user', 'iam1117-temp', $tenantId, $tempSessionId, true);

    $queue = videochat_iam1117_apply_lobby_command($pdo, $tempLobbyState, $tempPresenceState, $tempWaitingConnection, 'lobby/queue/join', $tempRoomId);
    videochat_iam1117_assert((bool) ($queue['ok'] ?? false), 'temp guest should queue for approval');
    videochat_iam1117_assert(videochat_iam1117_invite_state($pdo, $tempCallId, $tempUserId) === 'pending', 'queued temp guest should persist pending invite state');

    $allow = videochat_iam1117_apply_lobby_command($pdo, $tempLobbyState, $tempPresenceState, $ownerConnection, 'lobby/allow', $tempRoomId, $tempUserId);
    videochat_iam1117_assert((bool) ($allow['ok'] ?? false), 'owner should approve temp guest');
    videochat_iam1117_assert(videochat_iam1117_invite_state($pdo, $tempCallId, $tempUserId) === 'allowed', 'approved temp guest should persist allowed invite state');
    $approvedResolution = videochat_realtime_resolve_connection_rooms($tempAuth, $tempRoomId, $openDatabase, $tempCallId);
    videochat_iam1117_assert((string) ($approvedResolution['initial_room_id'] ?? '') === $tempRoomId, 'approved temp guest should directly enter call room');

    $kick = videochat_iam1117_apply_lobby_command($pdo, $tempLobbyState, $tempPresenceState, $ownerConnection, 'lobby/kick', $tempRoomId, $tempUserId);
    videochat_iam1117_assert((bool) ($kick['ok'] ?? false), 'owner should kick admitted temp guest');
    videochat_iam1117_assert((string) ($kick['action'] ?? '') === 'lobby/remove', 'kick should normalize to lobby/remove');
    videochat_iam1117_assert(videochat_iam1117_invite_state($pdo, $tempCallId, $tempUserId) === 'invited', 'kick should clear prior allowed admission');
    $kickedResolution = videochat_realtime_resolve_connection_rooms($tempAuth, $tempRoomId, $openDatabase, $tempCallId);
    videochat_iam1117_assert((string) ($kickedResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'kicked temp guest must not directly rejoin');
    videochat_iam1117_assert((string) ($kickedResolution['pending_room_id'] ?? '') === $tempRoomId, 'kicked temp guest should require renewed approval for same call');

    $renewedQueue = videochat_iam1117_apply_lobby_command($pdo, $tempLobbyState, $tempPresenceState, $tempWaitingConnection, 'lobby/queue/join', $tempRoomId);
    videochat_iam1117_assert((bool) ($renewedQueue['ok'] ?? false), 'kicked temp guest should be able to request renewed approval');
    $renewedAllow = videochat_iam1117_apply_lobby_command($pdo, $tempLobbyState, $tempPresenceState, $ownerConnection, 'lobby/allow', $tempRoomId, $tempUserId);
    videochat_iam1117_assert((bool) ($renewedAllow['ok'] ?? false), 'renewed owner approval should succeed after kick');
    $renewedResolution = videochat_realtime_resolve_connection_rooms($tempAuth, $tempRoomId, $openDatabase, $tempCallId);
    videochat_iam1117_assert((string) ($renewedResolution['initial_room_id'] ?? '') === $tempRoomId, 'only renewed approval should restore direct temp rejoin');

    $disabledCall = videochat_iam1117_create_call($pdo, $ownerUserId, $tenantId, 'IAM11-17 Disabled User Revocation', [$registeredUserId]);
    $disabledCallId = $disabledCall['call_id'];
    $disabledRoomId = $disabledCall['room_id'];
    $disabledAccessId = videochat_iam1117_create_access($pdo, $disabledCallId, $ownerUserId, $tenantId, [
        'link_kind' => 'personal',
        'participant_user_id' => $registeredUserId,
    ], 'disabled personal');
    $disabledSessionId = 'sess_iam1117_disabled_registered';
    $disabledSession = videochat_iam1117_issue_access_session($pdo, $disabledAccessId, $disabledSessionId);
    videochat_iam1117_assert((bool) ($disabledSession['ok'] ?? false), 'registered personal session should issue before disable');
    $pdo->prepare("UPDATE call_participants SET invite_state = 'allowed' WHERE call_id = :call_id AND user_id = :user_id")->execute([
        ':call_id' => $disabledCallId,
        ':user_id' => $registeredUserId,
    ]);
    $preDisableAuth = videochat_iam1117_auth($pdo, $disabledSessionId, $disabledRoomId, $disabledCallId);
    videochat_iam1117_assert((bool) ($preDisableAuth['ok'] ?? false), 'registered personal session should authenticate before disable');
    $preDisableResolution = videochat_realtime_resolve_connection_rooms($preDisableAuth, $disabledRoomId, $openDatabase, $disabledCallId);
    videochat_iam1117_assert((string) ($preDisableResolution['initial_room_id'] ?? '') === $disabledRoomId, 'allowed registered user should resolve into the room before disable');

    $deactivate = videochat_admin_deactivate_user($pdo, $registeredUserId, $tenantId);
    videochat_iam1117_assert((bool) ($deactivate['ok'] ?? false), 'admin user deactivation should succeed');
    videochat_iam1117_assert((string) ($deactivate['reason'] ?? '') === 'deactivated', 'deactivation should report deactivated');
    videochat_iam1117_assert((int) ($deactivate['revoked_sessions'] ?? 0) >= 1, 'deactivation should revoke active registered sessions');
    videochat_iam1117_assert(videochat_iam1117_user_status($pdo, $registeredUserId) === 'disabled', 'registered user should be disabled');
    videochat_iam1117_assert(
        videochat_iam1117_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id AND revoked_at IS NOT NULL AND revoked_at <> \'\'', [':id' => $disabledSessionId]) === 1,
        'registered call-access session should be stamped revoked'
    );
    $postDisableAuth = videochat_iam1117_auth($pdo, $disabledSessionId, $disabledRoomId, $disabledCallId);
    videochat_iam1117_assert(!(bool) ($postDisableAuth['ok'] ?? true), 'disabled user session must not authenticate');
    videochat_iam1117_assert((string) ($postDisableAuth['reason'] ?? '') === 'revoked_session', 'disabled user session should fail through explicit revocation');

    $rescheduleCall = videochat_iam1117_create_call($pdo, $ownerUserId, $tenantId, 'IAM11-17 Reschedule Stale Links', [], 'free_for_all');
    $rescheduleCallId = $rescheduleCall['call_id'];
    $oldOpenAccessId = videochat_iam1117_create_access($pdo, $rescheduleCallId, $ownerUserId, $tenantId, ['link_kind' => 'open'], 'old open reschedule');
    $oldOpenSessionId = 'sess_iam1117_old_open_reschedule';
    $oldOpenSession = videochat_iam1117_issue_access_session($pdo, $oldOpenAccessId, $oldOpenSessionId, ['guest_name' => 'IAM11-17 Old Open Guest']);
    videochat_iam1117_assert((bool) ($oldOpenSession['ok'] ?? false), 'old open session should issue before reschedule');
    $oldOpenGuestId = (int) (($oldOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_iam1117_assert($oldOpenGuestId > 0, 'old open link should allocate a temporary guest');

    $rescheduleUpdate = videochat_update_call($pdo, $rescheduleCallId, $ownerUserId, 'admin', [
        'starts_at' => gmdate('c', time() + 7200),
        'ends_at' => gmdate('c', time() + 10800),
    ], $tenantId);
    videochat_iam1117_assert((bool) ($rescheduleUpdate['ok'] ?? false), 'owner reschedule should succeed');
    $rescheduleLifecycle = is_array($rescheduleUpdate['lifecycle'] ?? null) ? $rescheduleUpdate['lifecycle'] : [];
    videochat_iam1117_assert(($rescheduleLifecycle['applied'] ?? null) === true, 'reschedule lifecycle should be applied');
    videochat_iam1117_assert((int) ($rescheduleLifecycle['invalidated_link_count'] ?? 0) >= 1, 'reschedule should invalidate stale open link');
    videochat_iam1117_assert((int) ($rescheduleLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'reschedule should revoke old open access session');
    videochat_iam1117_assert(videochat_iam1117_user_status($pdo, $oldOpenGuestId) === 'disabled', 'old open temporary guest should be invalidated');
    videochat_iam1117_assert(
        videochat_iam1117_count($pdo, 'SELECT COUNT(*) FROM sessions WHERE id = :id AND revoked_at IS NOT NULL AND revoked_at <> \'\'', [':id' => $oldOpenSessionId]) === 1,
        'old open access session should be revoked after reschedule'
    );
    videochat_iam1117_assert_stale_access_denied($pdo, $oldOpenAccessId, 'old_open_reschedule');

    $newOpenAccessId = videochat_iam1117_create_access($pdo, $rescheduleCallId, $ownerUserId, $tenantId, ['link_kind' => 'open'], 'new open reschedule');
    $newOpenSession = videochat_iam1117_issue_access_session($pdo, $newOpenAccessId, 'sess_iam1117_new_open_reschedule', ['guest_name' => 'IAM11-17 New Open Guest']);
    videochat_iam1117_assert((bool) ($newOpenSession['ok'] ?? false), 'new open link should issue after reschedule');
    $newOpenGuestId = (int) (($newOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_iam1117_assert($newOpenGuestId > 0 && $newOpenGuestId !== $oldOpenGuestId, 'new open link should allocate a fresh temp guest');
    videochat_iam1117_assert((string) (($newOpenSession['call'] ?? [])['id'] ?? '') === $rescheduleCallId, 'new open session should bind to current rescheduled call');

    @unlink($databasePath);
    fwrite(STDOUT, "[{$contract}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
