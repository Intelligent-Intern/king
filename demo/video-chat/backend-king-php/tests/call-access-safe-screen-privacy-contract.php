<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_safe_screen_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-safe-screen-privacy-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_safe_screen_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_access_safe_screen_create_user(
    PDO $pdo,
    PDOStatement $createUser,
    int $roleId,
    string $email,
    string $displayName
): int {
    $passwordHash = password_hash('call-access-safe-screen-privacy', PASSWORD_DEFAULT);
    videochat_call_access_safe_screen_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');
    $createUser->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_access_safe_screen_insert_session(PDO $pdo, string $sessionId, int $userId): void
{
    $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-safe-screen-privacy-contract')
SQL
    )->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

function videochat_call_access_safe_screen_assert_redacted(array $response, array $needles, string $label): void
{
    $body = (string) ($response['body'] ?? '');
    $lowerBody = strtolower($body);
    foreach ($needles as $needle) {
        $text = strtolower(trim((string) $needle));
        if ($text === '') {
            continue;
        }
        videochat_call_access_safe_screen_assert(
            !str_contains($lowerBody, $text),
            "{$label} leaked sensitive value {$needle}"
        );
    }

    $payload = videochat_call_access_safe_screen_decode($response);
    videochat_call_access_safe_screen_assert(!isset($payload['result']), "{$label} must not expose result payload");
    foreach (['access_link', 'call', 'target_user', 'target_hint', 'session', 'user'] as $key) {
        videochat_call_access_safe_screen_assert(!array_key_exists($key, $payload), "{$label} must not expose {$key}");
    }
}

function videochat_call_access_safe_screen_assert_error(
    array $response,
    int $expectedStatus,
    string $expectedCode,
    array $needles,
    string $label
): void {
    videochat_call_access_safe_screen_assert((int) ($response['status'] ?? 0) === $expectedStatus, "{$label} status mismatch");
    $payload = videochat_call_access_safe_screen_decode($response);
    videochat_call_access_safe_screen_assert(
        (string) (($payload['error'] ?? [])['code'] ?? '') === $expectedCode,
        "{$label} error code mismatch"
    );
    videochat_call_access_safe_screen_assert_redacted($response, $needles, $label);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-safe-screen-privacy-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-safe-screen-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_access_safe_screen_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_safe_screen_assert($userRoleId > 0, 'expected user role');

    $secret = 'safe_screen_' . bin2hex(random_bytes(5));
    $targetEmail = 'target-' . $secret . '@example.invalid';
    $targetName = 'Target ' . $secret;
    $disabledTargetEmail = 'disabled-target-' . $secret . '@example.invalid';
    $disabledTargetName = 'Disabled Target ' . $secret;
    $wrongEmail = 'wrong-' . $secret . '@example.invalid';
    $wrongName = 'Wrong ' . $secret;
    $externalEmail = 'external-' . $secret . '@example.invalid';
    $externalName = 'External ' . $secret;
    $activeTitle = 'Active Private Safe Screen ' . $secret;
    $endedTitle = 'Ended Private Safe Screen ' . $secret;
    $expiredTitle = 'Expired Private Safe Screen ' . $secret;
    $disabledTitle = 'Disabled Private Safe Screen ' . $secret;

    $createUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $targetUserId = videochat_call_access_safe_screen_create_user($pdo, $createUser, $userRoleId, $targetEmail, $targetName);
    $disabledTargetUserId = videochat_call_access_safe_screen_create_user($pdo, $createUser, $userRoleId, $disabledTargetEmail, $disabledTargetName);
    $wrongUserId = videochat_call_access_safe_screen_create_user($pdo, $createUser, $userRoleId, $wrongEmail, $wrongName);
    videochat_call_access_safe_screen_assert($targetUserId > 0 && $disabledTargetUserId > 0 && $wrongUserId > 0, 'expected inserted users');
    videochat_call_access_safe_screen_insert_session($pdo, 'sess_safe_screen_wrong_current', $wrongUserId);

    $createPersonalLink = static function (string $title, int $participantUserId, ?string $expiresAt = null) use (
        $pdo,
        $adminUserId,
        $externalEmail,
        $externalName
    ): array {
        $created = videochat_create_call($pdo, $adminUserId, [
            'title' => $title,
            'starts_at' => '2026-10-01T09:00:00Z',
            'ends_at' => '2026-10-01T10:00:00Z',
            'internal_participant_user_ids' => [$participantUserId],
            'external_participants' => [
                ['email' => $externalEmail, 'display_name' => $externalName],
            ],
        ]);
        videochat_call_access_safe_screen_assert((bool) ($created['ok'] ?? false), "call {$title} should be created");
        $callId = (string) (($created['call'] ?? [])['id'] ?? '');
        videochat_call_access_safe_screen_assert($callId !== '', "call {$title} id should be present");

        $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
            'link_kind' => 'personal',
            'participant_user_id' => $participantUserId,
        ]);
        videochat_call_access_safe_screen_assert((bool) ($access['ok'] ?? false), "access link {$title} should be created");
        $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
        videochat_call_access_safe_screen_assert($accessId !== '', "access link {$title} id should be present");

        if (is_string($expiresAt) && $expiresAt !== '') {
            $pdo->prepare('UPDATE call_access_links SET expires_at = :expires_at WHERE id = :id')
                ->execute([':id' => $accessId, ':expires_at' => $expiresAt]);
        }

        return [$callId, $accessId];
    };

    [$activeCallId, $activeAccessId] = $createPersonalLink($activeTitle, $targetUserId);
    [$endedCallId, $endedAccessId] = $createPersonalLink($endedTitle, $targetUserId);
    [$expiredCallId, $expiredAccessId] = $createPersonalLink($expiredTitle, $targetUserId, gmdate('c', time() - 60));
    [$disabledCallId, $disabledAccessId] = $createPersonalLink($disabledTitle, $disabledTargetUserId);
    $pdo->prepare("UPDATE calls SET status = 'ended', updated_at = :updated_at WHERE id = :id")
        ->execute([':id' => $endedCallId, ':updated_at' => gmdate('c')]);
    $pdo->prepare("UPDATE users SET status = 'disabled', updated_at = :updated_at WHERE id = :id")
        ->execute([':id' => $disabledTargetUserId, ':updated_at' => gmdate('c')]);

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

    $route = static function (
        string $accessId,
        string $suffix,
        string $method,
        array $headers = [],
        string $body = '',
        ?callable $issuer = null
    ) use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
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
            $issuer
        );
        videochat_call_access_safe_screen_assert(is_array($response), "{$method} {$path} should return response");
        return $response;
    };

    $protocolNeedles = [
        'expired-owner-offer-sdp-' . $secret,
        'candidate:expired-private-ice-' . $secret,
        'turn:expired-private-token-' . $secret,
        'media-token-' . $secret,
        'whiteboard-' . $secret,
        'launch-token-' . $secret,
        'cookie-secret-' . $secret,
    ];
    $sharedNeedles = [
        $targetEmail,
        $targetName,
        $disabledTargetEmail,
        $disabledTargetName,
        $wrongEmail,
        $wrongName,
        $externalEmail,
        $externalName,
        $activeCallId,
        $activeAccessId,
        $activeTitle,
        $endedCallId,
        $endedAccessId,
        $endedTitle,
        $expiredCallId,
        $expiredAccessId,
        $expiredTitle,
        $disabledCallId,
        $disabledAccessId,
        $disabledTitle,
        'sess_safe_screen_wrong_current',
        'sess_safe_screen_should_not_issue',
        ...$protocolNeedles,
    ];

    $guessedAccessId = '11111111-1111-4111-8111-111111111111';
    videochat_call_access_safe_screen_assert_error(
        $route($guessedAccessId, '/join', 'GET'),
        404,
        'call_access_not_found',
        [...$sharedNeedles, $guessedAccessId],
        'guessed join response'
    );

    videochat_call_access_safe_screen_assert_error(
        $route($expiredAccessId, '/join', 'GET'),
        410,
        'call_access_expired',
        $sharedNeedles,
        'expired join response'
    );

    videochat_call_access_safe_screen_assert_error(
        $route($endedAccessId, '/join', 'GET'),
        409,
        'call_access_conflict',
        $sharedNeedles,
        'ended join response'
    );

    videochat_call_access_safe_screen_assert_error(
        $route($disabledAccessId, '/join', 'GET'),
        404,
        'call_access_not_found',
        $sharedNeedles,
        'disabled-target join response'
    );

    $issuerCalls = 0;
    videochat_call_access_safe_screen_assert_error(
        $route(
            $expiredAccessId,
            '/session',
            'POST',
            ['User-Agent' => 'safe-screen-expired-session', 'Content-Type' => 'application/json'],
            '{}',
            static function () use (&$issuerCalls): string {
                $issuerCalls += 1;
                return 'sess_safe_screen_should_not_issue_expired';
            }
        ),
        410,
        'call_access_expired',
        $sharedNeedles,
        'expired session response'
    );

    videochat_call_access_safe_screen_assert_error(
        $route(
            $activeAccessId,
            '/join',
            'GET',
            ['Authorization' => 'Bearer sess_safe_screen_wrong_current'],
            ''
        ),
        403,
        'call_access_forbidden',
        $sharedNeedles,
        'wrong-account join response'
    );

    videochat_call_access_safe_screen_assert_error(
        $route(
            $activeAccessId,
            '/session',
            'POST',
            ['Authorization' => 'Bearer sess_safe_screen_wrong_current', 'User-Agent' => 'safe-screen-wrong-account'],
            '{}',
            static function () use (&$issuerCalls): string {
                $issuerCalls += 1;
                return 'sess_safe_screen_should_not_issue_wrong_account';
            }
        ),
        403,
        'call_access_forbidden',
        $sharedNeedles,
        'wrong-account session response'
    );
    videochat_call_access_safe_screen_assert($issuerCalls === 0, 'denied safe-screen paths must not issue sessions');
    $issuedRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id LIKE 'sess_safe_screen_should_not_issue%'")->fetchColumn();
    videochat_call_access_safe_screen_assert($issuedRows === 0, 'denied safe-screen paths must not persist sessions');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-safe-screen-privacy-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-safe-screen-privacy-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
