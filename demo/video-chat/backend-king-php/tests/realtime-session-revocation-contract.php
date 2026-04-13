<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_session_revocation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-session-revocation-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-realtime-session-revocation-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_realtime_session_revocation_assert($adminUserId > 0, 'expected seeded admin user');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, :revoked_at, '127.0.0.1', 'realtime-session-revocation-contract')
SQL
    );

    $now = time();
    $insertSession->execute([
        ':id' => 'sess_rt_valid',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_rt_expired',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 7200),
        ':expires_at' => gmdate('c', $now - 1),
        ':revoked_at' => null,
    ]);
    $insertSession->execute([
        ':id' => 'sess_rt_revoked',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 60),
        ':expires_at' => gmdate('c', $now + 3600),
        ':revoked_at' => gmdate('c', $now - 10),
    ]);

    $authenticateRequest = static function (array $request, string $transport) use ($databasePath): array {
        $pdoLocal = videochat_open_sqlite_pdo($databasePath);
        return videochat_authenticate_request($pdoLocal, $request, $transport);
    };

    $probe = videochat_realtime_session_probe_request('sess_rt_valid', '/ws');
    videochat_realtime_session_revocation_assert((string) ($probe['method'] ?? '') === 'GET', 'probe request method mismatch');
    videochat_realtime_session_revocation_assert((string) ($probe['uri'] ?? '') === '/ws', 'probe request uri mismatch');
    videochat_realtime_session_revocation_assert(
        (string) (($probe['headers'] ?? [])['Authorization'] ?? '') === 'Bearer sess_rt_valid',
        'probe request authorization header mismatch'
    );

    $validLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_valid', '/ws');
    videochat_realtime_session_revocation_assert($validLiveness['ok'] === true, 'valid session should pass realtime liveness');
    videochat_realtime_session_revocation_assert($validLiveness['reason'] === 'ok', 'valid session reason mismatch');

    $expiredLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_expired', '/ws');
    videochat_realtime_session_revocation_assert($expiredLiveness['ok'] === false, 'expired session should fail realtime liveness');
    videochat_realtime_session_revocation_assert($expiredLiveness['reason'] === 'expired_session', 'expired session reason mismatch');

    $revokedLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_revoked', '/ws');
    videochat_realtime_session_revocation_assert($revokedLiveness['ok'] === false, 'revoked session should fail realtime liveness');
    videochat_realtime_session_revocation_assert($revokedLiveness['reason'] === 'revoked_session', 'revoked session reason mismatch');

    $firstCheck = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_valid', '/ws');
    videochat_realtime_session_revocation_assert($firstCheck['ok'] === true, 'session should be valid before revocation');

    $revocation = videochat_revoke_session($pdo, 'sess_rt_valid');
    videochat_realtime_session_revocation_assert($revocation['ok'] === true, 'session revocation should succeed');

    $afterRevocation = videochat_realtime_validate_session_liveness($authenticateRequest, 'sess_rt_valid', '/ws');
    videochat_realtime_session_revocation_assert($afterRevocation['ok'] === false, 'revoked live session should fail liveness after revoke');
    videochat_realtime_session_revocation_assert($afterRevocation['reason'] === 'revoked_session', 'revoked live session reason mismatch');

    $missingLiveness = videochat_realtime_validate_session_liveness($authenticateRequest, '', '/ws');
    videochat_realtime_session_revocation_assert($missingLiveness['ok'] === false, 'missing session should fail liveness');
    videochat_realtime_session_revocation_assert($missingLiveness['reason'] === 'missing_session', 'missing session reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[realtime-session-revocation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[realtime-session-revocation-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
