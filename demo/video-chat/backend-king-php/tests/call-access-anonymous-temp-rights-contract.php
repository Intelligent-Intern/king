<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime_lobby_security.php';

function videochat_anonymous_temp_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-anonymous-temp-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_anonymous_temp_rights_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_anonymous_temp_rights_create_user(PDO $pdo, string $email, string $displayName): int
{
    $passwordHash = password_hash('anonymous-temp-rights-contract', PASSWORD_DEFAULT);
    videochat_anonymous_temp_rights_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => videochat_anonymous_temp_rights_role_id($pdo, 'user'),
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_anonymous_temp_rights_assert($userId > 0, "{$displayName} should be created");
    return $userId;
}

function videochat_anonymous_temp_rights_create_tenant(PDO $pdo, string $unique, string $suffix): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => "tenant-anonymous-temp-rights-{$suffix}-{$unique}",
        ':slug' => "anonymous-temp-rights-{$suffix}-{$unique}",
        ':label' => "Anonymous Temp Rights {$suffix}",
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_anonymous_temp_rights_assert($tenantId > 0, "{$suffix} tenant should be created");
    return $tenantId;
}

function videochat_anonymous_temp_rights_create_organization(PDO $pdo, int $tenantId, string $unique, string $suffix): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => "org-anonymous-temp-rights-{$suffix}-{$unique}",
        ':name' => "Anonymous Temp Rights {$suffix}",
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_anonymous_temp_rights_assert($organizationId > 0, "{$suffix} organization should be created");
    return $organizationId;
}

function videochat_anonymous_temp_rights_attach_user(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role): void
{
    videochat_tenant_attach_user($pdo, $userId, $tenantId);

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_anonymous_temp_rights_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title): string
{
    $create = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_anonymous_temp_rights_assert((bool) ($create['ok'] ?? false), "{$title} should be created");

    $callId = (string) (($create['call'] ?? [])['id'] ?? '');
    videochat_anonymous_temp_rights_assert($callId !== '', "{$title} should return a call id");
    return $callId;
}

function videochat_anonymous_temp_rights_insert_open_link(PDO $pdo, int $tenantId, string $callId, int $createdByUserId): string
{
    $accessId = videochat_generate_call_access_uuid();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_links(
    id,
    tenant_id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at
) VALUES(
    :id,
    :tenant_id,
    :call_id,
    NULL,
    NULL,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL
)
SQL
    );
    $insert->execute([
        ':id' => $accessId,
        ':tenant_id' => $tenantId,
        ':call_id' => $callId,
        ':created_by_user_id' => $createdByUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    return $accessId;
}

function videochat_anonymous_temp_rights_participant(PDO $pdo, string $callId, int $userId): ?array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, source, call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch();

    return is_array($row) ? $row : null;
}

function videochat_anonymous_temp_rights_guest_list_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND source = 'internal'
  AND invite_state IN ('invited', 'allowed', 'accepted')
SQL
    );
    $query->execute([':call_id' => $callId]);

    return (int) $query->fetchColumn();
}

function videochat_anonymous_temp_rights_connection(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $connectionId
): array {
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_anonymous_temp_rights_assert((bool) ($auth['ok'] ?? false), "{$connectionId}: session should authenticate");

    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_anonymous_temp_rights_assert((bool) ($resolution['ok'] ?? false), "{$connectionId}: room resolution should succeed");
    videochat_anonymous_temp_rights_assert((string) ($resolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$connectionId}: temporary guest should start in lobby");
    videochat_anonymous_temp_rights_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$connectionId}: pending room should stay call-bound");

    $connection = videochat_presence_connection_descriptor(
        (array) ($auth['user'] ?? []),
        $sessionId,
        $connectionId,
        'socket-' . $connectionId,
        (string) ($resolution['initial_room_id'] ?? videochat_realtime_waiting_room_id())
    );
    $connection['requested_room_id'] = (string) ($resolution['requested_room_id'] ?? '');
    $connection['pending_room_id'] = (string) ($resolution['pending_room_id'] ?? '');
    $connection['requested_call_id'] = $callId;

    return videochat_realtime_connection_with_call_context($connection, $openDatabase);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-temp-rights-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-temp-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    videochat_anonymous_temp_rights_assert(videochat_anonymous_temp_rights_role_id($pdo, 'user') > 0, 'user role should exist');

    $unique = bin2hex(random_bytes(5));
    $tenantAId = videochat_anonymous_temp_rights_create_tenant($pdo, $unique, 'a');
    $tenantBId = videochat_anonymous_temp_rights_create_tenant($pdo, $unique, 'b');
    $organizationAId = videochat_anonymous_temp_rights_create_organization($pdo, $tenantAId, $unique, 'a');
    $organizationBId = videochat_anonymous_temp_rights_create_organization($pdo, $tenantBId, $unique, 'b');

    $ownerAId = videochat_anonymous_temp_rights_create_user($pdo, "anonymous-temp-owner-a-{$unique}@example.test", 'Anonymous Temp Owner A');
    $ownerBId = videochat_anonymous_temp_rights_create_user($pdo, "anonymous-temp-owner-b-{$unique}@example.test", 'Anonymous Temp Owner B');
    $orgAdminAId = videochat_anonymous_temp_rights_create_user($pdo, "anonymous-temp-org-admin-a-{$unique}@example.test", 'Anonymous Temp Org Admin A');
    $memberAId = videochat_anonymous_temp_rights_create_user($pdo, "anonymous-temp-member-a-{$unique}@example.test", 'Anonymous Temp Member A');

    videochat_anonymous_temp_rights_attach_user($pdo, $tenantAId, $organizationAId, $ownerAId, 'member');
    videochat_anonymous_temp_rights_attach_user($pdo, $tenantAId, $organizationAId, $orgAdminAId, 'admin');
    videochat_anonymous_temp_rights_attach_user($pdo, $tenantAId, $organizationAId, $memberAId, 'member');
    videochat_anonymous_temp_rights_attach_user($pdo, $tenantBId, $organizationBId, $ownerBId, 'member');

    $callAId = videochat_anonymous_temp_rights_create_call($pdo, $ownerAId, $tenantAId, 'Anonymous Temp Rights A');
    $callBId = videochat_anonymous_temp_rights_create_call($pdo, $ownerBId, $tenantBId, 'Anonymous Temp Rights B');
    $callA = videochat_fetch_call_for_update($pdo, $callAId, $tenantAId);
    videochat_anonymous_temp_rights_assert(is_array($callA), 'tenant A call should load');
    videochat_anonymous_temp_rights_assert(
        videochat_can_administer_call($pdo, $callAId, 'user', $orgAdminAId, $ownerAId, $tenantAId),
        'same-organization admin should administer owner call'
    );
    videochat_anonymous_temp_rights_assert(
        !videochat_can_administer_call($pdo, $callBId, 'user', $orgAdminAId, $ownerBId, $tenantBId),
        'organization admin rights must not cross tenant or organization boundaries'
    );

    $personalLink = videochat_create_call_access_link_for_user($pdo, $callAId, $orgAdminAId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $memberAId,
    ], $tenantAId);
    videochat_anonymous_temp_rights_assert((bool) ($personalLink['ok'] ?? false), 'same-organization admin should create bounded personal call link');

    $crossOrgLink = videochat_create_call_access_link_for_user($pdo, $callBId, $orgAdminAId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $memberAId,
    ], $tenantBId);
    videochat_anonymous_temp_rights_assert(!(bool) ($crossOrgLink['ok'] ?? true), 'organization admin must not create links for another organization call');

    $openAccessId = videochat_anonymous_temp_rights_insert_open_link($pdo, $tenantAId, $callAId, $orgAdminAId);
    $guestListBefore = videochat_anonymous_temp_rights_guest_list_count($pdo, $callAId);
    $guestUsersBefore = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => 'sess_anonymous_temp_rights_guest',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-temp-rights-contract'],
        ['guest_name' => 'Anonymous Temp Org Admin A']
    );
    videochat_anonymous_temp_rights_assert((bool) ($session['ok'] ?? false), 'anonymous invite-only call-link session should issue for lobby/admission flow');
    $guestUser = is_array($session['user'] ?? null) ? $session['user'] : [];
    $guestUserId = (int) ($guestUser['id'] ?? 0);
    videochat_anonymous_temp_rights_assert($guestUserId > 0 && $guestUserId !== $orgAdminAId, 'temporary account should be distinct from matching org-admin display name');
    videochat_anonymous_temp_rights_assert((bool) ($guestUser['is_guest'] ?? false), 'temporary account should be marked guest');
    videochat_anonymous_temp_rights_assert((string) ($guestUser['role'] ?? '') === 'user', 'temporary account must keep user role');
    videochat_anonymous_temp_rights_assert(
        (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn() === $guestUsersBefore + 1,
        'anonymous link should create exactly one temporary guest account'
    );
    videochat_anonymous_temp_rights_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $callAId, $guestUserId, $tenantAId),
        'temporary account must not inherit organization admin rights'
    );
    videochat_anonymous_temp_rights_assert(
        !videochat_can_administer_call($pdo, $callAId, 'user', $guestUserId, $ownerAId, $tenantAId),
        'temporary account must not administer the call'
    );

    $directDecision = videochat_decide_call_access_for_user($pdo, $callAId, $guestUserId, 'user', $tenantAId);
    videochat_anonymous_temp_rights_assert(!(bool) ($directDecision['allowed'] ?? true), 'temporary account must not gain direct call access');
    videochat_anonymous_temp_rights_assert((string) ($directDecision['source'] ?? '') === 'none', 'temporary direct-access denial source mismatch');

    $directJoin = videochat_user_can_direct_join_call($pdo, $callAId, $guestUserId, 'user', $tenantAId);
    videochat_anonymous_temp_rights_assert(!(bool) ($directJoin['ok'] ?? true), 'temporary account must not gain guest-list direct join');
    videochat_anonymous_temp_rights_assert((string) ($directJoin['reason'] ?? '') === 'not_on_guest_list', 'temporary direct-join denial reason mismatch');
    videochat_anonymous_temp_rights_assert(
        videochat_anonymous_temp_rights_participant($pdo, $callAId, $guestUserId) === null,
        'anonymous session issuance must not create an invited/allowed participant row'
    );
    videochat_anonymous_temp_rights_assert(
        videochat_anonymous_temp_rights_guest_list_count($pdo, $callAId) === $guestListBefore,
        'anonymous session issuance must not add guest-list rights'
    );

    $guestConnection = videochat_anonymous_temp_rights_connection(
        $pdo,
        $openDatabase,
        'sess_anonymous_temp_rights_guest',
        $callAId,
        'conn_anonymous_temp_rights_guest'
    );
    videochat_anonymous_temp_rights_assert(
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $guestConnection),
        'temporary guest should create only a pending lobby row when queueing'
    );
    $pendingParticipant = videochat_anonymous_temp_rights_participant($pdo, $callAId, $guestUserId);
    videochat_anonymous_temp_rights_assert(is_array($pendingParticipant), 'queued temporary guest should have a lobby participant row');
    videochat_anonymous_temp_rights_assert((string) ($pendingParticipant['invite_state'] ?? '') === 'pending', 'queued temporary guest must remain pending');
    videochat_anonymous_temp_rights_assert((string) ($pendingParticipant['call_role'] ?? '') === 'participant', 'queued temporary guest must remain participant');
    videochat_anonymous_temp_rights_assert(
        videochat_anonymous_temp_rights_guest_list_count($pdo, $callAId) === $guestListBefore,
        'pending lobby row must not become a guest-list right'
    );

    $pendingDirectDecision = videochat_decide_call_access_for_user($pdo, $callAId, $guestUserId, 'user', $tenantAId);
    videochat_anonymous_temp_rights_assert(!(bool) ($pendingDirectDecision['allowed'] ?? true), 'pending temporary guest must not gain direct call access');
    videochat_anonymous_temp_rights_assert((string) ($pendingDirectDecision['reason'] ?? '') === 'not_on_guest_list', 'pending temporary direct-access denial reason mismatch');
    $pendingDirectJoin = videochat_user_can_direct_join_call($pdo, $callAId, $guestUserId, 'user', $tenantAId);
    videochat_anonymous_temp_rights_assert(!(bool) ($pendingDirectJoin['ok'] ?? true), 'pending temporary guest must not gain direct join');
    videochat_anonymous_temp_rights_assert((string) ($pendingDirectJoin['reason'] ?? '') === 'not_on_guest_list', 'pending temporary direct-join denial reason mismatch');

    $guestConnection = videochat_realtime_connection_with_call_context($guestConnection, $openDatabase);
    videochat_anonymous_temp_rights_assert((string) ($guestConnection['call_role'] ?? '') === 'participant', 'queued temporary guest call role mismatch');
    videochat_anonymous_temp_rights_assert(!(bool) ($guestConnection['can_moderate_call'] ?? true), 'queued temporary guest must not moderate lobby');
    videochat_anonymous_temp_rights_assert(!(bool) ($guestConnection['can_manage_call_owner'] ?? true), 'queued temporary guest must not manage owner');
    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'room_id' => $callAId,
        'target_user_id' => $guestUserId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_anonymous_temp_rights_assert((bool) ($allowCommand['ok'] ?? false), 'lobby allow command should decode');
    $authority = videochat_realtime_authorize_lobby_moderation_command($guestConnection, $allowCommand, $callAId, $openDatabase);
    videochat_anonymous_temp_rights_assert(!(bool) ($authority['ok'] ?? true), 'queued temporary guest must not authorize lobby admission');
    videochat_anonymous_temp_rights_assert((string) ($authority['error'] ?? '') === 'forbidden', 'queued temporary lobby denial reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-anonymous-temp-rights-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-anonymous-temp-rights-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
