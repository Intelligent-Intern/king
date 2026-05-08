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

function videochat_call_access_duplicate_review_create_tenant(PDO $pdo, string $slug, string $label): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_generate_call_access_uuid(),
        ':slug' => $slug,
        ':label' => $label,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_call_access_duplicate_review_assert($tenantId > 0, "{$label} tenant should be created");
    return $tenantId;
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

$videochatCallAccessDuplicateReviewWorkerProcess = false;

function videochat_call_access_duplicate_review_parallel_worker(
    string $databasePath,
    string $accessId,
    string $startPath,
    string $resultPath,
    string $issuedSessionId,
    int $userId,
    string $authSessionId,
    string $label
): void {
    global $videochatCallAccessDuplicateReviewWorkerProcess;
    $videochatCallAccessDuplicateReviewWorkerProcess = true;

    $deadline = microtime(true) + 10.0;
    while (!is_file($startPath) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    try {
        if (!is_file($startPath)) {
            throw new RuntimeException('parallel start signal timed out');
        }

        $workerPdo = videochat_open_sqlite_pdo($databasePath);
        $issue = videochat_issue_session_for_call_access(
            $workerPdo,
            $accessId,
            static fn (): string => $issuedSessionId,
            ['client_ip' => '127.0.0.7', 'user_agent' => 'duplicate-parallel-' . $label],
            [
                'authenticated_user_id' => $userId,
                'authenticated_session_id' => $authSessionId,
                'verified_user_id' => $userId,
                'verified_session_id' => $authSessionId,
            ]
        );
        $payload = [
            'ok' => (bool) ($issue['ok'] ?? false),
            'reason' => (string) ($issue['reason'] ?? ''),
            'errors' => is_array($issue['errors'] ?? null) ? $issue['errors'] : [],
            'session_id' => (string) ((($issue['session'] ?? [])['id'] ?? '')),
            'user_id' => (int) ((($issue['user'] ?? [])['id'] ?? 0)),
        ];
        file_put_contents($resultPath, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit(0);
    } catch (Throwable $error) {
        file_put_contents($resultPath, json_encode([
            'ok' => false,
            'reason' => 'worker_error',
            'message' => $error->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        exit(2);
    }
}

function videochat_call_access_duplicate_review_read_parallel_result(string $path, string $label): array
{
    videochat_call_access_duplicate_review_assert(is_file($path), "{$label} parallel result should exist");
    $decoded = json_decode((string) file_get_contents($path), true);
    videochat_call_access_duplicate_review_assert(is_array($decoded), "{$label} parallel result should decode");
    return $decoded;
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
    $thirdName = 'Duplicate Review Third ' . $secret;
    $hostEmail = 'host-' . $secret . '@example.test';
    $targetEmail = 'target-' . $secret . '@example.test';
    $secondEmail = 'second-' . $secret . '@example.test';
    $thirdEmail = 'third-' . $secret . '@example.test';
    $callTitle = 'Duplicate Review Private Call ' . $secret;

    $hostUserId = videochat_call_access_duplicate_review_create_user($pdo, $adminRoleId, $hostEmail, $hostName);
    $targetUserId = videochat_call_access_duplicate_review_create_user($pdo, $userRoleId, $targetEmail, $targetName);
    $secondUserId = videochat_call_access_duplicate_review_create_user($pdo, $userRoleId, $secondEmail, $secondName);
    $thirdUserId = videochat_call_access_duplicate_review_create_user($pdo, $userRoleId, $thirdEmail, $thirdName);
    videochat_tenant_attach_user($pdo, $hostUserId, $defaultTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $targetUserId, $defaultTenantId, 'member');
    videochat_tenant_attach_user($pdo, $secondUserId, $defaultTenantId, 'member');
    videochat_tenant_attach_user($pdo, $thirdUserId, $defaultTenantId, 'member');

    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_target', $targetUserId, $defaultTenantId);
    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_second', $secondUserId, $defaultTenantId);
    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_target_parallel_auth', $targetUserId, $defaultTenantId);
    videochat_call_access_duplicate_review_insert_session($pdo, 'sess_duplicate_third_parallel_auth', $thirdUserId, $defaultTenantId);

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

    $secretNeedles = [$accessId, $hostEmail, $hostName, $targetEmail, $targetName, $secondEmail, $secondName, $thirdEmail, $thirdName];

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
    videochat_call_access_duplicate_review_assert((int) ($flag['first_seen_user_id'] ?? 0) === $targetUserId, 'review flag should reference the first in-call linked account');
    videochat_call_access_duplicate_review_assert(trim((string) ($flag['first_seen_at'] ?? '')) !== '', 'review flag should keep first in-call session timestamp');
    videochat_call_access_duplicate_review_assert((string) ($flag['call_id'] ?? '') === $callId, 'review flag call id mismatch');
    videochat_call_access_duplicate_review_assert((string) ($flag['access_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'review flag access fingerprint mismatch');
    videochat_call_access_duplicate_review_assert_no_needles((string) ($flag['payload_json'] ?? ''), $secretNeedles, 'review flag payload');
    $warningModalFlagId = (string) ($flag['public_id'] ?? '');
    videochat_call_access_duplicate_review_assert($warningModalFlagId !== '', 'warning-modal review flag public id should be present');
    $warningModalFlagPayload = json_decode((string) ($flag['payload_json'] ?? '{}'), true);
    videochat_call_access_duplicate_review_assert(is_array($warningModalFlagPayload), 'warning-modal review flag payload should decode');
    videochat_call_access_duplicate_review_assert((string) ($warningModalFlagPayload['flag'] ?? '') === 'duplicate_personalized_link', 'warning-modal review flag payload flag mismatch');
    videochat_call_access_duplicate_review_assert((string) ($warningModalFlagPayload['stage'] ?? '') === 'join_opened', 'warning-modal review flag must be created at join-open reach');
    videochat_call_access_duplicate_review_assert((string) ($warningModalFlagPayload['review_status'] ?? '') === 'manual_review_required', 'warning-modal review flag status payload mismatch');
    videochat_call_access_duplicate_review_assert((bool) ($warningModalFlagPayload['raw_link_identifier_logged'] ?? true) === false, 'warning-modal review flag must mark raw link omission');
    videochat_call_access_duplicate_review_assert((bool) ($warningModalFlagPayload['account_email_logged'] ?? true) === false, 'warning-modal review flag must mark email omission');
    videochat_call_access_duplicate_review_assert((bool) ($warningModalFlagPayload['host_name_logged'] ?? true) === false, 'warning-modal review flag must mark host-name omission');
    $warningModalAuditQuery = $pdo->prepare(
        <<<'SQL'
SELECT payload_json
FROM videochat_audit_events
WHERE event_type = 'call_access_duplicate_personalized_link_review'
  AND actor_user_id = :actor_user_id
  AND call_id = :call_id
ORDER BY id DESC
LIMIT 1
SQL
    );
    $warningModalAuditQuery->execute([
        ':actor_user_id' => $secondUserId,
        ':call_id' => $callId,
    ]);
    $warningModalAuditPayload = json_decode((string) $warningModalAuditQuery->fetchColumn(), true);
    $warningModalAuditQuery->closeCursor();
    videochat_call_access_duplicate_review_assert(is_array($warningModalAuditPayload), 'warning-modal review audit payload should decode');
    videochat_call_access_duplicate_review_assert((string) ($warningModalAuditPayload['stage'] ?? '') === 'join_opened', 'warning-modal review audit should record join_opened stage');
    videochat_call_access_duplicate_review_assert((bool) ($warningModalAuditPayload['flag_created'] ?? false), 'warning-modal review audit should record initial flag creation');
    videochat_call_access_duplicate_review_assert((bool) ($warningModalAuditPayload['raw_link_identifier_logged'] ?? true) === false, 'warning-modal review audit must mark raw link omission');

    $loginSwitchResponse = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_duplicate_second',
            'Content-Type' => 'application/json',
            'User-Agent' => 'duplicate-same-browser-login-switch',
        ],
        json_encode([
            'verified_user_id' => $targetUserId,
            'verified_session_id' => 'sess_duplicate_target',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_duplicate_second_login_switch_should_not_issue'
    );
    videochat_call_access_duplicate_review_assert((int) ($loginSwitchResponse['status'] ?? 0) === 409, 'same-browser logout/login switch should fail closed');
    $loginSwitchPayload = videochat_call_access_duplicate_review_decode($loginSwitchResponse);
    videochat_call_access_duplicate_review_assert((string) (($loginSwitchPayload['error'] ?? [])['code'] ?? '') === 'call_access_conflict', 'login-switch conflict code mismatch');
    videochat_call_access_duplicate_review_assert(
        (string) (((($loginSwitchPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['auth'] ?? '') === 'session_context_changed',
        'login-switch conflict should expose only session_context_changed'
    );
    videochat_call_access_duplicate_review_assert_no_needles((string) ($loginSwitchResponse['body'] ?? ''), $secretNeedles, 'login-switch duplicate response');
    $loginSwitchSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_duplicate_second_login_switch_should_not_issue'")->fetchColumn();
    videochat_call_access_duplicate_review_assert($loginSwitchSessionRows === 0, 'login switch must not persist a second-account session');
    $loginSwitchParticipantRows = (int) $pdo->query("SELECT COUNT(*) FROM call_participants WHERE call_id = " . $pdo->quote($callId) . " AND user_id = " . $secondUserId)->fetchColumn();
    videochat_call_access_duplicate_review_assert($loginSwitchParticipantRows === 0, 'login switch must not attach account B as participant');
    $freshAfterLoginSwitch = videochat_fetch_call_access_link($pdo, $accessId, $defaultTenantId);
    videochat_call_access_duplicate_review_assert(is_array($freshAfterLoginSwitch), 'login-switch access link should still resolve');
    videochat_call_access_duplicate_review_assert((int) ($freshAfterLoginSwitch['participant_user_id'] ?? 0) === $targetUserId, 'login switch must not reassign the personalized link');

    $loginSwitchFlags = $pdo->query('SELECT * FROM call_access_review_flags')->fetchAll();
    videochat_call_access_duplicate_review_assert(count($loginSwitchFlags) === 1, 'login switch should reuse the duplicate review flag');
    $loginSwitchFlag = $loginSwitchFlags[0];
    videochat_call_access_duplicate_review_assert((string) ($loginSwitchFlag['public_id'] ?? '') === $warningModalFlagId, 'login-switch review should reuse the warning-modal flag');
    videochat_call_access_duplicate_review_assert((int) ($loginSwitchFlag['subject_user_id'] ?? 0) === $secondUserId, 'login-switch review subject user mismatch');
    videochat_call_access_duplicate_review_assert((int) ($loginSwitchFlag['target_user_id'] ?? 0) === $targetUserId, 'login-switch review target user mismatch');
    videochat_call_access_duplicate_review_assert((int) ($loginSwitchFlag['first_seen_user_id'] ?? 0) === $targetUserId, 'login-switch review should reference account A');
    videochat_call_access_duplicate_review_assert((string) ($loginSwitchFlag['call_id'] ?? '') === $callId, 'login-switch review call id mismatch');
    videochat_call_access_duplicate_review_assert((string) ($loginSwitchFlag['access_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'login-switch review access fingerprint mismatch');
    videochat_call_access_duplicate_review_assert_no_needles((string) ($loginSwitchFlag['payload_json'] ?? ''), $secretNeedles, 'login-switch review flag payload');

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
    $reusedFlagQuery = $pdo->prepare('SELECT public_id, payload_json FROM call_access_review_flags WHERE subject_user_id = :subject_user_id LIMIT 1');
    $reusedFlagQuery->execute([':subject_user_id' => $secondUserId]);
    $reusedFlag = $reusedFlagQuery->fetch(PDO::FETCH_ASSOC);
    $reusedFlagQuery->closeCursor();
    videochat_call_access_duplicate_review_assert(is_array($reusedFlag), 'reused warning-modal review flag should still exist');
    videochat_call_access_duplicate_review_assert((string) ($reusedFlag['public_id'] ?? '') === $warningModalFlagId, 'host verification must reuse the warning-modal review flag');
    $reusedFlagPayload = json_decode((string) ($reusedFlag['payload_json'] ?? '{}'), true);
    videochat_call_access_duplicate_review_assert(is_array($reusedFlagPayload), 'reused review flag payload should decode');
    videochat_call_access_duplicate_review_assert((string) ($reusedFlagPayload['stage'] ?? '') === 'join_opened', 'reused review flag must preserve the original warning-modal stage');
    $hostAttemptPayload = implode("\n", array_map(
        static fn (array $row): string => json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
        $pdo->query('SELECT * FROM call_access_host_verification_attempts')->fetchAll()
    ));
    videochat_call_access_duplicate_review_assert_no_needles($hostAttemptPayload, [$hostName, 'Another Wrong Host ' . $secret, 'Rate Limited Host ' . $secret, $accessId], 'host verification attempts');

    $issuedRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_duplicate_second_should_not_issue_%'")->fetchColumn();
    videochat_call_access_duplicate_review_assert($issuedRows === 0, 'duplicate denied and rate-limited attempts must not persist sessions');
    $auditCount = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_duplicate_personalized_link_review'")->fetchColumn();
    videochat_call_access_duplicate_review_assert($auditCount >= 2, 'duplicate review and login switch must be audit-logged');
    $loginSwitchAudit = $pdo->prepare(
        <<<'SQL'
SELECT payload_json
FROM videochat_audit_events
WHERE event_type = 'call_access_duplicate_personalized_link_review'
  AND actor_user_id = :actor_user_id
  AND call_id = :call_id
ORDER BY id ASC
SQL
    );
    $loginSwitchAudit->execute([
        ':actor_user_id' => $secondUserId,
        ':call_id' => $callId,
    ]);
    $loginSwitchAuditPayload = null;
    foreach ($loginSwitchAudit->fetchAll(PDO::FETCH_COLUMN) as $payloadJson) {
        $candidate = json_decode((string) $payloadJson, true);
        if (is_array($candidate) && (string) ($candidate['stage'] ?? '') === 'session_context_changed') {
            $loginSwitchAuditPayload = $candidate;
            break;
        }
    }
    videochat_call_access_duplicate_review_assert(is_array($loginSwitchAuditPayload), 'login-switch duplicate audit payload should decode');
    videochat_call_access_duplicate_review_assert((string) ($loginSwitchAuditPayload['stage'] ?? '') === 'session_context_changed', 'login-switch duplicate audit stage mismatch');
    videochat_call_access_duplicate_review_assert((string) ($loginSwitchAuditPayload['review_status'] ?? '') === 'manual_review_required', 'login-switch duplicate audit should be reviewer-understandable');
    videochat_call_access_duplicate_review_assert((bool) ($loginSwitchAuditPayload['raw_link_identifier_logged'] ?? true) === false, 'login-switch duplicate audit must omit raw link id');
    videochat_call_access_duplicate_review_assert((bool) ($loginSwitchAuditPayload['account_email_logged'] ?? true) === false, 'login-switch duplicate audit must omit email');

    if (function_exists('pcntl_fork')) {
        $parallelStartPath = $databasePath . '.parallel.start';
        $parallelTargetPath = $databasePath . '.parallel.target.json';
        $parallelThirdPath = $databasePath . '.parallel.third.json';
        @unlink($parallelStartPath);
        @unlink($parallelTargetPath);
        @unlink($parallelThirdPath);

        $targetPid = pcntl_fork();
        videochat_call_access_duplicate_review_assert($targetPid !== -1, 'target parallel worker should fork');
        if ($targetPid === 0) {
            videochat_call_access_duplicate_review_parallel_worker(
                $databasePath,
                $accessId,
                $parallelStartPath,
                $parallelTargetPath,
                'sess_duplicate_target_parallel_access',
                $targetUserId,
                'sess_duplicate_target_parallel_auth',
                'target'
            );
        }

        $thirdPid = pcntl_fork();
        videochat_call_access_duplicate_review_assert($thirdPid !== -1, 'third-account parallel worker should fork');
        if ($thirdPid === 0) {
            videochat_call_access_duplicate_review_parallel_worker(
                $databasePath,
                $accessId,
                $parallelStartPath,
                $parallelThirdPath,
                'sess_duplicate_third_should_not_issue_parallel',
                $thirdUserId,
                'sess_duplicate_third_parallel_auth',
                'third'
            );
        }

        file_put_contents($parallelStartPath, 'go');
        $targetStatus = 0;
        $thirdStatus = 0;
        pcntl_waitpid($targetPid, $targetStatus);
        pcntl_waitpid($thirdPid, $thirdStatus);
        videochat_call_access_duplicate_review_assert(pcntl_wifexited($targetStatus) && pcntl_wexitstatus($targetStatus) === 0, 'target parallel worker should exit cleanly');
        videochat_call_access_duplicate_review_assert(pcntl_wifexited($thirdStatus) && pcntl_wexitstatus($thirdStatus) === 0, 'third-account parallel worker should exit cleanly');

        $targetParallel = videochat_call_access_duplicate_review_read_parallel_result($parallelTargetPath, 'target');
        $thirdParallel = videochat_call_access_duplicate_review_read_parallel_result($parallelThirdPath, 'third-account');
        videochat_call_access_duplicate_review_assert((bool) ($targetParallel['ok'] ?? false), 'linked account parallel reopen should issue');
        videochat_call_access_duplicate_review_assert((string) ($targetParallel['session_id'] ?? '') === 'sess_duplicate_target_parallel_access', 'linked account parallel session id mismatch');
        videochat_call_access_duplicate_review_assert((int) ($targetParallel['user_id'] ?? 0) === $targetUserId, 'linked account parallel user mismatch');
        videochat_call_access_duplicate_review_assert(!(bool) ($thirdParallel['ok'] ?? false), 'foreign account parallel attempt must not issue');
        videochat_call_access_duplicate_review_assert((string) ($thirdParallel['reason'] ?? '') === 'conflict', 'foreign account parallel attempt should fail closed');
        videochat_call_access_duplicate_review_assert((string) (($thirdParallel['errors'] ?? [])['auth'] ?? '') === 'session_context_changed', 'foreign account parallel error should preserve verified-context conflict');

        $parallelSessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_duplicate_target_parallel_access' AND user_id = " . $targetUserId)->fetchColumn();
        videochat_call_access_duplicate_review_assert($parallelSessionCount === 1, 'parallel linked account session should persist exactly once');
        $foreignParallelSessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_duplicate_third_should_not_issue_parallel' OR (user_id = " . $thirdUserId . " AND id LIKE 'sess_duplicate_third_should_not_issue%')")->fetchColumn();
        videochat_call_access_duplicate_review_assert($foreignParallelSessionCount === 0, 'parallel foreign account must not persist a call session');
        $freshParallelAccess = videochat_fetch_call_access_link($pdo, $accessId, $defaultTenantId);
        videochat_call_access_duplicate_review_assert(is_array($freshParallelAccess), 'parallel access link should still resolve');
        videochat_call_access_duplicate_review_assert((int) ($freshParallelAccess['participant_user_id'] ?? 0) === $targetUserId, 'parallel race must not reassign the personalized link');
        videochat_call_access_duplicate_review_assert(trim((string) ($freshParallelAccess['consumed_at'] ?? '')) !== '', 'parallel race should leave the personalized link consumed');

        $thirdFlagQuery = $pdo->prepare(
            <<<'SQL'
SELECT *
FROM call_access_review_flags
WHERE access_fingerprint = :access_fingerprint
  AND reason = 'duplicate_personalized_link'
  AND subject_user_id = :subject_user_id
LIMIT 1
SQL
        );
        $thirdFlagQuery->execute([
            ':access_fingerprint' => videochat_audit_fingerprint($accessId),
            ':subject_user_id' => $thirdUserId,
        ]);
        $thirdFlag = $thirdFlagQuery->fetch();
        videochat_call_access_duplicate_review_assert(is_array($thirdFlag), 'parallel foreign account should create a duplicate review flag');
        videochat_call_access_duplicate_review_assert((int) ($thirdFlag['target_user_id'] ?? 0) === $targetUserId, 'parallel review target user mismatch');
        videochat_call_access_duplicate_review_assert((int) ($thirdFlag['first_seen_user_id'] ?? 0) === $targetUserId, 'parallel review should reference first in-call account');
        videochat_call_access_duplicate_review_assert(trim((string) ($thirdFlag['first_seen_at'] ?? '')) !== '', 'parallel review should keep first in-call timestamp');
        videochat_call_access_duplicate_review_assert_no_needles((string) ($thirdFlag['payload_json'] ?? ''), $secretNeedles, 'parallel review flag payload');

        $parallelAudit = $pdo->prepare(
            <<<'SQL'
SELECT COUNT(*)
FROM videochat_audit_events
WHERE event_type = 'call_access_duplicate_personalized_link_review'
  AND actor_user_id = :actor_user_id
  AND call_id = :call_id
SQL
        );
        $parallelAudit->execute([
            ':actor_user_id' => $thirdUserId,
            ':call_id' => $callId,
        ]);
        videochat_call_access_duplicate_review_assert((int) $parallelAudit->fetchColumn() >= 1, 'parallel duplicate review must be audit-logged');

        @unlink($parallelStartPath);
        @unlink($parallelTargetPath);
        @unlink($parallelThirdPath);
    } else {
        fwrite(STDOUT, "[call-access-duplicate-review-contract] SKIP: pcntl_fork unavailable for parallel race subcheck\n");
    }

    $alphaTenantId = videochat_call_access_duplicate_review_create_tenant($pdo, 'review-alpha-' . $secret, 'Review Alpha ' . $secret);
    $betaTenantId = videochat_call_access_duplicate_review_create_tenant($pdo, 'review-beta-' . $secret, 'Review Beta ' . $secret);
    $crossOrgAccessId = videochat_generate_call_access_uuid();
    $crossOrgCallId = videochat_generate_call_access_uuid();
    $crossOrgHostName = 'Cross Org Foreign Review Host ' . $secret;
    $crossOrgToken = 'tok_cross_org_review_' . $secret;
    $crossOrgSdp = 'v=0 secret-cross-org-review-sdp-' . $secret;
    $crossOrgIce = 'candidate:secret-cross-org-review-ice-' . $secret;
    $crossOrgReview = videochat_call_access_record_duplicate_personalized_link_review(
        $pdo,
        [
            'id' => $crossOrgAccessId,
            'tenant_id' => $alphaTenantId,
            'call_id' => $crossOrgCallId,
            'participant_user_id' => $targetUserId,
            'link_kind' => 'personal',
        ],
        [
            'id' => $crossOrgCallId,
            'tenant_id' => $betaTenantId,
        ],
        ['id' => $targetUserId],
        $secondUserId,
        'cross_org_review_assignment',
        [
            'session_id' => 'sess_duplicate_cross_org_review',
            'host_name' => $crossOrgHostName,
            'token' => $crossOrgToken,
            'sdp' => $crossOrgSdp,
            'ice_candidate' => $crossOrgIce,
        ]
    );
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgReview['ok'] ?? false), 'cross-organization review flag should record');
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgReview['flag_created'] ?? false), 'cross-organization review flag should be created');
    $crossOrgFlag = is_array($crossOrgReview['flag'] ?? null) ? $crossOrgReview['flag'] : [];
    videochat_call_access_duplicate_review_assert((int) ($crossOrgFlag['tenant_id'] ?? 0) === $betaTenantId, 'review flag tenant must follow the call organization');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgFlag['call_id'] ?? '') === $crossOrgCallId, 'review flag call id must follow the call');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgFlag['subject_user_id'] ?? 0) === $secondUserId, 'review flag subject must be the foreign account');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgFlag['target_user_id'] ?? 0) === $targetUserId, 'review flag target must be the linked account');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgFlag['first_seen_user_id'] ?? 0) === $targetUserId, 'review flag should identify the affected linked account reference');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgFlag['access_fingerprint'] ?? '') === videochat_audit_fingerprint($crossOrgAccessId), 'review flag must fingerprint the foreign link');
    videochat_call_access_duplicate_review_assert_no_needles(
        (string) ($crossOrgFlag['payload_json'] ?? ''),
        [$crossOrgAccessId, $crossOrgHostName, $crossOrgToken, $crossOrgSdp, $crossOrgIce, $hostEmail, $targetEmail, $secondEmail],
        'cross-org review flag payload'
    );

    $crossOrgAudit = $pdo->prepare(
        <<<'SQL'
SELECT tenant_id, actor_user_id, target_user_id, call_id, resource_id, resource_fingerprint, session_fingerprint, payload_json, created_at
FROM videochat_audit_events
WHERE event_type = 'call_access_duplicate_personalized_link_review'
  AND actor_user_id = :actor_user_id
  AND call_id = :call_id
ORDER BY id DESC
LIMIT 1
SQL
    );
    $crossOrgAudit->execute([
        ':actor_user_id' => $secondUserId,
        ':call_id' => $crossOrgCallId,
    ]);
    $crossOrgAuditRow = $crossOrgAudit->fetch();
    videochat_call_access_duplicate_review_assert(is_array($crossOrgAuditRow), 'cross-organization review audit event should exist');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgAuditRow['tenant_id'] ?? 0) === $betaTenantId, 'review audit tenant must follow the call organization');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgAuditRow['actor_user_id'] ?? 0) === $secondUserId, 'review audit actor must be the foreign account');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgAuditRow['target_user_id'] ?? 0) === $targetUserId, 'review audit target must be the linked account');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditRow['call_id'] ?? '') === $crossOrgCallId, 'review audit call id must follow the call');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditRow['resource_id'] ?? '') === '', 'review audit must not persist raw access id');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditRow['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($crossOrgAccessId), 'review audit must fingerprint the foreign link');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditRow['session_fingerprint'] ?? '') === videochat_audit_fingerprint('sess_duplicate_cross_org_review'), 'review audit must fingerprint the session id');
    videochat_call_access_duplicate_review_assert(trim((string) ($crossOrgAuditRow['created_at'] ?? '')) !== '', 'review audit should include a timestamp');

    $crossOrgAuditPayload = json_decode((string) ($crossOrgAuditRow['payload_json'] ?? '{}'), true);
    videochat_call_access_duplicate_review_assert(is_array($crossOrgAuditPayload), 'review audit payload should decode');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditPayload['flag'] ?? '') === 'duplicate_personalized_link', 'review audit flag mismatch');
    videochat_call_access_duplicate_review_assert((string) ($crossOrgAuditPayload['review_status'] ?? '') === 'manual_review_required', 'review audit should be reviewer-understandable');
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgAuditPayload['flag_created'] ?? false), 'review audit should record flag creation');
    videochat_call_access_duplicate_review_assert((int) ($crossOrgAuditPayload['first_seen_user_id'] ?? 0) === $targetUserId, 'review audit should reference the affected linked account');
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgAuditPayload['raw_link_identifier_logged'] ?? true) === false, 'review audit must mark raw link omission');
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgAuditPayload['account_email_logged'] ?? true) === false, 'review audit must mark email omission');
    videochat_call_access_duplicate_review_assert((bool) ($crossOrgAuditPayload['host_name_logged'] ?? true) === false, 'review audit must mark host-name omission');

    $crossOrgAuditEncoded = json_encode($crossOrgAuditRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_access_duplicate_review_assert(is_string($crossOrgAuditEncoded), 'review audit row should encode');
    videochat_call_access_duplicate_review_assert_no_needles(
        $crossOrgAuditEncoded,
        [$crossOrgAccessId, $crossOrgHostName, $crossOrgToken, $crossOrgSdp, $crossOrgIce, $hostEmail, $targetEmail, $secondEmail],
        'cross-org review audit event'
    );

    fwrite(STDOUT, "[call-access-duplicate-review-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-duplicate-review-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT');
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS');
    if (!$videochatCallAccessDuplicateReviewWorkerProcess && isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
