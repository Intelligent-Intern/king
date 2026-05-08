<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_iam_deleted_ended_disabled_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-deleted-ended-disabled-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_deleted_ended_disabled_join_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_iam_deleted_ended_disabled_join_assert_omits(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam_deleted_ended_disabled_join_assert(
            !str_contains($body, $normalized),
            "{$label} leaked {$needle}"
        );
    }
}

function videochat_iam_deleted_ended_disabled_join_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam_deleted_ended_disabled_join_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam_deleted_ended_disabled_join_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam_deleted_ended_disabled_join_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $payload = json_decode($body, true);
    return is_array($payload) ? [$payload, null] : [null, 'invalid_json'];
}

function videochat_iam_deleted_ended_disabled_join_auth_context(int $userId, string $role, int $tenantId): array
{
    return [
        'ok' => true,
        'user' => [
            'id' => $userId,
            'role' => $role,
        ],
        'session' => [
            'id' => 'sess_contract_' . $userId,
        ],
        'tenant' => [
            'id' => $tenantId,
        ],
    ];
}

function videochat_iam_deleted_ended_disabled_join_insert_session(PDO $pdo, int $userId, string $sessionId): void
{
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-deleted-ended-disabled-join-contract')
SQL
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

function videochat_iam_deleted_ended_disabled_join_create_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_iam_deleted_ended_disabled_join_assert($roleId > 0, 'user role should exist');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    )->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('contract-pass', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam_deleted_ended_disabled_join_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_iam_deleted_ended_disabled_join_attach_organization(
    PDO $pdo,
    int $tenantId,
    int $organizationId,
    int $userId,
    string $role
): void {
    $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => strtolower(trim($role)) === 'admin' ? 'admin' : 'member',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_iam_deleted_ended_disabled_join_create_call(PDO $pdo, int $ownerUserId, array $participantUserIds, string $title, int $tenantId): array
{
    $result = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'starts_at' => '2026-09-08T09:00:00Z',
        'ends_at' => '2026-09-08T10:00:00Z',
        'internal_participant_user_ids' => $participantUserIds,
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert((bool) ($result['ok'] ?? false), "{$title} should be created");
    $callId = (string) (($result['call'] ?? [])['id'] ?? '');
    videochat_iam_deleted_ended_disabled_join_assert($callId !== '', "{$title} id should be present");
    return [
        'id' => $callId,
        'title' => $title,
    ];
}

function videochat_iam_deleted_ended_disabled_join_create_personal_link(PDO $pdo, string $callId, int $adminUserId, int $targetUserId, int $tenantId): string
{
    $result = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert((bool) ($result['ok'] ?? false), 'personal link should be created');
    $accessId = (string) (($result['access_link'] ?? [])['id'] ?? '');
    videochat_iam_deleted_ended_disabled_join_assert($accessId !== '', 'personal access id should be present');
    return $accessId;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-deleted-ended-disabled-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-deleted-ended-disabled-join-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam_deleted_ended_disabled_join_assert($tenantId > 0, 'expected default tenant');
    videochat_iam_deleted_ended_disabled_join_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_iam_deleted_ended_disabled_join_assert($standardUserId > 0, 'expected seeded standard user');

    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_iam_deleted_ended_disabled_join_auth_context($adminUserId, 'admin', $tenantId);
    $jsonResponse = 'videochat_iam_deleted_ended_disabled_join_json_response';
    $errorResponse = 'videochat_iam_deleted_ended_disabled_join_error_response';
    $decodeJsonBody = 'videochat_iam_deleted_ended_disabled_join_decode_json_body';

    $organizationId = (int) $pdo->query("SELECT id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    videochat_iam_deleted_ended_disabled_join_assert($organizationId > 0, 'expected default organization');
    $orgAdminUserId = videochat_iam_deleted_ended_disabled_join_create_user($pdo, 'deleted-org-admin-join-contract@example.test', 'Deleted Org Admin Join Contract');
    $orgOwnerUserId = videochat_iam_deleted_ended_disabled_join_create_user($pdo, 'deleted-org-owner-join-contract@example.test', 'Deleted Org Owner Join Contract');
    videochat_tenant_attach_user($pdo, $orgAdminUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $orgOwnerUserId, $tenantId, 'member');
    videochat_iam_deleted_ended_disabled_join_attach_organization($pdo, $tenantId, $organizationId, $orgAdminUserId, 'admin');
    videochat_iam_deleted_ended_disabled_join_attach_organization($pdo, $tenantId, $organizationId, $orgOwnerUserId, 'member');

    $endedCall = videochat_iam_deleted_ended_disabled_join_create_call(
        $pdo,
        $adminUserId,
        [$standardUserId],
        'IAM Ended Secret Contract Call',
        $tenantId
    );
    $endedAccessId = videochat_iam_deleted_ended_disabled_join_create_personal_link($pdo, $endedCall['id'], $adminUserId, $standardUserId, $tenantId);
    $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id')->execute([
        ':status' => 'ended',
        ':id' => $endedCall['id'],
    ]);

    $endedAdminDecision = videochat_decide_call_access_for_user($pdo, $endedCall['id'], $adminUserId, 'admin', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($endedAdminDecision['allowed'] ?? true), 'system admin must not get normal ended-call join access');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($endedAdminDecision['reason'] ?? '') === 'call_not_joinable_from_status', 'ended-call admin denial reason mismatch');

    $endedResolve = videochat_handle_call_routes(
        '/api/calls/resolve/' . $endedCall['id'],
        'GET',
        ['method' => 'GET', 'uri' => '/api/calls/resolve/' . $endedCall['id'], 'headers' => []],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($endedResolve), 'ended direct resolve response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($endedResolve['status'] ?? 0) === 200, 'ended direct resolve status should be 200 safe envelope');
    $endedResolvePayload = videochat_iam_deleted_ended_disabled_join_decode($endedResolve);
    videochat_iam_deleted_ended_disabled_join_assert((string) (($endedResolvePayload['result'] ?? [])['state'] ?? '') === 'forbidden', 'ended direct resolve should be forbidden');
    videochat_iam_deleted_ended_disabled_join_assert(($endedResolvePayload['result']['call'] ?? null) === null, 'ended direct resolve must not include call payload');
    videochat_iam_deleted_ended_disabled_join_assert_omits($endedResolve, [$endedCall['title'], 'user@intelligent-intern.com'], 'ended direct resolve');

    $endedJoin = videochat_handle_call_access_routes(
        '/api/call-access/' . $endedAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $endedAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($endedJoin), 'ended public join response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($endedJoin['status'] ?? 0) === 409, 'ended public join should return conflict');
    videochat_iam_deleted_ended_disabled_join_assert_omits($endedJoin, [$endedCall['title'], 'user@intelligent-intern.com'], 'ended public join');

    $sessionIssuerCalls = 0;
    $endedSession = videochat_handle_call_access_routes(
        '/api/call-access/' . $endedAccessId . '/session',
        'POST',
        ['method' => 'POST', 'uri' => '/api/call-access/' . $endedAccessId . '/session', 'headers' => [], 'body' => '{}'],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static function () use (&$sessionIssuerCalls): string {
            $sessionIssuerCalls += 1;
            return 'sess_ended_must_not_issue';
        }
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($endedSession), 'ended session response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($endedSession['status'] ?? 0) === 409, 'ended session should return conflict');
    videochat_iam_deleted_ended_disabled_join_assert($sessionIssuerCalls === 0, 'ended session issuer must not be called');
    videochat_iam_deleted_ended_disabled_join_assert_omits($endedSession, [$endedCall['title'], 'sess_ended_must_not_issue'], 'ended session');

    $deletedCall = videochat_iam_deleted_ended_disabled_join_create_call(
        $pdo,
        $adminUserId,
        [$standardUserId],
        'IAM Deleted Secret Contract Call',
        $tenantId
    );
    $deletedAccessId = videochat_iam_deleted_ended_disabled_join_create_personal_link($pdo, $deletedCall['id'], $adminUserId, $standardUserId, $tenantId);
    $deleteResult = videochat_delete_call($pdo, $deletedCall['id'], $adminUserId, 'admin', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert((bool) ($deleteResult['ok'] ?? false), 'call delete should succeed');

    $deletedDecision = videochat_decide_call_access_for_user($pdo, $deletedCall['id'], $adminUserId, 'admin', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($deletedDecision['allowed'] ?? true), 'system admin must not get normal deleted-call join access');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($deletedDecision['reason'] ?? '') === 'not_found', 'deleted-call admin denial reason mismatch');

    $deletedResolve = videochat_handle_call_routes(
        '/api/calls/resolve/' . $deletedCall['id'],
        'GET',
        ['method' => 'GET', 'uri' => '/api/calls/resolve/' . $deletedCall['id'], 'headers' => []],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($deletedResolve), 'deleted direct resolve response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($deletedResolve['status'] ?? 0) === 200, 'deleted direct resolve status should be 200 safe envelope');
    $deletedResolvePayload = videochat_iam_deleted_ended_disabled_join_decode($deletedResolve);
    videochat_iam_deleted_ended_disabled_join_assert((string) (($deletedResolvePayload['result'] ?? [])['state'] ?? '') === 'not_found', 'deleted direct resolve should be safe not_found');
    videochat_iam_deleted_ended_disabled_join_assert(($deletedResolvePayload['result']['call'] ?? null) === null, 'deleted direct resolve must not include call payload');
    videochat_iam_deleted_ended_disabled_join_assert_omits($deletedResolve, [$deletedCall['title'], 'user@intelligent-intern.com'], 'deleted direct resolve');

    $deletedJoin = videochat_handle_call_access_routes(
        '/api/call-access/' . $deletedAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $deletedAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($deletedJoin), 'deleted public join response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($deletedJoin['status'] ?? 0) === 404, 'deleted public join should return not found');
    videochat_iam_deleted_ended_disabled_join_assert_omits($deletedJoin, [$deletedCall['title'], 'user@intelligent-intern.com'], 'deleted public join');

    $orgDeletedCall = videochat_iam_deleted_ended_disabled_join_create_call(
        $pdo,
        $orgOwnerUserId,
        [$standardUserId],
        'IAM Deleted Organization Admin Secret Contract Call',
        $tenantId
    );
    $orgDeletedAccessId = videochat_iam_deleted_ended_disabled_join_create_personal_link($pdo, $orgDeletedCall['id'], $adminUserId, $standardUserId, $tenantId);
    $orgAdminBeforeDelete = videochat_decide_call_access_for_user($pdo, $orgDeletedCall['id'], $orgAdminUserId, 'user', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert((bool) ($orgAdminBeforeDelete['allowed'] ?? false), 'organization admin should join same-organization call before deletion');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($orgAdminBeforeDelete['source'] ?? '') === 'organization_admin', 'organization admin pre-delete source mismatch');
    $orgDeleteResult = videochat_delete_call($pdo, $orgDeletedCall['id'], $orgOwnerUserId, 'user', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert((bool) ($orgDeleteResult['ok'] ?? false), 'organization-owned call delete should succeed');

    $orgDeletedDecision = videochat_decide_call_access_for_user($pdo, $orgDeletedCall['id'], $orgAdminUserId, 'user', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($orgDeletedDecision['allowed'] ?? true), 'organization admin must not get normal deleted-call join access');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($orgDeletedDecision['reason'] ?? '') === 'not_found', 'organization admin deleted-call denial reason mismatch');
    $orgDeletedResolve = videochat_handle_call_routes(
        '/api/calls/resolve/' . $orgDeletedCall['id'],
        'GET',
        ['method' => 'GET', 'uri' => '/api/calls/resolve/' . $orgDeletedCall['id'], 'headers' => []],
        videochat_iam_deleted_ended_disabled_join_auth_context($orgAdminUserId, 'user', $tenantId),
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($orgDeletedResolve), 'organization admin deleted resolve response should exist');
    $orgDeletedResolvePayload = videochat_iam_deleted_ended_disabled_join_decode($orgDeletedResolve);
    videochat_iam_deleted_ended_disabled_join_assert((string) (($orgDeletedResolvePayload['result'] ?? [])['state'] ?? '') === 'not_found', 'organization admin deleted resolve should be safe not_found');
    videochat_iam_deleted_ended_disabled_join_assert(($orgDeletedResolvePayload['result']['call'] ?? null) === null, 'organization admin deleted resolve must not include call payload');
    videochat_iam_deleted_ended_disabled_join_assert_omits($orgDeletedResolve, [$orgDeletedCall['title'], 'deleted-org-owner-join-contract@example.test'], 'organization admin deleted resolve');
    $orgDeletedJoin = videochat_handle_call_access_routes(
        '/api/call-access/' . $orgDeletedAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $orgDeletedAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($orgDeletedJoin), 'organization deleted personalized join response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($orgDeletedJoin['status'] ?? 0) === 404, 'organization deleted personalized join should return not found');
    videochat_iam_deleted_ended_disabled_join_assert_omits($orgDeletedJoin, [$orgDeletedCall['title'], 'deleted-org-owner-join-contract@example.test', 'user@intelligent-intern.com'], 'organization deleted personalized join');

    $disabledCall = videochat_iam_deleted_ended_disabled_join_create_call(
        $pdo,
        $adminUserId,
        [$standardUserId],
        'IAM Disabled User Secret Contract Call',
        $tenantId
    );
    $disabledAccessId = videochat_iam_deleted_ended_disabled_join_create_personal_link($pdo, $disabledCall['id'], $adminUserId, $standardUserId, $tenantId);
    videochat_iam_deleted_ended_disabled_join_insert_session($pdo, $standardUserId, 'sess_disabled_join_contract');
    $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id')->execute([
        ':status' => 'disabled',
        ':updated_at' => gmdate('c'),
        ':id' => $standardUserId,
    ]);

    $disabledAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/auth/session-state',
        'headers' => ['Authorization' => 'Bearer sess_disabled_join_contract'],
    ], 'rest');
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($disabledAuth['ok'] ?? true), 'disabled user session must not authenticate');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($disabledAuth['reason'] ?? '') === 'user_inactive', 'disabled user auth reason mismatch');

    $disabledDecision = videochat_decide_call_access_for_user($pdo, $disabledCall['id'], $standardUserId, 'user', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($disabledDecision['allowed'] ?? true), 'disabled user decision must deny join');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($disabledDecision['reason'] ?? '') === 'invalid_user', 'disabled user decision reason mismatch');

    $disabledJoin = videochat_handle_call_access_routes(
        '/api/call-access/' . $disabledAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $disabledAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($disabledJoin), 'disabled user public join response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($disabledJoin['status'] ?? 0) === 404, 'disabled user public join should return not found');
    videochat_iam_deleted_ended_disabled_join_assert_omits($disabledJoin, [$disabledCall['title'], 'user@intelligent-intern.com'], 'disabled user public join');

    $deletedUserId = videochat_iam_deleted_ended_disabled_join_create_user($pdo, 'deleted-join-contract@example.test', 'Deleted Join Contract User');
    videochat_tenant_attach_user($pdo, $deletedUserId, $tenantId, 'member');
    $deletedUserCall = videochat_iam_deleted_ended_disabled_join_create_call(
        $pdo,
        $adminUserId,
        [$deletedUserId],
        'IAM Deleted User Secret Contract Call',
        $tenantId
    );
    $deletedUserAccessId = videochat_iam_deleted_ended_disabled_join_create_personal_link($pdo, $deletedUserCall['id'], $adminUserId, $deletedUserId, $tenantId);
    videochat_iam_deleted_ended_disabled_join_insert_session($pdo, $deletedUserId, 'sess_deleted_user_join_contract');
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $deletedUserId]);

    $deletedUserAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/auth/session-state',
        'headers' => ['Authorization' => 'Bearer sess_deleted_user_join_contract'],
    ], 'rest');
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($deletedUserAuth['ok'] ?? true), 'deleted user session must not authenticate');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($deletedUserAuth['reason'] ?? '') === 'invalid_session', 'deleted user auth reason mismatch');

    $deletedUserDecision = videochat_decide_call_access_for_user($pdo, $deletedUserCall['id'], $deletedUserId, 'user', $tenantId);
    videochat_iam_deleted_ended_disabled_join_assert(!(bool) ($deletedUserDecision['allowed'] ?? true), 'deleted user decision must deny join');
    videochat_iam_deleted_ended_disabled_join_assert((string) ($deletedUserDecision['reason'] ?? '') === 'invalid_user', 'deleted user decision reason mismatch');

    $deletedUserJoin = videochat_handle_call_access_routes(
        '/api/call-access/' . $deletedUserAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $deletedUserAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_deleted_ended_disabled_join_assert(is_array($deletedUserJoin), 'deleted user public join response should exist');
    videochat_iam_deleted_ended_disabled_join_assert((int) ($deletedUserJoin['status'] ?? 0) === 404, 'deleted user public join should return not found');
    videochat_iam_deleted_ended_disabled_join_assert_omits($deletedUserJoin, [$deletedUserCall['title'], 'deleted-join-contract@example.test'], 'deleted user public join');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-deleted-ended-disabled-join-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-deleted-ended-disabled-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
