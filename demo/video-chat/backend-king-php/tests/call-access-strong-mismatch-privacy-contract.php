<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_strong_mismatch_privacy_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-strong-mismatch-privacy-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_strong_mismatch_privacy_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_strong_mismatch_privacy_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-strong-mismatch-privacy', PASSWORD_DEFAULT);
    videochat_call_access_strong_mismatch_privacy_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

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
    videochat_call_access_strong_mismatch_privacy_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_access_strong_mismatch_privacy_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-strong-mismatch-privacy-contract'{$tenantValue})
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

/**
 * @return array<string, mixed>
 */
function videochat_call_access_strong_mismatch_privacy_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, string> $needles
 */
function videochat_call_access_strong_mismatch_privacy_assert_no_needles(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $text = strtolower(trim($needle));
        if ($text === '') {
            continue;
        }
        videochat_call_access_strong_mismatch_privacy_assert(!str_contains($body, $text), "{$label} leaked {$needle}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-strong-mismatch-privacy-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-strong-mismatch-privacy-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    putenv('VIDEOCHAT_CALL_ACCESS_HOST_VERIFICATION_LIMIT=20');

    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_call_access_strong_mismatch_privacy_role_id($pdo, 'admin');
    $userRoleId = videochat_call_access_strong_mismatch_privacy_role_id($pdo, 'user');
    videochat_call_access_strong_mismatch_privacy_assert($defaultTenantId > 0, 'default tenant should exist');
    videochat_call_access_strong_mismatch_privacy_assert($adminRoleId > 0 && $userRoleId > 0, 'expected admin and user roles');

    $secret = 'strong' . bin2hex(random_bytes(5));
    $hostEmail = 'host-' . $secret . '@example.test';
    $hostName = 'Private Host ' . $secret;
    $targetEmail = 'target-' . $secret . '@example.test';
    $targetName = 'Foreign Invitee ' . $secret;
    $wrongEmail = 'wrong-' . $secret . '@example.test';
    $wrongName = 'Wrong Account ' . $secret;
    $externalEmail = 'external-host-' . $secret . '@example.test';
    $externalName = 'External Host ' . $secret;
    $callTitle = 'Strong Mismatch Private Call ' . $secret;
    $wrongHostName = 'Definitely Wrong Host ' . $secret;

    $hostUserId = videochat_call_access_strong_mismatch_privacy_create_user($pdo, $adminRoleId, $hostEmail, $hostName);
    $targetUserId = videochat_call_access_strong_mismatch_privacy_create_user($pdo, $userRoleId, $targetEmail, $targetName);
    $wrongUserId = videochat_call_access_strong_mismatch_privacy_create_user($pdo, $userRoleId, $wrongEmail, $wrongName);
    videochat_tenant_attach_user($pdo, $hostUserId, $defaultTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $targetUserId, $defaultTenantId, 'member');
    videochat_tenant_attach_user($pdo, $wrongUserId, $defaultTenantId, 'member');

    videochat_call_access_strong_mismatch_privacy_insert_session($pdo, 'sess_strong_mismatch_target', $targetUserId, $defaultTenantId);
    videochat_call_access_strong_mismatch_privacy_insert_session($pdo, 'sess_strong_mismatch_wrong', $wrongUserId, $defaultTenantId);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => $callTitle,
        'starts_at' => '2026-11-01T09:00:00Z',
        'ends_at' => '2026-11-01T10:00:00Z',
        'internal_participant_user_ids' => [$targetUserId, $wrongUserId],
        'external_participants' => [
            ['email' => $externalEmail, 'display_name' => $externalName],
        ],
    ], $defaultTenantId);
    videochat_call_access_strong_mismatch_privacy_assert((bool) ($createCall['ok'] ?? false), 'private call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_strong_mismatch_privacy_assert($callId !== '', 'private call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $defaultTenantId);
    videochat_call_access_strong_mismatch_privacy_assert((bool) ($access['ok'] ?? false), 'personalized access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_strong_mismatch_privacy_assert($accessId !== '', 'personalized access id should be present');

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
    $callAccessRoute = static function (
        string $suffix,
        string $method,
        array $headers,
        string $body = '',
        string $issuedSessionId = 'sess_strong_mismatch_unused'
    ) use ($accessId, $jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
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
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase,
            static fn (): string => $issuedSessionId
        );
        videochat_call_access_strong_mismatch_privacy_assert(is_array($response), "{$method} {$path} should return a response");
        return $response;
    };

    $secretNeedles = [
        $callId,
        $callTitle,
        $hostEmail,
        $hostName,
        $targetEmail,
        $targetName,
        $externalEmail,
        $externalName,
        'sess_strong_mismatch_wrong_host_should_not_issue',
        'sess_strong_mismatch_unverified_host_should_not_issue',
    ];

    $anonymousJoin = $callAccessRoute('/join', 'GET', ['User-Agent' => 'strong-mismatch-anonymous']);
    videochat_call_access_strong_mismatch_privacy_assert((int) ($anonymousJoin['status'] ?? 0) === 200, 'anonymous personalized link open should still resolve');

    $matchingJoin = $callAccessRoute('/join', 'GET', [
        'Authorization' => 'Bearer sess_strong_mismatch_target',
        'User-Agent' => 'strong-mismatch-matching-user',
    ]);
    videochat_call_access_strong_mismatch_privacy_assert((int) ($matchingJoin['status'] ?? 0) === 200, 'matching logged-in user should still resolve personalized link');
    $matchingPayload = videochat_call_access_strong_mismatch_privacy_decode($matchingJoin);
    videochat_call_access_strong_mismatch_privacy_assert(
        (string) (((($matchingPayload['result'] ?? [])['call'] ?? [])['id'] ?? '')) === $callId,
        'matching logged-in user should receive the call payload'
    );

    $wrongJoin = $callAccessRoute('/join', 'GET', [
        'Authorization' => 'Bearer sess_strong_mismatch_wrong',
        'User-Agent' => 'strong-mismatch-wrong-user',
    ]);
    videochat_call_access_strong_mismatch_privacy_assert((int) ($wrongJoin['status'] ?? 0) === 403, 'wrong logged-in user should not resolve foreign personalized link');
    $wrongJoinPayload = videochat_call_access_strong_mismatch_privacy_decode($wrongJoin);
    videochat_call_access_strong_mismatch_privacy_assert((string) (($wrongJoinPayload['error'] ?? [])['code'] ?? '') === 'call_access_forbidden', 'wrong join code mismatch');
    videochat_call_access_strong_mismatch_privacy_assert(
        (string) ((($wrongJoinPayload['error'] ?? [])['details'] ?? [])['mismatch'] ?? '') === 'strong_personalized_link',
        'wrong join should expose only generic strong-mismatch reason'
    );
    videochat_call_access_strong_mismatch_privacy_assert(
        (string) (((($wrongJoinPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'not_verified',
        'wrong join should require host verification without host data'
    );
    videochat_call_access_strong_mismatch_privacy_assert(!isset($wrongJoinPayload['result']), 'wrong join response must not include result payload');
    videochat_call_access_strong_mismatch_privacy_assert_no_needles($wrongJoin, $secretNeedles, 'wrong-user join response');

    $wrongHostSession = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_strong_mismatch_wrong',
            'Content-Type' => 'application/json',
            'User-Agent' => 'strong-mismatch-wrong-host',
        ],
        json_encode(['host_name' => $wrongHostName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_strong_mismatch_wrong_host_should_not_issue'
    );
    videochat_call_access_strong_mismatch_privacy_assert((int) ($wrongHostSession['status'] ?? 0) === 403, 'wrong host name should not issue a call session');
    $wrongHostPayload = videochat_call_access_strong_mismatch_privacy_decode($wrongHostSession);
    videochat_call_access_strong_mismatch_privacy_assert((string) (($wrongHostPayload['error'] ?? [])['code'] ?? '') === 'call_access_forbidden', 'wrong host code mismatch');
    videochat_call_access_strong_mismatch_privacy_assert(
        (string) (((($wrongHostPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'wrong_host_name',
        'wrong host response should expose only a field error'
    );
    videochat_call_access_strong_mismatch_privacy_assert_no_needles($wrongHostSession, $secretNeedles, 'wrong-host session response');
    $wrongHostRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_strong_mismatch_wrong_host_should_not_issue'")->fetchColumn();
    videochat_call_access_strong_mismatch_privacy_assert($wrongHostRows === 0, 'wrong host denial must not persist a session');
    $wrongHostReviewRows = (int) $pdo->query('SELECT COUNT(*) FROM call_access_review_flags WHERE subject_user_id = ' . (int) $wrongUserId . " AND status = 'open'")->fetchColumn();
    videochat_call_access_strong_mismatch_privacy_assert($wrongHostReviewRows >= 1, 'wrong host denial should create a manual-review flag');

    $correctHostSession = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_strong_mismatch_wrong',
            'Content-Type' => 'application/json',
            'User-Agent' => 'strong-mismatch-correct-host',
        ],
        json_encode([
            'verified_user_id' => $wrongUserId,
            'verified_session_id' => 'sess_strong_mismatch_wrong',
            'host_name' => $hostName,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'sess_strong_mismatch_correct_host_issued'
    );
    videochat_call_access_strong_mismatch_privacy_assert((int) ($correctHostSession['status'] ?? 0) === 200, 'correct host name should issue a logged-in account session');
    $correctHostPayload = videochat_call_access_strong_mismatch_privacy_decode($correctHostSession);
    videochat_call_access_strong_mismatch_privacy_assert((string) ($correctHostPayload['status'] ?? '') === 'ok', 'correct host response should be ok');
    videochat_call_access_strong_mismatch_privacy_assert((int) (((($correctHostPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $wrongUserId, 'correct host must continue as logged-in account');
    videochat_call_access_strong_mismatch_privacy_assert((string) (((($correctHostPayload['result'] ?? [])['user'] ?? [])['display_name'] ?? '')) === $wrongName, 'declining update baseline should leave logged-in account name unchanged');
    videochat_call_access_strong_mismatch_privacy_assert((string) (((($correctHostPayload['result'] ?? [])['call'] ?? [])['id'] ?? '')) === $callId, 'correct host response should reference the call');
    $correctSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_strong_mismatch_correct_host_issued' AND user_id = " . (int) $wrongUserId)->fetchColumn();
    videochat_call_access_strong_mismatch_privacy_assert($correctSessionRows === 1, 'correct host session must be bound to logged-in account');
    $hostVerifiedBinding = $pdo->query("SELECT user_id, host_verified_at FROM call_access_sessions WHERE session_id = 'sess_strong_mismatch_correct_host_issued' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_call_access_strong_mismatch_privacy_assert(is_array($hostVerifiedBinding), 'correct host call-access binding should exist');
    videochat_call_access_strong_mismatch_privacy_assert((int) ($hostVerifiedBinding['user_id'] ?? 0) === $wrongUserId, 'correct host binding must use logged-in account id');
    videochat_call_access_strong_mismatch_privacy_assert(trim((string) ($hostVerifiedBinding['host_verified_at'] ?? '')) !== '', 'correct host binding must mark host verification');
    $bindingValidation = videochat_validate_call_access_session_binding($pdo, 'sess_strong_mismatch_correct_host_issued', $wrongUserId);
    videochat_call_access_strong_mismatch_privacy_assert((bool) ($bindingValidation['ok'] ?? false), 'host-verified foreign personal-link session binding should validate');
    $wrongUserAfterCorrectHost = $pdo->query('SELECT display_name FROM users WHERE id = ' . (int) $wrongUserId)->fetch(PDO::FETCH_ASSOC);
    $targetUserAfterCorrectHost = $pdo->query('SELECT display_name FROM users WHERE id = ' . (int) $targetUserId)->fetch(PDO::FETCH_ASSOC);
    videochat_call_access_strong_mismatch_privacy_assert((string) ($wrongUserAfterCorrectHost['display_name'] ?? '') === $wrongName, 'correct host without update must leave logged-in account unchanged');
    videochat_call_access_strong_mismatch_privacy_assert((string) ($targetUserAfterCorrectHost['display_name'] ?? '') === $targetName, 'correct host without update must leave link account unchanged');
    videochat_call_access_strong_mismatch_privacy_assert_no_needles($correctHostSession, ['foreign-target-session'], 'correct-host session response');

    $unverifiedHostSession = $callAccessRoute(
        '/session',
        'POST',
        [
            'Authorization' => 'Bearer sess_strong_mismatch_wrong',
            'Content-Type' => 'application/json',
            'User-Agent' => 'strong-mismatch-unverified-host',
        ],
        '{}',
        'sess_strong_mismatch_unverified_host_should_not_issue'
    );
    videochat_call_access_strong_mismatch_privacy_assert((int) ($unverifiedHostSession['status'] ?? 0) === 403, 'unverified host should not issue a call session');
    $unverifiedPayload = videochat_call_access_strong_mismatch_privacy_decode($unverifiedHostSession);
    videochat_call_access_strong_mismatch_privacy_assert(
        (string) (((($unverifiedPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'not_verified',
        'unverified host response should expose only a field error'
    );
    videochat_call_access_strong_mismatch_privacy_assert_no_needles($unverifiedHostSession, $secretNeedles, 'unverified-host session response');
    $unverifiedRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_strong_mismatch_unverified_host_should_not_issue'")->fetchColumn();
    videochat_call_access_strong_mismatch_privacy_assert($unverifiedRows === 0, 'unverified host denial must not persist a session');

    $changedHostName = 'Changed Host ' . $secret;
    $pdo->prepare('UPDATE users SET display_name = :display_name, updated_at = :updated_at WHERE id = :id')->execute([
        ':display_name' => $changedHostName,
        ':updated_at' => gmdate('c'),
        ':id' => $hostUserId,
    ]);
    $hostNameVariants = [
        'capitalization' => strtoupper($hostName),
        'special characters' => "Dr. Host-Name O'Connor / QA? " . $secret,
        'spaces double name' => 'Mary Ann Van Buren ' . $secret,
        'ambiguous changed host' => 'Private Host',
    ];
    foreach ($hostNameVariants as $variantLabel => $variantHostName) {
        $sessionId = 'sess_strong_mismatch_' . str_replace(' ', '_', $variantLabel) . '_should_not_issue';
        $variantSession = $callAccessRoute(
            '/session',
            'POST',
            [
                'Authorization' => 'Bearer sess_strong_mismatch_wrong',
                'Content-Type' => 'application/json',
                'User-Agent' => 'strong-mismatch-host-' . str_replace(' ', '-', $variantLabel),
            ],
            json_encode(['host_name' => $variantHostName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $sessionId
        );
        videochat_call_access_strong_mismatch_privacy_assert((int) ($variantSession['status'] ?? 0) === 403, "{$variantLabel} host variant should not issue a call session");
        videochat_call_access_strong_mismatch_privacy_assert_no_needles($variantSession, [...$secretNeedles, $variantHostName, $changedHostName, $sessionId], "{$variantLabel} host variant response");
        $variantRows = (int) $pdo->query('SELECT COUNT(*) FROM sessions WHERE id = ' . $pdo->quote($sessionId))->fetchColumn();
        videochat_call_access_strong_mismatch_privacy_assert($variantRows === 0, "{$variantLabel} host variant denial must not persist a session");
    }
    $attemptRows = $pdo->query('SELECT * FROM call_access_host_verification_attempts')->fetchAll(PDO::FETCH_ASSOC);
    $attemptPayload = json_encode($attemptRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_access_strong_mismatch_privacy_assert(is_string($attemptPayload), 'host attempt rows should encode');
    videochat_call_access_strong_mismatch_privacy_assert(str_contains($attemptPayload, 'correct_host_name'), 'correct host verification attempt should be recorded');
    videochat_call_access_strong_mismatch_privacy_assert_no_needles(
        ['body' => $attemptPayload],
        [...array_values($hostNameVariants), $hostName, $changedHostName, $accessId],
        'host verification attempt storage'
    );
    $auditEvents = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 100]);
    $auditPayload = json_encode($auditEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_access_strong_mismatch_privacy_assert(is_string($auditPayload), 'audit events should encode');
    videochat_call_access_strong_mismatch_privacy_assert(str_contains($auditPayload, 'call_access_strong_mismatch_denied'), 'wrong host mismatch denial audit should be recorded');
    videochat_call_access_strong_mismatch_privacy_assert(str_contains($auditPayload, 'call_access_host_verification_succeeded'), 'host verification success audit should be recorded');
    videochat_call_access_strong_mismatch_privacy_assert(str_contains($auditPayload, '"account_update_offered":true'), 'success audit should record account-update offer');
    videochat_call_access_strong_mismatch_privacy_assert_no_needles(
        ['body' => $auditPayload],
        [$hostName, $wrongHostName, $changedHostName, $accessId, 'sess_strong_mismatch_correct_host_issued'],
        'host verification audit'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-strong-mismatch-privacy-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-strong-mismatch-privacy-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
