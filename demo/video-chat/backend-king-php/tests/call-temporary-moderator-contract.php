<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../http/module_realtime_lobby_security.php';

function videochat_temp_moderator_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-temporary-moderator-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_temp_moderator_contract_source(string $relativePath): string
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $source = is_file($path) ? file_get_contents($path) : false;
    videochat_temp_moderator_contract_assert(is_string($source), "source file missing: {$relativePath}");

    return $source;
}

function videochat_temp_moderator_contract_static_assertions(): void
{
    $contractSource = videochat_temp_moderator_contract_source('domain/calls/call_management_contract.php');
    $transferSource = videochat_temp_moderator_contract_source('domain/calls/call_management_owner_transfer.php');
    $querySource = videochat_temp_moderator_contract_source('domain/calls/call_management_query.php');
    $updateSource = videochat_temp_moderator_contract_source('domain/calls/call_management_update.php');
    $lobbySecuritySource = videochat_temp_moderator_contract_source('http/module_realtime_lobby_security.php');

    videochat_temp_moderator_contract_assert(
        str_contains($contractSource, 'function videochat_call_invite_state_allows_scoped_role'),
        'temporary moderator role authority must use shared active invite-state semantics'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($transferSource, "videochat_normalize_call_participant_role(\$targetRole, '')")
        && str_contains($transferSource, 'SET call_role = :call_role'),
        'temporary moderator grants must use the focused participant role mutation path'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($querySource, "['owner', 'moderator', 'participant']"),
        'call participant role normalization must stay limited to owner/moderator/participant'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($transferSource, "cannot_change_current_owner_role"),
        'temporary moderator path must not demote the current owner'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($lobbySecuritySource, 'videochat_realtime_lobby_server_role_for_user')
        && str_contains($lobbySecuritySource, 'videochat_realtime_call_role_context_for_room_user')
        && str_contains($lobbySecuritySource, "error' => 'forbidden'"),
        'realtime lobby moderation must be reauthorized from server-side role context'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($updateSource, 'videochat_can_manage_call_guest_list')
        && str_contains($updateSource, 'guest_list_management_requires_owner_or_admin'),
        'guest-list mutations must not be authorized by temporary moderator rights alone'
    );
    videochat_temp_moderator_contract_assert(
        str_contains($querySource, "calls.status")
        && str_contains($querySource, 'videochat_call_invite_state_allows_scoped_role')
        && str_contains($lobbySecuritySource, 'videochat_call_invite_state_allows_scoped_role'),
        'temporary moderator authority must expire for inactive participants and ended calls'
    );
}

function videochat_temp_moderator_contract_role_id(PDO $pdo, string $slug): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $slug]);

    return (int) $query->fetchColumn();
}

function videochat_temp_moderator_contract_create_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = videochat_temp_moderator_contract_role_id($pdo, 'user');
    videochat_temp_moderator_contract_assert($roleId > 0, 'expected seeded user role');
    $passwordHash = password_hash('temporary-moderator-contract', PASSWORD_DEFAULT);
    videochat_temp_moderator_contract_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

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
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_temp_moderator_contract_assert($userId > 0, 'created user should have id');
    return $userId;
}

function videochat_temp_moderator_contract_create_tenant(PDO $pdo, string $slug): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_generate_call_id(),
        ':slug' => $slug,
        ':label' => ucwords(str_replace('-', ' ', $slug)),
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_temp_moderator_contract_assert($tenantId > 0, 'created tenant should have id');
    return $tenantId;
}

function videochat_temp_moderator_contract_create_organization(PDO $pdo, int $tenantId, string $name): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, parent_organization_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, NULL, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => videochat_generate_call_id(),
        ':name' => $name,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_temp_moderator_contract_assert($organizationId > 0, 'created organization should have id');
    return $organizationId;
}

function videochat_temp_moderator_contract_attach_tenant(PDO $pdo, int $tenantId, int $userId): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, 'member', 'active', '{}', 0, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_temp_moderator_contract_attach_organization(PDO $pdo, int $tenantId, int $organizationId, int $userId): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, 'member', 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_temp_moderator_contract_authority(PDO $pdo, string $callId, string $roomId, int $userId, int $tenantId, array $overrides = []): array
{
    $connection = array_merge([
        'user_id' => $userId,
        'role' => 'user',
        'raw_role' => 'user',
        'call_role' => 'participant',
        'can_moderate_call' => false,
        'active_call_id' => $callId,
        'requested_call_id' => $callId,
        'tenant_id' => $tenantId,
    ], $overrides);

    return videochat_realtime_authorize_lobby_moderation_command(
        $connection,
        [
            'type' => 'lobby/allow',
            'room_id' => $roomId,
            'target_user_id' => 999001,
        ],
        $roomId,
        static fn (): PDO => $pdo
    );
}

function videochat_temp_moderator_contract_participant_count(PDO $pdo, string $callId, int $userId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);

    return (int) $query->fetchColumn();
}

function videochat_temp_moderator_contract_set_invite_state(PDO $pdo, string $callId, int $userId, string $inviteState): void
{
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $update->execute([
        ':invite_state' => $inviteState,
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

try {
    videochat_temp_moderator_contract_static_assertions();

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-temporary-moderator-contract] SKIP persistence: pdo_sqlite unavailable\n");
        fwrite(STDOUT, "[call-temporary-moderator-contract] PASS\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-temp-moderator-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = videochat_temp_moderator_contract_create_tenant($pdo, 'temp-moderator-' . bin2hex(random_bytes(3)));
    $organizationId = videochat_temp_moderator_contract_create_organization($pdo, $tenantId, 'Temporary Moderator Org');
    $ownerUserId = videochat_temp_moderator_contract_create_user($pdo, 'temp-moderator-owner@example.test', 'Temporary Moderator Owner');
    $participantUserId = videochat_temp_moderator_contract_create_user($pdo, 'temp-moderator-participant@example.test', 'Temporary Moderator Participant');
    $waitingUserId = videochat_temp_moderator_contract_create_user($pdo, 'temp-moderator-waiting@example.test', 'Temporary Moderator Waiting');
    foreach ([$ownerUserId, $participantUserId, $waitingUserId] as $userId) {
        videochat_temp_moderator_contract_attach_tenant($pdo, $tenantId, $userId);
        videochat_temp_moderator_contract_attach_organization($pdo, $tenantId, $organizationId, $userId);
    }

    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Temporary Moderator Contract',
        'starts_at' => '2026-10-12T09:00:00Z',
        'ends_at' => '2026-10-12T10:00:00Z',
        'internal_participant_user_ids' => [$participantUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($created['ok'] ?? false), 'call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    $roomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    videochat_temp_moderator_contract_assert($callId !== '' && $roomId !== '', 'created call should expose ids');

    $forgedBeforeGrant = videochat_temp_moderator_contract_authority($pdo, $callId, $roomId, $participantUserId, $tenantId, [
        'role' => 'admin',
        'raw_role' => 'moderator',
        'call_role' => 'moderator',
        'can_moderate_call' => true,
    ]);
    videochat_temp_moderator_contract_assert(!(bool) ($forgedBeforeGrant['ok'] ?? true), 'forged moderator connection must be denied before DB grant');
    videochat_temp_moderator_contract_assert((string) ($forgedBeforeGrant['error'] ?? '') === 'forbidden', 'forged moderator denial reason mismatch');

    $grantModerator = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $participantUserId,
        'moderator',
        $ownerUserId,
        'user',
        $tenantId
    );
    videochat_temp_moderator_contract_assert((bool) ($grantModerator['ok'] ?? false), 'owner should grant temporary moderator');

    $moderatorContext = videochat_call_role_context_for_room_user($pdo, $roomId, $participantUserId);
    videochat_temp_moderator_contract_assert((string) ($moderatorContext['call_role'] ?? '') === 'moderator', 'temporary moderator role should persist');
    videochat_temp_moderator_contract_assert((bool) ($moderatorContext['can_moderate'] ?? false), 'temporary moderator should gain controls');
    videochat_temp_moderator_contract_assert(!(bool) ($moderatorContext['can_manage_owner'] ?? true), 'temporary moderator must not manage owner transfer');

    $authorizedAfterGrant = videochat_temp_moderator_contract_authority($pdo, $callId, $roomId, $participantUserId, $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($authorizedAfterGrant['ok'] ?? false), 'server-side moderator grant should authorize lobby controls');

    $guestListUpdateByModerator = videochat_update_call($pdo, $callId, $participantUserId, 'user', [
        'internal_participant_user_ids' => [$participantUserId, $waitingUserId],
    ], $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($guestListUpdateByModerator['ok'] ?? true), 'temporary moderator must not mutate guest list');
    videochat_temp_moderator_contract_assert((string) ($guestListUpdateByModerator['reason'] ?? '') === 'forbidden', 'temporary moderator guest-list denial reason mismatch');
    videochat_temp_moderator_contract_assert(
        (string) (($guestListUpdateByModerator['errors'] ?? [])['participants'] ?? '') === 'guest_list_management_requires_owner_or_admin',
        'temporary moderator guest-list denial field mismatch'
    );
    videochat_temp_moderator_contract_assert(
        videochat_temp_moderator_contract_participant_count($pdo, $callId, $waitingUserId) === 0,
        'forbidden temporary moderator guest-list update must not add participant rows'
    );

    $ownerGuestListUpdate = videochat_update_call($pdo, $callId, $ownerUserId, 'user', [
        'internal_participant_user_ids' => [$participantUserId, $waitingUserId],
    ], $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($ownerGuestListUpdate['ok'] ?? false), 'owner should still mutate guest list');
    videochat_temp_moderator_contract_assert(
        videochat_temp_moderator_contract_participant_count($pdo, $callId, $waitingUserId) === 1,
        'owner guest-list update should persist the waiting participant row'
    );

    videochat_temp_moderator_contract_set_invite_state($pdo, $callId, $participantUserId, 'cancelled');
    $inactiveModeratorContext = videochat_call_role_context_for_room_user($pdo, $roomId, $participantUserId);
    videochat_temp_moderator_contract_assert((string) ($inactiveModeratorContext['call_role'] ?? '') === 'moderator', 'inactive moderator role row should remain auditable');
    videochat_temp_moderator_contract_assert(!(bool) ($inactiveModeratorContext['can_moderate'] ?? true), 'inactive temporary moderator should lose controls');
    $inactiveModeratorAuthority = videochat_temp_moderator_contract_authority($pdo, $callId, $roomId, $participantUserId, $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($inactiveModeratorAuthority['ok'] ?? true), 'inactive temporary moderator lobby authority must be denied');
    videochat_temp_moderator_contract_assert((string) ($inactiveModeratorAuthority['error'] ?? '') === 'forbidden', 'inactive moderator denial reason mismatch');
    $inactiveModeratorUpdate = videochat_update_call($pdo, $callId, $participantUserId, 'user', [
        'title' => 'Inactive Moderator Update',
    ], $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($inactiveModeratorUpdate['ok'] ?? true), 'inactive temporary moderator must not edit call settings');
    videochat_temp_moderator_contract_assert((string) ($inactiveModeratorUpdate['reason'] ?? '') === 'forbidden', 'inactive temporary moderator edit denial mismatch');
    videochat_temp_moderator_contract_set_invite_state($pdo, $callId, $participantUserId, 'allowed');

    $revokeModerator = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $participantUserId,
        'participant',
        $ownerUserId,
        'user',
        $tenantId
    );
    videochat_temp_moderator_contract_assert((bool) ($revokeModerator['ok'] ?? false), 'owner should revoke temporary moderator');

    $revokedContext = videochat_call_role_context_for_room_user($pdo, $roomId, $participantUserId);
    videochat_temp_moderator_contract_assert((string) ($revokedContext['call_role'] ?? '') === 'participant', 'revoked moderator should return to participant');
    videochat_temp_moderator_contract_assert(!(bool) ($revokedContext['can_moderate'] ?? true), 'revoked moderator should lose controls');

    $forgedAfterRevoke = videochat_temp_moderator_contract_authority($pdo, $callId, $roomId, $participantUserId, $tenantId, [
        'raw_role' => 'moderator',
        'call_role' => 'moderator',
        'can_moderate_call' => true,
    ]);
    videochat_temp_moderator_contract_assert(!(bool) ($forgedAfterRevoke['ok'] ?? true), 'forged moderator connection must be denied after revoke');
    videochat_temp_moderator_contract_assert((string) ($forgedAfterRevoke['error'] ?? '') === 'forbidden', 'forged revoke denial reason mismatch');

    $ownerDemotion = videochat_update_call_participant_role($pdo, $callId, $ownerUserId, 'participant', $ownerUserId, 'user', $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($ownerDemotion['ok'] ?? true), 'temporary moderator path must not demote the current owner');
    videochat_temp_moderator_contract_assert((string) (($ownerDemotion['errors'] ?? [])['role'] ?? '') === 'cannot_change_current_owner_role', 'owner demotion error mismatch');

    $terminalCall = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Temporary Moderator Ended Call Contract',
        'starts_at' => '2026-10-12T11:00:00Z',
        'ends_at' => '2026-10-12T12:00:00Z',
        'internal_participant_user_ids' => [$participantUserId, $waitingUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($terminalCall['ok'] ?? false), 'terminal-rights call should be created');
    $terminalCallId = (string) (($terminalCall['call'] ?? [])['id'] ?? '');
    $terminalRoomId = (string) (($terminalCall['call'] ?? [])['room_id'] ?? '');
    videochat_temp_moderator_contract_assert($terminalCallId !== '' && $terminalRoomId !== '', 'terminal-rights call ids should be present');

    $terminalGrant = videochat_update_call_participant_role($pdo, $terminalCallId, $participantUserId, 'moderator', $ownerUserId, 'user', $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($terminalGrant['ok'] ?? false), 'owner should grant terminal-call moderator');
    $terminalEnd = videochat_end_call($pdo, $terminalCallId, $ownerUserId, 'user', $tenantId);
    videochat_temp_moderator_contract_assert((bool) ($terminalEnd['ok'] ?? false), 'owner should end terminal-rights call');

    $endedModeratorAuthority = videochat_temp_moderator_contract_authority($pdo, $terminalCallId, $terminalRoomId, $participantUserId, $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($endedModeratorAuthority['ok'] ?? true), 'temporary moderator must lose lobby authority after call end');
    videochat_temp_moderator_contract_assert((string) ($endedModeratorAuthority['error'] ?? '') === 'forbidden', 'ended moderator lobby denial reason mismatch');
    $endedModeratorRoleUpdate = videochat_update_call_participant_role($pdo, $terminalCallId, $waitingUserId, 'moderator', $participantUserId, 'user', $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($endedModeratorRoleUpdate['ok'] ?? true), 'temporary moderator must not change roles after call end');
    videochat_temp_moderator_contract_assert((string) ($endedModeratorRoleUpdate['reason'] ?? '') === 'forbidden', 'ended moderator role-update denial mismatch');
    $endedModeratorCallUpdate = videochat_update_call($pdo, $terminalCallId, $participantUserId, 'user', [
        'title' => 'Ended Moderator Update',
    ], $tenantId);
    videochat_temp_moderator_contract_assert(!(bool) ($endedModeratorCallUpdate['ok'] ?? true), 'temporary moderator must not edit ended call');
    videochat_temp_moderator_contract_assert((string) ($endedModeratorCallUpdate['reason'] ?? '') === 'forbidden', 'ended moderator call-update denial mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-temporary-moderator-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-temporary-moderator-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
