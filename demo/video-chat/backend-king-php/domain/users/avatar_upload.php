<?php

declare(strict_types=1);

function videochat_avatar_max_bytes(?int $requestedBytes = null): int
{
    $raw = $requestedBytes;
    if (!is_int($raw) || $raw <= 0) {
        $env = getenv('VIDEOCHAT_AVATAR_MAX_BYTES');
        $raw = is_string($env) ? (int) $env : 0;
    }

    if ($raw <= 0) {
        $raw = 5 * 1024 * 1024;
    }

    if ($raw < 64 * 1024) {
        $raw = 64 * 1024;
    }
    if ($raw > 10 * 1024 * 1024) {
        $raw = 10 * 1024 * 1024;
    }

    return $raw;
}

/**
 * @return array<string, string>
 */
function videochat_avatar_allowed_mime_to_extension(): array
{
    return [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];
}

function videochat_avatar_normalize_mime(string $mime): string
{
    $normalized = strtolower(trim($mime));
    if ($normalized === 'image/jpg') {
        return 'image/jpeg';
    }
    return $normalized;
}

function videochat_avatar_detect_mime_from_binary(string $binary): ?string
{
    $length = strlen($binary);
    if ($length >= 8 && strncmp($binary, "\x89PNG\x0d\x0a\x1a\x0a", 8) === 0) {
        return 'image/png';
    }
    if ($length >= 3 && ord($binary[0]) === 0xff && ord($binary[1]) === 0xd8 && ord($binary[2]) === 0xff) {
        return 'image/jpeg';
    }
    if ($length >= 12 && substr($binary, 0, 4) === 'RIFF' && substr($binary, 8, 4) === 'WEBP') {
        return 'image/webp';
    }

    return null;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   data: array{
 *     binary: string,
 *     bytes: int,
 *     mime: string,
 *     extension: string
 *   }|null
 * }
 */
function videochat_avatar_parse_upload_payload(array $payload, int $maxBytes): array
{
    $errors = [];
    $base64Payload = '';
    $declaredMime = '';

    if (array_key_exists('data_url', $payload)) {
        $dataUrl = is_string($payload['data_url']) ? trim($payload['data_url']) : '';
        if ($dataUrl === '') {
            $errors['data_url'] = 'required_non_empty_data_url';
        } elseif (
            preg_match('/^data:([a-z0-9.+-]+\/[a-z0-9.+-]+);base64,(.*)$/is', $dataUrl, $matches) !== 1
        ) {
            $errors['data_url'] = 'invalid_data_url_format';
        } else {
            $declaredMime = videochat_avatar_normalize_mime((string) ($matches[1] ?? ''));
            $base64Payload = (string) ($matches[2] ?? '');
        }
    } else {
        $base64Payload = is_string($payload['content_base64'] ?? null) ? (string) $payload['content_base64'] : '';
        $declaredMime = is_string($payload['content_type'] ?? null)
            ? videochat_avatar_normalize_mime((string) $payload['content_type'])
            : '';

        if (trim($base64Payload) === '') {
            $errors['content_base64'] = 'required_non_empty_base64';
        }
        if ($declaredMime === '') {
            $errors['content_type'] = 'required_content_type_without_data_url';
        }
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $errors,
            'data' => null,
        ];
    }

    $allowedMimeMap = videochat_avatar_allowed_mime_to_extension();
    if ($declaredMime !== '' && !array_key_exists($declaredMime, $allowedMimeMap)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_type' => 'unsupported_content_type'],
            'data' => null,
        ];
    }

    $base64Payload = preg_replace('/\s+/', '', $base64Payload) ?? '';
    $binary = base64_decode($base64Payload, true);
    if (!is_string($binary)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_base64' => 'invalid_base64'],
            'data' => null,
        ];
    }

    $bytes = strlen($binary);
    if ($bytes <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_base64' => 'empty_payload'],
            'data' => null,
        ];
    }
    if ($bytes > $maxBytes) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_base64' => 'payload_too_large'],
            'data' => null,
        ];
    }

    $detectedMime = videochat_avatar_detect_mime_from_binary($binary);
    if ($detectedMime === null || !array_key_exists($detectedMime, $allowedMimeMap)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_base64' => 'unsupported_binary_type'],
            'data' => null,
        ];
    }

    if ($declaredMime !== '' && $detectedMime !== $declaredMime) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['content_type' => 'declared_type_mismatch'],
            'data' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'data' => [
            'binary' => $binary,
            'bytes' => $bytes,
            'mime' => $detectedMime,
            'extension' => $allowedMimeMap[$detectedMime],
        ],
    ];
}

function videochat_avatar_extract_filename_from_public_path(string $avatarPath): ?string
{
    $trimmed = trim($avatarPath);
    if ($trimmed === '' || !str_starts_with($trimmed, '/api/user/avatar-files/')) {
        return null;
    }

    $filename = substr($trimmed, strlen('/api/user/avatar-files/'));
    $decoded = rawurldecode($filename);
    if (!preg_match('/^[A-Za-z0-9._-]{1,200}$/', $decoded)) {
        return null;
    }

    return $decoded;
}

function videochat_avatar_resolve_read_path(string $storageRoot, string $filename): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,200}$/', $filename)) {
        return null;
    }

    $rootReal = realpath($storageRoot);
    if (!is_string($rootReal) || !is_dir($rootReal)) {
        return null;
    }

    $candidate = $rootReal . DIRECTORY_SEPARATOR . $filename;
    $candidateReal = realpath($candidate);
    if (!is_string($candidateReal) || !is_file($candidateReal)) {
        return null;
    }

    if (!str_starts_with($candidateReal, $rootReal . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $candidateReal;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   avatar_path: ?string,
 *   content_type: ?string,
 *   bytes: int,
 *   file_name: ?string
 * }
 */
function videochat_store_avatar_for_user(
    PDO $pdo,
    int $userId,
    array $payload,
    string $storageRoot,
    int $maxBytes
): array {
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    $selectUser = $pdo->prepare('SELECT id, avatar_path FROM users WHERE id = :id LIMIT 1');
    $selectUser->execute([':id' => $userId]);
    $userRow = $selectUser->fetch();
    if (!is_array($userRow)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    $parsed = videochat_avatar_parse_upload_payload($payload, $maxBytes);
    if (!(bool) ($parsed['ok'] ?? false) || !is_array($parsed['data'] ?? null)) {
        return [
            'ok' => false,
            'reason' => (string) ($parsed['reason'] ?? 'validation_failed'),
            'errors' => is_array($parsed['errors'] ?? null) ? $parsed['errors'] : ['payload' => 'invalid'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    $data = $parsed['data'];
    $trimmedRoot = trim($storageRoot);
    if ($trimmedRoot === '') {
        return [
            'ok' => false,
            'reason' => 'io_error',
            'errors' => ['storage' => 'empty_storage_root'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    if (!is_dir($trimmedRoot) && !mkdir($trimmedRoot, 0775, true) && !is_dir($trimmedRoot)) {
        return [
            'ok' => false,
            'reason' => 'io_error',
            'errors' => ['storage' => 'create_directory_failed'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    $rootReal = realpath($trimmedRoot);
    if (!is_string($rootReal) || !is_dir($rootReal)) {
        return [
            'ok' => false,
            'reason' => 'io_error',
            'errors' => ['storage' => 'resolve_storage_root_failed'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    try {
        $entropySeed = bin2hex(random_bytes(16));
    } catch (Throwable) {
        $entropySeed = uniqid((string) mt_rand(), true);
    }
    $entropy = hash('sha256', (string) $userId . ':' . $entropySeed . ':' . microtime(true));
    $filename = sprintf(
        'avatar-u%d-%s.%s',
        $userId,
        substr($entropy, 0, 24),
        (string) $data['extension']
    );
    $targetPath = $rootReal . DIRECTORY_SEPARATOR . $filename;
    if (!str_starts_with($targetPath, $rootReal . DIRECTORY_SEPARATOR)) {
        return [
            'ok' => false,
            'reason' => 'io_error',
            'errors' => ['storage' => 'invalid_target_path'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    $written = @file_put_contents($targetPath, (string) $data['binary'], LOCK_EX);
    if (!is_int($written) || $written !== (int) $data['bytes']) {
        @unlink($targetPath);
        return [
            'ok' => false,
            'reason' => 'io_error',
            'errors' => ['storage' => 'write_failed'],
            'avatar_path' => null,
            'content_type' => null,
            'bytes' => 0,
            'file_name' => null,
        ];
    }

    @chmod($targetPath, 0644);

    $publicPath = '/api/user/avatar-files/' . rawurlencode($filename);

    $update = $pdo->prepare('UPDATE users SET avatar_path = :avatar_path, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':avatar_path' => $publicPath,
        ':updated_at' => gmdate('c'),
        ':id' => $userId,
    ]);

    $previousAvatar = is_string($userRow['avatar_path'] ?? null) ? (string) $userRow['avatar_path'] : '';
    $previousFilename = videochat_avatar_extract_filename_from_public_path($previousAvatar);
    if (is_string($previousFilename) && $previousFilename !== $filename) {
        $previousPath = videochat_avatar_resolve_read_path($rootReal, $previousFilename);
        if (is_string($previousPath)) {
            @unlink($previousPath);
        }
    }

    return [
        'ok' => true,
        'reason' => 'uploaded',
        'errors' => [],
        'avatar_path' => $publicPath,
        'content_type' => (string) $data['mime'],
        'bytes' => (int) $data['bytes'],
        'file_name' => $filename,
    ];
}
