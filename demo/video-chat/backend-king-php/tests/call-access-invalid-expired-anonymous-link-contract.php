<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_iam_invalid_expired_anon_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-invalid-expired-anonymous-link-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_invalid_expired_anon_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_iam_invalid_expired_anon_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam_invalid_expired_anon_error_response(
    int $status,
    string $code,
    string $message,
    array $details = []
): array {
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam_invalid_expired_anon_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam_invalid_expired_anon_decode_body(array $request): array
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

function videochat_iam_invalid_expired_anon_count(PDO $pdo, string $sql, array $params = []): int
{
    $query = $pdo->prepare($sql);
    $query->execute($params);

    return (int) $query->fetchColumn();
}

function videochat_iam_invalid_expired_anon_guest_count(PDO $pdo): int
{
    return videochat_iam_invalid_expired_anon_count(
        $pdo,
        "SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'"
    );
}

function videochat_iam_invalid_expired_anon_session_count(PDO $pdo, string $sessionId): int
{
    return videochat_iam_invalid_expired_anon_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :id',
        [':id' => $sessionId]
    );
}

function videochat_iam_invalid_expired_anon_binding_count(PDO $pdo, string $accessId): int
{
    return videochat_iam_invalid_expired_anon_count(
        $pdo,
        'SELECT COUNT(*) FROM call_access_sessions WHERE access_id = :access_id',
        [':access_id' => $accessId]
    );
}

function videochat_iam_invalid_expired_anon_participant_count(PDO $pdo, string $callId): int
{
    return videochat_iam_invalid_expired_anon_count(
        $pdo,
        'SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_iam_invalid_expired_anon_audit_count(PDO $pdo): int
{
    return videochat_iam_invalid_expired_anon_count($pdo, 'SELECT COUNT(*) FROM videochat_audit_events');
}

function videochat_iam_invalid_expired_anon_assert_no_needles(string $body, array $needles, string $label): void
{
    $lowerBody = strtolower($body);
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam_invalid_expired_anon_assert(
            !str_contains($lowerBody, $normalized),
            "{$label} leaked {$needle}"
        );
    }
}

function videochat_iam_invalid_expired_anon_assert_domain_failure(
    array $result,
    string $expectedReason,
    string $label
): void {
    videochat_iam_invalid_expired_anon_assert(!(bool) ($result['ok'] ?? true), "{$label} should fail");
    videochat_iam_invalid_expired_anon_assert(
        (string) ($result['reason'] ?? '') === $expectedReason,
        "{$label} reason mismatch"
    );
    videochat_iam_invalid_expired_anon_assert(($result['access_link'] ?? null) === null, "{$label} must redact access link");
    videochat_iam_invalid_expired_anon_assert(($result['call'] ?? null) === null, "{$label} must redact call");
    videochat_iam_invalid_expired_anon_assert(($result['target_user'] ?? null) === null, "{$label} must redact target user");
    videochat_iam_invalid_expired_anon_assert(
        (($result['target_hint'] ?? [])['participant_email'] ?? null) === null,
        "{$label} must redact participant email hint"
    );
}

function videochat_iam_invalid_expired_anon_assert_session_failure(
    array $result,
    string $expectedReason,
    string $label
): void {
    videochat_iam_invalid_expired_anon_assert(!(bool) ($result['ok'] ?? true), "{$label} should fail");
    videochat_iam_invalid_expired_anon_assert(
        (string) ($result['reason'] ?? '') === $expectedReason,
        "{$label} reason mismatch"
    );
    foreach (['session', 'user', 'access_link', 'call'] as $key) {
        videochat_iam_invalid_expired_anon_assert(($result[$key] ?? null) === null, "{$label} must redact {$key}");
    }
}

function videochat_iam_invalid_expired_anon_assert_http_error(
    array $response,
    int $expectedStatus,
    string $expectedCode,
    array $needles,
    string $label
): void {
    videochat_iam_invalid_expired_anon_assert((int) ($response['status'] ?? 0) === $expectedStatus, "{$label} status mismatch");
    $payload = videochat_iam_invalid_expired_anon_decode($response);
    videochat_iam_invalid_expired_anon_assert(
        (string) (($payload['error'] ?? [])['code'] ?? '') === $expectedCode,
        "{$label} code mismatch"
    );
    videochat_iam_invalid_expired_anon_assert(!isset($payload['result']), "{$label} must not expose result envelope");
    foreach (['access_link', 'call', 'target_user', 'target_hint', 'session', 'user'] as $key) {
        videochat_iam_invalid_expired_anon_assert(!array_key_exists($key, $payload), "{$label} must not expose {$key}");
    }
    videochat_iam_invalid_expired_anon_assert_no_needles((string) ($response['body'] ?? ''), $needles, $label);
}

function videochat_iam_invalid_expired_anon_route(
    string $databasePath,
    string $accessId,
    string $suffix,
    string $method,
    array $headers = [],
    string $body = '',
    ?callable $issuer = null
): array {
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
        'videochat_iam_invalid_expired_anon_json_response',
        'videochat_iam_invalid_expired_anon_error_response',
        'videochat_iam_invalid_expired_anon_decode_body',
        static fn (): PDO => videochat_open_sqlite_pdo($databasePath),
        $issuer
    );
    videochat_iam_invalid_expired_anon_assert(is_array($response), "{$method} {$path} should return response");

    return $response;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-invalid-expired-anonymous-link-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-invalid-expired-anon-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam_invalid_expired_anon_assert($adminUserId > 0, 'expected seeded admin user');

    $secret = 'invalid_expired_anon_' . bin2hex(random_bytes(5));
    $callTitle = 'Invalid Expired Anonymous Link Secret ' . $secret;
    $expiredGuestName = 'Expired Anonymous Guest ' . $secret;
    $missingGuestName = 'Missing Anonymous Guest ' . $secret;
    $invalidAccessId = 'not-an-anonymous-link-' . $secret;
    $missingAccessId = '90000000-0000-4000-8000-000000000015';
    $expiredSessionId = 'sess_invalid_expired_anon_should_not_issue';
    $missingSessionId = 'sess_invalid_expired_anon_missing_should_not_issue';

    $created = videochat_create_call($pdo, $adminUserId, [
        'title' => $callTitle,
        'access_mode' => 'free_for_all',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_iam_invalid_expired_anon_assert((bool) ($created['ok'] ?? false), 'free-for-all call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_iam_invalid_expired_anon_assert($callId !== '', 'call id should be present');

    $link = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ]);
    videochat_iam_invalid_expired_anon_assert((bool) ($link['ok'] ?? false), 'anonymous open link should be created');
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_invalid_expired_anon_assert($accessId !== '', 'anonymous access id should be present');
    $accessLink = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_iam_invalid_expired_anon_assert(is_array($accessLink), 'anonymous access link should fetch');
    videochat_iam_invalid_expired_anon_assert(videochat_call_access_link_kind($accessLink) === 'open', 'link must be open/anonymous');

    $lastUsedBeforeExpiry = (string) ($accessLink['last_used_at'] ?? '');
    $pdo->prepare('UPDATE call_access_links SET expires_at = :expires_at WHERE id = :id')
        ->execute([':id' => $accessId, ':expires_at' => gmdate('c', time() - 60)]);

    $privateNeedles = [
        $callTitle,
        $callId,
        $accessId,
        $missingAccessId,
        $invalidAccessId,
        $expiredGuestName,
        $missingGuestName,
        'admin@intelligent-intern.com',
        $expiredSessionId,
        $missingSessionId,
    ];

    $guestCountBefore = videochat_iam_invalid_expired_anon_guest_count($pdo);
    $participantCountBefore = videochat_iam_invalid_expired_anon_participant_count($pdo, $callId);
    $auditCountBefore = videochat_iam_invalid_expired_anon_audit_count($pdo);

    $invalidResolve = videochat_resolve_call_access_public($pdo, $invalidAccessId);
    videochat_iam_invalid_expired_anon_assert_domain_failure($invalidResolve, 'validation_failed', 'invalid anonymous domain resolve');
    videochat_iam_invalid_expired_anon_assert_no_needles(json_encode($invalidResolve, JSON_UNESCAPED_SLASHES), $privateNeedles, 'invalid anonymous domain resolve');

    $invalidIssuerCalls = 0;
    $invalidSession = videochat_issue_session_for_call_access(
        $pdo,
        $invalidAccessId,
        static function () use (&$invalidIssuerCalls): string {
            $invalidIssuerCalls += 1;
            return 'sess_invalid_expired_anon_invalid_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalid-expired-anonymous-link-contract'],
        ['guest_name' => 'Invalid Anonymous Guest']
    );
    videochat_iam_invalid_expired_anon_assert_session_failure($invalidSession, 'validation_failed', 'invalid anonymous session issue');
    videochat_iam_invalid_expired_anon_assert($invalidIssuerCalls === 0, 'invalid anonymous session issuer must not run');

    $missingResolve = videochat_resolve_call_access_public($pdo, $missingAccessId);
    videochat_iam_invalid_expired_anon_assert_domain_failure($missingResolve, 'not_found', 'missing anonymous domain resolve');
    videochat_iam_invalid_expired_anon_assert_no_needles(json_encode($missingResolve, JSON_UNESCAPED_SLASHES), $privateNeedles, 'missing anonymous domain resolve');

    $missingIssuerCalls = 0;
    $missingSession = videochat_issue_session_for_call_access(
        $pdo,
        $missingAccessId,
        static function () use (&$missingIssuerCalls, $missingSessionId): string {
            $missingIssuerCalls += 1;
            return $missingSessionId;
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalid-expired-anonymous-link-contract'],
        ['guest_name' => $missingGuestName]
    );
    videochat_iam_invalid_expired_anon_assert_session_failure($missingSession, 'not_found', 'missing anonymous session issue');
    videochat_iam_invalid_expired_anon_assert($missingIssuerCalls === 0, 'missing anonymous session issuer must not run');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_session_count($pdo, $missingSessionId) === 0, 'missing anonymous session must not persist');

    $missingJoin = videochat_iam_invalid_expired_anon_route($databasePath, $missingAccessId, '/join', 'GET');
    videochat_iam_invalid_expired_anon_assert_http_error($missingJoin, 404, 'call_access_not_found', $privateNeedles, 'missing anonymous HTTP join');

    $httpMissingIssuerCalls = 0;
    $missingHttpSession = videochat_iam_invalid_expired_anon_route(
        $databasePath,
        $missingAccessId,
        '/session',
        'POST',
        ['User-Agent' => 'call-access-invalid-expired-anonymous-link-contract'],
        json_encode(['guest_name' => $missingGuestName], JSON_UNESCAPED_SLASHES),
        static function () use (&$httpMissingIssuerCalls, $missingSessionId): string {
            $httpMissingIssuerCalls += 1;
            return $missingSessionId;
        }
    );
    videochat_iam_invalid_expired_anon_assert_http_error($missingHttpSession, 404, 'call_access_not_found', $privateNeedles, 'missing anonymous HTTP session');
    videochat_iam_invalid_expired_anon_assert($httpMissingIssuerCalls === 0, 'missing anonymous HTTP issuer must not run');

    $expiredResolve = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam_invalid_expired_anon_assert_domain_failure($expiredResolve, 'expired', 'expired anonymous domain resolve');
    videochat_iam_invalid_expired_anon_assert_no_needles(json_encode($expiredResolve, JSON_UNESCAPED_SLASHES), $privateNeedles, 'expired anonymous domain resolve');

    $expiredIssuerCalls = 0;
    $expiredSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static function () use (&$expiredIssuerCalls, $expiredSessionId): string {
            $expiredIssuerCalls += 1;
            return $expiredSessionId;
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalid-expired-anonymous-link-contract'],
        ['guest_name' => $expiredGuestName]
    );
    videochat_iam_invalid_expired_anon_assert_session_failure($expiredSession, 'expired', 'expired anonymous session issue');
    videochat_iam_invalid_expired_anon_assert($expiredIssuerCalls === 0, 'expired anonymous session issuer must not run');

    $expiredJoin = videochat_iam_invalid_expired_anon_route($databasePath, $accessId, '/join', 'GET');
    videochat_iam_invalid_expired_anon_assert_http_error($expiredJoin, 410, 'call_access_expired', $privateNeedles, 'expired anonymous HTTP join');

    $httpExpiredIssuerCalls = 0;
    $expiredHttpSession = videochat_iam_invalid_expired_anon_route(
        $databasePath,
        $accessId,
        '/session',
        'POST',
        ['User-Agent' => 'call-access-invalid-expired-anonymous-link-contract'],
        json_encode(['guest_name' => $expiredGuestName], JSON_UNESCAPED_SLASHES),
        static function () use (&$httpExpiredIssuerCalls, $expiredSessionId): string {
            $httpExpiredIssuerCalls += 1;
            return $expiredSessionId;
        }
    );
    videochat_iam_invalid_expired_anon_assert_http_error($expiredHttpSession, 410, 'call_access_expired', $privateNeedles, 'expired anonymous HTTP session');
    videochat_iam_invalid_expired_anon_assert($httpExpiredIssuerCalls === 0, 'expired anonymous HTTP issuer must not run');

    $expiredLinkAfterAttempts = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_iam_invalid_expired_anon_assert(is_array($expiredLinkAfterAttempts), 'expired anonymous link row should stay persisted');
    videochat_iam_invalid_expired_anon_assert(
        (string) ($expiredLinkAfterAttempts['last_used_at'] ?? '') === $lastUsedBeforeExpiry,
        'expired anonymous attempts must not touch last_used_at'
    );
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_guest_count($pdo) === $guestCountBefore, 'invalid/expired anonymous links must not create guests');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_participant_count($pdo, $callId) === $participantCountBefore, 'invalid/expired anonymous links must not create lobby or participant rows');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_binding_count($pdo, $accessId) === 0, 'expired anonymous link must not persist call-access sessions');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_binding_count($pdo, $missingAccessId) === 0, 'missing anonymous link must not persist call-access sessions');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_session_count($pdo, $expiredSessionId) === 0, 'expired anonymous link must not persist auth sessions');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_session_count($pdo, $missingSessionId) === 0, 'missing anonymous link must not persist auth sessions');
    videochat_iam_invalid_expired_anon_assert(videochat_iam_invalid_expired_anon_audit_count($pdo) === $auditCountBefore, 'invalid/expired anonymous attempts must not add audit events with raw reasoning');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-invalid-expired-anonymous-link-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-invalid-expired-anonymous-link-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
