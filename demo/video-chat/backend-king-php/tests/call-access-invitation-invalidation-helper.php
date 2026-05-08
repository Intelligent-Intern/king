<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_iam_invitation_invalidation_assert(bool $condition, string $message, string $label): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$label}] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_invitation_invalidation_skip_without_sqlite(string $label): void
{
    if (extension_loaded('pdo_sqlite')) {
        return;
    }

    fwrite(STDOUT, "[{$label}] SKIP: pdo_sqlite unavailable\n");
    exit(0);
}

/**
 * @return array{0: string, 1: PDO}
 */
function videochat_iam_invitation_invalidation_bootstrap_database(string $prefix): array
{
    $databasePath = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);

    return [$databasePath, videochat_open_sqlite_pdo($databasePath)];
}

/**
 * @return array<string, mixed>
 */
function videochat_iam_invitation_invalidation_personal_fixture(PDO $pdo, string $label, string $title): array
{
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $invitedUser = $pdo->query("SELECT id, email, display_name FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    videochat_iam_invitation_invalidation_assert($tenantId > 0, 'expected default tenant', $label);
    videochat_iam_invitation_invalidation_assert($adminUserId > 0, 'expected seeded admin user', $label);
    videochat_iam_invitation_invalidation_assert(is_array($invitedUser), 'expected seeded invited user', $label);

    $invitedUserId = (int) ($invitedUser['id'] ?? 0);
    $invitedEmail = (string) ($invitedUser['email'] ?? '');
    $invitedDisplayName = (string) ($invitedUser['display_name'] ?? '');
    videochat_iam_invitation_invalidation_assert($invitedUserId > 0, 'expected invited user id', $label);
    videochat_iam_invitation_invalidation_assert($invitedEmail !== '', 'expected invited user email', $label);

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => $title,
        'starts_at' => gmdate('c', time() + 1800),
        'ends_at' => gmdate('c', time() + 5400),
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_invitation_invalidation_assert((bool) ($createCall['ok'] ?? false), 'call should be created', $label);
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_iam_invitation_invalidation_assert($callId !== '', 'call id should be present', $label);

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ], $tenantId);
    videochat_iam_invitation_invalidation_assert((bool) ($access['ok'] ?? false), 'personal access link should be created', $label);
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_iam_invitation_invalidation_assert($accessId !== '', 'personal access id should be present', $label);

    $initialResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam_invitation_invalidation_assert((bool) ($initialResolution['ok'] ?? false), 'personal link should resolve before invalidation', $label);
    videochat_iam_invitation_invalidation_assert(
        (int) (($initialResolution['target_user'] ?? [])['id'] ?? 0) === $invitedUserId,
        'pre-invalidation target user mismatch',
        $label
    );

    return [
        'tenant_id' => $tenantId,
        'admin_user_id' => $adminUserId,
        'invited_user_id' => $invitedUserId,
        'invited_email' => $invitedEmail,
        'invited_display_name' => $invitedDisplayName,
        'call_id' => $callId,
        'access_id' => $accessId,
        'title' => $title,
    ];
}

function videochat_iam_invitation_invalidation_set_invite_state(PDO $pdo, array $fixture, string $state): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':invite_state' => $state,
        ':call_id' => (string) ($fixture['call_id'] ?? ''),
        ':user_id' => (int) ($fixture['invited_user_id'] ?? 0),
    ]);
}

function videochat_iam_invitation_invalidation_cancel_personal_invitation(PDO $pdo, array $fixture, array $context = []): array
{
    $result = videochat_invalidate_call_access_invitation(
        $pdo,
        (string) ($fixture['access_id'] ?? ''),
        'cancelled',
        (int) ($fixture['admin_user_id'] ?? 0),
        [
            'invalidation_reason' => 'participant_invite_cancelled',
            ...$context,
        ]
    );

    return $result;
}

function videochat_iam_invitation_invalidation_expire_link(PDO $pdo, array $fixture): void
{
    $statement = $pdo->prepare('UPDATE call_access_links SET expires_at = :expires_at WHERE id = :id');
    $statement->execute([
        ':id' => (string) ($fixture['access_id'] ?? ''),
        ':expires_at' => gmdate('c', time() - 60),
    ]);
}

/**
 * @return array<string, mixed>
 */
function videochat_iam_invitation_invalidation_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

/**
 * @return array<int, string>
 */
function videochat_iam_invitation_invalidation_private_needles(array $fixture): array
{
    return array_values(array_filter([
        (string) ($fixture['title'] ?? ''),
        (string) ($fixture['call_id'] ?? ''),
        (string) ($fixture['invited_email'] ?? ''),
        (string) ($fixture['invited_display_name'] ?? ''),
    ], static fn (string $value): bool => trim($value) !== ''));
}

function videochat_iam_invitation_invalidation_assert_no_private_data(string $body, array $fixture, string $label, string $context): void
{
    foreach (videochat_iam_invitation_invalidation_private_needles($fixture) as $needle) {
        videochat_iam_invitation_invalidation_assert(
            !str_contains($body, $needle),
            "{$context} must not leak private fixture data: {$needle}",
            $label
        );
    }
}

function videochat_iam_invitation_invalidation_seed_account_session(
    PDO $pdo,
    int $userId,
    int $tenantId,
    string $sessionId,
    string $clientIp,
    string $userAgent
): void {
    $trimmedSessionId = trim($sessionId);
    videochat_iam_invitation_invalidation_assert($userId > 0, 'account session user id is required', 'call-access-invalidation-contract');
    videochat_iam_invitation_invalidation_assert($trimmedSessionId !== '', 'account session id is required', 'call-access-invalidation-contract');

    $hasActiveTenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id');
    $tenantColumn = $hasActiveTenantColumn ? ', active_tenant_id' : '';
    $tenantValue = $hasActiveTenantColumn ? ', :active_tenant_id' : '';
    $statement = $pdo->prepare(
        <<<SQL
INSERT OR IGNORE INTO sessions(id, user_id{$tenantColumn}, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id{$tenantValue}, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
    );
    $params = [
        ':id' => $trimmedSessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':client_ip' => $clientIp,
        ':user_agent' => $userAgent,
    ];
    if ($hasActiveTenantColumn) {
        $params[':active_tenant_id'] = $tenantId > 0 ? $tenantId : null;
    }
    $statement->execute($params);
}

function videochat_iam_invitation_invalidation_payload_has_key(mixed $value, string $needle): bool
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $entry) {
        if ((string) $key === $needle) {
            return true;
        }
        if (videochat_iam_invitation_invalidation_payload_has_key($entry, $needle)) {
            return true;
        }
    }

    return false;
}

function videochat_iam_invitation_invalidation_assert_audit_logged(
    PDO $pdo,
    array $fixture,
    string $label,
    string $expectedReason,
    string $expectedSessionId = '',
    bool $requireSessionFingerprint = true
): void {
    $events = videochat_audit_fetch_events($pdo, [
        'tenant_id' => (int) ($fixture['tenant_id'] ?? 0),
        'call_id' => (string) ($fixture['call_id'] ?? ''),
        'event_type' => 'call_access_invitation_invalidated',
        'limit' => 20,
    ]);
    videochat_iam_invitation_invalidation_assert($events !== [], 'invite invalidation audit event missing', $label);

    $event = $events[count($events) - 1] ?? [];
    $payload = (array) (($event['payload'] ?? null) ?: []);
    videochat_iam_invitation_invalidation_assert((string) ($event['resource_type'] ?? '') === 'call_access_link', 'audit resource type mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($event['resource_id'] ?? '') === '', 'audit must not persist raw access id as resource id', $label);
    videochat_iam_invitation_invalidation_assert((string) ($event['resource_fingerprint'] ?? '') === videochat_audit_fingerprint((string) ($fixture['access_id'] ?? '')), 'audit must fingerprint access id', $label);
    videochat_iam_invitation_invalidation_assert((int) ($event['actor_user_id'] ?? 0) === (int) ($fixture['admin_user_id'] ?? 0), 'audit actor mismatch', $label);
    videochat_iam_invitation_invalidation_assert((int) ($event['target_user_id'] ?? 0) === (int) ($fixture['invited_user_id'] ?? 0), 'audit target mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($payload['audit_scope'] ?? '') === 'iam_call_access', 'audit scope mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($payload['action'] ?? '') === 'invalidate_invitation', 'audit action mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($payload['invalidation_reason'] ?? '') === $expectedReason, 'audit invalidation reason mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($payload['invite_state'] ?? '') === 'cancelled', 'audit invite state mismatch', $label);
    videochat_iam_invitation_invalidation_assert((string) ($payload['link_kind'] ?? '') === 'personal', 'audit link kind mismatch', $label);
    videochat_iam_invitation_invalidation_assert((bool) ($payload['raw_link_identifier_logged'] ?? true) === false, 'audit must pin raw link omission', $label);
    videochat_iam_invitation_invalidation_assert((bool) ($payload['raw_credential_identifier_logged'] ?? true) === false, 'audit must pin raw session omission', $label);
    videochat_iam_invitation_invalidation_assert((bool) ($payload['raw_guest_identity_logged'] ?? true) === false, 'audit must pin raw guest omission', $label);

    foreach (['access_id', 'session_id', 'token', 'participant_email', 'email', 'display_name', 'guest_name'] as $forbiddenKey) {
        videochat_iam_invitation_invalidation_assert(
            !videochat_iam_invitation_invalidation_payload_has_key($payload, $forbiddenKey),
            "audit payload must not contain key {$forbiddenKey}",
            $label
        );
    }

    $encoded = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_iam_invitation_invalidation_assert(is_string($encoded), 'audit events should encode', $label);
    foreach ([
        (string) ($fixture['access_id'] ?? ''),
        (string) ($fixture['invited_email'] ?? ''),
        (string) ($fixture['invited_display_name'] ?? ''),
        $expectedSessionId,
    ] as $forbiddenText) {
        if (trim($forbiddenText) === '') {
            continue;
        }
        videochat_iam_invitation_invalidation_assert(
            !str_contains($encoded, $forbiddenText),
            'audit event leaked raw invite/session/guest data: ' . $forbiddenText,
            $label
        );
    }
    if (trim($expectedSessionId) !== '' && $requireSessionFingerprint) {
        videochat_iam_invitation_invalidation_assert(
            str_contains($encoded, videochat_audit_fingerprint($expectedSessionId)),
            'audit event should retain session fingerprint',
            $label
        );
    }
}

/**
 * @return array{0: callable, 1: callable, 2: callable}
 */
function videochat_iam_invitation_invalidation_http_helpers(): array
{
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

    return [$jsonResponse, $errorResponse, $decodeJsonBody];
}

function videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
    PDO $pdo,
    array $fixture,
    string $label,
    string $expectedReason,
    int $expectedHttpStatus,
    string $expectedHttpCode,
    array $requestContext = []
): void {
    $accessId = (string) ($fixture['access_id'] ?? '');
    $contextLabel = trim((string) ($requestContext['label'] ?? 'default'));
    $contextSuffix = preg_replace('/[^a-z0-9_]+/', '_', strtolower($contextLabel));
    if (!is_string($contextSuffix) || trim($contextSuffix, '_') === '') {
        $contextSuffix = 'default';
    }
    $contextSuffix = trim($contextSuffix, '_');
    $clientIp = trim((string) ($requestContext['client_ip'] ?? '127.0.0.1'));
    $userAgent = trim((string) ($requestContext['user_agent'] ?? $label));
    $issuerSessionId = trim((string) ($requestContext['issuer_session_id'] ?? ''));
    if ($issuerSessionId === '') {
        $issuerSessionId = 'sess_iam_invitation_stale_should_not_issue_' . $contextSuffix . '_' . substr(str_replace('-', '', $accessId), 0, 8);
    }
    $httpIssuerSessionId = trim((string) ($requestContext['http_issuer_session_id'] ?? ''));
    if ($httpIssuerSessionId === '') {
        $httpIssuerSessionId = 'sess_iam_invitation_http_should_not_issue_' . $contextSuffix;
    }
    $sessionOptions = [];
    foreach (['authenticated_user_id', 'authenticated_session_id', 'verified_user_id', 'verified_session_id', 'host_name', 'guest_name'] as $optionKey) {
        if (array_key_exists($optionKey, $requestContext)) {
            $sessionOptions[$optionKey] = $requestContext[$optionKey];
        }
    }

    $resolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam_invitation_invalidation_assert(!(bool) ($resolution['ok'] ?? true), 'stale personalized link must not resolve', $label);
    videochat_iam_invitation_invalidation_assert((string) ($resolution['reason'] ?? '') === $expectedReason, 'stale link resolution reason mismatch', $label);
    videochat_iam_invitation_invalidation_assert(($resolution['access_link'] ?? null) === null, 'stale resolution must not expose access link metadata', $label);
    videochat_iam_invitation_invalidation_assert(($resolution['call'] ?? null) === null, 'stale resolution must not expose call data', $label);
    videochat_iam_invitation_invalidation_assert(($resolution['target_user'] ?? null) === null, 'stale resolution must not expose target user data', $label);
    videochat_iam_invitation_invalidation_assert((($resolution['target_hint'] ?? [])['participant_email'] ?? null) === null, 'stale resolution must not expose participant email hint', $label);

    $sessionIssuerCalls = 0;
    $sessionResult = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static function () use (&$sessionIssuerCalls, $issuerSessionId): string {
            $sessionIssuerCalls += 1;
            return $issuerSessionId;
        },
        ['client_ip' => $clientIp, 'user_agent' => $userAgent],
        $sessionOptions
    );
    videochat_iam_invitation_invalidation_assert(!(bool) ($sessionResult['ok'] ?? true), 'stale personalized link must not create a fresh session', $label);
    videochat_iam_invitation_invalidation_assert((string) ($sessionResult['reason'] ?? '') === $expectedReason, 'stale session reason mismatch', $label);
    videochat_iam_invitation_invalidation_assert($sessionIssuerCalls === 0, 'session id issuer must not run for stale link', $label);
    videochat_iam_invitation_invalidation_assert(($sessionResult['session'] ?? null) === null, 'stale session attempt must not expose session', $label);
    videochat_iam_invitation_invalidation_assert(($sessionResult['user'] ?? null) === null, 'stale session attempt must not expose user', $label);
    videochat_iam_invitation_invalidation_assert(($sessionResult['access_link'] ?? null) === null, 'stale session attempt must not expose access link', $label);
    videochat_iam_invitation_invalidation_assert(($sessionResult['call'] ?? null) === null, 'stale session attempt must not expose call', $label);

    $sessionCount = (int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE id = ' . $pdo->quote($issuerSessionId))->fetchColumn();
    videochat_iam_invitation_invalidation_assert($sessionCount === 0, 'stale link must not persist a fresh session', $label);

    [$jsonResponse, $errorResponse, $decodeJsonBody] = videochat_iam_invitation_invalidation_http_helpers();
    $openDatabase = static fn (): PDO => $pdo;
    $headers = [];
    if ($userAgent !== '') {
        $headers['User-Agent'] = $userAgent;
    }
    $authorizationSessionId = trim((string) ($requestContext['authorization_session_id'] ?? ($requestContext['authenticated_session_id'] ?? '')));
    if ($authorizationSessionId !== '') {
        $headers['Authorization'] = 'Bearer ' . $authorizationSessionId;
    }
    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/call-access/' . $accessId . '/join',
            'headers' => $headers,
            'remote_address' => $clientIp,
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_iam_invitation_invalidation_assert(is_array($joinResponse), 'stale join response should be an array', $label);
    videochat_iam_invitation_invalidation_assert((int) ($joinResponse['status'] ?? 0) === $expectedHttpStatus, 'stale join status mismatch', $label);
    $joinPayload = videochat_iam_invitation_invalidation_decode($joinResponse);
    videochat_iam_invitation_invalidation_assert((string) (($joinPayload['error'] ?? [])['code'] ?? '') === $expectedHttpCode, 'stale join error code mismatch', $label);
    videochat_iam_invitation_invalidation_assert_no_private_data((string) ($joinResponse['body'] ?? ''), $fixture, $label, 'stale join response');

    $httpSessionIssuerCalls = 0;
    $bodyPayload = [];
    foreach (['verified_user_id', 'verified_session_id', 'host_name', 'guest_name'] as $bodyKey) {
        if (array_key_exists($bodyKey, $requestContext)) {
            $bodyPayload[$bodyKey] = $requestContext[$bodyKey];
        }
    }
    $httpSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => $headers,
            'remote_address' => $clientIp,
            'body' => json_encode($bodyPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static function () use (&$httpSessionIssuerCalls, $httpIssuerSessionId): string {
            $httpSessionIssuerCalls += 1;
            return $httpIssuerSessionId;
        }
    );
    videochat_iam_invitation_invalidation_assert(is_array($httpSessionResponse), 'stale HTTP session response should be an array', $label);
    videochat_iam_invitation_invalidation_assert((int) ($httpSessionResponse['status'] ?? 0) === $expectedHttpStatus, 'stale HTTP session status mismatch', $label);
    videochat_iam_invitation_invalidation_assert($httpSessionIssuerCalls === 0, 'HTTP session issuer must not run for stale link', $label);
    $httpSessionPayload = videochat_iam_invitation_invalidation_decode($httpSessionResponse);
    videochat_iam_invitation_invalidation_assert((string) (($httpSessionPayload['error'] ?? [])['code'] ?? '') === $expectedHttpCode, 'stale HTTP session error code mismatch', $label);
    videochat_iam_invitation_invalidation_assert_no_private_data((string) ($httpSessionResponse['body'] ?? ''), $fixture, $label, 'stale session response');
}

function videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions(
    PDO $pdo,
    array $fixture,
    string $label,
    string $prefix = 'cross-context'
): void {
    $tenantId = (int) ($fixture['tenant_id'] ?? 0);
    $userId = (int) ($fixture['invited_user_id'] ?? 0);
    $accessPrefix = substr(str_replace('-', '', (string) ($fixture['access_id'] ?? '')), 0, 8);
    $contexts = [
        [
            'label' => $prefix . '-browser-a',
            'client_ip' => '127.10.24.11',
            'user_agent' => 'King IAM invalidated-link Chromium browser A',
            'authenticated_session_id' => 'sess_iam_invalidated_browser_a_' . $accessPrefix,
            'verified_session_id' => 'sess_iam_invalidated_browser_a_' . $accessPrefix,
        ],
        [
            'label' => $prefix . '-device-b',
            'client_ip' => '127.10.24.12',
            'user_agent' => 'King IAM invalidated-link mobile device B',
            'authenticated_session_id' => 'sess_iam_invalidated_device_b_' . $accessPrefix,
            'verified_session_id' => 'sess_iam_invalidated_device_b_' . $accessPrefix,
        ],
        [
            'label' => $prefix . '-fresh-session-c',
            'client_ip' => '127.10.24.13',
            'user_agent' => 'King IAM invalidated-link fresh session C',
            'authenticated_session_id' => 'sess_iam_invalidated_session_c_' . $accessPrefix,
            'verified_session_id' => 'sess_iam_invalidated_session_c_' . $accessPrefix,
        ],
    ];

    foreach ($contexts as $context) {
        videochat_iam_invitation_invalidation_seed_account_session(
            $pdo,
            $userId,
            $tenantId,
            (string) ($context['authenticated_session_id'] ?? ''),
            (string) ($context['client_ip'] ?? ''),
            (string) ($context['user_agent'] ?? '')
        );
        $context['authenticated_user_id'] = $userId;
        $context['verified_user_id'] = $userId;
        $context['authorization_session_id'] = (string) ($context['authenticated_session_id'] ?? '');
        $context['issuer_session_id'] = 'sess_iam_invalidated_should_not_issue_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($context['label'] ?? 'context'))) . '_' . $accessPrefix;
        $context['http_issuer_session_id'] = 'sess_iam_invalidated_http_should_not_issue_' . preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($context['label'] ?? 'context'))) . '_' . $accessPrefix;
        videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
            $pdo,
            $fixture,
            $label,
            'not_found',
            404,
            'call_access_not_found',
            $context
        );
    }

    $issuedPrefixCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_iam_invalidated_should_not_issue_%' OR id LIKE 'sess_iam_invalidated_http_should_not_issue_%'"
    )->fetchColumn();
    videochat_iam_invitation_invalidation_assert($issuedPrefixCount === 0, 'invalidated link must not persist any cross-context call-access sessions', $label);
}

function videochat_iam_invitation_invalidation_session_id(array $fixture, string $suffix): string
{
    return 'sess_iam_invitation_' . $suffix . '_' . substr(str_replace('-', '', (string) ($fixture['access_id'] ?? '')), 0, 8);
}

function videochat_iam_invitation_invalidation_assert_existing_session_rejected_after_cancel(
    PDO $pdo,
    array $fixture,
    string $label
): void {
    videochat_iam_invitation_invalidation_set_invite_state($pdo, $fixture, 'allowed');
    $sessionId = videochat_iam_invitation_invalidation_session_id($fixture, 'cancel');
    $session = videochat_issue_session_for_call_access(
        $pdo,
        (string) ($fixture['access_id'] ?? ''),
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_invitation_invalidation_assert((bool) ($session['ok'] ?? false), 'valid personalized link should issue a session before invalidation', $label);

    $authBeforeCancel = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $sessionId . '&room=' . rawurlencode((string) ($fixture['call_id'] ?? '')) . '&call_id=' . rawurlencode((string) ($fixture['call_id'] ?? '')),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_invitation_invalidation_assert((bool) ($authBeforeCancel['ok'] ?? false), 'session should authenticate before invalidation', $label);
    $roomResolution = videochat_realtime_resolve_connection_rooms(
        $authBeforeCancel,
        (string) ($fixture['call_id'] ?? ''),
        static fn (): PDO => $pdo,
        (string) ($fixture['call_id'] ?? '')
    );
    videochat_iam_invitation_invalidation_assert((string) ($roomResolution['initial_room_id'] ?? '') === (string) ($fixture['call_id'] ?? ''), 'allowed user should resolve to the room before invalidation', $label);

    $invalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $fixture, [
        'session_id' => $sessionId,
        'invalidation_reason' => 'participant_invite_cancelled_after_session',
    ]);
    videochat_iam_invitation_invalidation_assert((bool) ($invalidation['ok'] ?? false), 'invite invalidation should be audited', $label);
    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($fixture['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'invalidated access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'domain should classify cancelled participant invite as invalidated', $label);
    videochat_iam_invitation_invalidation_assert_audit_logged(
        $pdo,
        $fixture,
        $label,
        'participant_invite_cancelled_after_session',
        $sessionId
    );

    $validationAfterCancel = videochat_validate_session_token($pdo, $sessionId);
    videochat_iam_invitation_invalidation_assert(!(bool) ($validationAfterCancel['ok'] ?? true), 'stale call-access session must fail after invite invalidation', $label);
    videochat_iam_invitation_invalidation_assert((string) ($validationAfterCancel['reason'] ?? '') === 'call_access_link_invalidated', 'stale call-access session reason mismatch after cancellation', $label);

    $authAfterCancel = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $sessionId . '&room=' . rawurlencode((string) ($fixture['call_id'] ?? '')) . '&call_id=' . rawurlencode((string) ($fixture['call_id'] ?? '')),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_invitation_invalidation_assert(!(bool) ($authAfterCancel['ok'] ?? true), 'stale websocket rejoin must be blocked after invite invalidation', $label);
    videochat_iam_invitation_invalidation_assert((string) ($authAfterCancel['reason'] ?? '') === 'call_access_link_invalidated', 'stale websocket rejoin reason mismatch after cancellation', $label);
    videochat_iam_invitation_invalidation_assert(videochat_fetch_call_access_session_binding($pdo, $sessionId) === null, 'invalidated call-access binding must not resolve after cancellation', $label);
}

function videochat_iam_invitation_invalidation_assert_existing_session_rejected_after_expiry(
    PDO $pdo,
    array $fixture,
    string $label
): void {
    videochat_iam_invitation_invalidation_set_invite_state($pdo, $fixture, 'allowed');
    $sessionId = videochat_iam_invitation_invalidation_session_id($fixture, 'expiry');
    $session = videochat_issue_session_for_call_access(
        $pdo,
        (string) ($fixture['access_id'] ?? ''),
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_invitation_invalidation_assert((bool) ($session['ok'] ?? false), 'valid personalized link should issue a session before expiry', $label);

    videochat_iam_invitation_invalidation_expire_link($pdo, $fixture);

    $validationAfterExpiry = videochat_validate_session_token($pdo, $sessionId);
    videochat_iam_invitation_invalidation_assert(!(bool) ($validationAfterExpiry['ok'] ?? true), 'stale call-access session must fail after link expiry', $label);
    videochat_iam_invitation_invalidation_assert((string) ($validationAfterExpiry['reason'] ?? '') === 'call_access_link_expired', 'stale call-access session reason mismatch after expiry', $label);
    videochat_iam_invitation_invalidation_assert(videochat_fetch_call_access_session_binding($pdo, $sessionId) === null, 'expired call-access binding must not resolve after link expiry', $label);
}
