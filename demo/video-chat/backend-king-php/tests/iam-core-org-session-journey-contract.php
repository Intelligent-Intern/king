<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/tenant_context.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_auth_session.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_tenancy.php';
require_once __DIR__ . '/../http/module_users.php';

function videochat_iam_core_org_session_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[iam-core-org-session-journey-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_core_org_session_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_iam_core_org_session_bearer_request(string $sessionId): array
{
    return [
        'method' => 'GET',
        'uri' => '/api/auth/session',
        'headers' => ['Authorization' => 'Bearer ' . $sessionId],
    ];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[iam-core-org-session-journey-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-iam-core-org-session-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $seededAdminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $seededAdminTenant = videochat_tenant_context_for_user($pdo, $seededAdminUserId, $tenantId);
    videochat_iam_core_org_session_assert($tenantId > 0, 'default tenant should exist');
    videochat_iam_core_org_session_assert($seededAdminUserId > 0, 'seeded admin user should exist');
    videochat_iam_core_org_session_assert(is_array($seededAdminTenant), 'seeded admin tenant context should exist');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };

    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $adminAuth = [
        'ok' => true,
        'token' => 'sess_iam_core_seeded_admin',
        'session' => ['id' => 'sess_iam_core_seeded_admin'],
        'user' => [
            'id' => $seededAdminUserId,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'tenant' => videochat_tenant_auth_payload($seededAdminTenant),
    ];

    $dispatchUser = static function (string $method, string $path, array $payload = null) use (
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $response = videochat_handle_user_routes(
            $path,
            $method,
            [
                'method' => $method,
                'uri' => $path,
                'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            ],
            $adminAuth,
            [],
            sys_get_temp_dir(),
            512000,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
        videochat_iam_core_org_session_assert(is_array($response), "{$path} should return a user-route response");

        return $response;
    };

    $dispatchTenancy = static function (string $method, string $path, array $payload = null) use (
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $response = videochat_handle_tenancy_routes(
            $path,
            $method,
            [
                'method' => $method,
                'uri' => $path,
                'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            ],
            $adminAuth,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
        videochat_iam_core_org_session_assert(is_array($response), "{$path} should return a tenancy-route response");

        return $response;
    };

    $createAccount = static function (string $email, string $displayName, string $password) use ($dispatchUser): array {
        $response = $dispatchUser('POST', '/api/admin/users', [
            'email' => $email,
            'display_name' => $displayName,
            'password' => $password,
            'role' => 'user',
            'status' => 'active',
        ]);
        videochat_iam_core_org_session_assert((int) ($response['status'] ?? 0) === 201, "{$email} should be registered");
        $payload = videochat_iam_core_org_session_decode($response);
        $user = ($payload['result'] ?? [])['user'] ?? null;
        videochat_iam_core_org_session_assert(is_array($user), "{$email} create response should include user");
        videochat_iam_core_org_session_assert((int) ($user['id'] ?? 0) > 0, "{$email} user id should be present");
        videochat_iam_core_org_session_assert((string) ($user['email'] ?? '') === strtolower($email), "{$email} response email mismatch");

        return ['id' => (int) $user['id'], 'email' => strtolower($email), 'password' => $password, 'payload' => $payload];
    };

    $normalUser = $createAccount(
        'iam-core-normal-user@example.test',
        'IAM Core Normal User',
        'iam-core-normal-password'
    );
    $organizationAdminUser = $createAccount(
        'iam-core-organization-admin@example.test',
        'IAM Core Organization Admin',
        'iam-core-admin-password'
    );
    $tenantOnlyUser = $createAccount(
        'iam-core-no-organization@example.test',
        'IAM Core No Organization',
        'iam-core-no-org-password'
    );

    foreach ([$normalUser, $organizationAdminUser, $tenantOnlyUser] as $createdUser) {
        $tenantMembership = $pdo->prepare(
            'SELECT membership_role FROM tenant_memberships WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = \'active\' LIMIT 1'
        );
        $tenantMembership->execute([':tenant_id' => $tenantId, ':user_id' => $createdUser['id']]);
        videochat_iam_core_org_session_assert($tenantMembership->fetchColumn() !== false, $createdUser['email'] . ' should be registered in the active tenant');
    }

    $organizationResponse = $dispatchTenancy('POST', '/api/governance/organizations', [
        'name' => 'IAM Core Contract Organization',
        'status' => 'active',
        'relationships' => [
            'users' => [
                ['entity_key' => 'users', 'id' => (string) $normalUser['id']],
                ['entity_key' => 'users', 'id' => (string) $organizationAdminUser['id']],
            ],
        ],
    ]);
    videochat_iam_core_org_session_assert((int) ($organizationResponse['status'] ?? 0) === 201, 'organization should be created through governance route');
    $organizationPayload = videochat_iam_core_org_session_decode($organizationResponse);
    $organizationRow = ($organizationPayload['result'] ?? [])['row'] ?? null;
    videochat_iam_core_org_session_assert(is_array($organizationRow), 'organization create response should include row');
    $organizationPublicId = (string) ($organizationRow['id'] ?? ($organizationRow['public_id'] ?? ''));
    videochat_iam_core_org_session_assert($organizationPublicId !== '', 'organization public id should be present');

    $organizationIdQuery = $pdo->prepare('SELECT id FROM organizations WHERE tenant_id = :tenant_id AND public_id = :public_id LIMIT 1');
    $organizationIdQuery->execute([':tenant_id' => $tenantId, ':public_id' => $organizationPublicId]);
    $organizationId = (int) $organizationIdQuery->fetchColumn();
    videochat_iam_core_org_session_assert($organizationId > 0, 'created organization should be persisted');

    $roleUpdate = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET membership_role = 'admin',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
  AND status = 'active'
SQL
    );
    $roleUpdate->execute([
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $organizationAdminUser['id'],
    ]);
    videochat_iam_core_org_session_assert($roleUpdate->rowCount() === 1, 'organization admin role should be assigned');

    $membershipRole = $pdo->prepare(
        <<<'SQL'
SELECT membership_role
FROM organization_memberships
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
  AND status = 'active'
LIMIT 1
SQL
    );
    $membershipRole->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $normalUser['id'],
    ]);
    videochat_iam_core_org_session_assert((string) $membershipRole->fetchColumn() === 'member', 'normal user should keep organization role User/member');
    $membershipRole->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $organizationAdminUser['id'],
    ]);
    videochat_iam_core_org_session_assert((string) $membershipRole->fetchColumn() === 'admin', 'admin user should have organization role Admin');

    $tenantOnlyOrganizationCount = $pdo->prepare(
        'SELECT COUNT(*) FROM organization_memberships WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = \'active\''
    );
    $tenantOnlyOrganizationCount->execute([':tenant_id' => $tenantId, ':user_id' => $tenantOnlyUser['id']]);
    videochat_iam_core_org_session_assert((int) $tenantOnlyOrganizationCount->fetchColumn() === 0, 'tenant-only user should not belong to an organization');

    $login = static function (array $account, string $sessionId) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $activeWebsockets = [];
        $response = videochat_handle_auth_session_routes(
            '/api/auth/login',
            'POST',
            [
                'method' => 'POST',
                'uri' => '/api/auth/login',
                'headers' => ['User-Agent' => 'iam-core-org-session-contract'],
                'remote_address' => '127.0.0.1',
                'body' => json_encode([
                    'email' => $account['email'],
                    'password' => $account['password'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            [],
            $activeWebsockets,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => $sessionId
        );
        videochat_iam_core_org_session_assert(is_array($response), $account['email'] . ' login response should be an array');
        videochat_iam_core_org_session_assert((int) ($response['status'] ?? 0) === 200, $account['email'] . ' should log in');
        $payload = videochat_iam_core_org_session_decode($response);
        videochat_iam_core_org_session_assert((string) (($payload['session'] ?? [])['token'] ?? '') === $sessionId, $account['email'] . ' session token mismatch');
        videochat_iam_core_org_session_assert((int) (($payload['user'] ?? [])['id'] ?? 0) === (int) $account['id'], $account['email'] . ' login user id mismatch');
        videochat_iam_core_org_session_assert((string) (($payload['user'] ?? [])['account_type'] ?? '') === 'account', $account['email'] . ' should log in as registered account');
        videochat_iam_core_org_session_assert((bool) (($payload['user'] ?? [])['is_guest'] ?? true) === false, $account['email'] . ' should not log in as guest');

        return $payload;
    };

    $normalSessionId = 'sess_iam_core_normal_user_login';
    $organizationAdminSessionId = 'sess_iam_core_organization_admin_login';
    $tenantOnlySessionId = 'sess_iam_core_tenant_only_login';
    $normalLogin = $login($normalUser, $normalSessionId);
    $organizationAdminLogin = $login($organizationAdminUser, $organizationAdminSessionId);
    $tenantOnlyLogin = $login($tenantOnlyUser, $tenantOnlySessionId);
    videochat_iam_core_org_session_assert((string) (($normalLogin['user'] ?? [])['role'] ?? '') === 'user', 'normal organization user should log in with User role');
    videochat_iam_core_org_session_assert((string) (($organizationAdminLogin['user'] ?? [])['role'] ?? '') === 'user', 'organization admin should keep registered user account role while holding organization Admin role');
    videochat_iam_core_org_session_assert((string) (($tenantOnlyLogin['user'] ?? [])['role'] ?? '') === 'user', 'tenant-only user should log in as registered user');

    $startsAt = gmdate('c', time() - 300);
    $endsAt = gmdate('c', time() + 3600);
    $ownedCall = videochat_create_call($pdo, $normalUser['id'], [
        'title' => 'IAM Core Organization Rights Call',
        'access_mode' => 'invite_only',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_core_org_session_assert((bool) ($ownedCall['ok'] ?? false), 'organization member should create an invite-only call');
    $ownedCallId = (string) (($ownedCall['call'] ?? [])['id'] ?? '');
    videochat_iam_core_org_session_assert($ownedCallId !== '', 'owned call id should be present');

    $adminParticipantCount = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND user_id = :user_id');
    $adminParticipantCount->execute([':call_id' => $ownedCallId, ':user_id' => $organizationAdminUser['id']]);
    videochat_iam_core_org_session_assert((int) $adminParticipantCount->fetchColumn() === 0, 'organization admin proof must not depend on guest-list membership');
    videochat_iam_core_org_session_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $ownedCallId, $normalUser['id'], $tenantId),
        'organization role User/member must not create organization-admin rights'
    );
    videochat_iam_core_org_session_assert(
        videochat_user_is_organization_admin_for_call($pdo, $ownedCallId, $organizationAdminUser['id'], $tenantId),
        'organization role Admin should grant same-organization call rights'
    );
    videochat_iam_core_org_session_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $ownedCallId, $tenantOnlyUser['id'], $tenantId),
        'user without organization should not receive organization-admin call rights'
    );

    $organizationAdminAuth = videochat_authenticate_request(
        $pdo,
        videochat_iam_core_org_session_bearer_request($organizationAdminSessionId),
        'rest'
    );
    videochat_iam_core_org_session_assert((bool) ($organizationAdminAuth['ok'] ?? false), 'organization admin session should authenticate');
    $tenantOnlyAuth = videochat_authenticate_request(
        $pdo,
        videochat_iam_core_org_session_bearer_request($tenantOnlySessionId),
        'rest'
    );
    videochat_iam_core_org_session_assert((bool) ($tenantOnlyAuth['ok'] ?? false), 'tenant-only session should authenticate');

    $resolveCall = static function (string $callId, array $authContext) use (
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    ): array {
        $response = videochat_handle_call_routes(
            '/api/calls/resolve/' . rawurlencode($callId),
            'GET',
            [
                'method' => 'GET',
                'uri' => '/api/calls/resolve/' . rawurlencode($callId),
                'headers' => ['Authorization' => 'Bearer ' . (string) ($authContext['token'] ?? '')],
            ],
            $authContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
        videochat_iam_core_org_session_assert(is_array($response), 'call resolve response should be an array');
        videochat_iam_core_org_session_assert((int) ($response['status'] ?? 0) === 200, 'call resolve should return 200 envelope');

        return videochat_iam_core_org_session_decode($response);
    };

    $organizationAdminResolve = $resolveCall($ownedCallId, $organizationAdminAuth);
    videochat_iam_core_org_session_assert(
        (string) (($organizationAdminResolve['result'] ?? [])['state'] ?? '') === 'resolved',
        'organization admin should resolve same-organization call without guest-list entry'
    );
    videochat_iam_core_org_session_assert(
        (string) (((($organizationAdminResolve['result'] ?? [])['call'] ?? [])['id'] ?? '')) === $ownedCallId,
        'organization admin resolve should include the authorized call'
    );

    $tenantOnlyResolve = $resolveCall($ownedCallId, $tenantOnlyAuth);
    videochat_iam_core_org_session_assert(
        (string) (($tenantOnlyResolve['result'] ?? [])['state'] ?? '') === 'forbidden',
        'tenant-only user without organization should be forbidden from org-based call access'
    );
    videochat_iam_core_org_session_assert(
        (($tenantOnlyResolve['result'] ?? [])['call'] ?? null) === null,
        'tenant-only denial must not include private call payload'
    );

    $openCall = videochat_create_call($pdo, $normalUser['id'], [
        'title' => 'IAM Core Logged In Open Link Call',
        'access_mode' => 'free_for_all',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_core_org_session_assert((bool) ($openCall['ok'] ?? false), 'open link call should be created');
    $openCallId = (string) (($openCall['call'] ?? [])['id'] ?? '');

    $openLink = videochat_create_call_access_link_for_user($pdo, $openCallId, $normalUser['id'], 'user', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_iam_core_org_session_assert((bool) ($openLink['ok'] ?? false), 'open access link should be created');
    $openAccessId = (string) (($openLink['access_link'] ?? [])['id'] ?? '');
    videochat_iam_core_org_session_assert($openAccessId !== '', 'open access id should be present');

    $normalAuth = videochat_authenticate_request(
        $pdo,
        videochat_iam_core_org_session_bearer_request($normalSessionId),
        'rest'
    );
    videochat_iam_core_org_session_assert((bool) ($normalAuth['ok'] ?? false), 'normal user session should authenticate before opening link');

    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $openAccessId . '/join',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/call-access/' . $openAccessId . '/join',
            'headers' => ['Authorization' => 'Bearer ' . $normalSessionId],
        ],
        $normalAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_core_org_session_assert(is_array($joinResponse), 'logged-in open-link join response should be an array');
    videochat_iam_core_org_session_assert((int) ($joinResponse['status'] ?? 0) === 200, 'logged-in open-link join should resolve');
    $joinPayload = videochat_iam_core_org_session_decode($joinResponse);
    videochat_iam_core_org_session_assert((string) (($joinPayload['result'] ?? [])['link_kind'] ?? '') === 'open', 'logged-in open-link join kind mismatch');
    videochat_iam_core_org_session_assert((($joinPayload['result'] ?? [])['target_user'] ?? null) === null, 'open link join must not bind a temporary target user before session start');

    $callAccessSessionId = 'sess_iam_core_open_link_same_account';
    $callAccessSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $openAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $openAccessId . '/session',
            'headers' => [
                'Authorization' => 'Bearer ' . $normalSessionId,
                'User-Agent' => 'iam-core-org-session-contract-link',
            ],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'verified_user_id' => $normalUser['id'],
                'verified_session_id' => $normalSessionId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $normalAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => $callAccessSessionId
    );
    videochat_iam_core_org_session_assert(is_array($callAccessSessionResponse), 'logged-in open-link session response should be an array');
    videochat_iam_core_org_session_assert((int) ($callAccessSessionResponse['status'] ?? 0) === 200, 'logged-in open-link session should start');
    $callAccessSessionPayload = videochat_iam_core_org_session_decode($callAccessSessionResponse);
    videochat_iam_core_org_session_assert(
        (int) (((($callAccessSessionPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $normalUser['id'],
        'logged-in open link should keep the active registered account'
    );
    videochat_iam_core_org_session_assert(
        (string) (((($callAccessSessionPayload['result'] ?? [])['user'] ?? [])['account_type'] ?? '')) === 'account',
        'logged-in open link should not create a temporary account'
    );
    videochat_iam_core_org_session_assert(
        (bool) (((($callAccessSessionPayload['result'] ?? [])['user'] ?? [])['is_guest'] ?? true)) === false,
        'logged-in open link should keep guest flag false'
    );

    $normalAuthAfterLink = videochat_authenticate_request(
        $pdo,
        videochat_iam_core_org_session_bearer_request($normalSessionId),
        'rest'
    );
    videochat_iam_core_org_session_assert((bool) ($normalAuthAfterLink['ok'] ?? false), 'opening a call link must not revoke the logged-in account session');
    videochat_iam_core_org_session_assert((int) (($normalAuthAfterLink['user'] ?? [])['id'] ?? 0) === $normalUser['id'], 'account session should still belong to the same user after opening link');

    $missingSessionWebsockets = [];
    $missingSessionState = videochat_handle_auth_session_routes(
        '/api/auth/session-state',
        'GET',
        ['method' => 'GET', 'uri' => '/api/auth/session-state', 'headers' => []],
        [],
        $missingSessionWebsockets,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_unused_iam_core_missing'
    );
    videochat_iam_core_org_session_assert(is_array($missingSessionState), 'missing session-state response should be an array');
    videochat_iam_core_org_session_assert((int) ($missingSessionState['status'] ?? 0) === 200, 'session-state should return an unauthenticated envelope for missing sessions');
    $missingPayload = videochat_iam_core_org_session_decode($missingSessionState);
    videochat_iam_core_org_session_assert((string) (($missingPayload['result'] ?? [])['state'] ?? '') === 'unauthenticated', 'logged-out probe should be unauthenticated');
    videochat_iam_core_org_session_assert(($missingPayload['session'] ?? null) === null, 'logged-out probe should not expose a session');
    videochat_iam_core_org_session_assert(($missingPayload['user'] ?? null) === null, 'logged-out probe should not expose an active account');
    videochat_iam_core_org_session_assert(($missingPayload['tenant'] ?? null) === null, 'logged-out probe should not expose tenant context');

    $logoutWebsockets = [];
    $logoutResponse = videochat_handle_auth_session_routes(
        '/api/auth/logout',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/auth/logout',
            'headers' => ['Authorization' => 'Bearer ' . $organizationAdminSessionId],
            'remote_address' => '127.0.0.1',
            'body' => '',
        ],
        $organizationAdminAuth,
        $logoutWebsockets,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_unused_iam_core_logout'
    );
    videochat_iam_core_org_session_assert(is_array($logoutResponse), 'logout response should be an array');
    videochat_iam_core_org_session_assert((int) ($logoutResponse['status'] ?? 0) === 200, 'organization admin should log out');
    $logoutPayload = videochat_iam_core_org_session_decode($logoutResponse);
    videochat_iam_core_org_session_assert((string) (($logoutPayload['result'] ?? [])['session_id'] ?? '') === $organizationAdminSessionId, 'logout session id mismatch');
    videochat_iam_core_org_session_assert(
        in_array((string) (($logoutPayload['result'] ?? [])['revocation_state'] ?? ''), ['revoked', 'already_revoked'], true),
        'logout should revoke the active session'
    );

    $revokedSessionState = videochat_handle_auth_session_routes(
        '/api/auth/session-state',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/auth/session-state',
            'headers' => ['Authorization' => 'Bearer ' . $organizationAdminSessionId],
        ],
        [],
        $logoutWebsockets,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_unused_iam_core_revoked'
    );
    videochat_iam_core_org_session_assert(is_array($revokedSessionState), 'revoked session-state response should be an array');
    videochat_iam_core_org_session_assert((int) ($revokedSessionState['status'] ?? 0) === 200, 'revoked session-state should return an unauthenticated envelope');
    $revokedPayload = videochat_iam_core_org_session_decode($revokedSessionState);
    videochat_iam_core_org_session_assert((string) (($revokedPayload['result'] ?? [])['state'] ?? '') === 'unauthenticated', 'revoked session should no longer be authenticated');
    videochat_iam_core_org_session_assert(($revokedPayload['session'] ?? null) === null, 'revoked session should not expose session payload');
    videochat_iam_core_org_session_assert(($revokedPayload['user'] ?? null) === null, 'revoked session should not expose account payload');

    @unlink($databasePath);
    fwrite(STDOUT, "[iam-core-org-session-journey-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[iam-core-org-session-journey-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
