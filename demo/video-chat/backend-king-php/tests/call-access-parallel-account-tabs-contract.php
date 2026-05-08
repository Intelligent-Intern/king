<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_parallel_tabs_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-parallel-account-tabs-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_parallel_tabs_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_parallel_tabs_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-parallel-tabs', PASSWORD_DEFAULT);
    videochat_call_access_parallel_tabs_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

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
    videochat_call_access_parallel_tabs_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_access_parallel_tabs_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-parallel-account-tabs-contract'{$tenantValue})
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

function videochat_call_access_parallel_tabs_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_access_parallel_tabs_assert_no_needles(string $text, array $needles, string $label): void
{
    $lowerText = strtolower($text);
    foreach ($needles as $needle) {
        $value = strtolower(trim((string) $needle));
        if ($value === '') {
            continue;
        }
        videochat_call_access_parallel_tabs_assert(!str_contains($lowerText, $value), "{$label} leaked {$needle}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-parallel-account-tabs-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-parallel-tabs-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_call_access_parallel_tabs_role_id($pdo, 'admin');
    $userRoleId = videochat_call_access_parallel_tabs_role_id($pdo, 'user');
    videochat_call_access_parallel_tabs_assert($tenantId > 0, 'default tenant should exist');
    videochat_call_access_parallel_tabs_assert($adminRoleId > 0 && $userRoleId > 0, 'expected admin and user roles');

    $secret = 'parallel' . bin2hex(random_bytes(5));
    $ownerUserId = videochat_call_access_parallel_tabs_create_user($pdo, $adminRoleId, "owner-{$secret}@example.test", "Parallel Owner {$secret}");
    $linkedUserId = videochat_call_access_parallel_tabs_create_user($pdo, $userRoleId, "linked-{$secret}@example.test", "Parallel Linked {$secret}");
    $otherUserId = videochat_call_access_parallel_tabs_create_user($pdo, $userRoleId, "other-{$secret}@example.test", "Intruder Other {$secret}");
    $switchUserId = videochat_call_access_parallel_tabs_create_user($pdo, $userRoleId, "switch-{$secret}@example.test", "Parallel Switch {$secret}");
    $deviceUserId = videochat_call_access_parallel_tabs_create_user($pdo, $userRoleId, "device-{$secret}@example.test", "Parallel Device {$secret}");
    $browserUserId = videochat_call_access_parallel_tabs_create_user($pdo, $userRoleId, "browser-{$secret}@example.test", "Parallel Browser {$secret}");
    videochat_tenant_attach_user($pdo, $ownerUserId, $tenantId, 'owner');
    videochat_tenant_attach_user($pdo, $linkedUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $otherUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $switchUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $deviceUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $browserUserId, $tenantId, 'member');

    $linkedTabSessionA = 'sess_parallel_linked_tab_a';
    $linkedTabSessionB = 'sess_parallel_linked_tab_b';
    $otherTabSession = 'sess_parallel_other_tab';
    $sameBrowserSwitchSession = 'sess_parallel_same_browser_switch';
    $crossDeviceSession = 'sess_parallel_cross_device';
    $crossBrowserSession = 'sess_parallel_cross_browser';
    videochat_call_access_parallel_tabs_insert_session($pdo, $linkedTabSessionA, $linkedUserId, $tenantId);
    videochat_call_access_parallel_tabs_insert_session($pdo, $linkedTabSessionB, $linkedUserId, $tenantId);
    videochat_call_access_parallel_tabs_insert_session($pdo, $otherTabSession, $otherUserId, $tenantId);
    videochat_call_access_parallel_tabs_insert_session($pdo, $sameBrowserSwitchSession, $switchUserId, $tenantId);
    videochat_call_access_parallel_tabs_insert_session($pdo, $crossDeviceSession, $deviceUserId, $tenantId);
    videochat_call_access_parallel_tabs_insert_session($pdo, $crossBrowserSession, $browserUserId, $tenantId);
    $sameBrowserAuthProbe = videochat_authenticate_request($pdo, ['headers' => ['Authorization' => 'Bearer ' . $sameBrowserSwitchSession]], 'rest');
    videochat_call_access_parallel_tabs_assert((bool) ($sameBrowserAuthProbe['ok'] ?? false), 'same-browser switch fixture session should authenticate');
    videochat_call_access_parallel_tabs_assert((int) ((($sameBrowserAuthProbe['user'] ?? [])['id'] ?? 0)) === $switchUserId, 'same-browser switch fixture auth user mismatch');

    $createCall = videochat_create_call($pdo, $ownerUserId, [
        'title' => "Parallel Account Tabs {$secret}",
        'starts_at' => '2026-12-02T09:00:00Z',
        'ends_at' => '2026-12-02T10:00:00Z',
        'internal_participant_user_ids' => [$linkedUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_parallel_tabs_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_parallel_tabs_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $linkedUserId,
    ], $tenantId);
    videochat_call_access_parallel_tabs_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_parallel_tabs_assert($accessId !== '', 'access id should be present');

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
        $raw = (string) ($request['body'] ?? '');
        if (trim($raw) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };
    $route = static function (
        string $suffix,
        string $method,
        string $sessionToken,
        array $body,
        string $issuedSessionId,
        string $userAgent = 'call-access-parallel-account-tabs-contract'
    ) use ($accessId, $jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $path = '/api/call-access/' . $accessId . $suffix;
        $headers = ['User-Agent' => $userAgent];
        if ($sessionToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $sessionToken;
        }
        $rawBody = '';
        if ($body !== []) {
            $headers['Content-Type'] = 'application/json';
            $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        $response = videochat_handle_call_routes(
            $path,
            $method,
            [
                'method' => $method,
                'uri' => $path,
                'headers' => $headers,
                'remote_address' => '127.0.0.1',
                'body' => $rawBody,
            ],
            [],
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => $issuedSessionId
        );
        videochat_call_access_parallel_tabs_assert(is_array($response), "{$method} {$path} should return a response");
        return $response;
    };

    $privateNeedles = [
        "linked-{$secret}@example.test",
        "Parallel Linked {$secret}",
        "owner-{$secret}@example.test",
        "Parallel Owner {$secret}",
        "switch-{$secret}@example.test",
        "Parallel Switch {$secret}",
        "device-{$secret}@example.test",
        "Parallel Device {$secret}",
        "browser-{$secret}@example.test",
        "Parallel Browser {$secret}",
    ];

    $targetSessionA = $route('/session', 'POST', $linkedTabSessionA, [
        'verified_user_id' => $linkedUserId,
        'verified_session_id' => $linkedTabSessionA,
    ], 'sess_parallel_linked_call_access_a');
    videochat_call_access_parallel_tabs_assert((int) ($targetSessionA['status'] ?? 0) === 200, 'linked account tab A should issue before wrong-account open');
    $targetPayloadA = videochat_call_access_parallel_tabs_decode($targetSessionA);
    videochat_call_access_parallel_tabs_assert((int) (((($targetPayloadA['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $linkedUserId, 'tab A issued session user mismatch');

    $wrongOpen = $route('/join', 'GET', $otherTabSession, [], 'sess_parallel_unused_join');
    videochat_call_access_parallel_tabs_assert((int) ($wrongOpen['status'] ?? 0) === 403, 'e2e_duplicate_link_005_concurrent_two_accounts_same_link_detected should deny wrong-account link open');
    videochat_call_access_parallel_tabs_assert_no_needles((string) ($wrongOpen['body'] ?? ''), $privateNeedles, 'wrong-account open response');

    $wrongSession = $route('/session', 'POST', $otherTabSession, [
        'verified_user_id' => $otherUserId,
        'verified_session_id' => $otherTabSession,
    ], 'sess_parallel_other_should_not_issue');
    videochat_call_access_parallel_tabs_assert((int) ($wrongSession['status'] ?? 0) === 409, 'wrong-account parallel session should fail safely with verified-context conflict');
    $wrongSessionPayload = videochat_call_access_parallel_tabs_decode($wrongSession);
    videochat_call_access_parallel_tabs_assert((string) (($wrongSessionPayload['error'] ?? [])['code'] ?? '') === 'call_access_conflict', 'wrong-account conflict code mismatch');

    $targetSessionB = $route('/session', 'POST', $linkedTabSessionB, [
        'verified_user_id' => $linkedUserId,
        'verified_session_id' => $linkedTabSessionB,
    ], 'sess_parallel_linked_call_access_b');
    videochat_call_access_parallel_tabs_assert((int) ($targetSessionB['status'] ?? 0) === 200, 'same linked account tab B should reopen without duplicate flag');

    $bindingQuery = $pdo->prepare(
        <<<'SQL'
SELECT session_id, user_id
FROM call_access_sessions
WHERE access_id = :access_id
ORDER BY session_id ASC
SQL
    );
    $bindingQuery->execute([':access_id' => $accessId]);
    $bindings = $bindingQuery->fetchAll();
    videochat_call_access_parallel_tabs_assert(count($bindings) === 2, 'parallel target tabs should persist two call-access sessions');
    foreach ($bindings as $binding) {
        videochat_call_access_parallel_tabs_assert((int) ($binding['user_id'] ?? 0) === $linkedUserId, 'call-access binding must stay on linked account');
    }

    $otherSessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_parallel_other_should_not_issue'")->fetchColumn();
    videochat_call_access_parallel_tabs_assert($otherSessionCount === 0, 'wrong-account parallel tab must not persist a session');

    $otherParticipant = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND user_id = :user_id');
    $otherParticipant->execute([':call_id' => $callId, ':user_id' => $otherUserId]);
    videochat_call_access_parallel_tabs_assert((int) $otherParticipant->fetchColumn() === 0, 'wrong-account parallel tab must not become a call participant');

    $flagRows = $pdo->query('SELECT * FROM call_access_review_flags')->fetchAll();
    videochat_call_access_parallel_tabs_assert(count($flagRows) === 1, 'wrong-account parallel opens should reuse one duplicate review flag');
    $flag = $flagRows[0];
    videochat_call_access_parallel_tabs_assert((int) ($flag['subject_user_id'] ?? 0) === $otherUserId, 'duplicate flag subject user mismatch');
    videochat_call_access_parallel_tabs_assert((int) ($flag['target_user_id'] ?? 0) === $linkedUserId, 'duplicate flag target user mismatch');
    videochat_call_access_parallel_tabs_assert((string) ($flag['reason'] ?? '') === 'duplicate_personalized_link', 'duplicate flag reason mismatch');
    videochat_call_access_parallel_tabs_assert_no_needles((string) ($flag['payload_json'] ?? ''), $privateNeedles, 'duplicate flag payload');

    $sameBrowserOpen = $route(
        '/session',
        'POST',
        $sameBrowserSwitchSession,
        [
            'verified_user_id' => $linkedUserId,
            'verified_session_id' => $linkedTabSessionA,
        ],
        'sess_same_browser_switch_should_not_issue',
        'King IAM E2E same-browser after logout/login switch'
    );
    videochat_call_access_parallel_tabs_assert((int) ($sameBrowserOpen['status'] ?? 0) === 409, 'same-browser logout/login switch should deny duplicate personalized-link session');
    videochat_call_access_parallel_tabs_assert_no_needles((string) ($sameBrowserOpen['body'] ?? ''), $privateNeedles, 'same-browser logout/login duplicate session response');

    $crossDeviceOpen = $route(
        '/session',
        'POST',
        $crossDeviceSession,
        [
            'verified_user_id' => $deviceUserId,
            'verified_session_id' => $crossDeviceSession,
        ],
        'sess_cross_device_should_not_issue',
        'King IAM E2E Mobile Device B'
    );
    videochat_call_access_parallel_tabs_assert((int) ($crossDeviceOpen['status'] ?? 0) === 409, 'e2e_duplicate_link_007 cross-device duplicate session should be review-flagged');
    videochat_call_access_parallel_tabs_assert_no_needles((string) ($crossDeviceOpen['body'] ?? ''), $privateNeedles, 'cross-device duplicate session response');

    $crossBrowserOpen = $route(
        '/session',
        'POST',
        $crossBrowserSession,
        [
            'verified_user_id' => $browserUserId,
            'verified_session_id' => $crossBrowserSession,
        ],
        'sess_cross_browser_should_not_issue',
        'Mozilla/5.0 King IAM E2E Firefox Browser C'
    );
    videochat_call_access_parallel_tabs_assert((int) ($crossBrowserOpen['status'] ?? 0) === 409, 'e2e_duplicate_link_008 cross-browser duplicate session should be review-flagged');
    videochat_call_access_parallel_tabs_assert_no_needles((string) ($crossBrowserOpen['body'] ?? ''), $privateNeedles, 'cross-browser duplicate session response');

    $pdo = videochat_open_sqlite_pdo($databasePath);
    $fetchDuplicateFlag = static function (int $subjectUserId) use ($pdo, $accessId): array {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT *
FROM call_access_review_flags
WHERE access_fingerprint = :access_fingerprint
  AND reason = 'duplicate_personalized_link'
  AND subject_user_id = :subject_user_id
LIMIT 1
SQL
        );
        $query->execute([
            ':access_fingerprint' => videochat_audit_fingerprint($accessId),
            ':subject_user_id' => $subjectUserId,
        ]);
        $row = $query->fetch();
        return is_array($row) ? $row : [];
    };

    foreach ([
        'same-browser logout/login switch duplicate flag' => $fetchDuplicateFlag($switchUserId),
        'cross-device duplicate flag' => $fetchDuplicateFlag($deviceUserId),
        'cross-browser duplicate flag' => $fetchDuplicateFlag($browserUserId),
    ] as $label => $duplicateFlag) {
        videochat_call_access_parallel_tabs_assert($duplicateFlag !== [], "{$label} should exist");
        videochat_call_access_parallel_tabs_assert((int) ($duplicateFlag['target_user_id'] ?? 0) === $linkedUserId, "{$label} target user mismatch");
        videochat_call_access_parallel_tabs_assert((string) ($duplicateFlag['reason'] ?? '') === 'duplicate_personalized_link', "{$label} reason mismatch");
        videochat_call_access_parallel_tabs_assert_no_needles((string) ($duplicateFlag['payload_json'] ?? ''), $privateNeedles, "{$label} payload");
    }

    $unexpectedDuplicateSessions = (int) $pdo->query(
        "SELECT COUNT(*) FROM sessions WHERE id IN ('sess_same_browser_switch_should_not_issue', 'sess_cross_device_should_not_issue', 'sess_cross_browser_should_not_issue')"
    )->fetchColumn();
    videochat_call_access_parallel_tabs_assert($unexpectedDuplicateSessions === 0, 'same-browser/device/browser duplicate sessions must not persist issued sessions');

    $freshAccess = videochat_fetch_call_access_link($pdo, $accessId, $tenantId);
    videochat_call_access_parallel_tabs_assert(is_array($freshAccess), 'duplicate device/browser access link should still resolve');
    videochat_call_access_parallel_tabs_assert((int) ($freshAccess['participant_user_id'] ?? 0) === $linkedUserId, 'same-browser/device/browser duplicate sessions must not reassign personalized link');

    $auditRows = $pdo->query(
        "SELECT payload_json FROM videochat_audit_events WHERE event_type = 'call_access_duplicate_personalized_link_review'"
    )->fetchAll();
    $stages = [];
    foreach ($auditRows as $row) {
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        if (is_array($payload) && is_string($payload['stage'] ?? null)) {
            $stages[] = (string) $payload['stage'];
        }
    }
    videochat_call_access_parallel_tabs_assert(in_array('join_opened', $stages, true), 'parallel duplicate audit should name join_opened');
    videochat_call_access_parallel_tabs_assert(in_array('session_verified_context', $stages, true), 'parallel duplicate audit should name session_verified_context');

    fwrite(STDOUT, "[call-access-parallel-account-tabs-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-parallel-account-tabs-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
