<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam_anonymous_disabled_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-anonymous-disabled-link-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_anonymous_disabled_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_iam_anonymous_disabled_create_user(PDO $pdo, int $roleId, int $tenantId, string $email, string $displayName): int
{
    $passwordHash = password_hash('iam-anonymous-disabled-link-contract', PASSWORD_DEFAULT);
    videochat_iam_anonymous_disabled_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be available');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_tenant_attach_user($pdo, $userId, $tenantId);

    return $userId;
}

function videochat_iam_anonymous_disabled_create_open_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, string $accessMode = 'free_for_all'): string
{
    $call = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_anonymous_disabled_assert((bool) ($call['ok'] ?? false), "call {$title} should be created");
    $callId = (string) (($call['call'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_disabled_assert($callId !== '', "call {$title} id should be non-empty");

    return $callId;
}

function videochat_iam_anonymous_disabled_create_open_link(PDO $pdo, string $callId, int $ownerUserId, int $tenantId): string
{
    $link = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'user', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_iam_anonymous_disabled_assert((bool) ($link['ok'] ?? false), 'open anonymous link should be created');
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_anonymous_disabled_assert($accessId !== '', 'open access id should be non-empty');

    return $accessId;
}

function videochat_iam_anonymous_disabled_guest_user_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
}

function videochat_iam_anonymous_disabled_guest_participant_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
JOIN users ON users.id = call_participants.user_id
WHERE call_participants.call_id = :call_id
  AND users.email LIKE 'guest+%@videochat.local'
SQL
    );
    $query->execute([':call_id' => $callId]);

    return (int) $query->fetchColumn();
}

function videochat_iam_anonymous_disabled_access_session_count(PDO $pdo, string $accessId): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM call_access_sessions WHERE access_id = :access_id');
    $query->execute([':access_id' => $accessId]);

    return (int) $query->fetchColumn();
}

function videochat_iam_anonymous_disabled_issue_guest_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    string $guestName
): int {
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-disabled-link-contract'],
        ['guest_name' => $guestName]
    );
    videochat_iam_anonymous_disabled_assert((bool) ($issued['ok'] ?? false), "{$guestName} should issue from anonymous link");
    videochat_iam_anonymous_disabled_assert((string) (($issued['session'] ?? [])['id'] ?? '') === $sessionId, "{$guestName} session id mismatch");
    $userId = (int) ((($issued['user'] ?? [])['id'] ?? 0));
    videochat_iam_anonymous_disabled_assert($userId > 0, "{$guestName} should bind to a temporary user");

    return $userId;
}

function videochat_iam_anonymous_disabled_assert_session_room(
    PDO $pdo,
    string $callId,
    string $sessionId,
    string $expectedInitialRoomId,
    string $label
): void {
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_anonymous_disabled_assert((bool) ($auth['ok'] ?? false), "{$label} should authenticate before disable");
    $resolution = videochat_realtime_resolve_connection_rooms(
        $auth,
        $callId,
        static fn (): PDO => $pdo,
        $callId
    );
    videochat_iam_anonymous_disabled_assert((string) ($resolution['initial_room_id'] ?? '') === $expectedInitialRoomId, "{$label} initial room mismatch");
    if ($expectedInitialRoomId === videochat_realtime_waiting_room_id()) {
        videochat_iam_anonymous_disabled_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$label} pending room should stay call-bound");
    } else {
        videochat_iam_anonymous_disabled_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$label} should not keep a pending room");
    }
}

/**
 * @param array<int, string> $sessionIds
 */
function videochat_iam_anonymous_disabled_assert_sessions_invalidated(PDO $pdo, array $sessionIds, string $label): void
{
    foreach ($sessionIds as $sessionId) {
        $validation = videochat_validate_session_token($pdo, $sessionId);
        videochat_iam_anonymous_disabled_assert(!(bool) ($validation['ok'] ?? true), "{$label}: session {$sessionId} must fail after anonymous link disable");
        videochat_iam_anonymous_disabled_assert((string) ($validation['reason'] ?? '') === 'call_access_link_invalidated', "{$label}: session {$sessionId} reason mismatch");
        videochat_iam_anonymous_disabled_assert(videochat_fetch_call_access_session_binding($pdo, $sessionId) === null, "{$label}: binding {$sessionId} must not resolve after disable");

        $auth = videochat_authenticate_request(
            $pdo,
            [
                'method' => 'GET',
                'uri' => '/ws?session=' . rawurlencode($sessionId),
                'headers' => ['Authorization' => 'Bearer ' . $sessionId],
            ],
            'websocket'
        );
        videochat_iam_anonymous_disabled_assert(!(bool) ($auth['ok'] ?? true), "{$label}: websocket auth {$sessionId} must fail after disable");
        videochat_iam_anonymous_disabled_assert((string) ($auth['reason'] ?? '') === 'call_access_link_invalidated', "{$label}: websocket auth {$sessionId} reason mismatch");
    }
}

function videochat_iam_anonymous_disabled_mark_guest_joined(PDO $pdo, string $callId, int $userId): void
{
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed',
    joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $update->execute([
        ':joined_at' => gmdate('c'),
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    videochat_iam_anonymous_disabled_assert($update->rowCount() > 0, 'anonymous guest participant should be marked as joined');
}

function videochat_iam_anonymous_disabled_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam_anonymous_disabled_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam_anonymous_disabled_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam_anonymous_disabled_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [null, 'invalid_json'];
    }

    return [$decoded, null];
}

function videochat_iam_anonymous_disabled_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_iam_anonymous_disabled_assert_omits(string $body, array $needles, string $label): void
{
    $lowerBody = strtolower($body);
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam_anonymous_disabled_assert(!str_contains($lowerBody, $normalized), "{$label} leaked {$needle}");
    }
}

function videochat_iam_anonymous_disabled_http_call(
    PDO $pdo,
    string $path,
    string $method,
    array $request,
    ?callable $issueSessionId = null
): array {
    $response = videochat_handle_call_routes(
        $path,
        $method,
        $request,
        [],
        'videochat_iam_anonymous_disabled_json_response',
        'videochat_iam_anonymous_disabled_error_response',
        'videochat_iam_anonymous_disabled_decode_json_body',
        static fn (): PDO => $pdo,
        $issueSessionId
    );
    videochat_iam_anonymous_disabled_assert(is_array($response), "{$method} {$path} should return an array response");

    return $response;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-anonymous-disabled-link-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-anonymous-disabled-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_iam_anonymous_disabled_assert(
        videochat_tenant_table_has_column($pdo, 'call_access_links', 'disabled_at'),
        'call_access_links.disabled_at should be migrated'
    );

    $userRoleId = videochat_iam_anonymous_disabled_role_id($pdo, 'user');
    videochat_iam_anonymous_disabled_assert($userRoleId > 0, 'expected user role');
    $unique = bin2hex(random_bytes(5));
    $now = gmdate('c');

    $tenantInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $tenantInsert->execute([
        ':public_id' => 'tenant-iam-anon-disabled-' . $unique,
        ':slug' => 'iam-anon-disabled-' . $unique,
        ':label' => 'IAM Anonymous Disabled ' . $unique,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $tenantId = (int) $pdo->lastInsertId();
    videochat_iam_anonymous_disabled_assert($tenantId > 0, 'tenant should be created');

    $ownerUserId = videochat_iam_anonymous_disabled_create_user(
        $pdo,
        $userRoleId,
        $tenantId,
        'iam-anon-disabled-owner-' . $unique . '@example.test',
        'IAM Anonymous Disabled Owner'
    );

    $callId = videochat_iam_anonymous_disabled_create_open_call($pdo, $ownerUserId, $tenantId, 'Disabled Anonymous Link Primary Secret');
    $foreignCallId = videochat_iam_anonymous_disabled_create_open_call($pdo, $ownerUserId, $tenantId, 'Disabled Anonymous Link Foreign Secret');
    $accessId = videochat_iam_anonymous_disabled_create_open_link($pdo, $callId, $ownerUserId, $tenantId);

    $forgedBodyIssuerCalls = 0;
    $forgedBodySessionId = 'sess_iam_anon_disabled_forged_body_should_not_issue';
    $forgedBodyResponse = videochat_iam_anonymous_disabled_http_call(
        $pdo,
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => ['User-Agent' => 'call-access-anonymous-disabled-link-contract'],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'guest_name' => 'Forged Call Body Guest',
                'call_id' => $foreignCallId,
            ], JSON_UNESCAPED_SLASHES),
        ],
        static function () use (&$forgedBodyIssuerCalls, $forgedBodySessionId): string {
            $forgedBodyIssuerCalls += 1;
            return $forgedBodySessionId;
        }
    );
    videochat_iam_anonymous_disabled_assert((int) ($forgedBodyResponse['status'] ?? 0) === 422, 'forged call_id body should be rejected as client authority');
    $forgedBodyPayload = videochat_iam_anonymous_disabled_decode($forgedBodyResponse);
    videochat_iam_anonymous_disabled_assert((string) (($forgedBodyPayload['error'] ?? [])['code'] ?? '') === 'call_access_validation_failed', 'forged call_id body error code mismatch');
    videochat_iam_anonymous_disabled_assert($forgedBodyIssuerCalls === 0, 'forged call_id body must not call session issuer');
    $forgedBinding = videochat_fetch_call_access_session_binding($pdo, $forgedBodySessionId);
    videochat_iam_anonymous_disabled_assert(!is_array($forgedBinding), 'forged body must not persist a session binding');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_participant_count($pdo, $foreignCallId) === 0, 'forged call_id body must not create a foreign-call lobby participant');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_participant_count($pdo, $callId) === 0, 'forged call_id body must not create an original-call lobby participant');

    $missingAccessId = '90000000-0000-4000-8000-000000000001';
    $missingIssuerCalls = 0;
    $missingSession = videochat_issue_session_for_call_access(
        $pdo,
        $missingAccessId,
        static function () use (&$missingIssuerCalls): string {
            $missingIssuerCalls += 1;
            return 'sess_iam_anon_disabled_missing_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-disabled-link-contract'],
        [
            'authenticated_user_id' => $ownerUserId,
            'authenticated_session_id' => 'sess_iam_anon_disabled_logged_in_missing',
            'verified_user_id' => $ownerUserId,
            'verified_session_id' => 'sess_iam_anon_disabled_logged_in_missing',
            'guest_name' => 'Ignored Missing Link Guest',
        ]
    );
    videochat_iam_anonymous_disabled_assert(!(bool) ($missingSession['ok'] ?? true), 'manipulated logged-in anonymous link id must not issue a session');
    videochat_iam_anonymous_disabled_assert((string) ($missingSession['reason'] ?? '') === 'not_found', 'manipulated anonymous link reason mismatch');
    videochat_iam_anonymous_disabled_assert($missingIssuerCalls === 0, 'manipulated logged-in anonymous link must not call session issuer');

    $disabledCallId = videochat_iam_anonymous_disabled_create_open_call($pdo, $ownerUserId, $tenantId, 'Disabled Anonymous Link No Lobby Secret');
    $disabledAccessId = videochat_iam_anonymous_disabled_create_open_link($pdo, $disabledCallId, $ownerUserId, $tenantId);
    $disabledPrivateNeedles = [
        'Disabled Anonymous Link No Lobby Secret',
        $disabledCallId,
        'IAM Anonymous Disabled Owner',
        'iam-anon-disabled-owner-' . $unique . '@example.test',
    ];

    $preDisableResolve = videochat_resolve_call_access_public($pdo, $disabledAccessId);
    videochat_iam_anonymous_disabled_assert((bool) ($preDisableResolve['ok'] ?? false), 'anonymous link should resolve before disable');
    $disable = videochat_disable_anonymous_call_access_link($pdo, $disabledAccessId, $ownerUserId, [
        'invalidation_reason' => 'contract_manual_anonymous_link_disable',
    ]);
    videochat_iam_anonymous_disabled_assert((bool) ($disable['ok'] ?? false), 'anonymous link disable should succeed');
    videochat_iam_anonymous_disabled_assert((string) ($disable['reason'] ?? '') === 'disabled', 'anonymous link disable reason mismatch');
    videochat_iam_anonymous_disabled_assert(is_array($disable['audit_event'] ?? null), 'anonymous link disable should be audit-logged');

    $disabledLink = videochat_fetch_call_access_link($pdo, $disabledAccessId, $tenantId);
    videochat_iam_anonymous_disabled_assert(is_array($disabledLink), 'disabled anonymous link row should stay persisted');
    videochat_iam_anonymous_disabled_assert(videochat_call_access_link_is_disabled($disabledLink), 'disabled anonymous link should expose disabled state');
    videochat_iam_anonymous_disabled_assert(videochat_call_access_link_is_invalidated($pdo, $disabledLink), 'disabled anonymous link should be rejected by invalidation predicate');

    $repeatDisable = videochat_disable_anonymous_call_access_link($pdo, $disabledAccessId, $ownerUserId, [
        'invalidation_reason' => 'contract_manual_anonymous_link_disable_repeat',
    ]);
    videochat_iam_anonymous_disabled_assert((bool) ($repeatDisable['ok'] ?? false), 'repeat anonymous link disable should be idempotent');
    videochat_iam_anonymous_disabled_assert((string) ($repeatDisable['reason'] ?? '') === 'already_disabled', 'repeat anonymous link disable reason mismatch');

    $guestCountBeforeDisabledAttempt = videochat_iam_anonymous_disabled_guest_user_count($pdo);
    $disabledResolve = videochat_resolve_call_access_public($pdo, $disabledAccessId);
    videochat_iam_anonymous_disabled_assert(!(bool) ($disabledResolve['ok'] ?? true), 'disabled anonymous link must not resolve');
    videochat_iam_anonymous_disabled_assert((string) ($disabledResolve['reason'] ?? '') === 'not_found', 'disabled anonymous link resolve reason mismatch');
    videochat_iam_anonymous_disabled_assert(($disabledResolve['access_link'] ?? null) === null, 'disabled resolve must not expose access link');
    videochat_iam_anonymous_disabled_assert(($disabledResolve['call'] ?? null) === null, 'disabled resolve must not expose call');
    videochat_iam_anonymous_disabled_assert(($disabledResolve['target_user'] ?? null) === null, 'disabled resolve must not expose target user');

    $disabledIssuerCalls = 0;
    $disabledSession = videochat_issue_session_for_call_access(
        $pdo,
        $disabledAccessId,
        static function () use (&$disabledIssuerCalls): string {
            $disabledIssuerCalls += 1;
            return 'sess_iam_anon_disabled_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-disabled-link-contract'],
        ['guest_name' => 'Disabled Anonymous Guest']
    );
    videochat_iam_anonymous_disabled_assert(!(bool) ($disabledSession['ok'] ?? true), 'disabled anonymous link must not issue a session');
    videochat_iam_anonymous_disabled_assert((string) ($disabledSession['reason'] ?? '') === 'not_found', 'disabled anonymous link session reason mismatch');
    videochat_iam_anonymous_disabled_assert($disabledIssuerCalls === 0, 'disabled anonymous link must not call session issuer');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_user_count($pdo) === $guestCountBeforeDisabledAttempt, 'disabled anonymous link must not create a temporary guest');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_access_session_count($pdo, $disabledAccessId) === 0, 'disabled anonymous link must not persist a call-access session');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_participant_count($pdo, $disabledCallId) === 0, 'disabled anonymous link must not create a lobby participant');

    $joinResponse = videochat_iam_anonymous_disabled_http_call(
        $pdo,
        '/api/call-access/' . $disabledAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $disabledAccessId . '/join', 'headers' => []]
    );
    videochat_iam_anonymous_disabled_assert((int) ($joinResponse['status'] ?? 0) === 404, 'disabled anonymous HTTP join should return 404');
    videochat_iam_anonymous_disabled_assert((string) ((videochat_iam_anonymous_disabled_decode($joinResponse)['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'disabled anonymous HTTP join code mismatch');
    videochat_iam_anonymous_disabled_assert_omits((string) ($joinResponse['body'] ?? ''), $disabledPrivateNeedles, 'disabled anonymous join response');

    $httpDisabledIssuerCalls = 0;
    $sessionResponse = videochat_iam_anonymous_disabled_http_call(
        $pdo,
        '/api/call-access/' . $disabledAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $disabledAccessId . '/session',
            'headers' => ['User-Agent' => 'call-access-anonymous-disabled-link-contract'],
            'remote_address' => '127.0.0.1',
            'body' => json_encode(['guest_name' => 'Disabled Anonymous Guest'], JSON_UNESCAPED_SLASHES),
        ],
        static function () use (&$httpDisabledIssuerCalls): string {
            $httpDisabledIssuerCalls += 1;
            return 'sess_iam_anon_disabled_http_should_not_issue';
        }
    );
    videochat_iam_anonymous_disabled_assert((int) ($sessionResponse['status'] ?? 0) === 404, 'disabled anonymous HTTP session should return 404');
    videochat_iam_anonymous_disabled_assert($httpDisabledIssuerCalls === 0, 'disabled anonymous HTTP session must not call issuer');
    videochat_iam_anonymous_disabled_assert((string) ((videochat_iam_anonymous_disabled_decode($sessionResponse)['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'disabled anonymous HTTP session code mismatch');
    videochat_iam_anonymous_disabled_assert_omits((string) ($sessionResponse['body'] ?? ''), $disabledPrivateNeedles, 'disabled anonymous session response');

    $lobbyCallId = videochat_iam_anonymous_disabled_create_open_call($pdo, $ownerUserId, $tenantId, 'Disabled Anonymous Link Lobby Active Secret');
    $lobbyAccessId = videochat_iam_anonymous_disabled_create_open_link($pdo, $lobbyCallId, $ownerUserId, $tenantId);
    $lobbySessionA = 'sess_iam_anon_disabled_lobby_a';
    $lobbySessionB = 'sess_iam_anon_disabled_lobby_b';
    videochat_iam_anonymous_disabled_issue_guest_session($pdo, $lobbyAccessId, $lobbySessionA, 'Disabled Lobby Guest A');
    videochat_iam_anonymous_disabled_issue_guest_session($pdo, $lobbyAccessId, $lobbySessionB, 'Disabled Lobby Guest B');
    videochat_iam_anonymous_disabled_assert_session_room($pdo, $lobbyCallId, $lobbySessionA, videochat_realtime_waiting_room_id(), 'anonymous lobby browser A');
    videochat_iam_anonymous_disabled_assert_session_room($pdo, $lobbyCallId, $lobbySessionB, videochat_realtime_waiting_room_id(), 'anonymous lobby browser B');
    $lobbyDisable = videochat_disable_anonymous_call_access_link($pdo, $lobbyAccessId, $ownerUserId, [
        'invalidation_reason' => 'contract_anonymous_link_disable_while_lobby',
    ]);
    videochat_iam_anonymous_disabled_assert((bool) ($lobbyDisable['ok'] ?? false), 'anonymous link disable while lobby should succeed');
    videochat_iam_anonymous_disabled_assert((int) ($lobbyDisable['access_session_count'] ?? 0) === 2, 'anonymous lobby disable should count both browser sessions');
    videochat_iam_anonymous_disabled_assert_sessions_invalidated($pdo, [$lobbySessionA, $lobbySessionB], 'anonymous lobby disable');
    $lobbyFreshIssuerCalls = 0;
    $lobbyGuestCount = videochat_iam_anonymous_disabled_guest_user_count($pdo);
    $lobbyFresh = videochat_issue_session_for_call_access(
        $pdo,
        $lobbyAccessId,
        static function () use (&$lobbyFreshIssuerCalls): string {
            $lobbyFreshIssuerCalls += 1;
            return 'sess_iam_anon_disabled_lobby_fresh_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-disabled-link-contract'],
        ['guest_name' => 'Disabled Lobby Fresh Guest']
    );
    videochat_iam_anonymous_disabled_assert(!(bool) ($lobbyFresh['ok'] ?? true), 'disabled lobby anonymous link must not issue a fresh session');
    videochat_iam_anonymous_disabled_assert((string) ($lobbyFresh['reason'] ?? '') === 'not_found', 'disabled lobby anonymous fresh reason mismatch');
    videochat_iam_anonymous_disabled_assert($lobbyFreshIssuerCalls === 0, 'disabled lobby anonymous link must not call fresh issuer');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_user_count($pdo) === $lobbyGuestCount, 'disabled lobby anonymous link must not recreate a temporary guest');

    $activeCallId = videochat_iam_anonymous_disabled_create_open_call($pdo, $ownerUserId, $tenantId, 'Disabled Anonymous Link In Call Secret');
    $activeAccessId = videochat_iam_anonymous_disabled_create_open_link($pdo, $activeCallId, $ownerUserId, $tenantId);
    $activeSessionA = 'sess_iam_anon_disabled_active_a';
    $activeSessionB = 'sess_iam_anon_disabled_active_b';
    $activeUserA = videochat_iam_anonymous_disabled_issue_guest_session($pdo, $activeAccessId, $activeSessionA, 'Disabled Active Guest A');
    $activeUserB = videochat_iam_anonymous_disabled_issue_guest_session($pdo, $activeAccessId, $activeSessionB, 'Disabled Active Guest B');
    videochat_iam_anonymous_disabled_mark_guest_joined($pdo, $activeCallId, $activeUserA);
    videochat_iam_anonymous_disabled_mark_guest_joined($pdo, $activeCallId, $activeUserB);
    videochat_iam_anonymous_disabled_assert_session_room($pdo, $activeCallId, $activeSessionA, $activeCallId, 'anonymous active-call browser A');
    videochat_iam_anonymous_disabled_assert_session_room($pdo, $activeCallId, $activeSessionB, $activeCallId, 'anonymous active-call browser B');
    $activeDisable = videochat_disable_anonymous_call_access_link($pdo, $activeAccessId, $ownerUserId, [
        'invalidation_reason' => 'contract_anonymous_link_disable_while_in_call',
    ]);
    videochat_iam_anonymous_disabled_assert((bool) ($activeDisable['ok'] ?? false), 'anonymous link disable while in call should succeed');
    videochat_iam_anonymous_disabled_assert((int) ($activeDisable['access_session_count'] ?? 0) === 2, 'anonymous active-call disable should count both browser sessions');
    videochat_iam_anonymous_disabled_assert_sessions_invalidated($pdo, [$activeSessionA, $activeSessionB], 'anonymous active-call disable');
    $activeFreshIssuerCalls = 0;
    $activeGuestCount = videochat_iam_anonymous_disabled_guest_user_count($pdo);
    $activeFresh = videochat_issue_session_for_call_access(
        $pdo,
        $activeAccessId,
        static function () use (&$activeFreshIssuerCalls): string {
            $activeFreshIssuerCalls += 1;
            return 'sess_iam_anon_disabled_active_fresh_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-anonymous-disabled-link-contract'],
        ['guest_name' => 'Disabled Active Fresh Guest']
    );
    videochat_iam_anonymous_disabled_assert(!(bool) ($activeFresh['ok'] ?? true), 'disabled active-call anonymous link must not issue a fresh session');
    videochat_iam_anonymous_disabled_assert((string) ($activeFresh['reason'] ?? '') === 'not_found', 'disabled active-call anonymous fresh reason mismatch');
    videochat_iam_anonymous_disabled_assert($activeFreshIssuerCalls === 0, 'disabled active-call anonymous link must not call fresh issuer');
    videochat_iam_anonymous_disabled_assert(videochat_iam_anonymous_disabled_guest_user_count($pdo) === $activeGuestCount, 'disabled active-call anonymous link must not recreate a temporary guest');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-anonymous-disabled-link-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-anonymous-disabled-link-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
