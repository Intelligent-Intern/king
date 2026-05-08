<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_invalidation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-invalidation-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_invalidation_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-invalidation-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-invalidation-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $invitedUser = $pdo->query("SELECT id, email, display_name FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetch();
    videochat_call_access_invalidation_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_invalidation_assert(is_array($invitedUser), 'expected seeded invited user');
    $invitedUserId = (int) ($invitedUser['id'] ?? 0);
    $invitedEmail = (string) ($invitedUser['email'] ?? '');
    $invitedDisplayName = (string) ($invitedUser['display_name'] ?? '');
    videochat_call_access_invalidation_assert($invitedUserId > 0, 'expected invited user id');
    videochat_call_access_invalidation_assert($invitedEmail !== '', 'expected invited user email');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Invalidation Secret Title',
        'starts_at' => '2026-09-05T09:00:00Z',
        'ends_at' => '2026-09-05T10:00:00Z',
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_invalidation_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ]);
    videochat_call_access_invalidation_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($accessId !== '', 'personal access id should be present');

    $initialResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_invalidation_assert((bool) ($initialResolution['ok'] ?? false), 'personal link should resolve before invalidation');
    videochat_call_access_invalidation_assert((int) (($initialResolution['target_user'] ?? [])['id'] ?? 0) === $invitedUserId, 'pre-invalidation target user mismatch');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $invitedUserId,
    ]);

    $invalidatedLink = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_call_access_invalidation_assert(is_array($invalidatedLink), 'invalidated access link row should remain persisted');
    videochat_call_access_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'domain should classify cancelled participant invite as invalidated');

    $invalidatedResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_invalidation_assert(!(bool) ($invalidatedResolution['ok'] ?? true), 'invalidated link must not resolve');
    videochat_call_access_invalidation_assert((string) ($invalidatedResolution['reason'] ?? '') === 'not_found', 'invalidated link should fail as safe invalid-link state');
    videochat_call_access_invalidation_assert(($invalidatedResolution['access_link'] ?? null) === null, 'invalidated resolution must not expose access link metadata');
    videochat_call_access_invalidation_assert(($invalidatedResolution['call'] ?? null) === null, 'invalidated resolution must not expose call data');
    videochat_call_access_invalidation_assert(($invalidatedResolution['target_user'] ?? null) === null, 'invalidated resolution must not expose target user data');
    videochat_call_access_invalidation_assert((($invalidatedResolution['target_hint'] ?? [])['participant_email'] ?? null) === null, 'invalidated resolution must not expose participant email hint');

    $sessionIssueAttempts = 0;
    $sessionResult = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static function () use (&$sessionIssueAttempts): string {
            $sessionIssueAttempts += 1;
            return 'sess_call_access_invalidated_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalidation-contract']
    );
    videochat_call_access_invalidation_assert(!(bool) ($sessionResult['ok'] ?? true), 'invalidated personalized link must not create a fresh session');
    videochat_call_access_invalidation_assert((string) ($sessionResult['reason'] ?? '') === 'not_found', 'invalidated session attempt should fail as safe invalid-link state');
    videochat_call_access_invalidation_assert($sessionIssueAttempts === 0, 'session id issuer must not run for invalidated link');
    videochat_call_access_invalidation_assert(($sessionResult['session'] ?? null) === null, 'invalidated session attempt must not expose session');
    videochat_call_access_invalidation_assert(($sessionResult['user'] ?? null) === null, 'invalidated session attempt must not expose user');
    videochat_call_access_invalidation_assert(($sessionResult['access_link'] ?? null) === null, 'invalidated session attempt must not expose access link');
    videochat_call_access_invalidation_assert(($sessionResult['call'] ?? null) === null, 'invalidated session attempt must not expose call');

    $sessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_call_access_invalidated_should_not_issue'")->fetchColumn();
    videochat_call_access_invalidation_assert($sessionCount === 0, 'invalidated link must not persist a fresh session');
    $bindingCount = (int) $pdo->query("SELECT COUNT(*) FROM call_access_sessions WHERE access_id = " . $pdo->quote($accessId))->fetchColumn();
    videochat_call_access_invalidation_assert($bindingCount === 0, 'invalidated link must not persist a call-access session binding');

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
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $accessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_invalidation_assert(is_array($joinResponse), 'invalidated join response should be an array');
    videochat_call_access_invalidation_assert((int) ($joinResponse['status'] ?? 0) === 404, 'invalidated join should return safe not-found status');
    $joinBody = (string) ($joinResponse['body'] ?? '');
    $joinPayload = videochat_call_access_invalidation_decode($joinResponse);
    videochat_call_access_invalidation_assert((string) (($joinPayload['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'invalidated join error code mismatch');

    $httpSessionIssuerCalls = 0;
    $httpSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => ['User-Agent' => 'call-access-invalidation-contract-http'],
            'remote_address' => '127.0.0.1',
            'body' => '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static function () use (&$httpSessionIssuerCalls): string {
            $httpSessionIssuerCalls += 1;
            return 'sess_call_access_invalidated_http_should_not_issue';
        }
    );
    videochat_call_access_invalidation_assert(is_array($httpSessionResponse), 'invalidated HTTP session response should be an array');
    videochat_call_access_invalidation_assert((int) ($httpSessionResponse['status'] ?? 0) === 404, 'invalidated HTTP session should return safe not-found status');
    videochat_call_access_invalidation_assert($httpSessionIssuerCalls === 0, 'HTTP session issuer must not run for invalidated link');
    $httpSessionBody = (string) ($httpSessionResponse['body'] ?? '');
    $httpSessionPayload = videochat_call_access_invalidation_decode($httpSessionResponse);
    videochat_call_access_invalidation_assert((string) (($httpSessionPayload['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'invalidated HTTP session error code mismatch');

    foreach ([$joinBody, $httpSessionBody] as $body) {
        videochat_call_access_invalidation_assert(!str_contains($body, $invitedEmail), 'invalidated response must not leak invited email');
        if ($invitedDisplayName !== '') {
            videochat_call_access_invalidation_assert(!str_contains($body, $invitedDisplayName), 'invalidated response must not leak invited display name');
        }
        videochat_call_access_invalidation_assert(!str_contains($body, 'Call Access Invalidation Secret Title'), 'invalidated response must not leak call title');
        videochat_call_access_invalidation_assert(!str_contains($body, $callId), 'invalidated response must not leak call id');
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-invalidation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-invalidation-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
