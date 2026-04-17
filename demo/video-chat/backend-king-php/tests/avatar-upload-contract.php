<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/users/avatar_upload.php';

function videochat_avatar_upload_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[avatar-upload-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-avatar-upload-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }
    $storageRoot = sys_get_temp_dir() . '/videochat-avatar-storage-' . bin2hex(random_bytes(6));

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
    videochat_avatar_upload_assert($userId > 0, 'expected seeded user for avatar upload');

    $onePixelPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO3Z8iUAAAAASUVORK5CYII=';
    $pngBinary = base64_decode($onePixelPngBase64, true);
    videochat_avatar_upload_assert(is_string($pngBinary) && $pngBinary !== '', 'test PNG payload decode failed');

    $parseValid = videochat_avatar_parse_upload_payload([
        'content_type' => 'image/png',
        'content_base64' => $onePixelPngBase64,
    ], 1024 * 1024);
    videochat_avatar_upload_assert($parseValid['ok'] === true, 'valid avatar payload should parse');
    videochat_avatar_upload_assert((string) (($parseValid['data'] ?? [])['mime'] ?? '') === 'image/png', 'parsed mime mismatch');

    $parseTooLarge = videochat_avatar_parse_upload_payload([
        'content_type' => 'image/png',
        'content_base64' => $onePixelPngBase64,
    ], 32);
    videochat_avatar_upload_assert($parseTooLarge['ok'] === false, 'oversized avatar payload should fail');
    videochat_avatar_upload_assert(
        (string) (($parseTooLarge['errors'] ?? [])['content_base64'] ?? '') === 'payload_too_large',
        'oversized avatar error mismatch'
    );

    $parseMismatch = videochat_avatar_parse_upload_payload([
        'content_type' => 'image/jpeg',
        'content_base64' => $onePixelPngBase64,
    ], 1024 * 1024);
    videochat_avatar_upload_assert($parseMismatch['ok'] === false, 'declared mime mismatch should fail');
    videochat_avatar_upload_assert(
        (string) (($parseMismatch['errors'] ?? [])['content_type'] ?? '') === 'declared_type_mismatch',
        'declared mime mismatch error mismatch'
    );

    $parseDataUrl = videochat_avatar_parse_upload_payload([
        'data_url' => 'data:image/png;base64,' . $onePixelPngBase64,
    ], 1024 * 1024);
    videochat_avatar_upload_assert($parseDataUrl['ok'] === true, 'data URL avatar payload should parse');

    $storeFirst = videochat_store_avatar_for_user(
        $pdo,
        $userId,
        [
            'content_type' => 'image/png',
            'content_base64' => $onePixelPngBase64,
        ],
        $storageRoot,
        1024 * 1024
    );
    videochat_avatar_upload_assert($storeFirst['ok'] === true, 'first avatar upload should succeed');
    $firstAvatarPath = (string) ($storeFirst['avatar_path'] ?? '');
    videochat_avatar_upload_assert(str_starts_with($firstAvatarPath, '/api/user/avatar-files/'), 'avatar path prefix mismatch');
    $firstFilename = (string) ($storeFirst['file_name'] ?? '');
    videochat_avatar_upload_assert($firstFilename !== '', 'uploaded avatar filename must be set');

    $firstFilePath = videochat_avatar_resolve_read_path($storageRoot, $firstFilename);
    videochat_avatar_upload_assert(is_string($firstFilePath), 'uploaded avatar file should resolve');
    videochat_avatar_upload_assert((int) ($storeFirst['bytes'] ?? 0) === strlen($pngBinary), 'reported avatar bytes mismatch');

    $dbAvatarCheck = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $dbAvatarCheck->execute([':id' => $userId]);
    $dbAvatarPath = (string) $dbAvatarCheck->fetchColumn();
    videochat_avatar_upload_assert($dbAvatarPath === $firstAvatarPath, 'database avatar_path should match upload result');

    $storeSecond = videochat_store_avatar_for_user(
        $pdo,
        $userId,
        [
            'data_url' => 'data:image/png;base64,' . $onePixelPngBase64,
        ],
        $storageRoot,
        1024 * 1024
    );
    videochat_avatar_upload_assert($storeSecond['ok'] === true, 'second avatar upload should succeed');
    $secondFilename = (string) ($storeSecond['file_name'] ?? '');
    videochat_avatar_upload_assert($secondFilename !== '' && $secondFilename !== $firstFilename, 'second avatar should use new filename');

    $firstStillExists = videochat_avatar_resolve_read_path($storageRoot, $firstFilename);
    videochat_avatar_upload_assert($firstStillExists === null, 'previous avatar file should be removed after replacement');
    $secondResolved = videochat_avatar_resolve_read_path($storageRoot, $secondFilename);
    videochat_avatar_upload_assert(is_string($secondResolved), 'second avatar should resolve');

    $deleteAvatar = videochat_delete_avatar_for_user($pdo, $userId, $storageRoot);
    videochat_avatar_upload_assert($deleteAvatar['ok'] === true, 'avatar delete should succeed');
    videochat_avatar_upload_assert((string) ($deleteAvatar['reason'] ?? '') === 'cleared', 'avatar delete reason mismatch');
    videochat_avatar_upload_assert((bool) ($deleteAvatar['removed_file'] ?? false) === true, 'avatar delete should remove managed file');

    $secondAfterDelete = videochat_avatar_resolve_read_path($storageRoot, $secondFilename);
    videochat_avatar_upload_assert($secondAfterDelete === null, 'avatar file should be removed after delete');

    $dbAvatarAfterDelete = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $dbAvatarAfterDelete->execute([':id' => $userId]);
    $avatarAfterDelete = $dbAvatarAfterDelete->fetchColumn();
    videochat_avatar_upload_assert($avatarAfterDelete === null || $avatarAfterDelete === '', 'avatar_path should be null after delete');

    $deleteAvatarAgain = videochat_delete_avatar_for_user($pdo, $userId, $storageRoot);
    videochat_avatar_upload_assert($deleteAvatarAgain['ok'] === true, 'second avatar delete should still succeed');
    videochat_avatar_upload_assert((string) ($deleteAvatarAgain['reason'] ?? '') === 'already_empty', 'second avatar delete reason mismatch');

    $traversalAttempt = videochat_avatar_resolve_read_path($storageRoot, '../etc/passwd');
    videochat_avatar_upload_assert($traversalAttempt === null, 'path traversal filename should be rejected');

    $missingUser = videochat_store_avatar_for_user(
        $pdo,
        999999,
        [
            'content_type' => 'image/png',
            'content_base64' => $onePixelPngBase64,
        ],
        $storageRoot,
        1024 * 1024
    );
    videochat_avatar_upload_assert($missingUser['ok'] === false, 'missing user upload should fail');
    videochat_avatar_upload_assert((string) ($missingUser['reason'] ?? '') === 'not_found', 'missing user upload reason mismatch');

    if (is_dir($storageRoot)) {
        $files = scandir($storageRoot);
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                @unlink($storageRoot . DIRECTORY_SEPARATOR . $file);
            }
        }
        @rmdir($storageRoot);
    }
    @unlink($databasePath);

    fwrite(STDOUT, "[avatar-upload-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[avatar-upload-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
