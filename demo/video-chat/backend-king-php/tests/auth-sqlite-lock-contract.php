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
    $authSource = file_get_contents(__DIR__ . '/../support/auth.php');
    $routerSource = file_get_contents(__DIR__ . '/../http/router.php');
    $serverSource = file_get_contents(__DIR__ . '/../server.php');
    $launcherSource = file_get_contents(__DIR__ . '/../run-dev.sh');
    $tenantContextSource = file_get_contents(__DIR__ . '/../support/tenant_context.php');
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
            && str_contains($databaseSource, 'flock($lockHandle, LOCK_UN)')
            && str_contains($databaseSource, 'function videochat_sqlite_runtime_snapshot'),
        'sqlite bootstrap must be serialized by a per-database file lock and workers must have a read-only runtime snapshot',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($authSessionSource)
            && str_contains($authSessionSource, '$maxLoginAttempts = 20')
            && str_contains($authSessionSource, "BEGIN IMMEDIATE")
            && str_contains($authSessionSource, 'videochat_auth_session_rollback_if_open')
            && str_contains($authSessionSource, 'auth_login_retryable_locked')
            && str_contains($authSessionSource, 'videochat_authenticate_request_with_retry($openDatabase')
            && str_contains($authSessionSource, "return \$errorResponse(503, 'auth_session_probe_failed'")
            && str_contains($authSessionSource, "'retryable' => true")
            && str_contains($authSessionSource, "'retry_after_seconds' => 2"),
        'login and session probes must use explicit write locks, rollback cleanup, retries, and retryable errors',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($authSource)
            && str_contains($authSource, 'function videochat_authenticate_request_with_retry')
            && str_contains($authSource, "backend_reason' => 'sqlite_busy'")
            && str_contains($authSource, "retryable' => true"),
        'shared auth retry helper must preserve retryable sqlite-busy semantics',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($routerSource)
            && str_contains($routerSource, 'videochat_authenticate_request_with_retry($openDatabase')
            && str_contains($routerSource, 'authentication backend busy transport='),
        'router auth middleware must use shared auth retry helper',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($serverSource)
            && str_contains($serverSource, 'VIDEOCHAT_KING_BOOTSTRAP_ONLY')
            && str_contains($serverSource, 'VIDEOCHAT_KING_SKIP_BOOTSTRAP')
            && str_contains($serverSource, 'videochat_sqlite_runtime_snapshot($dbPath)'),
        'server workers must support bootstrap-only and skip-bootstrap modes',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($launcherSource)
            && str_contains($launcherSource, 'run_parent_bootstrap')
            && str_contains($launcherSource, 'VIDEOCHAT_KING_BOOTSTRAP_ONLY=1')
            && str_contains($launcherSource, 'VIDEOCHAT_KING_SKIP_BOOTSTRAP=1'),
        'backend launcher must run one parent bootstrap before worker fan-out',
    );
    videochat_auth_sqlite_lock_assert(
        is_string($tenantContextSource)
            && preg_match('/\\$row = \\$query->fetch\\(\\);\\s*if \\(!is_array\\(\\$row\\)\\) \\{\\s*try \\{\\s*videochat_tenant_backfill_default_memberships\\(\\$pdo\\);/s', $tenantContextSource) === 1,
        'tenant context lookup must read first and only run membership backfill as a fallback',
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

    $lockedProbeOpenDatabase = static function (): PDO {
        throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    };
    $sessionProbeResponse = videochat_handle_auth_session_routes(
        '/api/auth/session-state',
        'GET',
        [
            'headers' => ['Authorization' => 'Bearer sess_probe_contract'],
            'uri' => '/api/auth/session-state',
            'remote_address' => '127.0.0.1',
        ],
        [],
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $lockedProbeOpenDatabase,
        static fn (): string => 'contract-session-unused',
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

    videochat_auth_sqlite_lock_assert(is_array($sessionProbeResponse), 'session-state should return a response under sqlite lock pressure');
    videochat_auth_sqlite_lock_assert((int) ($sessionProbeResponse['status'] ?? 0) === 503, 'session-state sqlite lock should return HTTP 503');
    $probePayload = is_array($sessionProbeResponse['payload'] ?? null) ? $sessionProbeResponse['payload'] : [];
    videochat_auth_sqlite_lock_assert((string) ($probePayload['code'] ?? '') === 'auth_session_probe_failed', 'session-state sqlite lock code mismatch');
    $probeDetails = is_array($probePayload['details'] ?? null) ? $probePayload['details'] : [];
    videochat_auth_sqlite_lock_assert(($probeDetails['retryable'] ?? false) === true, 'session-state sqlite lock must be retryable');
    videochat_auth_sqlite_lock_assert((string) ($probeDetails['reason'] ?? '') === 'sqlite_busy', 'session-state sqlite lock reason mismatch');

    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    @unlink($databasePath . '.bootstrap.lock');

    echo "[auth-sqlite-lock-contract] PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[auth-sqlite-lock-contract] FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
