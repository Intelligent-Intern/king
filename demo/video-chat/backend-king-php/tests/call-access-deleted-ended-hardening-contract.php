<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_iam9_12_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-deleted-ended-hardening-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_iam9_12_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam9_12_error_response(int $status, string $code, string $message, array $details = []): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return videochat_iam9_12_json_response($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
}

function videochat_iam9_12_decode_json_body(array $request): array
{
    $body = (string) ($request['body'] ?? '');
    if (trim($body) === '') {
        return [null, 'empty_body'];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
}

function videochat_iam9_12_decode_response(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, string|int|null> $needles
 */
function videochat_iam9_12_assert_body_omits(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $normalized = strtolower(trim((string) $needle));
        if ($normalized === '') {
            continue;
        }
        videochat_iam9_12_assert(!str_contains($body, $normalized), "{$label} leaked {$needle}");
    }
}

function videochat_iam9_12_create_user(PDO $pdo, int $tenantId, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_iam9_12_assert($roleId > 0, 'user role should exist');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('iam9-12-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_iam9_12_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, 'member');

    return $userId;
}

function videochat_iam9_12_attach_organization(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role): void
{
    $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => strtolower(trim($role)) === 'admin' ? 'admin' : 'member',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_iam9_12_create_org_call(PDO $pdo, int $tenantId, int $ownerUserId, string $title): array
{
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_iam9_12_assert((bool) ($created['ok'] ?? false), "{$title} should be created");
    $call = is_array($created['call'] ?? null) ? $created['call'] : [];
    videochat_iam9_12_assert((string) ($call['id'] ?? '') !== '', "{$title} call id should be present");

    return $call;
}

function videochat_iam9_12_create_personal_link(PDO $pdo, string $callId, int $creatorUserId, int $targetUserId, int $tenantId): string
{
    $created = videochat_create_call_access_link_for_user($pdo, $callId, $creatorUserId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $tenantId);
    videochat_iam9_12_assert((bool) ($created['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($created['access_link'] ?? [])['id'] ?? '');
    videochat_iam9_12_assert($accessId !== '', 'personal access id should be present');

    return $accessId;
}

function videochat_iam9_12_auth(int $userId, int $tenantId): array
{
    return [
        'ok' => true,
        'user' => ['id' => $userId, 'role' => 'user'],
        'session' => ['id' => 'sess_iam9_12_' . $userId],
        'tenant' => ['id' => $tenantId],
    ];
}

function videochat_iam9_12_call_route(string $databasePath, string $path, array $auth): array
{
    $response = videochat_handle_call_routes(
        $path,
        'GET',
        ['method' => 'GET', 'uri' => $path, 'headers' => []],
        $auth,
        'videochat_iam9_12_json_response',
        'videochat_iam9_12_error_response',
        'videochat_iam9_12_decode_json_body',
        static fn (): PDO => videochat_open_sqlite_pdo($databasePath)
    );
    videochat_iam9_12_assert(is_array($response), "{$path} should return a response");

    return $response;
}

function videochat_iam9_12_join_route(string $databasePath, string $accessId): array
{
    $path = '/api/call-access/' . $accessId . '/join';
    $response = videochat_handle_call_access_routes(
        $path,
        'GET',
        ['method' => 'GET', 'uri' => $path, 'headers' => []],
        [],
        'videochat_iam9_12_json_response',
        'videochat_iam9_12_error_response',
        'videochat_iam9_12_decode_json_body',
        static fn (): PDO => videochat_open_sqlite_pdo($databasePath)
    );
    videochat_iam9_12_assert(is_array($response), "{$path} should return a response");

    return $response;
}

function videochat_iam9_12_assert_org_admin_denied_after_terminal_state(
    PDO $pdo,
    string $databasePath,
    int $tenantId,
    int $ownerUserId,
    int $orgAdminUserId,
    int $targetUserId,
    string $state
): void {
    $call = videochat_iam9_12_create_org_call($pdo, $tenantId, $ownerUserId, "IAM9-12 {$state} Org Admin Secret");
    $callId = (string) $call['id'];
    $accessId = videochat_iam9_12_create_personal_link($pdo, $callId, $ownerUserId, $targetUserId, $tenantId);

    $before = videochat_decide_call_access_for_user($pdo, $callId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam9_12_assert((bool) ($before['allowed'] ?? false), "{$state} org admin should join before terminal transition");
    videochat_iam9_12_assert((string) ($before['source'] ?? '') === 'organization_admin', "{$state} pre-terminal source mismatch");

    if ($state === 'deleted') {
        $transition = videochat_delete_call($pdo, $callId, $ownerUserId, 'user', $tenantId);
    } else {
        $transition = videochat_end_call($pdo, $callId, $ownerUserId, 'user', $tenantId);
    }
    videochat_iam9_12_assert((bool) ($transition['ok'] ?? false), "{$state} transition should succeed");

    $decision = videochat_decide_call_access_for_user($pdo, $callId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam9_12_assert(!(bool) ($decision['allowed'] ?? true), "{$state} org admin must not bypass terminal call state");
    videochat_iam9_12_assert((string) ($decision['source'] ?? '') === 'none', "{$state} denied source should be none");
    videochat_iam9_12_assert(
        (string) ($decision['reason'] ?? '') === ($state === 'deleted' ? 'not_found' : 'conflict'),
        "{$state} org admin denial reason mismatch"
    );

    $resolve = videochat_iam9_12_call_route($databasePath, '/api/calls/resolve/' . $callId, videochat_iam9_12_auth($orgAdminUserId, $tenantId));
    videochat_iam9_12_assert((int) ($resolve['status'] ?? 0) === 200, "{$state} resolve route should return a safe envelope");
    $resolvePayload = videochat_iam9_12_decode_response($resolve);
    $resolveResult = is_array($resolvePayload['result'] ?? null) ? $resolvePayload['result'] : [];
    videochat_iam9_12_assert(($resolveResult['call'] ?? null) === null, "{$state} resolve must redact call payload");
    videochat_iam9_12_assert(
        in_array((string) ($resolveResult['state'] ?? ''), ['forbidden', 'not_found'], true),
        "{$state} resolve should expose only a terminal safe state"
    );
    videochat_iam9_12_assert_body_omits($resolve, [$call['title'] ?? '', $accessId], "{$state} org admin resolve");

    $join = videochat_iam9_12_join_route($databasePath, $accessId);
    videochat_iam9_12_assert(
        (int) ($join['status'] ?? 0) === 404,
        "{$state} personalized public join should stay hidden after terminal lifecycle invalidation"
    );
    videochat_iam9_12_assert_body_omits($join, [$call['title'] ?? '', $accessId, 'user@intelligent-intern.com'], "{$state} public join");
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-deleted-ended-hardening-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-iam9-12-deleted-ended-hardening-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $organizationId = (int) $pdo->query("SELECT id FROM organizations WHERE tenant_id = {$tenantId} ORDER BY id ASC LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_iam9_12_assert($tenantId > 0 && $organizationId > 0 && $standardUserId > 0, 'seed ids should exist');

    $orgOwnerUserId = videochat_iam9_12_create_user($pdo, $tenantId, 'iam9-12-org-owner@example.test', 'IAM9-12 Org Owner');
    $orgAdminUserId = videochat_iam9_12_create_user($pdo, $tenantId, 'iam9-12-org-admin@example.test', 'IAM9-12 Org Admin');
    videochat_iam9_12_attach_organization($pdo, $tenantId, $organizationId, $orgOwnerUserId, 'member');
    videochat_iam9_12_attach_organization($pdo, $tenantId, $organizationId, $orgAdminUserId, 'admin');

    videochat_iam9_12_assert_org_admin_denied_after_terminal_state(
        $pdo,
        $databasePath,
        $tenantId,
        $orgOwnerUserId,
        $orgAdminUserId,
        $standardUserId,
        'deleted'
    );
    videochat_iam9_12_assert_org_admin_denied_after_terminal_state(
        $pdo,
        $databasePath,
        $tenantId,
        $orgOwnerUserId,
        $orgAdminUserId,
        $standardUserId,
        'ended'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-deleted-ended-hardening-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-deleted-ended-hardening-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
