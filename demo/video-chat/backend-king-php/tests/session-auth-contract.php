<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';

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

    $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id')->execute([
        ':status' => 'disabled',
        ':updated_at' => gmdate('c'),
        ':id' => $adminUserId,
    ]);
    $wsDisabledUser = videochat_authenticate_request(
        $pdo,
        ['method' => 'GET', 'uri' => '/ws?session=sess_valid_contract', 'headers' => []],
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
