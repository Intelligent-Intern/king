<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_access_privacy_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-privacy-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_privacy_create_user(PDO $pdo, PDOStatement $createUser, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-access-privacy', PASSWORD_DEFAULT);
    videochat_call_access_privacy_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');
    $createUser->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * @return array<string, mixed>
 */
function videochat_call_access_privacy_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, string> $needles
 */
function videochat_call_access_privacy_assert_body_has_no_needles(array $response, array $needles, string $label): void
{
    $body = (string) ($response['body'] ?? '');
    $lowerBody = strtolower($body);
    foreach ($needles as $needle) {
        $normalizedNeedle = strtolower(trim($needle));
        if ($normalizedNeedle === '') {
            continue;
        }
        videochat_call_access_privacy_assert(
            !str_contains($lowerBody, $normalizedNeedle),
            $label . ' leaked sensitive value: ' . $needle
        );
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-privacy-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-privacy-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_access_privacy_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_privacy_assert($userRoleId > 0, 'expected user role');

    $secret = 'privacy' . bin2hex(random_bytes(5));
    $targetEmail = 'target-' . $secret . '@example.test';
    $targetName = 'Target ' . $secret;
    $wrongEmail = 'wrong-' . $secret . '@example.test';
    $wrongName = 'Wrong ' . $secret;
    $externalEmail = 'external-' . $secret . '@example.test';
    $externalName = 'External ' . $secret;
    $callTitle = 'Private Call ' . $secret;

    $createUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $targetUserId = videochat_call_access_privacy_create_user($pdo, $createUser, $userRoleId, $targetEmail, $targetName);
    $wrongUserId = videochat_call_access_privacy_create_user($pdo, $createUser, $userRoleId, $wrongEmail, $wrongName);
    videochat_call_access_privacy_assert($targetUserId > 0 && $wrongUserId > 0, 'expected inserted users');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => $callTitle,
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$targetUserId],
        'external_participants' => [
            ['email' => $externalEmail, 'display_name' => $externalName],
        ],
    ]);
    videochat_call_access_privacy_assert((bool) ($createCall['ok'] ?? false), 'private call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_privacy_assert($callId !== '', 'private call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ]);
    videochat_call_access_privacy_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_privacy_assert($accessId !== '', 'personal access id should be present');

    $pdo->prepare("UPDATE users SET status = 'disabled', updated_at = :updated_at WHERE id = :id")
        ->execute([':id' => $targetUserId, ':updated_at' => gmdate('c')]);

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

    $secretNeedles = [
        $callId,
        $callTitle,
        $targetEmail,
        $targetName,
        $wrongEmail,
        $wrongName,
        $externalEmail,
        $externalName,
    ];

    $guessedAccessId = '11111111-1111-4111-8111-111111111111';
    $guessedJoinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $guessedAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $guessedAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_privacy_assert(is_array($guessedJoinResponse), 'guessed join response should be an array');
    videochat_call_access_privacy_assert((int) ($guessedJoinResponse['status'] ?? 0) === 404, 'guessed join should return 404');
    videochat_call_access_privacy_assert_body_has_no_needles($guessedJoinResponse, $secretNeedles, 'guessed join response');

    $brokenJoinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $accessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_privacy_assert(is_array($brokenJoinResponse), 'broken personalized join response should be an array');
    videochat_call_access_privacy_assert((int) ($brokenJoinResponse['status'] ?? 0) === 404, 'broken personalized join should return 404');
    videochat_call_access_privacy_assert_body_has_no_needles($brokenJoinResponse, $secretNeedles, 'broken personalized join response');

    $brokenSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => ['User-Agent' => 'call-access-privacy-contract'],
            'remote_address' => '127.0.0.1',
            'body' => '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_call_access_privacy_broken'
    );
    videochat_call_access_privacy_assert(is_array($brokenSessionResponse), 'broken personalized session response should be an array');
    videochat_call_access_privacy_assert((int) ($brokenSessionResponse['status'] ?? 0) === 404, 'broken personalized session should return 404');
    videochat_call_access_privacy_assert_body_has_no_needles($brokenSessionResponse, $secretNeedles, 'broken personalized session response');

    $wrongUserResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId,
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $accessId, 'headers' => []],
        ['user' => ['id' => $wrongUserId, 'role' => 'user']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_privacy_assert(is_array($wrongUserResponse), 'wrong-user access response should be an array');
    videochat_call_access_privacy_assert((int) ($wrongUserResponse['status'] ?? 0) === 403, 'wrong-user access should return 403');
    videochat_call_access_privacy_assert_body_has_no_needles($wrongUserResponse, $secretNeedles, 'wrong-user access response');
    $wrongUserPayload = videochat_call_access_privacy_decode($wrongUserResponse);
    videochat_call_access_privacy_assert(
        !isset($wrongUserPayload['result']['call']) && !isset($wrongUserPayload['result']['target_user']),
        'wrong-user response must not include call or target user result data'
    );

    $domainResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_privacy_assert($domainResolution['ok'] === false, 'domain public resolution should fail closed for broken personalized link');
    videochat_call_access_privacy_assert($domainResolution['access_link'] === null, 'domain public resolution must not return access link on broken personalized link');
    videochat_call_access_privacy_assert($domainResolution['call'] === null, 'domain public resolution must not return call on broken personalized link');
    videochat_call_access_privacy_assert($domainResolution['target_user'] === null, 'domain public resolution must not return target user on broken personalized link');
    videochat_call_access_privacy_assert(
        (($domainResolution['target_hint'] ?? [])['participant_email'] ?? null) === null,
        'domain public resolution must not return participant hint on broken personalized link'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-privacy-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-access-privacy-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
