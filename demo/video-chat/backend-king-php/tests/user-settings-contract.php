<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_settings.php';

function videochat_user_settings_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[user-settings-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-user-settings-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $userQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $userId = (int) $userQuery->fetchColumn();
    videochat_user_settings_assert($userId > 0, 'expected seeded standard user in sqlite bootstrap');

    $sessionId = 'sess_user_settings_contract';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'user-settings-contract')
SQL
    );
    $insertSession->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    $initialAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_user_settings_assert($initialAuth['ok'] === true, 'initial auth should succeed');
    videochat_user_settings_assert((string) (($initialAuth['user'] ?? [])['role'] ?? '') === 'user', 'initial auth role mismatch');

    $initialSettings = videochat_fetch_user_settings($pdo, $userId);
    videochat_user_settings_assert(is_array($initialSettings), 'initial settings lookup should succeed');

    $invalidEmptyPayload = videochat_update_user_settings($pdo, $userId, []);
    videochat_user_settings_assert($invalidEmptyPayload['ok'] === false, 'empty settings update should fail');
    videochat_user_settings_assert($invalidEmptyPayload['reason'] === 'validation_failed', 'empty settings update reason mismatch');
    videochat_user_settings_assert(
        (string) ($invalidEmptyPayload['errors']['payload'] ?? '') === 'at_least_one_supported_field_required',
        'empty settings payload error mismatch'
    );

    $invalidValues = videochat_update_user_settings($pdo, $userId, [
        'time_format' => '99h',
        'date_format' => 'unknown',
        'theme' => '',
    ]);
    videochat_user_settings_assert($invalidValues['ok'] === false, 'invalid settings update should fail');
    videochat_user_settings_assert($invalidValues['reason'] === 'validation_failed', 'invalid settings update reason mismatch');
    videochat_user_settings_assert(
        (string) ($invalidValues['errors']['time_format'] ?? '') === 'must_be_24h_or_12h',
        'invalid time_format error mismatch'
    );
    videochat_user_settings_assert(
        (string) ($invalidValues['errors']['date_format'] ?? '') === 'must_be_supported_date_format',
        'invalid date_format error mismatch'
    );
    videochat_user_settings_assert(
        (string) ($invalidValues['errors']['theme'] ?? '') === 'required_theme',
        'invalid theme error mismatch'
    );

    $unknownFieldPayload = videochat_update_user_settings($pdo, $userId, [
        'role' => 'admin',
    ]);
    videochat_user_settings_assert($unknownFieldPayload['ok'] === false, 'unknown-field settings update should fail');
    videochat_user_settings_assert($unknownFieldPayload['reason'] === 'validation_failed', 'unknown-field settings reason mismatch');
    videochat_user_settings_assert(
        (string) ($unknownFieldPayload['errors']['role'] ?? '') === 'field_not_updatable',
        'unknown-field settings error mismatch'
    );

    $validUpdate = videochat_update_user_settings($pdo, $userId, [
        'display_name' => 'Call User Updated',
        'time_format' => '12h',
        'date_format' => 'ymd_dash',
        'theme' => 'light',
        'avatar_path' => '/avatars/call-user-updated.png',
    ]);
    videochat_user_settings_assert($validUpdate['ok'] === true, 'valid settings update should succeed');
    videochat_user_settings_assert($validUpdate['reason'] === 'updated', 'valid settings update reason mismatch');
    videochat_user_settings_assert(
        (string) (($validUpdate['user'] ?? [])['display_name'] ?? '') === 'Call User Updated',
        'updated display_name mismatch'
    );
    videochat_user_settings_assert(
        (string) (($validUpdate['user'] ?? [])['time_format'] ?? '') === '12h',
        'updated time_format mismatch'
    );
    videochat_user_settings_assert(
        (string) (($validUpdate['user'] ?? [])['date_format'] ?? '') === 'ymd_dash',
        'updated date_format mismatch'
    );
    videochat_user_settings_assert(
        (string) (($validUpdate['user'] ?? [])['theme'] ?? '') === 'light',
        'updated theme mismatch'
    );
    videochat_user_settings_assert(
        (string) (($validUpdate['user'] ?? [])['avatar_path'] ?? '') === '/avatars/call-user-updated.png',
        'updated avatar_path mismatch'
    );

    $reauth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_user_settings_assert($reauth['ok'] === true, 'reauth after settings update should succeed');
    videochat_user_settings_assert(
        (string) (($reauth['user'] ?? [])['display_name'] ?? '') === 'Call User Updated',
        'reauth display_name should reflect persisted settings'
    );
    videochat_user_settings_assert(
        (string) (($reauth['user'] ?? [])['time_format'] ?? '') === '12h',
        'reauth time_format should reflect persisted settings'
    );
    videochat_user_settings_assert(
        (string) (($reauth['user'] ?? [])['date_format'] ?? '') === 'ymd_dash',
        'reauth date_format should reflect persisted settings'
    );
    videochat_user_settings_assert(
        (string) (($reauth['user'] ?? [])['theme'] ?? '') === 'light',
        'reauth theme should reflect persisted settings'
    );
    videochat_user_settings_assert(
        (string) (($reauth['user'] ?? [])['avatar_path'] ?? '') === '/avatars/call-user-updated.png',
        'reauth avatar_path should reflect persisted settings'
    );

    $avatarClear = videochat_update_user_settings($pdo, $userId, [
        'avatar_path' => null,
    ]);
    videochat_user_settings_assert($avatarClear['ok'] === true, 'avatar clear should succeed');
    $avatarClearUser = is_array($avatarClear['user'] ?? null) ? $avatarClear['user'] : [];
    videochat_user_settings_assert(
        array_key_exists('avatar_path', $avatarClearUser) && $avatarClearUser['avatar_path'] === null,
        'avatar clear should persist null avatar_path'
    );

    $missingUserUpdate = videochat_update_user_settings($pdo, 999999, [
        'theme' => 'dark',
    ]);
    videochat_user_settings_assert($missingUserUpdate['ok'] === false, 'missing user settings update should fail');
    videochat_user_settings_assert($missingUserUpdate['reason'] === 'not_found', 'missing user settings reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[user-settings-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[user-settings-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
