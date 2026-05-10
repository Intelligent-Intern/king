<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_identity_mismatch_review_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-identity-mismatch-review-flow-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_identity_mismatch_review_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_identity_mismatch_review_create_user(PDO $pdo, int $roleId, int $tenantId, string $email, string $displayName): int
{
    $hash = password_hash('call-access-identity-mismatch-review-flow', PASSWORD_DEFAULT);
    videochat_identity_mismatch_review_assert(is_string($hash) && $hash !== '', 'password hash failed');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $hash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_identity_mismatch_review_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, $roleId === videochat_identity_mismatch_review_role_id($pdo, 'admin') ? 'owner' : 'member');

    return $userId;
}

function videochat_identity_mismatch_review_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'identity-mismatch-review-contract'{$tenantValue})
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

function videochat_identity_mismatch_review_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_identity_mismatch_review_assert_no_needles(string $text, array $needles, string $label): void
{
    $body = strtolower($text);
    foreach ($needles as $needle) {
        $value = strtolower(trim((string) $needle));
        if ($value === '') {
            continue;
        }
        videochat_identity_mismatch_review_assert(!str_contains($body, $value), "{$label} leaked {$needle}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-identity-mismatch-review-flow-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT=1');
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS=900');

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-identity-mismatch-review-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_identity_mismatch_review_role_id($pdo, 'admin');
    $userRoleId = videochat_identity_mismatch_review_role_id($pdo, 'user');
    videochat_identity_mismatch_review_assert($tenantId > 0 && $adminRoleId > 0 && $userRoleId > 0, 'tenant and roles should exist');

    $secret = 'idm' . bin2hex(random_bytes(5));
    $hostName = 'Identity Review Host ' . $secret;
    $targetName = 'Identity Review Target ' . $secret;
    $currentName = 'Identity Review Current ' . $secret;
    $hostEmail = 'host-' . $secret . '@example.test';
    $targetEmail = 'target-' . $secret . '@example.test';
    $currentEmail = 'current-' . $secret . '@example.test';
    $callTitle = 'Identity Review Private Call ' . $secret;
    $targetSessionId = 'sess_identity_target_' . $secret;
    $currentSessionId = 'sess_identity_current_' . $secret;

    $hostUserId = videochat_identity_mismatch_review_create_user($pdo, $adminRoleId, $tenantId, $hostEmail, $hostName);
    $targetUserId = videochat_identity_mismatch_review_create_user($pdo, $userRoleId, $tenantId, $targetEmail, $targetName);
    $currentUserId = videochat_identity_mismatch_review_create_user($pdo, $userRoleId, $tenantId, $currentEmail, $currentName);
    videochat_identity_mismatch_review_insert_session($pdo, $targetSessionId, $targetUserId, $tenantId);
    videochat_identity_mismatch_review_insert_session($pdo, $currentSessionId, $currentUserId, $tenantId);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => $callTitle,
        'starts_at' => '2026-12-15T09:00:00Z',
        'ends_at' => '2026-12-15T10:00:00Z',
        'internal_participant_user_ids' => [$targetUserId],
    ], $tenantId);
    videochat_identity_mismatch_review_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_identity_mismatch_review_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $tenantId);
    videochat_identity_mismatch_review_assert((bool) ($access['ok'] ?? false), 'personalized link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_identity_mismatch_review_assert($accessId !== '', 'access id should be present');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
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
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $route = static function (array $headers, array $body, string $issuedSessionId) use ($accessId, $jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $path = '/api/call-access/' . $accessId . '/session';
        $response = videochat_handle_call_routes(
            $path,
            'POST',
            [
                'method' => 'POST',
                'uri' => $path,
                'headers' => $headers + ['Content-Type' => 'application/json'],
                'remote_address' => '127.0.0.1',
                'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ],
            [],
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => $issuedSessionId
        );
        videochat_identity_mismatch_review_assert(is_array($response), 'call-access route should return a response');
        return $response;
    };

    $secretNeedles = [
        $accessId,
        $hostName,
        $hostEmail,
        $targetName,
        $targetEmail,
        $currentEmail,
        $callTitle,
        $targetSessionId,
        $currentSessionId,
        'sess_identity_switch_should_not_issue_' . $secret,
        'sess_identity_wrong_host_should_not_issue_' . $secret,
        'sess_identity_rate_limited_should_not_issue_' . $secret,
    ];

    $switchResponse = $route(
        ['Authorization' => 'Bearer ' . $currentSessionId, 'User-Agent' => 'identity-mismatch-switch'],
        [
            'verified_user_id' => $targetUserId,
            'verified_session_id' => $targetSessionId,
        ],
        'sess_identity_switch_should_not_issue_' . $secret
    );
    videochat_identity_mismatch_review_assert((int) ($switchResponse['status'] ?? 0) === 409, 'verified/authenticated user mismatch should conflict');
    $switchPayload = videochat_identity_mismatch_review_decode($switchResponse);
    videochat_identity_mismatch_review_assert((string) (($switchPayload['error'] ?? [])['code'] ?? '') === 'call_access_conflict', 'mismatch conflict code mismatch');
    videochat_identity_mismatch_review_assert(
        (string) (((($switchPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['auth'] ?? '') === 'session_context_changed',
        'mismatch conflict must use stable auth field'
    );
    videochat_identity_mismatch_review_assert_no_needles((string) ($switchResponse['body'] ?? ''), $secretNeedles, 'session-context mismatch response');
    $switchRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_identity_switch_should_not_issue_{$secret}'")->fetchColumn();
    videochat_identity_mismatch_review_assert($switchRows === 0, 'session-context mismatch must not persist a session');

    $reviewRows = $pdo->query("SELECT * FROM call_access_review_flags WHERE reason = 'identity_mismatch_review'")->fetchAll();
    videochat_identity_mismatch_review_assert(count($reviewRows) === 1, 'session-context mismatch should create one identity review flag');
    $review = $reviewRows[0];
    videochat_identity_mismatch_review_assert((int) ($review['subject_user_id'] ?? 0) === $currentUserId, 'identity review subject mismatch');
    videochat_identity_mismatch_review_assert((int) ($review['target_user_id'] ?? 0) === $targetUserId, 'identity review target mismatch');
    videochat_identity_mismatch_review_assert((string) ($review['access_fingerprint'] ?? '') === videochat_audit_fingerprint($accessId), 'identity review fingerprint mismatch');
    videochat_identity_mismatch_review_assert_no_needles((string) ($review['payload_json'] ?? ''), $secretNeedles, 'identity review payload');

    $wrongHostResponse = $route(
        ['Authorization' => 'Bearer ' . $currentSessionId, 'User-Agent' => 'identity-mismatch-wrong-host'],
        ['host_name' => 'Wrong Host ' . $secret],
        'sess_identity_wrong_host_should_not_issue_' . $secret
    );
    videochat_identity_mismatch_review_assert((int) ($wrongHostResponse['status'] ?? 0) === 403, 'wrong host mismatch should be forbidden');
    $wrongHostPayload = videochat_identity_mismatch_review_decode($wrongHostResponse);
    videochat_identity_mismatch_review_assert(
        (string) (((($wrongHostPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'wrong_host_name',
        'wrong host mismatch must use safe host field'
    );
    videochat_identity_mismatch_review_assert_no_needles((string) ($wrongHostResponse['body'] ?? ''), $secretNeedles, 'wrong host mismatch response');

    $rateLimitedResponse = $route(
        ['Authorization' => 'Bearer ' . $currentSessionId, 'User-Agent' => 'identity-mismatch-rate-limited'],
        ['host_name' => 'Rate Limited Host ' . $secret],
        'sess_identity_rate_limited_should_not_issue_' . $secret
    );
    videochat_identity_mismatch_review_assert((int) ($rateLimitedResponse['status'] ?? 0) === 429, 'second host attempt should be rate-limited');
    $rateLimitedPayload = videochat_identity_mismatch_review_decode($rateLimitedResponse);
    videochat_identity_mismatch_review_assert(
        (string) (((($rateLimitedPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'rate_limited',
        'rate-limited host mismatch must use safe host field'
    );
    videochat_identity_mismatch_review_assert_no_needles((string) ($rateLimitedResponse['body'] ?? ''), $secretNeedles, 'rate-limited mismatch response');

    $issuedDeniedRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_identity_%_should_not_issue_{$secret}'")->fetchColumn();
    videochat_identity_mismatch_review_assert($issuedDeniedRows === 0, 'denied mismatch attempts must not persist sessions');
    $hostAttemptRows = $pdo->query('SELECT * FROM call_access_host_verification_attempts')->fetchAll();
    videochat_identity_mismatch_review_assert(count($hostAttemptRows) === 2, 'wrong and rate-limited host attempts should be recorded');
    $hostAttemptJson = json_encode($hostAttemptRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    videochat_identity_mismatch_review_assert_no_needles($hostAttemptJson, ['Wrong Host ' . $secret, 'Rate Limited Host ' . $secret, $accessId], 'host attempt rows');

    $events = videochat_audit_fetch_events($pdo, ['limit' => 100]);
    $eventTypes = array_map(static fn (array $event): string => (string) ($event['event_type'] ?? ''), $events);
    videochat_identity_mismatch_review_assert(in_array('call_access_identity_mismatch_review', $eventTypes, true), 'identity mismatch review audit missing');
    videochat_identity_mismatch_review_assert(in_array('call_access_strong_mismatch_denied', $eventTypes, true), 'strong mismatch denial audit missing');
    videochat_identity_mismatch_review_assert(in_array('call_access_account_compared', $eventTypes, true), 'account comparison audit missing');
    videochat_identity_mismatch_review_assert(in_array('call_access_host_name_verification_failed', $eventTypes, true), 'host-name verification failure audit missing');
    $legacyRejectedEvents = videochat_audit_fetch_events($pdo, ['event_type' => 'call_access_host_name_rejected', 'limit' => 20]);
    videochat_identity_mismatch_review_assert(count($legacyRejectedEvents) >= 2, 'legacy host-name rejection alias should find canonical failed audits');
    $legacyFailedEvents = videochat_audit_fetch_events($pdo, ['event_type' => 'call_access_host_verification_failed', 'limit' => 20]);
    videochat_identity_mismatch_review_assert(count($legacyFailedEvents) >= 2, 'legacy host-verification failed alias should find canonical failed audits');
    $legacyHostRejectedTypes = array_map(static fn (array $event): string => (string) ($event['event_type'] ?? ''), $legacyRejectedEvents);
    videochat_identity_mismatch_review_assert(
        in_array('call_access_host_name_verification_failed', $legacyHostRejectedTypes, true),
        'legacy host-name rejection audit filter must resolve to canonical failure audit'
    );
    $hostFailurePayload = (array) (($legacyRejectedEvents[0] ?? [])['payload'] ?? []);
    videochat_identity_mismatch_review_assert((string) ($hostFailurePayload['canonical_event_type'] ?? '') === 'call_access_host_name_verification_failed', 'host failure audit must mark canonical event type');
    videochat_identity_mismatch_review_assert(in_array('call_access_host_name_rejected', (array) ($hostFailurePayload['legacy_event_types'] ?? []), true), 'host failure audit must retain rejected legacy alias');
    videochat_identity_mismatch_review_assert(in_array('call_access_host_verification_failed', (array) ($hostFailurePayload['legacy_event_types'] ?? []), true), 'host failure audit must retain failed legacy alias');
    $eventsJson = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    videochat_identity_mismatch_review_assert_no_needles($eventsJson, $secretNeedles, 'identity mismatch audit events');
    videochat_identity_mismatch_review_assert(str_contains($eventsJson, '"raw_link_identifier_logged":false'), 'audit must state raw access ids are not logged');
    videochat_identity_mismatch_review_assert(str_contains($eventsJson, '"raw_session_identifier_logged":false'), 'audit must state raw session ids are not logged');
    videochat_identity_mismatch_review_assert(str_contains($eventsJson, '"foreign_account_data_logged":false'), 'audit must state foreign account data is not logged');

    fwrite(STDOUT, "[call-access-identity-mismatch-review-flow-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-identity-mismatch-review-flow-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT');
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_WINDOW_SECONDS');
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
