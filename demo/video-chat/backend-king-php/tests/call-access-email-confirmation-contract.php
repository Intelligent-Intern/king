<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_email_confirmation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-email-confirmation-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_email_confirmation_audit_payloads_by_type(array $auditRows): array
{
    $payloadsByType = [];
    foreach ($auditRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
        $payloadsByType[(string) ($row['event_type'] ?? '')][] = is_array($payload) ? $payload : [];
    }

    return $payloadsByType;
}

function videochat_call_access_email_confirmation_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_email_confirmation_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-email-confirmation', PASSWORD_DEFAULT);
    videochat_call_access_email_confirmation_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
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
    videochat_call_access_email_confirmation_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_access_email_confirmation_insert_session(
    PDO $pdo,
    string $sessionId,
    int $userId,
    int $tenantId,
    int $expiresInSeconds = 3600
): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-email-confirmation-contract'{$tenantValue})
SQL
    );
    $issuedAt = $expiresInSeconds <= 0 ? gmdate('c', time() - 3600) : gmdate('c', time() - 30);
    $expiresAt = gmdate('c', time() + $expiresInSeconds);
    $params = [
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => $issuedAt,
        ':expires_at' => $expiresAt,
    ];
    if ($tenantColumn !== '') {
        $params[':active_tenant_id'] = $tenantId;
    }
    $insert->execute($params);
}

function videochat_call_access_email_confirmation_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_access_email_confirmation_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch();
    return is_array($row) ? $row : [];
}

function videochat_call_access_email_confirmation_assert_no_needles(string $text, array $needles, string $label): void
{
    $body = strtolower($text);
    foreach ($needles as $needle) {
        $value = strtolower(trim((string) $needle));
        if ($value === '') {
            continue;
        }
        videochat_call_access_email_confirmation_assert(!str_contains($body, $value), "{$label} leaked {$needle}");
    }
}

function videochat_call_access_email_confirmation_insert_pending(
    PDO $pdo,
    string $token,
    int $tenantId,
    string $callId,
    string $accessId,
    int $userId,
    string $email,
    string $sessionId,
    string $displayName,
    string $expiresAt
): void {
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_account_update_confirmations(
    id, token_fingerprint, tenant_id, call_id, access_fingerprint, user_id, recipient_email_fingerprint,
    requesting_session_fingerprint, pending_payload_json, expires_at, consumed_at, superseded_at,
    superseded_by_fingerprint, created_at
) VALUES(
    :id, :token_fingerprint, :tenant_id, :call_id, :access_fingerprint, :user_id, :recipient_email_fingerprint,
    :requesting_session_fingerprint, :pending_payload_json, :expires_at, NULL, NULL,
    '', :created_at
)
SQL
    );
    $insert->execute([
        ':id' => videochat_call_access_account_confirmation_public_id(),
        ':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($token),
        ':tenant_id' => $tenantId,
        ':call_id' => $callId,
        ':access_fingerprint' => videochat_audit_fingerprint($accessId),
        ':user_id' => $userId,
        ':recipient_email_fingerprint' => videochat_audit_fingerprint($email),
        ':requesting_session_fingerprint' => videochat_call_access_account_confirmation_session_fingerprint($sessionId),
        ':pending_payload_json' => json_encode(['display_name' => $displayName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':expires_at' => $expiresAt,
        ':created_at' => gmdate('c', time() - 3600),
    ]);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-email-confirmation-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $routeSource = (string) file_get_contents(__DIR__ . '/../http/module_calls_access.php');
    videochat_call_access_email_confirmation_assert(
        str_contains($routeSource, "['session_id' => videochat_call_access_route_session_id"),
        'confirm route must pass authenticated session id into account-update confirmation'
    );

    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_LIMIT=8');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_WINDOW_SECONDS=900');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_TTL_SECONDS=3600');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER=1');

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-email-confirmation-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_call_access_email_confirmation_role_id($pdo, 'admin');
    $userRoleId = videochat_call_access_email_confirmation_role_id($pdo, 'user');
    videochat_call_access_email_confirmation_assert($defaultTenantId > 0, 'default tenant should exist');
    videochat_call_access_email_confirmation_assert($adminRoleId > 0 && $userRoleId > 0, 'expected admin and user roles');

    $secret = 'confirm' . bin2hex(random_bytes(5));
    $hostEmail = 'host-' . $secret . '@example.test';
    $hostName = 'Confirmation Host ' . $secret;
    $linkEmail = 'link-target-' . $secret . '@example.test';
    $linkName = 'Foreign Link Target ' . $secret;
    $currentEmail = 'current-' . $secret . '@example.test';
    $currentName = 'Current Account ' . $secret;
    $confirmedName = 'Re Entered Confirmed Name ' . $secret;
    $supersededPendingName = 'Superseded Pending Name ' . $secret;
    $latestPendingName = 'Latest Pending Name ' . $secret;

    $hostUserId = videochat_call_access_email_confirmation_create_user($pdo, $adminRoleId, $hostEmail, $hostName);
    $linkUserId = videochat_call_access_email_confirmation_create_user($pdo, $userRoleId, $linkEmail, $linkName);
    $currentUserId = videochat_call_access_email_confirmation_create_user($pdo, $userRoleId, $currentEmail, $currentName);
    videochat_tenant_attach_user($pdo, $hostUserId, $defaultTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $linkUserId, $defaultTenantId, 'member');
    videochat_tenant_attach_user($pdo, $currentUserId, $defaultTenantId, 'member');
    videochat_call_access_email_confirmation_insert_session($pdo, 'sess_confirmation_current', $currentUserId, $defaultTenantId);
    videochat_call_access_email_confirmation_insert_session($pdo, 'sess_confirmation_browser_b', $currentUserId, $defaultTenantId);
    videochat_call_access_email_confirmation_insert_session($pdo, 'sess_confirmation_expired_pending', $currentUserId, $defaultTenantId, -60);
    videochat_call_access_email_confirmation_insert_session($pdo, 'sess_confirmation_link_target', $linkUserId, $defaultTenantId);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => 'Confirmation Private Call ' . $secret,
        'starts_at' => '2026-12-02T09:00:00Z',
        'ends_at' => '2026-12-02T10:00:00Z',
        'internal_participant_user_ids' => [$linkUserId],
        'external_participants' => [],
    ], $defaultTenantId);
    videochat_call_access_email_confirmation_assert((bool) ($createCall['ok'] ?? false), 'private call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $linkUserId,
    ], $defaultTenantId);
    videochat_call_access_email_confirmation_assert((bool) ($access['ok'] ?? false), 'personalized access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_email_confirmation_assert($accessId !== '', 'access id should be present');

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
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    $callRoute = static function (
        string $path,
        string $method,
        array $headers,
        string $body = ''
    ) use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
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
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => 'sess_confirmation_route_should_not_issue'
        );
        videochat_call_access_email_confirmation_assert(is_array($response), "{$method} {$path} should return a response");
        return $response;
    };

    $firstRequest = videochat_call_access_request_account_update_confirmation(
        $pdo,
        $accessId,
        $currentUserId,
        ['display_name' => $confirmedName],
        ['session_id' => 'sess_confirmation_current']
    );
    videochat_call_access_email_confirmation_assert((bool) ($firstRequest['ok'] ?? false), 'first confirmation request should be accepted');
    videochat_call_access_email_confirmation_assert((string) ($firstRequest['recipient_email'] ?? '') === $currentEmail, 'confirmation must be sent to current logged-in email');
    videochat_call_access_email_confirmation_assert((bool) ($firstRequest['sent_to_logged_in_account'] ?? false), 'confirmation should mark logged-in recipient');
    videochat_call_access_email_confirmation_assert((bool) ($firstRequest['sent_to_link_account'] ?? true) === false, 'confirmation must not go to link account');
    videochat_call_access_email_confirmation_assert((int) ($firstRequest['superseded_pending_count'] ?? -1) === 0, 'first confirmation should not supersede another pending row');
    $firstToken = (string) ($firstRequest['token'] ?? '');
    videochat_call_access_email_confirmation_assert(str_starts_with($firstToken, 'cau_'), 'first request should create a call-access account token');

    $beforeConfirmUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($beforeConfirmUser['display_name'] ?? '') === $currentName, 'account data must not update before confirmation');
    $sessionUserBefore = (int) $pdo->query("SELECT user_id FROM sessions WHERE id = 'sess_confirmation_current' LIMIT 1")->fetchColumn();
    videochat_call_access_email_confirmation_assert($sessionUserBefore === $currentUserId, 'current session must remain bound to current account before confirmation');

    $expiredConfirm = $callRoute(
        '/api/call-access/account-update-confirmations/' . $firstToken . '/confirm',
        'POST',
        [
            'Authorization' => 'Bearer sess_confirmation_expired_pending',
            'User-Agent' => 'call-access-email-confirmation-expired-session',
        ]
    );
    videochat_call_access_email_confirmation_assert((int) ($expiredConfirm['status'] ?? 0) === 401, 'expired pending-confirmation session should be rejected');
    $expiredPayload = videochat_call_access_email_confirmation_decode($expiredConfirm);
    videochat_call_access_email_confirmation_assert((string) (($expiredPayload['error'] ?? [])['code'] ?? '') === 'auth_failed', 'expired pending-confirmation code mismatch');
    videochat_call_access_email_confirmation_assert((string) ((($expiredPayload['error'] ?? [])['details'] ?? [])['reason'] ?? '') === 'expired_session', 'expired pending-confirmation reason mismatch');
    $afterExpiredSessionUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterExpiredSessionUser['display_name'] ?? '') === $currentName, 'expired pending-confirmation session must not update account data');
    $pendingConsumed = $pdo->prepare('SELECT coalesce(consumed_at, \'\') FROM call_access_account_update_confirmations WHERE token_fingerprint = :token_fingerprint LIMIT 1');
    $pendingConsumed->execute([':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($firstToken)]);
    videochat_call_access_email_confirmation_assert((string) $pendingConsumed->fetchColumn() === '', 'expired pending-confirmation session must not consume the token');

    $wrongAccountConfirm = videochat_call_access_confirm_account_update($pdo, $firstToken, $linkUserId, ['session_id' => 'sess_confirmation_link_target']);
    videochat_call_access_email_confirmation_assert((bool) ($wrongAccountConfirm['ok'] ?? true) === false, 'confirmation token cannot be used by another account');
    videochat_call_access_email_confirmation_assert((string) ($wrongAccountConfirm['reason'] ?? '') === 'forbidden', 'wrong-account confirmation reason mismatch');
    videochat_call_access_email_confirmation_assert((string) (($wrongAccountConfirm['errors'] ?? [])['token'] ?? '') === 'account_bound', 'wrong-account field mismatch');

    $afterDeniedUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterDeniedUser['display_name'] ?? '') === $currentName, 'denied confirmation attempts must not update data');
    $firstConsumedBefore = $pdo->prepare('SELECT coalesce(consumed_at, \'\') FROM call_access_account_update_confirmations WHERE token_fingerprint = :token_fingerprint LIMIT 1');
    $firstConsumedBefore->execute([':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($firstToken)]);
    videochat_call_access_email_confirmation_assert((string) $firstConsumedBefore->fetchColumn() === '', 'denied confirmation attempts must not consume token');

    $confirmResponse = $callRoute(
        '/api/call-access/account-update-confirmations/' . $firstToken . '/confirm',
        'POST',
        [
            'Authorization' => 'Bearer sess_confirmation_browser_b',
            'User-Agent' => 'call-access-email-confirmation-browser-b',
        ]
    );
    videochat_call_access_email_confirmation_assert((int) ($confirmResponse['status'] ?? 0) === 200, 'another browser session for same account should confirm');
    $confirmPayload = videochat_call_access_email_confirmation_decode($confirmResponse);
    videochat_call_access_email_confirmation_assert((string) (($confirmPayload['result'] ?? [])['state'] ?? '') === 'confirmed', 'browser-b confirmation state mismatch');
    videochat_call_access_email_confirmation_assert((int) (((($confirmPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $currentUserId, 'browser-b confirmation user mismatch');
    videochat_call_access_email_confirmation_assert_no_needles((string) ($confirmResponse['body'] ?? ''), [$linkEmail, $linkName, $hostEmail, $hostName, $accessId, $firstToken], 'browser-b confirmation response');
    $afterConfirmUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterConfirmUser['display_name'] ?? '') === $confirmedName, 'confirmed display name mismatch');
    videochat_call_access_email_confirmation_assert((string) ($afterConfirmUser['email'] ?? '') === $currentEmail, 'confirmation must not change email');
    $linkTargetAfterConfirm = videochat_call_access_email_confirmation_user($pdo, $linkUserId);
    videochat_call_access_email_confirmation_assert((string) ($linkTargetAfterConfirm['display_name'] ?? '') === $linkName, 'confirmation must not update link target account');
    $sessionUserAfter = (int) $pdo->query("SELECT user_id FROM sessions WHERE id = 'sess_confirmation_current' LIMIT 1")->fetchColumn();
    videochat_call_access_email_confirmation_assert($sessionUserAfter === $currentUserId, 'confirmation must not rebind the current session');
    $browserBSessionAfter = (int) $pdo->query("SELECT user_id FROM sessions WHERE id = 'sess_confirmation_browser_b' LIMIT 1")->fetchColumn();
    videochat_call_access_email_confirmation_assert($browserBSessionAfter === $currentUserId, 'confirmation must not rebind a parallel session');

    $replay = videochat_call_access_confirm_account_update($pdo, $firstToken, $currentUserId, ['session_id' => 'sess_confirmation_current']);
    videochat_call_access_email_confirmation_assert((bool) ($replay['ok'] ?? true) === false, 'confirmation token replay should fail');
    videochat_call_access_email_confirmation_assert((string) ($replay['reason'] ?? '') === 'conflict', 'replay reason mismatch');
    videochat_call_access_email_confirmation_assert((string) (($replay['errors'] ?? [])['token'] ?? '') === 'already_consumed', 'replay field mismatch');

    $olderRequest = videochat_call_access_request_account_update_confirmation(
        $pdo,
        $accessId,
        $currentUserId,
        ['display_name' => $supersededPendingName],
        ['session_id' => 'sess_confirmation_current']
    );
    videochat_call_access_email_confirmation_assert((bool) ($olderRequest['ok'] ?? false), 'older pending confirmation request should be accepted');
    $olderToken = (string) ($olderRequest['token'] ?? '');
    videochat_call_access_email_confirmation_assert($olderToken !== '' && $olderToken !== $firstToken, 'older pending token should be distinct');

    $newerRequest = videochat_call_access_request_account_update_confirmation(
        $pdo,
        $accessId,
        $currentUserId,
        ['display_name' => $latestPendingName],
        ['session_id' => 'sess_confirmation_current']
    );
    videochat_call_access_email_confirmation_assert((bool) ($newerRequest['ok'] ?? false), 'newer confirmation request should be accepted');
    videochat_call_access_email_confirmation_assert((int) ($newerRequest['superseded_pending_count'] ?? 0) === 1, 'newer request should supersede exactly one pending token');
    $newerToken = (string) ($newerRequest['token'] ?? '');
    videochat_call_access_email_confirmation_assert($newerToken !== '' && $newerToken !== $olderToken, 'newer pending token should be distinct');

    $supersededRowQuery = $pdo->prepare(
        'SELECT coalesce(superseded_at, \'\') AS superseded_at, coalesce(superseded_by_fingerprint, \'\') AS superseded_by_fingerprint FROM call_access_account_update_confirmations WHERE token_fingerprint = :token_fingerprint LIMIT 1'
    );
    $supersededRowQuery->execute([':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($olderToken)]);
    $supersededRow = $supersededRowQuery->fetch();
    videochat_call_access_email_confirmation_assert(is_array($supersededRow), 'superseded confirmation row should exist');
    videochat_call_access_email_confirmation_assert((string) ($supersededRow['superseded_at'] ?? '') !== '', 'older pending token should be marked superseded');
    videochat_call_access_email_confirmation_assert((string) ($supersededRow['superseded_by_fingerprint'] ?? '') === videochat_call_access_account_confirmation_token_fingerprint($newerToken), 'superseded row must point to newer token fingerprint');
    videochat_call_access_email_confirmation_assert((string) ($supersededRow['superseded_by_fingerprint'] ?? '') !== $newerToken, 'superseded row must not store raw newer token');

    $supersededConfirm = videochat_call_access_confirm_account_update($pdo, $olderToken, $currentUserId, ['session_id' => 'sess_confirmation_current']);
    videochat_call_access_email_confirmation_assert((bool) ($supersededConfirm['ok'] ?? true) === false, 'superseded confirmation should fail closed');
    videochat_call_access_email_confirmation_assert((string) ($supersededConfirm['reason'] ?? '') === 'conflict', 'superseded reason mismatch');
    videochat_call_access_email_confirmation_assert((string) (($supersededConfirm['errors'] ?? [])['token'] ?? '') === 'superseded', 'superseded field mismatch');
    $afterSupersededUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterSupersededUser['display_name'] ?? '') === $confirmedName, 'superseded confirmation must not update data');

    $newerConfirm = videochat_call_access_confirm_account_update($pdo, $newerToken, $currentUserId, ['session_id' => 'sess_confirmation_current']);
    videochat_call_access_email_confirmation_assert((bool) ($newerConfirm['ok'] ?? false), 'newer pending confirmation should confirm');
    $afterNewerUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterNewerUser['display_name'] ?? '') === $latestPendingName, 'newer pending confirmation should apply latest payload');

    $newerReplay = videochat_call_access_confirm_account_update($pdo, $newerToken, $currentUserId, ['session_id' => 'sess_confirmation_current']);
    videochat_call_access_email_confirmation_assert((bool) ($newerReplay['ok'] ?? true) === false, 'duplicate concurrent confirmation should fail after first consume');
    videochat_call_access_email_confirmation_assert((string) ($newerReplay['reason'] ?? '') === 'conflict', 'duplicate concurrent reason mismatch');
    videochat_call_access_email_confirmation_assert((string) (($newerReplay['errors'] ?? [])['token'] ?? '') === 'already_consumed', 'duplicate concurrent field mismatch');

    $expiredToken = 'cau_expired_' . bin2hex(random_bytes(12));
    videochat_call_access_email_confirmation_insert_pending(
        $pdo,
        $expiredToken,
        $defaultTenantId,
        $callId,
        $accessId,
        $currentUserId,
        $currentEmail,
        'sess_confirmation_current',
        'Expired Name ' . $secret,
        gmdate('c', time() - 60)
    );
    $expired = videochat_call_access_confirm_account_update($pdo, $expiredToken, $currentUserId, ['session_id' => 'sess_confirmation_current']);
    videochat_call_access_email_confirmation_assert((bool) ($expired['ok'] ?? true) === false, 'expired confirmation should fail');
    videochat_call_access_email_confirmation_assert((string) ($expired['reason'] ?? '') === 'expired', 'expired reason mismatch');
    $expiredConsumed = $pdo->prepare('SELECT coalesce(consumed_at, \'\') FROM call_access_account_update_confirmations WHERE token_fingerprint = :token_fingerprint LIMIT 1');
    $expiredConsumed->execute([':token_fingerprint' => videochat_call_access_account_confirmation_token_fingerprint($expiredToken)]);
    videochat_call_access_email_confirmation_assert((string) $expiredConsumed->fetchColumn() === '', 'expired confirmation must not consume token');
    $afterExpiredUser = videochat_call_access_email_confirmation_user($pdo, $currentUserId);
    videochat_call_access_email_confirmation_assert((string) ($afterExpiredUser['display_name'] ?? '') === $latestPendingName, 'expired confirmation must not update data');

    $confirmationRows = $pdo->query(
        'SELECT id, token_fingerprint, recipient_email_fingerprint, requesting_session_fingerprint, access_fingerprint, superseded_by_fingerprint, pending_payload_json FROM call_access_account_update_confirmations'
    )->fetchAll();
    $confirmationDump = json_encode($confirmationRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    videochat_call_access_email_confirmation_assert_no_needles(
        $confirmationDump,
        [$firstToken, $olderToken, $newerToken, $expiredToken, $accessId, $linkEmail, $hostEmail, $currentEmail, 'sess_confirmation_current', 'sess_confirmation_browser_b', 'sess_confirmation_expired_pending', 'sess_confirmation_link_target'],
        'confirmation storage'
    );
    videochat_call_access_email_confirmation_assert(str_contains($confirmationDump, videochat_audit_fingerprint($firstToken)), 'confirmation storage should keep token fingerprint');
    videochat_call_access_email_confirmation_assert(str_contains($confirmationDump, videochat_audit_fingerprint($accessId)), 'confirmation storage should keep link fingerprint');
    videochat_call_access_email_confirmation_assert(str_contains($confirmationDump, videochat_audit_fingerprint($currentEmail)), 'confirmation storage should keep recipient fingerprint');
    videochat_call_access_email_confirmation_assert(str_contains($confirmationDump, videochat_call_access_account_confirmation_session_fingerprint('sess_confirmation_current')), 'confirmation storage should keep request session fingerprint');

    $auditRequested = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_update_confirmation_requested'")->fetchColumn();
    $auditConfirmed = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_update_confirmed'")->fetchColumn();
    $auditRateLimited = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_update_confirmation_rate_limited'")->fetchColumn();
    $auditFailed = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_update_confirmation_failed'")->fetchColumn();
    $auditSuperseded = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_update_confirmation_superseded'")->fetchColumn();
    videochat_call_access_email_confirmation_assert($auditRequested >= 3, 'confirmation requests should be audit-logged');
    videochat_call_access_email_confirmation_assert($auditConfirmed >= 2, 'confirmation success should be audit-logged');
    videochat_call_access_email_confirmation_assert($auditRateLimited === 0, 'focused race contract should not hit rate limiting');
    videochat_call_access_email_confirmation_assert($auditFailed >= 5, 'failed confirmation attempts should be audit-logged');
    videochat_call_access_email_confirmation_assert($auditSuperseded >= 1, 'superseded confirmation should be audit-logged');
    $auditRows = $pdo->query('SELECT event_type, resource_fingerprint, session_fingerprint, payload_json FROM videochat_audit_events')->fetchAll();
    $auditPayloadsByType = videochat_call_access_email_confirmation_audit_payloads_by_type($auditRows);
    $confirmedPayload = (array) (($auditPayloadsByType['call_access_account_update_confirmed'] ?? [])[0] ?? []);
    videochat_call_access_email_confirmation_assert((string) ($confirmedPayload['audit_scope'] ?? '') === 'iam_call_access', 'confirmed account-update audit scope mismatch');
    videochat_call_access_email_confirmation_assert((string) ($confirmedPayload['action'] ?? '') === 'confirm_account_update', 'confirmed account-update audit action mismatch');
    videochat_call_access_email_confirmation_assert((string) ($confirmedPayload['result'] ?? '') === 'confirmed', 'confirmed account-update audit result mismatch');
    $failurePayloads = (array) ($auditPayloadsByType['call_access_account_update_confirmation_failed'] ?? []);
    $failureReasons = [];
    foreach ($failurePayloads as $failurePayload) {
        if (!is_array($failurePayload)) {
            continue;
        }
        videochat_call_access_email_confirmation_assert((string) ($failurePayload['audit_scope'] ?? '') === 'iam_call_access', 'failed account-update audit scope mismatch');
        videochat_call_access_email_confirmation_assert((string) ($failurePayload['action'] ?? '') === 'confirm_account_update', 'failed account-update audit action mismatch');
        videochat_call_access_email_confirmation_assert((string) ($failurePayload['result'] ?? '') === 'failed', 'failed account-update audit result mismatch');
        videochat_call_access_email_confirmation_assert(($failurePayload['token_logged'] ?? true) === false, 'failed account-update audit must not log tokens');
        videochat_call_access_email_confirmation_assert(($failurePayload['recipient_email_logged'] ?? true) === false, 'failed account-update audit must not log recipient emails');
        $failureReasons[] = (string) ($failurePayload['failure_reason'] ?? '');
    }
    foreach (['account_bound', 'session_bound', 'already_consumed', 'superseded', 'expired'] as $expectedFailureReason) {
        videochat_call_access_email_confirmation_assert(
            in_array($expectedFailureReason, $failureReasons, true),
            "failed confirmation audit should include {$expectedFailureReason}"
        );
    }
    $auditDump = json_encode($auditRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    videochat_call_access_email_confirmation_assert_no_needles(
        $auditDump,
        [$firstToken, $olderToken, $newerToken, $expiredToken, $accessId, $linkEmail, $hostEmail, $currentEmail, 'sess_confirmation_current', 'sess_confirmation_browser_b', 'sess_confirmation_expired_pending', 'sess_confirmation_link_target'],
        'confirmation audit'
    );

    fwrite(STDOUT, "[call-access-email-confirmation-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-email-confirmation-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_LIMIT');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_UPDATE_CONFIRMATION_WINDOW_SECONDS');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_TTL_SECONDS');
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_INVALIDATE_OLDER');
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
