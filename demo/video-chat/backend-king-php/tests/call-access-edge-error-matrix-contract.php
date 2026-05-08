<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_calls_access.php';

function videochat_iam_edge_error_matrix_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-edge-error-matrix-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam_edge_error_matrix_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_iam_edge_error_matrix_assert_omits(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam_edge_error_matrix_assert(!str_contains($body, $normalized), "{$label} leaked {$needle}");
    }
}

function videochat_iam_edge_error_matrix_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam_edge_error_matrix_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam_edge_error_matrix_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam_edge_error_matrix_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $payload = json_decode($body, true);
    return is_array($payload) ? [$payload, null] : [null, 'invalid_json'];
}

function videochat_iam_edge_error_matrix_auth_context(int $userId, string $role, int $tenantId): array
{
    return [
        'ok' => true,
        'user' => [
            'id' => $userId,
            'role' => $role,
        ],
        'session' => [
            'id' => 'sess_edge_error_matrix_' . $userId,
        ],
        'tenant' => [
            'id' => $tenantId,
            'tenant_id' => $tenantId,
        ],
    ];
}

function videochat_iam_edge_error_matrix_create_user(PDO $pdo, string $email, string $displayName, int $tenantId): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_iam_edge_error_matrix_assert($roleId > 0, 'user role should exist');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    )->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('contract-pass', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam_edge_error_matrix_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, 'member');
    return $userId;
}

function videochat_iam_edge_error_matrix_create_call(PDO $pdo, int $ownerUserId, int $participantUserId, string $title, int $tenantId): array
{
    $result = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'starts_at' => '2026-09-18T09:00:00Z',
        'ends_at' => '2026-09-18T10:00:00Z',
        'internal_participant_user_ids' => [$participantUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam_edge_error_matrix_assert((bool) ($result['ok'] ?? false), "{$title} should be created");
    $callId = (string) (($result['call'] ?? [])['id'] ?? '');
    videochat_iam_edge_error_matrix_assert($callId !== '', "{$title} id should be present");

    return [
        'id' => $callId,
        'title' => $title,
    ];
}

function videochat_iam_edge_error_matrix_create_personal_link(PDO $pdo, string $callId, int $ownerUserId, int $targetUserId, int $tenantId): string
{
    $result = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $tenantId);
    videochat_iam_edge_error_matrix_assert((bool) ($result['ok'] ?? false), 'personal link should be created');
    $accessId = (string) (($result['access_link'] ?? [])['id'] ?? '');
    videochat_iam_edge_error_matrix_assert($accessId !== '', 'personal access id should be present');
    return $accessId;
}

function videochat_iam_edge_error_matrix_join_response(string $accessId, callable $openDatabase): array
{
    return videochat_handle_call_access_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $accessId . '/join', 'headers' => []],
        [],
        'videochat_iam_edge_error_matrix_json_response',
        'videochat_iam_edge_error_matrix_error_response',
        'videochat_iam_edge_error_matrix_decode_json_body',
        $openDatabase
    ) ?? [];
}

function videochat_iam_edge_error_matrix_session_response(string $accessId, callable $openDatabase, int &$issuerCalls): array
{
    return videochat_handle_call_access_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        ['method' => 'POST', 'uri' => '/api/call-access/' . $accessId . '/session', 'headers' => [], 'body' => '{}'],
        [],
        'videochat_iam_edge_error_matrix_json_response',
        'videochat_iam_edge_error_matrix_error_response',
        'videochat_iam_edge_error_matrix_decode_json_body',
        $openDatabase,
        static function () use (&$issuerCalls): string {
            $issuerCalls += 1;
            return 'sess_edge_error_matrix_must_not_issue';
        }
    ) ?? [];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-edge-error-matrix-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-edge-error-matrix-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam_edge_error_matrix_assert($tenantId > 0, 'expected default tenant');
    videochat_iam_edge_error_matrix_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_iam_edge_error_matrix_assert($standardUserId > 0, 'expected seeded standard user');

    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $adminAuth = videochat_iam_edge_error_matrix_auth_context($adminUserId, 'admin', $tenantId);

    $missingCallId = videochat_generate_call_access_uuid();
    $missingCallResolve = videochat_handle_call_routes(
        '/api/calls/resolve/' . $missingCallId,
        'GET',
        ['method' => 'GET', 'uri' => '/api/calls/resolve/' . $missingCallId, 'headers' => []],
        $adminAuth,
        'videochat_iam_edge_error_matrix_json_response',
        'videochat_iam_edge_error_matrix_error_response',
        'videochat_iam_edge_error_matrix_decode_json_body',
        $openDatabase
    );
    videochat_iam_edge_error_matrix_assert(is_array($missingCallResolve), 'e2e_edge_001_call_does_not_exist resolve response should exist');
    videochat_iam_edge_error_matrix_assert((int) ($missingCallResolve['status'] ?? 0) === 200, 'missing call resolve should use safe envelope');
    $missingPayload = videochat_iam_edge_error_matrix_decode($missingCallResolve);
    videochat_iam_edge_error_matrix_assert((string) (($missingPayload['result'] ?? [])['state'] ?? '') === 'not_found', 'missing call should resolve as not_found');
    videochat_iam_edge_error_matrix_assert(($missingPayload['result']['call'] ?? null) === null, 'missing call resolve must not include call payload');

    $missingAccessId = videochat_generate_call_access_uuid();
    $missingAccessJoin = videochat_iam_edge_error_matrix_join_response($missingAccessId, $openDatabase);
    videochat_iam_edge_error_matrix_assert((int) ($missingAccessJoin['status'] ?? 0) === 404, 'missing access join should return 404');
    videochat_iam_edge_error_matrix_assert((string) ((videochat_iam_edge_error_matrix_decode($missingAccessJoin)['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'missing access join error code mismatch');
    $missingIssuerCalls = 0;
    $missingAccessSession = videochat_iam_edge_error_matrix_session_response($missingAccessId, $openDatabase, $missingIssuerCalls);
    videochat_iam_edge_error_matrix_assert((int) ($missingAccessSession['status'] ?? 0) === 404, 'missing access session should return 404');
    videochat_iam_edge_error_matrix_assert($missingIssuerCalls === 0, 'missing access session issuer must not be called');

    $tenantCall = videochat_iam_edge_error_matrix_create_call(
        $pdo,
        $adminUserId,
        $standardUserId,
        'IAM Archived Organization Secret Call',
        $tenantId
    );
    $tenantAccessId = videochat_iam_edge_error_matrix_create_personal_link($pdo, $tenantCall['id'], $adminUserId, $standardUserId, $tenantId);
    $pdo->prepare("UPDATE tenants SET status = 'archived', updated_at = :updated_at WHERE id = :id")->execute([
        ':updated_at' => gmdate('c'),
        ':id' => $tenantId,
    ]);

    $tenantDecision = videochat_decide_call_access_for_user($pdo, $tenantCall['id'], $standardUserId, 'user', $tenantId);
    videochat_iam_edge_error_matrix_assert(!(bool) ($tenantDecision['allowed'] ?? true), 'e2e_edge_002_disabled_organization must deny decision');
    videochat_iam_edge_error_matrix_assert((string) ($tenantDecision['reason'] ?? '') === 'tenant_inactive', 'disabled organization decision reason mismatch');
    $tenantJoin = videochat_iam_edge_error_matrix_join_response($tenantAccessId, $openDatabase);
    videochat_iam_edge_error_matrix_assert((int) ($tenantJoin['status'] ?? 0) === 404, 'disabled organization public join should return 404');
    videochat_iam_edge_error_matrix_assert_omits($tenantJoin, [$tenantCall['title'], 'user@intelligent-intern.com'], 'disabled organization join');
    $tenantIssuerCalls = 0;
    $tenantSession = videochat_iam_edge_error_matrix_session_response($tenantAccessId, $openDatabase, $tenantIssuerCalls);
    videochat_iam_edge_error_matrix_assert((int) ($tenantSession['status'] ?? 0) === 404, 'disabled organization session should return 404');
    videochat_iam_edge_error_matrix_assert($tenantIssuerCalls === 0, 'disabled organization session issuer must not be called');
    videochat_iam_edge_error_matrix_assert_omits($tenantSession, [$tenantCall['title'], 'user@intelligent-intern.com'], 'disabled organization session');

    $pdo->prepare("UPDATE tenants SET status = 'active', updated_at = :updated_at WHERE id = :id")->execute([
        ':updated_at' => gmdate('c'),
        ':id' => $tenantId,
    ]);

    $hostUserId = videochat_iam_edge_error_matrix_create_user($pdo, 'edge-host-disabled@example.test', 'Edge Host Disabled', $tenantId);
    $hostTargetUserId = videochat_iam_edge_error_matrix_create_user($pdo, 'edge-host-target@example.test', 'Edge Host Target', $tenantId);
    $hostCall = videochat_iam_edge_error_matrix_create_call(
        $pdo,
        $hostUserId,
        $hostTargetUserId,
        'IAM Disabled Host Secret Call',
        $tenantId
    );
    $hostAccessId = videochat_iam_edge_error_matrix_create_personal_link($pdo, $hostCall['id'], $hostUserId, $hostTargetUserId, $tenantId);
    $pdo->prepare("UPDATE users SET status = 'disabled', updated_at = :updated_at WHERE id = :id")->execute([
        ':updated_at' => gmdate('c'),
        ':id' => $hostUserId,
    ]);

    $hostDecision = videochat_decide_call_access_for_user($pdo, $hostCall['id'], $hostTargetUserId, 'user', $tenantId);
    videochat_iam_edge_error_matrix_assert(!(bool) ($hostDecision['allowed'] ?? true), 'e2e_edge_003_host_disabled must deny decision');
    videochat_iam_edge_error_matrix_assert((string) ($hostDecision['reason'] ?? '') === 'call_host_inactive', 'disabled host decision reason mismatch');
    $hostJoin = videochat_iam_edge_error_matrix_join_response($hostAccessId, $openDatabase);
    videochat_iam_edge_error_matrix_assert((int) ($hostJoin['status'] ?? 0) === 404, 'disabled host public join should return 404');
    videochat_iam_edge_error_matrix_assert_omits($hostJoin, [$hostCall['title'], 'edge-host-disabled@example.test', 'edge-host-target@example.test'], 'disabled host join');
    $hostIssuerCalls = 0;
    $hostSession = videochat_iam_edge_error_matrix_session_response($hostAccessId, $openDatabase, $hostIssuerCalls);
    videochat_iam_edge_error_matrix_assert((int) ($hostSession['status'] ?? 0) === 404, 'disabled host session should return 404');
    videochat_iam_edge_error_matrix_assert($hostIssuerCalls === 0, 'disabled host session issuer must not be called');

    $guestCreate = videochat_create_guest_user_for_call_access($pdo, 'Edge Deleted Temporary Guest', $tenantId);
    videochat_iam_edge_error_matrix_assert((bool) ($guestCreate['ok'] ?? false), 'temporary guest should be created');
    $temporaryGuest = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : [];
    $temporaryGuestId = (int) ($temporaryGuest['id'] ?? 0);
    $temporaryGuestEmail = (string) ($temporaryGuest['email'] ?? '');
    videochat_iam_edge_error_matrix_assert($temporaryGuestId > 0 && $temporaryGuestEmail !== '', 'temporary guest identity should be present');
    $guestCall = videochat_iam_edge_error_matrix_create_call(
        $pdo,
        $adminUserId,
        $temporaryGuestId,
        'IAM Deleted Temporary Guest Secret Call',
        $tenantId
    );
    $guestAccessId = videochat_iam_edge_error_matrix_create_personal_link($pdo, $guestCall['id'], $adminUserId, $temporaryGuestId, $tenantId);
    $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $temporaryGuestId]);

    $guestJoin = videochat_iam_edge_error_matrix_join_response($guestAccessId, $openDatabase);
    videochat_iam_edge_error_matrix_assert((int) ($guestJoin['status'] ?? 0) === 404, 'e2e_edge_004_invited_temporary_account_deleted join should return 404');
    videochat_iam_edge_error_matrix_assert_omits($guestJoin, [$guestCall['title'], $temporaryGuestEmail, 'Edge Deleted Temporary Guest'], 'deleted temporary guest join');
    $guestIssuerCalls = 0;
    $guestSession = videochat_iam_edge_error_matrix_session_response($guestAccessId, $openDatabase, $guestIssuerCalls);
    videochat_iam_edge_error_matrix_assert((int) ($guestSession['status'] ?? 0) === 404, 'deleted temporary guest session should return 404');
    videochat_iam_edge_error_matrix_assert($guestIssuerCalls === 0, 'deleted temporary guest session issuer must not be called');
    videochat_iam_edge_error_matrix_assert_omits($guestSession, [$guestCall['title'], $temporaryGuestEmail, 'Edge Deleted Temporary Guest'], 'deleted temporary guest session');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-edge-error-matrix-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-edge-error-matrix-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
