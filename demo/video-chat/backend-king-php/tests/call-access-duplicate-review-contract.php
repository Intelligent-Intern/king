<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_duplicate_review_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-duplicate-review-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_duplicate_review_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_duplicate_review_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-duplicate-review', PASSWORD_DEFAULT);
    videochat_call_access_duplicate_review_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_call_access_duplicate_review_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_access_duplicate_review_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-duplicate-review-contract'{$tenantValue})
SQL
    );
    $params = [
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
    ];
    if ($tenantColumn !== '') {
        $params[':active_tenant_id'] = $tenantId;
    }
    $insert->execute($params);
}

function videochat_call_access_duplicate_review_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_access_duplicate_review_assert_no_needles(string $text, array $needles, string $label): void
{
    $body = strtolower($text);
    foreach ($needles as $needle) {
        $value = strtolower(trim((string) $needle));
        if ($value === '') {
            continue;
        }
        videochat_call_access_duplicate_review_assert(!str_contains($body, $value), "{$label} leaked {$needle}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-duplicate-review-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT=2');
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS=900');

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-duplicate-review-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_call_access_duplicate_review_role_id($pdo, 'admin');
    $userRoleId = videochat_call_access_duplicate_review_role_id($pdo, 'user');
    videochat_call_access_duplicate_review_assert($defaultTenantId > 0, 'default tenant should exist');
    videochat_call_access_duplicate_review_assert($adminRoleId > 0 && $userRoleId > 0, 'expected admin and user roles');

    $secret = 'dup' . bin2hex(random_bytes(5));
    $hostName = 'Duplicate Review Host ' . $secret;
    $targetName = 'Duplicate Review Target ' . $secret;
    $secondName = 'Duplicate Review Second ' . $secret;
    $hostEmail = 'host-' . $secret . '@example.test';
    $targetEmail = 'target-' . $secret . '@example.test';
    $secondEmail = 'second-' . $secret . '@example.test';
    $callTitle = 'Duplicate Review Private Call ' . $secret;

    $hostUserId = videochat_call_access_duplicate_review_create_user($pdo, $adminRoleId, $hostEmail, $hostName);
    $targetUserId = videochat_call_access_duplicate_review_create_user($pdo, $userRoleId, $targetEmail, $targetName);
    $secondUserId = videochat_call_access_duplicate_review_create_user($pdo, $userRoleId, $secondEmail, $secondName);
    videochat_tenant_attach_user($pdo, $hostUserId, $defaultTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $targetUserId, $defaultTenantId, 'member');
    videochat_tenant_attach_user($pdo, $secondUserId, $defaultTenantId, 'member');

    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_target', $targetUserId, $defaultTenantId);
    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_second', $secondUserId, $defaultTenantId);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => $callTitle,
        'starts_at' => '2026-12-01T09:00:00Z',
        'ends_at' => '2026-12-01T10:00:00Z',
        'internal_participant_user_ids' => [$targetUserId],
        'external_participants' => [],
    ], $defaultTenantId);
    videochat_call_access_duplicate_review_assert((bool) ($createCall['ok'] ?? false), 'private call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_duplicate_review_assert($callId !== '', 'private call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $defaultTenantId);
    videochat_call_access_duplicate_review_assert((bool) ($access['ok'] ?? false), 'personalized access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_duplicate_review_assert($accessId !== '', 'personalized access id should be present');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return $jsonResponse($status, ['status' => 'error', 'error' => $error, 'time' => gmdate('c')]);
    };
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };
    $callAccessRoute = static function (
        string $suffix,
        string $method,
        array $headers,
        string $body = '',
        string $issuedSessionId = 'sess_duplicate_unused'
    ) use ($accessId, $jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $path = '/api/call-access/' . $accessId . $suffix;
        $response = videochat_handle_call_routes(
            $path,
            $method,
            [
                'method' => $method,
                'uri' => $path,
                'headers' => $headers,
                'remote_address' => '127.0.0.1',
                'body' => $body,
            ],
            [],
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => $issuedSessionId
        );
        videochat_call_access_duplicate_review_assert(is_array($response), "{$method} {$path} should return a response");
        return $response;
    };

    $secretNeedles = [$accessId, $hostEmail, $hostName, $targetEmail, $targetName, $secondEmail, $secondName];

    $firstIssue = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => 'sess_duplicate_target_access',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'target-open'],
        [
            'authenticated_user_id' => $targetUserId,
            'authenticated_session_id' => 'sess_duplicate_target',
            'verified_user_id' => $targetUserId,
            'verified_session_id' => 'sess_duplicate_target',
        ]
    );
    videochat_call_access_duplicate_review_assert((bool) ($firstIssue['ok'] ?? false), 'first matching account should issue');
    videochat_call_access_duplicate_review_assert(videochat_call_access_review_bootstrap($pdo), 'review bootstrap should succeed');
    $sameAccountFlagCount = (int) $pdo->query('SELECT COUNT(*) FROM call_access_review_flags')->fetchColumn();
    videochat_call_access_duplicate_review_assert($sameAccountFlagCount === 0, 'same linked account must not create a duplicate review flag');

    $joinResponse = $callAccessRoute('/join', 'GET', [
        'Authorization' => 'Bearer sess_duplicate_second',
        'User-Agent' => 'duplicate-second-open',
    ]);
    videochat_call_access_duplicate_review_assert((int) ($joinResponse['status'] ?? 0) === 403, 'second account join open should be forbidden');
    $joinPayload = videochat_call_access_duplicate_review_decode($joinResponse);
    videochat_call_access_duplicate_review_assert((string) (($joinPayload['error'] ?? [])['code'] ?? '') === 'call_access_forbidden', 'join duplicate code mismatch');
    videochat_call_access_duplicate_review_assert(
        (string) (((($joinPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'not_verified',
        'join duplicate should use host_name not_verified field'
    );
    videochat_call_access_duplicate_review_assert_no_needles((string) ($joinResponse['body'] ?? ''), $secretNeedles, 'duplicate join response');

    $flagRows = $pdo->query('SELECT * FROM call_access_review_flags')->fetchAll();
    videochat_call_access_duplicate_review_assert(count($flagRows) === 1, 'second account open should create exactly one review flag');
    $flag = $flagRows[0];
    videochat_call_access_duplicate_review_assert((string) ($flag['reason'] ?? '') === 'duplicate_personalized_link', 'review flag reason mismatch');
    videochat_call_access_duplicate_review_assert((string) ($flag['status'] ?? '') === 'open', 'review flag status mismatch');
    videochat_call_access_duplicate_review_assert((int) ($flag['subject_user_id'] ?? 0) === $secondUserId, 'review flag subject user mismatch');
    videochat_call_access_duplicate_review_assert((int) ($flag['target_user_id'] ?? 0) === $targetUserId, 'review flag target user mismatch');
    videochat_call_access_duplicate_review_assert((string) ($flag['call_id'] ?? '') === $callId, 'review flag call id mismatch');
    videochat_call_access_duplicate_review_assert((string) ($flag['access_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'review flag access fingerprint mismatch');
    videochat_call_access_duplicate_review_assert_no_needles((string) ($flag['payload_json'] ?? ''), $secretNeedles, 'review flag payload');

    $wrongHostOne = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_duplicate_second',
            'Content-Type' => 'application/json',
            'User-Agent' => 'duplicate-second-correct-host-text',
        ],
        json_encode(['host_name' => $hostName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_duplicate_second_should_not_issue_1'
    );
    videochat_call_access_duplicate_review_assert((int) ($wrongHostOne['status'] ?? 0) === 403, 'host-name duplicate attempt should not issue a session');
    $wrongHostOnePayload = videochat_call_access_duplicate_review_decode($wrongHostOne);
    videochat_call_access_duplicate_review_assert(
        (string) (((($wrongHostOnePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'wrong_host_name',
        'wrong-host response should use host_name wrong_host_name'
    );
    videochat_call_access_duplicate_review_assert_no_needles((string) ($wrongHostOne['body'] ?? ''), $secretNeedles, 'wrong-host duplicate response');

    $wrongHostTwo = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_duplicate_second',
            'Content-Type' => 'application/json',
            'User-Agent' => 'duplicate-second-wrong-host-second',
        ],
        json_encode(['host_name' => 'Another Wrong Host ' . $secret], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_duplicate_second_should_not_issue_2'
    );
    videochat_call_access_duplicate_review_assert((int) ($wrongHostTwo['status'] ?? 0) === 403, 'second host attempt should still fail as forbidden');

    $rateLimited = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_duplicate_second',
            'Content-Type' => 'application/json',
            'User-Agent' => 'duplicate-second-rate-limited',
        ],
        json_encode(['host_name' => 'Rate Limited Host ' . $secret], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_duplicate_second_should_not_issue_3'
    );
    videochat_call_access_duplicate_review_assert((int) ($rateLimited['status'] ?? 0) === 429, 'third host attempt should be rate-limited');
    $ratePayload = videochat_call_access_duplicate_review_decode($rateLimited);
    videochat_call_access_duplicate_review_assert(
        (string) (((($ratePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'rate_limited',
        'rate-limited response should name host_name safely'
    );
    videochat_call_access_duplicate_review_assert_no_needles((string) ($rateLimited['body'] ?? ''), $secretNeedles, 'rate-limited duplicate response');

    $reviewFlagCount = (int) $pdo->query('SELECT COUNT(*) FROM call_access_review_flags')->fetchColumn();
    videochat_call_access_duplicate_review_assert($reviewFlagCount === 1, 'repeat duplicate attempts should reuse the same review flag');
    $hostAttemptPayload = implode("\n", array_map(
        static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        $pdo->query('SELECT * FROM call_access_host_verification_attempts')->fetchAll()
    ));
    videochat_call_access_duplicate_review_assert_no_needles($hostAttemptPayload, [$hostName, 'Another Wrong Host ' . $secret, 'Rate Limited Host ' . $secret, $accessId], 'host verification attempts');

    $issuedRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_duplicate_second_should_not_issue_%'")->fetchColumn();
    videochat_call_access_duplicate_review_assert($issuedRows === 0, 'duplicate denied and rate-limited attempts must not persist sessions');
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_duplicate_personalized_link_review'")->fetchColumn();
    videochat_call_access_duplicate_review_assert($auditCount >= 1, 'duplicate review must be audit-logged');

    fwrite(STDOUT, "[call-access-duplicate-review-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-duplicate-review-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT');
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS');
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
