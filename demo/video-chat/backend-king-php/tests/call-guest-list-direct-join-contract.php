<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_call_guest_list_direct_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-guest-list-direct-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_guest_list_direct_join_create_tenant(PDO $pdo, string $slug, string $label): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => 'tenant-' . bin2hex(random_bytes(8)),
        ':slug' => $slug,
        ':label' => $label,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_guest_list_direct_join_attach_user(PDO $pdo, int $tenantId, int $userId, string $role): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, permissions_json, status, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, '{}', 'active', 0, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_guest_list_direct_join_create_user(
    PDO $pdo,
    PDOStatement $insertUser,
    int $roleId,
    string $email,
    string $displayName
): int {
    $passwordHash = password_hash('call-guest-list-direct-join-contract', PASSWORD_DEFAULT);
    videochat_call_guest_list_direct_join_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');
    $insertUser->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_call_guest_list_direct_join_assert($userId > 0, "{$displayName} should be created");
    return $userId;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-guest-list-direct-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-guest-list-direct-join-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $guestListUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_guest_list_direct_join_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_guest_list_direct_join_assert($guestListUserId > 0, 'expected seeded guest-list user');

    $tenantAId = videochat_call_guest_list_direct_join_create_tenant($pdo, 'guest-list-direct-a', 'Guest List Direct A');
    $tenantBId = videochat_call_guest_list_direct_join_create_tenant($pdo, 'guest-list-direct-b', 'Guest List Direct B');
    videochat_call_guest_list_direct_join_assert($tenantAId > 0 && $tenantBId > 0 && $tenantAId !== $tenantBId, 'expected isolated tenant ids');
    videochat_call_guest_list_direct_join_attach_user($pdo, $tenantAId, $adminUserId, 'owner');
    videochat_call_guest_list_direct_join_attach_user($pdo, $tenantAId, $guestListUserId, 'member');

    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_guest_list_direct_join_assert($roleId > 0, 'expected user role');
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $notOnGuestListUserId = videochat_call_guest_list_direct_join_create_user(
        $pdo,
        $insertUser,
        $roleId,
        'not-on-guest-list@intelligent-intern.com',
        'Not On Guest List'
    );

    $guestListedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($guestListedCall['ok'] ?? false), 'guest-listed call should be created');
    $guestListedCallId = (string) (($guestListedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($guestListedCallId !== '', 'guest-listed call id should be present');

    $unrelatedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Unrelated Guest List Scope',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-02T09:00:00Z',
        'ends_at' => '2026-10-02T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($unrelatedCall['ok'] ?? false), 'unrelated call should be created');
    $unrelatedCallId = (string) (($unrelatedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($unrelatedCallId !== '', 'unrelated call id should be present');

    $guestListedDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert((bool) ($guestListedDecision['ok'] ?? false), 'user on guest list should be allowed to direct join');
    videochat_call_guest_list_direct_join_assert((string) ($guestListedDecision['reason'] ?? '') === 'guest_list', 'guest-list direct join reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ($guestListedDecision['call_id'] ?? '') === $guestListedCallId, 'guest-list direct join call id mismatch');
    videochat_call_guest_list_direct_join_assert((int) ((($guestListedDecision['guest_list_entry'] ?? [])['user_id'] ?? 0)) === $guestListUserId, 'guest-list entry user mismatch');

    $notGuestListedDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $notOnGuestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($notGuestListedDecision['ok'] ?? true), 'user not on guest list should not direct join');
    videochat_call_guest_list_direct_join_assert((string) ($notGuestListedDecision['reason'] ?? '') === 'not_on_guest_list', 'non-guest-list denial reason mismatch');
    videochat_call_guest_list_direct_join_assert(($notGuestListedDecision['guest_list_entry'] ?? null) === null, 'non-guest-list denial must not fabricate an entry');

    $scopedDecision = videochat_user_can_direct_join_call($pdo, $unrelatedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($scopedDecision['ok'] ?? true), 'guest list from one call must not grant direct join to another call');
    videochat_call_guest_list_direct_join_assert((string) ($scopedDecision['reason'] ?? '') === 'not_on_guest_list', 'scoped denial reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ($scopedDecision['call_id'] ?? '') === $unrelatedCallId, 'scoped denial call id mismatch');

    $declinedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Declined Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-03T09:00:00Z',
        'ends_at' => '2026-10-03T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($declinedCall['ok'] ?? false), 'declined guest-list call should be created');
    $declinedCallId = (string) (($declinedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($declinedCallId !== '', 'declined guest-list call id should be present');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'declined'
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':call_id' => $declinedCallId,
        ':user_id' => $guestListUserId,
    ]);
    $declinedDecision = videochat_user_can_direct_join_call($pdo, $declinedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($declinedDecision['ok'] ?? true), 'declined guest-list entry must not direct join');
    videochat_call_guest_list_direct_join_assert((string) ($declinedDecision['reason'] ?? '') === 'guest_list_entry_inactive', 'declined denial reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ((($declinedDecision['guest_list_entry'] ?? [])['invite_state'] ?? '')) === 'declined', 'declined denial should expose normalized inactive entry');

    $externalOnlyCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'External Participant Is Not Guest List',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-04T09:00:00Z',
        'ends_at' => '2026-10-04T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($externalOnlyCall['ok'] ?? false), 'external-only call should be created');
    $externalOnlyCallId = (string) (($externalOnlyCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($externalOnlyCallId !== '', 'external-only call id should be present');
    $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'external', 'participant', 'allowed', NULL, NULL)
SQL
    )->execute([
        ':call_id' => $externalOnlyCallId,
        ':user_id' => $guestListUserId,
        ':email' => 'external-only-guest-list@example.test',
        ':display_name' => 'External Only Guest List',
    ]);
    $externalOnlyDecision = videochat_user_can_direct_join_call($pdo, $externalOnlyCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($externalOnlyDecision['ok'] ?? true), 'external participant row must not count as internal guest list');
    videochat_call_guest_list_direct_join_assert((string) ($externalOnlyDecision['reason'] ?? '') === 'not_on_guest_list', 'external participant denial reason mismatch');

    $tenantCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Tenant Scoped Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-05T09:00:00Z',
        'ends_at' => '2026-10-05T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ], $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($tenantCall['ok'] ?? false), 'tenant-scoped guest-list call should be created');
    $tenantCallId = (string) (($tenantCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($tenantCallId !== '', 'tenant-scoped call id should be present');
    $tenantAllowedDecision = videochat_user_can_direct_join_call($pdo, $tenantCallId, $guestListUserId, 'user', $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($tenantAllowedDecision['ok'] ?? false), 'guest list should allow direct join in the owning tenant');
    videochat_call_guest_list_direct_join_assert((string) ($tenantAllowedDecision['reason'] ?? '') === 'guest_list', 'tenant guest-list allow reason mismatch');
    $tenantDeniedDecision = videochat_user_can_direct_join_call($pdo, $tenantCallId, $guestListUserId, 'user', $tenantBId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($tenantDeniedDecision['ok'] ?? true), 'guest list must not cross tenant call lookup');
    videochat_call_guest_list_direct_join_assert((string) ($tenantDeniedDecision['reason'] ?? '') === 'not_found', 'cross-tenant guest-list denial reason mismatch');
    videochat_call_guest_list_direct_join_assert(($tenantDeniedDecision['guest_list_entry'] ?? null) === null, 'cross-tenant denial must not expose guest-list entry');

    $temporaryInviteEmail = 'temporary-direct-join@example.test';
    $temporaryCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Temporary Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [
            ['email' => $temporaryInviteEmail, 'display_name' => 'Temporary Direct Join Guest'],
        ],
    ], $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryCall['ok'] ?? false), 'temporary direct-join call should be created');
    $temporaryCallId = (string) (($temporaryCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($temporaryCallId !== '', 'temporary direct-join call id should be present');
    $temporaryAccess = videochat_create_call_access_link_for_user($pdo, $temporaryCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_email' => $temporaryInviteEmail,
    ], $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryAccess['ok'] ?? false), 'temporary personalized direct-join link should be created');
    $temporaryAccessLink = is_array($temporaryAccess['access_link'] ?? null) ? $temporaryAccess['access_link'] : [];
    $temporaryAccessId = (string) ($temporaryAccessLink['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($temporaryAccessId !== '', 'temporary personalized direct-join access id should be present');

    $issuedForgedSessions = [];
    $forgedRouteResponse = videochat_handle_call_access_routes(
        '/api/call-access/' . $temporaryAccessId . '/session',
        'POST',
        [
            'body' => json_encode([
                'guest_name' => 'Temporary Direct Join Guest',
                'participant_user_id' => $notOnGuestListUserId,
                'call_id' => $unrelatedCallId,
                'room_id' => 'forged-direct-join-room',
            ], JSON_UNESCAPED_SLASHES),
            'headers' => [
                'content-type' => 'application/json',
            ],
        ],
        [],
        static fn (int $status, array $payload): array => ['status' => $status, 'payload' => $payload],
        static fn (int $status, string $code, string $message, array $details = []): array => [
            'status' => $status,
            'payload' => [
                'status' => 'error',
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'details' => $details,
                ],
            ],
        ],
        static function (array $request): array {
            $decoded = json_decode((string) ($request['body'] ?? ''), true);
            return [is_array($decoded) ? $decoded : null, json_last_error_msg()];
        },
        static fn (): PDO => $pdo,
        static function () use (&$issuedForgedSessions): string {
            $issuedForgedSessions[] = 'sess_direct_join_temp_manipulated_body';
            return 'sess_direct_join_temp_manipulated_body';
        }
    );
    videochat_call_guest_list_direct_join_assert((int) ($forgedRouteResponse['status'] ?? 0) === 422, 'body fields must not change the temporary link identity');
    $forgedFields = (array) (((($forgedRouteResponse['payload'] ?? [])['error'] ?? [])['details'] ?? [])['fields'] ?? []);
    videochat_call_guest_list_direct_join_assert((string) ($forgedFields['participant_user_id'] ?? '') === 'server_authoritative', 'participant_user_id body field should be server authoritative');
    videochat_call_guest_list_direct_join_assert((string) ($forgedFields['call_id'] ?? '') === 'server_authoritative', 'call_id body field should be server authoritative');
    videochat_call_guest_list_direct_join_assert((string) ($forgedFields['room_id'] ?? '') === 'server_authoritative', 'room_id body field should be server authoritative');
    videochat_call_guest_list_direct_join_assert($issuedForgedSessions === [], 'forged body must be rejected before issuing a session id');
    videochat_call_guest_list_direct_join_assert(videochat_fetch_call_access_session_binding($pdo, 'sess_direct_join_temp_manipulated_body') === null, 'forged body must not create a call-access session binding');

    $temporarySession = videochat_issue_session_for_call_access(
        $pdo,
        $temporaryAccessId,
        static fn (): string => 'sess_direct_join_temp_guest',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-list-direct-join-contract'],
        ['guest_name' => 'Temporary Direct Join Guest']
    );
    videochat_call_guest_list_direct_join_assert((bool) ($temporarySession['ok'] ?? false), 'temporary personalized direct-join session should issue with server-bound link identity');
    $temporaryUser = is_array($temporarySession['user'] ?? null) ? $temporarySession['user'] : [];
    $temporaryUserId = (int) ($temporaryUser['id'] ?? 0);
    videochat_call_guest_list_direct_join_assert($temporaryUserId > 0, 'temporary personalized direct-join session should return a user');
    videochat_call_guest_list_direct_join_assert($temporaryUserId !== $notOnGuestListUserId && $temporaryUserId !== $guestListUserId, 'temporary link must not assume another participant identity');
    $temporaryBinding = videochat_fetch_call_access_session_binding($pdo, 'sess_direct_join_temp_guest');
    videochat_call_guest_list_direct_join_assert(is_array($temporaryBinding), 'temporary personalized session binding should persist');
    videochat_call_guest_list_direct_join_assert((int) ($temporaryBinding['user_id'] ?? 0) === $temporaryUserId, 'temporary personalized binding user mismatch');
    videochat_call_guest_list_direct_join_assert((string) ($temporaryBinding['access_id'] ?? '') === $temporaryAccessId, 'temporary personalized binding access mismatch');
    $mutatedTemporaryAccessId = substr($temporaryAccessId, 0, -1) . (substr($temporaryAccessId, -1) === '0' ? '1' : '0');
    $mutatedTemporaryResolve = videochat_resolve_call_access_public($pdo, $mutatedTemporaryAccessId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($mutatedTemporaryResolve['ok'] ?? true), 'mutated temporary personalized link should be rejected');
    videochat_call_guest_list_direct_join_assert((string) ($mutatedTemporaryResolve['reason'] ?? '') === 'not_found', 'mutated temporary personalized link denial reason mismatch');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET left_at = :left_at
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':left_at' => gmdate('c'),
        ':call_id' => $temporaryCallId,
        ':user_id' => $temporaryUserId,
    ]);
    $temporaryBindingAfterLeaving = videochat_fetch_call_access_session_binding($pdo, 'sess_direct_join_temp_guest');
    videochat_call_guest_list_direct_join_assert(is_array($temporaryBindingAfterLeaving), 'temporary guest-list session should remain bound after leaving');
    videochat_call_guest_list_direct_join_assert((int) ($temporaryBindingAfterLeaving['user_id'] ?? 0) === $temporaryUserId, 'temporary guest-list session should remain bound to the same user after leaving');
    $reopenedTemporaryAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/join/' . $temporaryAccessId,
            'headers' => ['Authorization' => 'Bearer sess_direct_join_temp_guest'],
        ],
        'rest'
    );
    videochat_call_guest_list_direct_join_assert((bool) ($reopenedTemporaryAuth['ok'] ?? false), 'reopened temporary link should recognize the same temporary account');
    videochat_call_guest_list_direct_join_assert((int) (($reopenedTemporaryAuth['user'] ?? [])['id'] ?? 0) === $temporaryUserId, 'reopened temporary link should authenticate as the same temporary account');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-guest-list-direct-join-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-guest-list-direct-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
