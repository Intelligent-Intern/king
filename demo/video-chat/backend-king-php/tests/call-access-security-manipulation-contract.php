<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_security_manipulation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-security-manipulation-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_security_manipulation_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_access_security_manipulation_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch();
    return is_array($row) ? $row : [];
}

function videochat_call_access_security_manipulation_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :tenant_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-security-manipulation-contract')
SQL
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':tenant_id' => $tenantId,
        ':issued_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

function videochat_call_access_security_manipulation_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, array $participants = [], string $accessMode = 'invite_only'): string
{
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => '2026-12-11T09:00:00Z',
        'ends_at' => '2026-12-11T10:00:00Z',
        'internal_participant_user_ids' => $participants,
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_security_manipulation_assert((bool) ($created['ok'] ?? false), "{$title} should be created");
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_access_security_manipulation_assert($callId !== '', "{$title} id should be present");
    return $callId;
}

function videochat_call_access_security_manipulation_create_link(PDO $pdo, string $callId, int $ownerUserId, int $targetUserId, int $tenantId, string $kind = 'personal'): string
{
    $options = ['link_kind' => $kind];
    if ($kind === 'personal') {
        $options['participant_user_id'] = $targetUserId;
    }
    $created = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', $options, $tenantId);
    videochat_call_access_security_manipulation_assert((bool) ($created['ok'] ?? false), "{$kind} access link should be created");
    $accessId = (string) (($created['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_security_manipulation_assert($accessId !== '', "{$kind} access id should be present");
    return $accessId;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-security-manipulation-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $previousFrontendOrigin = getenv('VIDEOCHAT_FRONTEND_ORIGIN');
    putenv('VIDEOCHAT_FRONTEND_ORIGIN=https://app.kingrt.test');

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-security-manipulation-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_security_manipulation_assert($tenantId > 0, 'default tenant should exist');
    videochat_call_access_security_manipulation_assert($adminUserId > 0, 'seeded admin should exist');
    videochat_call_access_security_manipulation_assert($standardUserId > 0, 'seeded standard user should exist');

    videochat_call_access_security_manipulation_insert_session($pdo, 'sess_security_standard', $standardUserId, $tenantId);

    $primaryCallId = videochat_call_access_security_manipulation_create_call(
        $pdo,
        $adminUserId,
        $tenantId,
        'Security Primary Bound Call',
        [$standardUserId]
    );
    $secondaryCallId = videochat_call_access_security_manipulation_create_call(
        $pdo,
        $adminUserId,
        $tenantId,
        'Security Private Secondary Call',
        [$standardUserId]
    );
    $openCallId = videochat_call_access_security_manipulation_create_call(
        $pdo,
        $adminUserId,
        $tenantId,
        'Security Anonymous Open Call',
        [],
        'free_for_all'
    );
    $personalAccessId = videochat_call_access_security_manipulation_create_link($pdo, $primaryCallId, $adminUserId, $standardUserId, $tenantId);
    $openAccessId = videochat_call_access_security_manipulation_create_link($pdo, $openCallId, $adminUserId, 0, $tenantId, 'open');

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
    $route = static function (
        string $path,
        string $method,
        array $headers = [],
        string $body = '',
        array $apiAuthContext = [],
        ?callable $issuer = null,
        string $uri = ''
    ) use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $response = videochat_handle_call_routes(
            $path,
            $method,
            [
                'method' => $method,
                'uri' => $uri === '' ? $path : $uri,
                'headers' => $headers,
                'remote_address' => '127.0.0.1',
                'body' => $body,
            ],
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            $issuer
        );
        videochat_call_access_security_manipulation_assert(is_array($response), "{$method} {$path} should return a response");
        return $response;
    };

    $issuerCalls = 0;
    $rejectingIssuer = static function () use (&$issuerCalls): string {
        $issuerCalls += 1;
        return 'sess_security_should_not_issue_' . $issuerCalls;
    };

    foreach ([
        'personalized session body call_id' => [
            '/api/call-access/' . $personalAccessId . '/session',
            ['call_id' => $secondaryCallId],
        ],
        'anonymous session body call_id' => [
            '/api/call-access/' . $openAccessId . '/session',
            ['guest_name' => 'Forged Anonymous Guest', 'call_id' => $secondaryCallId],
        ],
        'forged identity and organization fields' => [
            '/api/call-access/' . $openAccessId . '/session',
            [
                'guest_name' => 'Forged Admin Guest',
                'user_id' => $adminUserId,
                'tenant_id' => 999999,
                'organization_id' => 999999,
                'role' => 'admin',
            ],
        ],
    ] as $label => [$path, $payload]) {
        $response = $route(
            $path,
            'POST',
            [],
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            [],
            $rejectingIssuer
        );
        videochat_call_access_security_manipulation_assert((int) ($response['status'] ?? 0) === 422, "{$label} should be rejected");
        $decoded = videochat_call_access_security_manipulation_decode($response);
        videochat_call_access_security_manipulation_assert((string) (($decoded['error'] ?? [])['code'] ?? '') === 'call_access_validation_failed', "{$label} error code mismatch");
    }
    videochat_call_access_security_manipulation_assert($issuerCalls === 0, 'rejected manipulation requests must not issue sessions');

    $queryManipulation = $route(
        '/api/call-access/' . $personalAccessId . '/join',
        'GET',
        [],
        '',
        [],
        null,
        '/api/call-access/' . $personalAccessId . '/join?call_id=' . rawurlencode($secondaryCallId)
    );
    videochat_call_access_security_manipulation_assert((int) ($queryManipulation['status'] ?? 0) === 422, 'query call_id override should be rejected');

    $guestRows = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $rejectedSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_security_should_not_issue_%'")->fetchColumn();
    videochat_call_access_security_manipulation_assert($guestRows === 0, 'rejected forged open-link request must not create guest users');
    videochat_call_access_security_manipulation_assert($rejectedSessionRows === 0, 'rejected forged requests must not persist sessions');

    $csrfName = 'CSRF Changed Name Should Not Persist';
    $evilRequest = $route(
        '/api/call-access/' . $personalAccessId . '/account-update-confirmation',
        'POST',
        [
            'Authorization' => 'Bearer sess_security_standard',
            'Host' => 'api.kingrt.test',
            'Origin' => 'https://evil.example.test',
        ],
        json_encode(['display_name' => $csrfName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
    );
    videochat_call_access_security_manipulation_assert((int) ($evilRequest['status'] ?? 0) === 403, 'cross-origin account update request should be rejected');
    $evilPayload = videochat_call_access_security_manipulation_decode($evilRequest);
    videochat_call_access_security_manipulation_assert((string) (($evilPayload['error'] ?? [])['code'] ?? '') === 'csrf_origin_forbidden', 'CSRF request error code mismatch');
    $afterEvilRequest = videochat_call_access_security_manipulation_user($pdo, $standardUserId);
    videochat_call_access_security_manipulation_assert((string) ($afterEvilRequest['display_name'] ?? '') !== $csrfName, 'cross-origin request must not update user data');
    $confirmationCountAfterEvil = (int) $pdo->query('SELECT COUNT(*) FROM call_access_account_update_confirmations')->fetchColumn();
    videochat_call_access_security_manipulation_assert($confirmationCountAfterEvil === 0, 'cross-origin request must not create confirmation token');

    $allowedName = 'Allowed Confirmation Name';
    $allowedRequest = $route(
        '/api/call-access/' . $personalAccessId . '/account-update-confirmation',
        'POST',
        [
            'Authorization' => 'Bearer sess_security_standard',
            'Host' => 'api.kingrt.test',
            'Origin' => 'https://app.kingrt.test',
        ],
        json_encode(['display_name' => $allowedName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
    );
    videochat_call_access_security_manipulation_assert((int) ($allowedRequest['status'] ?? 0) === 200, 'allowed frontend origin should create account confirmation');
    $allowedPayload = videochat_call_access_security_manipulation_decode($allowedRequest);
    $token = (string) (((($allowedPayload['result'] ?? [])['debug_confirmation_token'] ?? '')));
    videochat_call_access_security_manipulation_assert($token !== '', 'allowed confirmation should expose test token outside production');

    $evilConfirm = $route(
        '/api/call-access/account-update-confirmations/' . $token . '/confirm',
        'POST',
        [
            'Authorization' => 'Bearer sess_security_standard',
            'Host' => 'api.kingrt.test',
            'Origin' => 'https://evil.example.test',
        ]
    );
    videochat_call_access_security_manipulation_assert((int) ($evilConfirm['status'] ?? 0) === 403, 'cross-origin confirmation should be rejected');
    $afterEvilConfirm = videochat_call_access_security_manipulation_user($pdo, $standardUserId);
    videochat_call_access_security_manipulation_assert((string) ($afterEvilConfirm['display_name'] ?? '') !== $allowedName, 'cross-origin confirmation must not update user data');
    $consumedQuery = $pdo->prepare('SELECT coalesce(consumed_at, \'\') FROM call_access_account_update_confirmations WHERE id = :id LIMIT 1');
    $consumedQuery->execute([':id' => $token]);
    videochat_call_access_security_manipulation_assert((string) $consumedQuery->fetchColumn() === '', 'cross-origin confirmation must not consume token');

    $allowedConfirm = $route(
        '/api/call-access/account-update-confirmations/' . $token . '/confirm',
        'POST',
        [
            'Authorization' => 'Bearer sess_security_standard',
            'Host' => 'api.kingrt.test',
            'Origin' => 'https://app.kingrt.test',
        ]
    );
    videochat_call_access_security_manipulation_assert((int) ($allowedConfirm['status'] ?? 0) === 200, 'allowed frontend origin should confirm account update');
    $afterAllowedConfirm = videochat_call_access_security_manipulation_user($pdo, $standardUserId);
    videochat_call_access_security_manipulation_assert((string) ($afterAllowedConfirm['display_name'] ?? '') === $allowedName, 'allowed confirmation should update only after confirmation');

    $boundIssue = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_security_call_access_bound',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'security-manipulation-bound'],
        [
            'authenticated_user_id' => $standardUserId,
            'authenticated_session_id' => 'sess_security_standard',
            'verified_user_id' => $standardUserId,
            'verified_session_id' => 'sess_security_standard',
        ]
    );
    videochat_call_access_security_manipulation_assert((bool) ($boundIssue['ok'] ?? false), 'bound call-access session should issue');
    $boundAuth = videochat_authenticate_request($pdo, [
        'method' => 'GET',
        'uri' => '/api/calls/resolve/' . $secondaryCallId,
        'headers' => ['Authorization' => 'Bearer sess_security_call_access_bound'],
    ], 'rest');
    videochat_call_access_security_manipulation_assert((bool) ($boundAuth['ok'] ?? false), 'bound call-access session should authenticate before mismatch test');

    $mismatchedResolve = $route(
        '/api/calls/resolve/' . $secondaryCallId,
        'GET',
        ['Authorization' => 'Bearer sess_security_call_access_bound'],
        '',
        $boundAuth
    );
    videochat_call_access_security_manipulation_assert((int) ($mismatchedResolve['status'] ?? 0) === 200, 'mismatched resolve should return safe envelope');
    $mismatchedPayload = videochat_call_access_security_manipulation_decode($mismatchedResolve);
    videochat_call_access_security_manipulation_assert((string) (($mismatchedPayload['result'] ?? [])['state'] ?? '') === 'forbidden', 'mismatched resolve should be forbidden');
    videochat_call_access_security_manipulation_assert((string) (($mismatchedPayload['result'] ?? [])['reason'] ?? '') === 'call_access_session_call_mismatch', 'mismatched resolve reason mismatch');
    videochat_call_access_security_manipulation_assert(($mismatchedPayload['result']['call'] ?? null) === null, 'mismatched resolve must not include call payload');
    videochat_call_access_security_manipulation_assert(!str_contains((string) ($mismatchedResolve['body'] ?? ''), 'Security Private Secondary Call'), 'mismatched resolve must not leak secondary call title');

    $primaryResolve = $route(
        '/api/calls/resolve/' . $primaryCallId,
        'GET',
        ['Authorization' => 'Bearer sess_security_call_access_bound'],
        '',
        $boundAuth
    );
    $primaryPayload = videochat_call_access_security_manipulation_decode($primaryResolve);
    videochat_call_access_security_manipulation_assert((string) (($primaryPayload['result'] ?? [])['state'] ?? '') === 'resolved', 'bound call-access session should resolve its own call');

    $endedCallId = videochat_call_access_security_manipulation_create_call($pdo, $adminUserId, $tenantId, 'Security Ended Entry Call', [$standardUserId]);
    $endedAccessId = videochat_call_access_security_manipulation_create_link($pdo, $endedCallId, $adminUserId, $standardUserId, $tenantId);
    $endedIssue = videochat_issue_session_for_call_access(
        $pdo,
        $endedAccessId,
        static fn (): string => 'sess_security_ended_bound',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'security-ended-bound'],
        ['authenticated_user_id' => $standardUserId]
    );
    videochat_call_access_security_manipulation_assert((bool) ($endedIssue['ok'] ?? false), 'ended setup session should issue before call ends');
    $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id')->execute([':status' => 'ended', ':id' => $endedCallId]);
    $endedAuth = videochat_validate_session_token($pdo, 'sess_security_ended_bound');
    videochat_call_access_security_manipulation_assert(!(bool) ($endedAuth['ok'] ?? true), 'ended call-access session must not authenticate');
    videochat_call_access_security_manipulation_assert((string) ($endedAuth['reason'] ?? '') === 'call_access_call_not_joinable', 'ended call-access auth reason mismatch');

    $deletedCallId = videochat_call_access_security_manipulation_create_call($pdo, $adminUserId, $tenantId, 'Security Deleted Entry Call', [$standardUserId]);
    $deletedAccessId = videochat_call_access_security_manipulation_create_link($pdo, $deletedCallId, $adminUserId, $standardUserId, $tenantId);
    $deletedIssue = videochat_issue_session_for_call_access(
        $pdo,
        $deletedAccessId,
        static fn (): string => 'sess_security_deleted_bound',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'security-deleted-bound'],
        ['authenticated_user_id' => $standardUserId]
    );
    videochat_call_access_security_manipulation_assert((bool) ($deletedIssue['ok'] ?? false), 'deleted setup session should issue before call deletion');
    $deleteResult = videochat_delete_call($pdo, $deletedCallId, $adminUserId, 'admin', $tenantId);
    videochat_call_access_security_manipulation_assert((bool) ($deleteResult['ok'] ?? false), 'delete setup call should be deleted');
    $deletedAuth = videochat_validate_session_token($pdo, 'sess_security_deleted_bound');
    videochat_call_access_security_manipulation_assert(!(bool) ($deletedAuth['ok'] ?? true), 'deleted call-access session must not authenticate');
    videochat_call_access_security_manipulation_assert(
        in_array((string) ($deletedAuth['reason'] ?? ''), ['call_access_binding_mismatch', 'call_access_link_invalidated', 'call_access_call_not_joinable'], true),
        'deleted call-access auth reason mismatch'
    );

    fwrite(STDOUT, "[call-access-security-manipulation-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-security-manipulation-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($previousFrontendOrigin) && is_string($previousFrontendOrigin) && $previousFrontendOrigin !== '') {
        putenv('VIDEOCHAT_FRONTEND_ORIGIN=' . $previousFrontendOrigin);
    } else {
        putenv('VIDEOCHAT_FRONTEND_ORIGIN');
    }
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
