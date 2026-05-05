<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function videochat_auth_sqlite_lock_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[auth-sqlite-lock-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databaseCoreSource = file_get_contents(__DIR__ . '/../support/database_core.php');
    $databaseSource = file_get_contents(__DIR__ . '/../support/database.php');
    $authSessionSource = file_get_contents(__DIR__ . '/../http/module_auth_session.php');
    videochat_auth_sqlite_lock_assert(
        is_string($databaseCoreSource)
            && str_contains($databaseCoreSource, 'VIDEOCHAT_SQLITE_BUSY_TIMEOUT_MS = 15000')
            && str_contains($databaseCoreSource, 'function videochat_sqlite_is_transient_lock')
            && str_contains($databaseCoreSource, 'database table is locked')
            && str_contains($databaseCoreSource, 'function videochat_sqlite_retry_delay_us'),
        'shared sqlite lock helper and busy timeout must be contracted',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($databaseSource)
            && str_contains($databaseSource, ".bootstrap.lock'")
            && str_contains($databaseSource, 'flock($lockHandle, LOCK_EX)')
            && str_contains($databaseSource, 'flock($lockHandle, LOCK_UN)'),
        'sqlite bootstrap must be serialized by a per-database file lock',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($authSessionSource)
            && str_contains($authSessionSource, '$maxLoginAttempts = 8')
            && str_contains($authSessionSource, 'auth_login_retryable_locked')
            && str_contains($authSessionSource, "'retryable' => true")
            && str_contains($authSessionSource, "'retry_after_seconds' => 2"),
        'login lock exhaustion must be retryable and distinct from invalid credentials',
    );

    $databasePath = sys_get_temp_dir() . '/videochat-auth-sqlite-lock-' . bin2hex(random_bytes(6)) . '.sqlite';
    videochat_bootstrap_sqlite($databasePath);

    $locker = new PDO('sqlite:' . $databasePath);
    $locker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $locker->exec('PRAGMA busy_timeout = 1');
    $locker->exec('BEGIN EXCLUSIVE');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'payload' => $payload,
    ];
    $errorResponse = static fn (int $status, string $code, string $message, array $details = []): array => [
        'status' => $status,
        'payload' => [
            'status' => 'error',
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
    ];
    $decodeJsonBody = static fn (array $request): array => [
        json_decode((string) ($request['body'] ?? ''), true),
        null,
    ];
    $openDatabase = static function () use ($databasePath): PDO {
        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 1');
        return $pdo;
    };

    $activeWebsocketsBySession = [];
    $response = videochat_handle_auth_session_routes(
        '/api/auth/login',
        'POST',
        [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'email' => 'admin@intelligent-intern.com',
                'password' => 'admin123',
            ], JSON_THROW_ON_ERROR),
            'remote_address' => '127.0.0.1',
        ],
        [],
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'contract-session-' . bin2hex(random_bytes(4)),
    );

    $locker->exec('ROLLBACK');
    $locker = null;

    videochat_auth_sqlite_lock_assert(is_array($response), 'login route should return a response under sqlite lock pressure');
    videochat_auth_sqlite_lock_assert((int) ($response['status'] ?? 0) === 503, 'sqlite lock exhaustion should return HTTP 503');
    $payload = is_array($response['payload'] ?? null) ? $response['payload'] : [];
    videochat_auth_sqlite_lock_assert((string) ($payload['code'] ?? '') === 'auth_login_retryable_locked', 'sqlite lock exhaustion code mismatch');
    $details = is_array($payload['details'] ?? null) ? $payload['details'] : [];
    videochat_auth_sqlite_lock_assert(($details['retryable'] ?? false) === true, 'sqlite lock exhaustion must be marked retryable');
    videochat_auth_sqlite_lock_assert((string) ($details['reason'] ?? '') === 'sqlite_busy', 'sqlite lock exhaustion reason mismatch');

    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    @unlink($databasePath . '.bootstrap.lock');

    echo "[auth-sqlite-lock-contract] PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-sqlite-lock-contract] FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
