<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_session_route_guard_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-session-route-guard-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_access_session_route_guard_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_call_access_session_route_guard_assert_no_leak(array $response, array $needles, string $label): void
{
    $body = (string) ($response['body'] ?? '');
    foreach ($needles as $needle) {
        $text = is_string($needle) ? trim($needle) : '';
        if ($text === '') {
            continue;
        }
        videochat_call_access_session_route_guard_assert(!str_contains($body, $text), "{$label} leaked {$text}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-session-route-guard-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-session-route-guard-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_session_route_guard_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_session_route_guard_assert($standardUserId > 0, 'expected seeded standard user');

    $standardEmail = (string) $pdo->query("SELECT email FROM users WHERE id = {$standardUserId} LIMIT 1")->fetchColumn();
    $standardName = (string) $pdo->query("SELECT display_name FROM users WHERE id = {$standardUserId} LIMIT 1")->fetchColumn();

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-session-route-guard-contract')
SQL
    );
    $now = time();
    $insertSession->execute([
        ':id' => 'sess_route_guard_admin',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
    ]);
    $insertSession->execute([
        ':id' => 'sess_route_guard_standard',
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
    ]);

    $createPersonalCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Route Guard Secret Personal Call',
        'starts_at' => '2026-09-01T09:00:00Z',
        'ends_at' => '2026-09-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_session_route_guard_assert((bool) ($createPersonalCall['ok'] ?? false), 'personal call should be created');
    $personalCallId = (string) (($createPersonalCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_route_guard_assert($personalCallId !== '', 'personal call id should be present');

    $personalAccess = videochat_create_call_access_link_for_user($pdo, $personalCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ]);
    videochat_call_access_session_route_guard_assert((bool) ($personalAccess['ok'] ?? false), 'personal access link should be created');
    $personalAccessId = (string) (($personalAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_route_guard_assert($personalAccessId !== '', 'personal access id should be present');

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
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };
    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };
    $callSessionRoute = static function (
        string $accessId,
        array $headers,
        string $body,
        string $issuedSessionId
    ) use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $response = videochat_handle_call_routes(
            '/api/call-access/' . $accessId . '/session',
            'POST',
            [
                'method' => 'POST',
                'uri' => '/api/call-access/' . $accessId . '/session',
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
        videochat_call_access_session_route_guard_assert(is_array($response), 'call access session route should return a response');
        return $response;
    };

    $anonymousPersonal = $callSessionRoute($personalAccessId, ['User-Agent' => 'route-guard-anonymous'], '{}', 'sess_route_guard_anonymous_personal');
    videochat_call_access_session_route_guard_assert((int) ($anonymousPersonal['status'] ?? 0) === 200, 'anonymous personal link should still issue');
    $anonymousPayload = videochat_call_access_session_route_guard_decode($anonymousPersonal);
    videochat_call_access_session_route_guard_assert(
        (int) (((($anonymousPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $standardUserId,
        'anonymous personal link should bind the linked user'
    );

    $standardPersonal = $callSessionRoute(
        $personalAccessId,
        ['Authorization' => 'Bearer sess_route_guard_standard', 'User-Agent' => 'route-guard-standard'],
        '{}',
        'sess_route_guard_standard_personal'
    );
    videochat_call_access_session_route_guard_assert((int) ($standardPersonal['status'] ?? 0) === 200, 'matching logged-in user should issue');
    $standardPayload = videochat_call_access_session_route_guard_decode($standardPersonal);
    videochat_call_access_session_route_guard_assert(
        (int) (((($standardPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $standardUserId,
        'matching logged-in route should bind the linked user'
    );

    $wrongAccount = $callSessionRoute(
        $personalAccessId,
        ['Authorization' => 'Bearer sess_route_guard_admin', 'User-Agent' => 'route-guard-wrong-account'],
        '{}',
        'sess_route_guard_wrong_account_should_not_issue'
    );
    videochat_call_access_session_route_guard_assert((int) ($wrongAccount['status'] ?? 0) === 403, 'wrong logged-in account should be forbidden');
    $wrongPayload = videochat_call_access_session_route_guard_decode($wrongAccount);
    videochat_call_access_session_route_guard_assert((string) (($wrongPayload['error'] ?? [])['code'] ?? '') === 'call_access_forbidden', 'wrong account error code mismatch');
    videochat_call_access_session_route_guard_assert(
        (string) (((($wrongPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['auth'] ?? '') === 'not_bound_to_current_user',
        'wrong account route should surface auth mismatch only'
    );
    videochat_call_access_session_route_guard_assert_no_leak($wrongAccount, [$standardEmail, $standardName, 'Route Guard Secret Personal Call', $personalCallId], 'wrong account response');
    $wrongAccountSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_route_guard_wrong_account_should_not_issue'")->fetchColumn();
    videochat_call_access_session_route_guard_assert($wrongAccountSessionRows === 0, 'wrong account route must not persist a session');

    $sessionSwitch = $callSessionRoute(
        $personalAccessId,
        ['Authorization' => 'Bearer sess_route_guard_admin', 'User-Agent' => 'route-guard-session-switch', 'Content-Type' => 'application/json'],
        json_encode([
            'verified_user_id' => $standardUserId,
            'verified_session_id' => 'sess_route_guard_standard',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_route_guard_switch_should_not_issue'
    );
    videochat_call_access_session_route_guard_assert((int) ($sessionSwitch['status'] ?? 0) === 409, 'session switch should conflict');
    $switchPayload = videochat_call_access_session_route_guard_decode($sessionSwitch);
    videochat_call_access_session_route_guard_assert((string) (($switchPayload['error'] ?? [])['code'] ?? '') === 'call_access_conflict', 'session switch error code mismatch');
    videochat_call_access_session_route_guard_assert(
        (string) (((($switchPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['auth'] ?? '') === 'session_context_changed',
        'session switch route should surface context-change mismatch only'
    );
    videochat_call_access_session_route_guard_assert_no_leak($sessionSwitch, [$standardEmail, $standardName, 'Route Guard Secret Personal Call', $personalCallId], 'session switch response');
    $switchRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_route_guard_switch_should_not_issue'")->fetchColumn();
    videochat_call_access_session_route_guard_assert($switchRows === 0, 'session switch route must not persist a session');

    $invalidPresentedSession = $callSessionRoute(
        $personalAccessId,
        ['Authorization' => 'Bearer sess_route_guard_missing', 'User-Agent' => 'route-guard-invalid-auth'],
        '{}',
        'sess_route_guard_invalid_auth_should_not_issue'
    );
    videochat_call_access_session_route_guard_assert((int) ($invalidPresentedSession['status'] ?? 0) === 401, 'invalid presented session should fail before public issuance');
    $invalidRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_route_guard_invalid_auth_should_not_issue'")->fetchColumn();
    videochat_call_access_session_route_guard_assert($invalidRows === 0, 'invalid presented session must not persist a session');

    $createOpenCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Route Guard Open Call',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-09-02T09:00:00Z',
        'ends_at' => '2026-09-02T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_access_session_route_guard_assert((bool) ($createOpenCall['ok'] ?? false), 'open call should be created');
    $openCallId = (string) (($createOpenCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_route_guard_assert($openCallId !== '', 'open call id should be present');
    $openAccess = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ]);
    videochat_call_access_session_route_guard_assert((bool) ($openAccess['ok'] ?? false), 'open access link should be created');
    $openAccessId = (string) (($openAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_route_guard_assert($openAccessId !== '', 'open access id should be present');

    $openLoggedIn = $callSessionRoute(
        $openAccessId,
        ['Authorization' => 'Bearer sess_route_guard_admin', 'User-Agent' => 'route-guard-open', 'Content-Type' => 'application/json'],
        json_encode(['guest_name' => 'Route Guard Guest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_route_guard_open_guest'
    );
    videochat_call_access_session_route_guard_assert((int) ($openLoggedIn['status'] ?? 0) === 200, 'logged-in open link should still issue guest session');
    $openPayload = videochat_call_access_session_route_guard_decode($openLoggedIn);
    $guestUserId = (int) (((($openPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0));
    videochat_call_access_session_route_guard_assert($guestUserId > 0 && $guestUserId !== $adminUserId, 'open link should issue an isolated guest user');
    videochat_call_access_session_route_guard_assert((bool) (((($openPayload['result'] ?? [])['user'] ?? [])['is_guest'] ?? false)) === true, 'open link user should be a guest');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-session-route-guard-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-session-route-guard-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
