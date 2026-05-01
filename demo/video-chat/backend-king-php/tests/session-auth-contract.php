<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';

function videochat_test_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[session-auth-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-session-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserQuery = $pdo->prepare(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com') AND roles.slug = 'admin'
LIMIT 1
SQL
    );
    $adminUserQuery->execute();
    $adminUserId = (int) $adminUserQuery->fetchColumn();
    videochat_test_assert($adminUserId > 0, 'expected seeded admin user in sqlite bootstrap');

    $standardUserQuery = $pdo->prepare(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $standardUserQuery->execute();
    $standardUserId = (int) $standardUserQuery->fetchColumn();
    videochat_test_assert($standardUserId > 0, 'expected seeded standard user in sqlite bootstrap');

    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_test_assert($userRoleId > 0, 'expected user role in sqlite bootstrap');
    $createGuestUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, NULL, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createGuestUser->execute([
        ':email' => 'guest+sessionauthcontract@videochat.local',
        ':display_name' => 'Guest Session Auth Contract',
        ':role_id' => $userRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $guestUserId = (int) $pdo->lastInsertId();
    videochat_test_assert($guestUserId > 0, 'expected contract guest user to be inserted');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, :revoked_at, '127.0.0.1', 'session-auth-contract-test')
SQL
    );

    $now = time();
    $insertSession->execute([
        ':id' => 'sess_valid_contract',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_expired_contract',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 7200),
        ':expires_at' => gmdate('c', $now - 5),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_revoked_contract',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 7200),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => gmdate('c', $now - 60),
    ]);
    $insertSession->execute([
        ':id' => 'sess_disabled_user_contract',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_user_contract',
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_guest_contract',
        ':user_id' => $guestUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);

    $restMissing = videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => '/api/auth/session', 'headers' => []],
        'rest'
    );
    videochat_test_assert($restMissing['ok'] === false, 'REST auth should fail without session token');
    videochat_test_assert($restMissing['reason'] === 'missing_session', 'REST missing token reason mismatch');

    $restValid = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_valid_contract'],
        ],
        'rest'
    );
    videochat_test_assert($restValid['ok'] === true, 'REST auth should pass with valid Bearer token');
    videochat_test_assert((string) ($restValid['user']['role'] ?? '') === 'admin', 'REST auth user role should be admin');
    videochat_test_assert((string) ($restValid['user']['account_type'] ?? '') === 'account', 'REST auth account user type mismatch');
    videochat_test_assert((bool) ($restValid['user']['is_guest'] ?? true) === false, 'REST auth account guest flag mismatch');

    $guestValid = videochat_validate_session_token($pdo, 'sess_guest_contract');
    videochat_test_assert($guestValid['ok'] === true, 'guest session should validate');
    videochat_test_assert((string) ($guestValid['user']['role'] ?? '') === 'user', 'guest session role should remain user');
    videochat_test_assert((string) ($guestValid['user']['account_type'] ?? '') === 'guest', 'guest session account type mismatch');
    videochat_test_assert((bool) ($guestValid['user']['is_guest'] ?? false) === true, 'guest session flag mismatch');

    $restRevoked = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['X-Session-Id' => 'sess_revoked_contract'],
        ],
        'rest'
    );
    videochat_test_assert($restRevoked['ok'] === false, 'REST auth should fail with revoked session');
    videochat_test_assert($restRevoked['reason'] === 'revoked_session', 'REST revoked reason mismatch');

    $wsValid = videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => '/ws?session=sess_valid_contract', 'headers' => []],
        'websocket'
    );
    videochat_test_assert($wsValid['ok'] === true, 'WebSocket auth should accept query session token');
    videochat_test_assert((string) ($wsValid['session']['id'] ?? '') === 'sess_valid_contract', 'WebSocket auth session id mismatch');

    $wsExpired = videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => '/ws?token=sess_expired_contract', 'headers' => []],
        'websocket'
    );
    videochat_test_assert($wsExpired['ok'] === false, 'WebSocket auth should reject expired session');
    videochat_test_assert($wsExpired['reason'] === 'expired_session', 'WebSocket expired reason mismatch');

    $revocation = videochat_revoke_session($pdo, 'sess_valid_contract');
    videochat_test_assert($revocation['ok'] === true, 'session revocation should succeed');
    videochat_test_assert($revocation['reason'] === 'revoked', 'session revocation reason mismatch');
    videochat_test_assert(is_string($revocation['revoked_at'] ?? null), 'session revocation timestamp missing');

    $revokeAgain = videochat_revoke_session($pdo, 'sess_valid_contract');
    videochat_test_assert($revokeAgain['ok'] === true, 'second session revocation should stay idempotent');
    videochat_test_assert($revokeAgain['reason'] === 'already_revoked', 'second session revocation reason mismatch');

    $restNowRevoked = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_valid_contract'],
        ],
        'rest'
    );
    videochat_test_assert($restNowRevoked['ok'] === false, 'revoked session must fail auth checks');
    videochat_test_assert($restNowRevoked['reason'] === 'revoked_session', 'revoked session auth reason mismatch');

    $restUser = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_user_contract'],
        ],
        'rest'
    );
    videochat_test_assert($restUser['ok'] === true, 'user session should authenticate');
    videochat_test_assert((string) ($restUser['user']['role'] ?? '') === 'user', 'user role mismatch after auth');

    $adminRbac = videochat_authorize_role_for_path((array) ($restValid['user'] ?? []), '/api/admin/ping');
    videochat_test_assert($adminRbac['ok'] === true, 'admin should pass admin RBAC path');

    $adminModerationRbac = videochat_authorize_role_for_path((array) ($restValid['user'] ?? []), '/api/moderation/ping');
    videochat_test_assert($adminModerationRbac['ok'] === true, 'admin should pass moderation RBAC path');

    $userModerationRbac = videochat_authorize_role_for_path((array) ($restUser['user'] ?? []), '/api/moderation/ping');
    videochat_test_assert($userModerationRbac['ok'] === false, 'user should fail moderation RBAC path');
    videochat_test_assert($userModerationRbac['reason'] === 'role_not_allowed', 'user/moderation RBAC reason mismatch');

    $userUserRbac = videochat_authorize_role_for_path((array) ($restUser['user'] ?? []), '/api/user/ping');
    videochat_test_assert($userUserRbac['ok'] === true, 'user should pass user RBAC path');

    $unknownRoleRbac = videochat_authorize_role_for_path(['role' => 'guest'], '/api/user/ping');
    videochat_test_assert($unknownRoleRbac['ok'] === false, 'unknown role should fail protected RBAC paths');
    videochat_test_assert($unknownRoleRbac['reason'] === 'invalid_role', 'unknown role RBAC reason mismatch');

    $openPathRbac = videochat_authorize_role_for_path((array) ($restUser['user'] ?? []), '/api/runtime');
    videochat_test_assert($openPathRbac['ok'] === true, 'unmapped RBAC paths should stay pass-through');
    videochat_test_assert($openPathRbac['reason'] === 'not_applicable', 'unmapped RBAC reason mismatch');

    $activeWebsockets = [];
    $registeredA = videochat_register_active_websocket($activeWebsockets, 'sess_valid_contract', 'ws-a', 'conn-a');
    $registeredB = videochat_register_active_websocket($activeWebsockets, 'sess_valid_contract', 'ws-b', 'conn-b');
    $registeredOther = videochat_register_active_websocket($activeWebsockets, 'sess_other_contract', 'ws-c', 'conn-c');
    videochat_test_assert($registeredA === 'conn-a', 'first websocket registration id mismatch');
    videochat_test_assert($registeredB === 'conn-b', 'second websocket registration id mismatch');
    videochat_test_assert($registeredOther === 'conn-c', 'other websocket registration id mismatch');

    videochat_unregister_active_websocket($activeWebsockets, 'sess_valid_contract', 'conn-b');
    videochat_test_assert(
        !isset($activeWebsockets['sess_valid_contract']['conn-b']),
        'unregister should remove the targeted websocket entry'
    );

    $closed = [];
    $closedCount = videochat_close_tracked_websockets_for_session(
        $activeWebsockets,
        'sess_valid_contract',
        static function (mixed $websocket) use (&$closed): bool {
            $closed[] = $websocket;
            return true;
        }
    );
    videochat_test_assert($closedCount === 1, 'close helper should close remaining tracked websocket');
    videochat_test_assert($closed === ['ws-a'], 'close helper should close the expected tracked websocket');
    videochat_test_assert(
        !isset($activeWebsockets['sess_valid_contract']),
        'close helper should clear the session bucket after closing'
    );
    videochat_test_assert(
        isset($activeWebsockets['sess_other_contract']['conn-c']),
        'close helper should not close websockets from different sessions'
    );

    $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id')->execute([
        ':status' => 'disabled',
        ':updated_at' => gmdate('c'),
        ':id' => $adminUserId,
    ]);
    $wsDisabledUser = videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => '/ws?session=sess_disabled_user_contract', 'headers' => []],
        'websocket'
    );
    videochat_test_assert($wsDisabledUser['ok'] === false, 'WebSocket auth should reject disabled users');
    videochat_test_assert($wsDisabledUser['reason'] === 'user_inactive', 'WebSocket disabled-user reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[session-auth-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[session-auth-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
