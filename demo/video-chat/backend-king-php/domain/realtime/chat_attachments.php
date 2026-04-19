<?php

declare(strict_types=1);

function videochat_chat_attachment_inline_max_chars(): int
{
    return videochat_chat_max_chars();
}

function videochat_chat_attachment_inline_max_bytes(): int
{
    return videochat_chat_max_bytes();
}

function videochat_chat_attachment_max_count(): int
{
    return 10;
}

function videochat_chat_attachment_max_images(): int
{
    return 10;
}

function videochat_chat_attachment_max_image_bytes(): int
{
    return 8 * 1024 * 1024;
}

function videochat_chat_attachment_max_document_bytes(): int
{
    return 25 * 1024 * 1024;
}

function videochat_chat_attachment_max_text_bytes(): int
{
    return 8 * 1024 * 1024;
}

function videochat_chat_attachment_max_message_bytes(): int
{
    return 100 * 1024 * 1024;
}

function videochat_chat_attachment_max_upload_body_bytes(): int
{
    $maxPayloadBytes = max(
        videochat_chat_attachment_max_image_bytes(),
        videochat_chat_attachment_max_text_bytes(),
        videochat_chat_attachment_max_document_bytes()
    );

    return (int) ceil(($maxPayloadBytes * 4) / 3) + 64 * 1024;
}

function videochat_chat_attachment_call_hard_quota_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_CHAT_ATTACHMENT_CALL_HARD_QUOTA_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured) || $configured <= 0) {
        return 512 * 1024 * 1024;
    }

    return max(10 * 1024 * 1024, min($configured, 10 * 1024 * 1024 * 1024));
}

function videochat_chat_attachment_call_soft_quota_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_CHAT_ATTACHMENT_CALL_SOFT_QUOTA_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured) || $configured <= 0) {
        $configured = 256 * 1024 * 1024;
    }

    $hardQuota = videochat_chat_attachment_call_hard_quota_bytes();
    return max(10 * 1024 * 1024, min($configured, $hardQuota));
}

function videochat_chat_object_store_max_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_OBJECT_STORE_MAX_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured) || $configured <= 0) {
        return 2 * 1024 * 1024 * 1024;
    }

    return max(64 * 1024 * 1024, min($configured, 100 * 1024 * 1024 * 1024));
}

function videochat_chat_object_store_init(string $storageRoot, int $maxBytes): bool
{
    $root = trim($storageRoot);
    if ($root === '' || !function_exists('king_object_store_init')) {
        return false;
    }

    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return false;
    }

    try {
        return king_object_store_init([
            'primary_backend' => 'local_fs',
            'storage_root_path' => $root,
            'max_storage_size_bytes' => $maxBytes,
        ]) === true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array<string, string>
 */
function videochat_chat_attachment_extension_mime_map(): array
{
    return [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'md' => 'text/markdown',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_chat_attachment_blocked_extensions(): array
{
    return ['exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'sh', 'js', 'msi', 'jar', 'app', 'deb', 'rpm', 'zip', 'rar', '7z', 'tar', 'gz'];
}

function videochat_chat_attachment_normalize_extension(string $filename): string
{
    $extension = strtolower(trim((string) pathinfo($filename, PATHINFO_EXTENSION)));
    return preg_match('/^[a-z0-9]{1,12}$/', $extension) === 1 ? $extension : '';
}

function videochat_chat_attachment_normalize_call_id(string $callId): string
{
    $normalized = trim($callId);
    return preg_match('/^[A-Za-z0-9._-]{1,200}$/', $normalized) === 1 ? $normalized : '';
}

function videochat_chat_attachment_safe_filename(string $filename, string $fallbackExtension = 'txt'): string
{
    $basename = trim(basename(str_replace('\\', '/', $filename)));
    $basename = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $basename) ?? '';
    $basename = preg_replace('/\s+/', ' ', $basename) ?? '';
    $basename = trim($basename, " .\t\n\r\0\x0B");
    if ($basename === '') {
        $basename = 'attachment.' . $fallbackExtension;
    }
    if (strlen($basename) > 160) {
        $extension = videochat_chat_attachment_normalize_extension($basename);
        $stemLength = $extension !== '' ? 150 - strlen($extension) : 150;
        $basename = substr($basename, 0, max(16, $stemLength));
        $basename = rtrim($basename, " ._-");
        if ($extension !== '') {
            $basename .= '.' . $extension;
        }
    }

    return $basename;
}

function videochat_chat_attachment_kind_for_extension(string $extension): string
{
    if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return 'image';
    }
    if (in_array($extension, ['txt', 'csv', 'md'], true)) {
        return 'text';
    }
    if ($extension === 'pdf') {
        return 'pdf';
    }

    return 'document';
}

function videochat_chat_attachment_normalize_mime(string $mime): string
{
    $normalized = strtolower(trim(explode(';', $mime, 2)[0] ?? ''));
    return match ($normalized) {
        'image/jpg' => 'image/jpeg',
        'text/x-markdown', 'text/md', 'application/x-markdown' => 'text/markdown',
        'application/x-pdf' => 'application/pdf',
        default => $normalized,
    };
}

function videochat_chat_attachment_detect_mime(string $binary, string $extension): ?string
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
    if ($length >= 6 && (strncmp($binary, "GIF87a", 6) === 0 || strncmp($binary, "GIF89a", 6) === 0)) {
        return 'image/gif';
    }
    if ($length >= 5 && strncmp($binary, '%PDF-', 5) === 0) {
        return 'application/pdf';
    }
    if (in_array($extension, ['doc', 'xls', 'ppt'], true) && $length >= 8 && substr($binary, 0, 8) === "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") {
        return videochat_chat_attachment_extension_mime_map()[$extension] ?? null;
    }
    if (in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true) && $length >= 4 && substr($binary, 0, 4) === "PK\x03\x04") {
        if (videochat_chat_attachment_zip_container_matches_extension($binary, $extension)) {
            return videochat_chat_attachment_extension_mime_map()[$extension] ?? null;
        }
        return null;
    }
    if (in_array($extension, ['txt', 'csv', 'md'], true) && videochat_chat_attachment_binary_looks_text($binary)) {
        return videochat_chat_attachment_extension_mime_map()[$extension] ?? 'text/plain';
    }

    return null;
}

function videochat_chat_attachment_binary_looks_text(string $binary): bool
{
    if ($binary === '' || str_contains($binary, "\0")) {
        return false;
    }

    if (function_exists('mb_check_encoding') && !mb_check_encoding($binary, 'UTF-8')) {
        return false;
    }

    $sample = substr($binary, 0, min(4096, strlen($binary)));
    $printable = 0;
    $length = strlen($sample);
    for ($index = 0; $index < $length; $index += 1) {
        $byte = ord($sample[$index]);
        if ($byte === 9 || $byte === 10 || $byte === 13 || ($byte >= 32 && $byte !== 127)) {
            $printable += 1;
        }
    }

    return $length > 0 && ($printable / $length) >= 0.92;
}

function videochat_chat_attachment_zip_container_matches_extension(string $binary, string $extension): bool
{
    $sample = substr($binary, 0, min(strlen($binary), 1024 * 1024));
    if (!str_contains($sample, '[Content_Types].xml') && !str_contains($sample, 'mimetype')) {
        return false;
    }

    return match ($extension) {
        'docx' => str_contains($sample, 'word/'),
        'xlsx' => str_contains($sample, 'xl/'),
        'pptx' => str_contains($sample, 'ppt/'),
        'odt' => str_contains($sample, 'application/vnd.oasis.opendocument.text'),
        'ods' => str_contains($sample, 'application/vnd.oasis.opendocument.spreadsheet'),
        'odp' => str_contains($sample, 'application/vnd.oasis.opendocument.presentation'),
        default => false,
    };
}

/**
 * @return array{ok: bool, code: string, message: string, details: array<string, mixed>, data: array<string, mixed>|null}
 */
function videochat_chat_attachment_parse_upload_payload(array $payload): array
{
    $originalName = is_string($payload['file_name'] ?? null) ? trim((string) $payload['file_name']) : '';
    if ($originalName === '') {
        return videochat_chat_attachment_error('attachment_name_required', 'Attachment file name is required.', ['field' => 'file_name']);
    }

    $extension = videochat_chat_attachment_normalize_extension($originalName);
    if ($extension === '' || in_array($extension, videochat_chat_attachment_blocked_extensions(), true)) {
        return videochat_chat_attachment_error('attachment_type_not_allowed', 'Attachment file type is not allowed.', ['extension' => $extension]);
    }

    $allowedMap = videochat_chat_attachment_extension_mime_map();
    if (!array_key_exists($extension, $allowedMap)) {
        return videochat_chat_attachment_error('attachment_type_not_allowed', 'Attachment file type is not allowed.', ['extension' => $extension]);
    }

    $declaredMime = is_string($payload['content_type'] ?? null)
        ? videochat_chat_attachment_normalize_mime((string) $payload['content_type'])
        : '';
    $base64 = is_string($payload['content_base64'] ?? null) ? (string) $payload['content_base64'] : '';
    if (trim($base64) === '') {
        return videochat_chat_attachment_error('attachment_content_required', 'Attachment content is required.', ['field' => 'content_base64']);
    }

    $base64 = preg_replace('/\s+/', '', $base64) ?? '';
    $binary = base64_decode($base64, true);
    if (!is_string($binary)) {
        return videochat_chat_attachment_error('attachment_invalid_base64', 'Attachment content must be valid base64.', ['field' => 'content_base64']);
    }

    $bytes = strlen($binary);
    if ($bytes <= 0) {
        return videochat_chat_attachment_error('attachment_empty', 'Attachment content must not be empty.', []);
    }

    $kind = videochat_chat_attachment_kind_for_extension($extension);
    $maxBytes = match ($kind) {
        'image' => videochat_chat_attachment_max_image_bytes(),
        'text' => videochat_chat_attachment_max_text_bytes(),
        default => videochat_chat_attachment_max_document_bytes(),
    };
    if ($bytes > $maxBytes) {
        return videochat_chat_attachment_error('attachment_too_large', 'Attachment exceeds the allowed size.', [
            'size_bytes' => $bytes,
            'max_bytes' => $maxBytes,
            'kind' => $kind,
        ]);
    }

    $detectedMime = videochat_chat_attachment_detect_mime($binary, $extension);
    if ($detectedMime === null) {
        return videochat_chat_attachment_error('attachment_type_not_allowed', 'Attachment binary type is not allowed.', [
            'extension' => $extension,
            'reason' => 'magic_byte_mismatch',
        ]);
    }

    $expectedMime = $allowedMap[$extension];
    if ($detectedMime !== $expectedMime) {
        return videochat_chat_attachment_error('attachment_type_not_allowed', 'Attachment file extension does not match its binary type.', [
            'extension' => $extension,
            'expected_content_type' => $expectedMime,
            'detected_content_type' => $detectedMime,
        ]);
    }

    if ($declaredMime !== '' && $declaredMime !== $detectedMime) {
        return videochat_chat_attachment_error('attachment_type_not_allowed', 'Declared content type does not match attachment binary type.', [
            'declared_content_type' => $declaredMime,
            'detected_content_type' => $detectedMime,
        ]);
    }

    $safeName = videochat_chat_attachment_safe_filename($originalName, $extension);

    return [
        'ok' => true,
        'code' => '',
        'message' => '',
        'details' => [],
        'data' => [
            'binary' => $binary,
            'file_name' => $safeName,
            'original_name' => $safeName,
            'extension' => $extension,
            'content_type' => $detectedMime,
            'size_bytes' => $bytes,
            'kind' => $kind,
        ],
    ];
}

/**
 * @return array{ok: false, code: string, message: string, details: array<string, mixed>, data: null}
 */
function videochat_chat_attachment_error(string $code, string $message, array $details): array
{
    return [
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'details' => $details,
        'data' => null,
    ];
}

function videochat_chat_attachments_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_attachments (
    id TEXT PRIMARY KEY,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    uploaded_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('image', 'text', 'pdf', 'document')),
    extension TEXT NOT NULL,
    object_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'attached', 'deleted')),
    attached_message_id TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    attached_at TEXT
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_call_id ON call_chat_attachments(call_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_room_id ON call_chat_attachments(room_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_uploaded_by ON call_chat_attachments(uploaded_by_user_id, status)');
}

/**
 * @return array<string, mixed>|null
 */
function videochat_chat_attachment_call_context(
    PDO $pdo,
    string $callId,
    int $userId,
    string $role,
    array $allowedStatuses = ['scheduled', 'active']
): ?array
{
    $normalizedCallId = videochat_chat_attachment_normalize_call_id($callId);
    if ($normalizedCallId === '' || $userId <= 0) {
        return null;
    }

    $normalizedStatuses = [];
    foreach ($allowedStatuses as $status) {
        $normalized = strtolower(trim((string) $status));
        if (in_array($normalized, ['scheduled', 'active', 'ended', 'cancelled'], true)) {
            $normalizedStatuses[$normalized] = true;
        }
    }
    if ($normalizedStatuses === []) {
        $normalizedStatuses = ['scheduled' => true, 'active' => true];
    }

    $statusPlaceholders = [];
    $statusParams = [];
    $index = 0;
    foreach (array_keys($normalizedStatuses) as $status) {
        $placeholder = ':status_' . $index;
        $statusPlaceholders[] = $placeholder;
        $statusParams[$placeholder] = $status;
        $index += 1;
    }

    $statement = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT
    calls.id,
    calls.room_id,
    calls.owner_user_id,
    calls.status,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  AND calls.status IN (%s)
  AND (
      calls.owner_user_id = :user_id
      OR cp.user_id IS NOT NULL
      OR :is_admin = 1
)
LIMIT 1
SQL
            ,
            implode(', ', $statusPlaceholders)
        )
    );
    $statement->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
        ':is_admin' => videochat_normalize_role_slug($role) === 'admin' ? 1 : 0,
        ...$statusParams,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function videochat_chat_attachment_generate_id(): string
{
    try {
        return 'att_' . bin2hex(random_bytes(16));
    } catch (Throwable) {
        return 'att_' . substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 32);
    }
}

function videochat_chat_attachment_object_key(string $callId, string $roomId, string $attachmentId, string $filename): string
{
    $callHash = substr(hash('sha256', $callId), 0, 16);
    $roomHash = substr(hash('sha256', $roomId), 0, 16);
    $fileHash = substr(hash('sha256', videochat_chat_attachment_safe_filename($filename, 'bin')), 0, 12);
    $safeAttachmentId = preg_replace('/[^A-Za-z0-9._-]+/', '_', $attachmentId) ?? '';
    $safeAttachmentId = trim(substr($safeAttachmentId, 0, 40), '._-');
    if ($safeAttachmentId === '') {
        $safeAttachmentId = substr(hash('sha256', $attachmentId), 0, 16);
    }

    // King object IDs are flat identifiers: no path separators and max 127 bytes.
    return 'vcchat_' . $callHash . '_' . $roomHash . '_' . $safeAttachmentId . '_' . $fileHash;
}

function videochat_chat_attachment_store_put(string $objectKey, string $binary, string $contentType): bool
{
    $override = $GLOBALS['videochat_chat_attachment_store_put'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey, $binary, $contentType) === true;
    }

    if (!function_exists('king_object_store_put_from_stream')) {
        return false;
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return false;
    }
    fwrite($stream, $binary);
    rewind($stream);

    try {
        return king_object_store_put_from_stream($objectKey, $stream, [
            'content_type' => $contentType,
            'cache_class' => 'private',
        ]) === true;
    } catch (Throwable) {
        return false;
    } finally {
        fclose($stream);
    }
}

function videochat_chat_attachment_store_get(string $objectKey): string|false
{
    $override = $GLOBALS['videochat_chat_attachment_store_get'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey);
    }

    if (!function_exists('king_object_store_get')) {
        return false;
    }

    try {
        return king_object_store_get($objectKey);
    } catch (Throwable) {
        return false;
    }
}

function videochat_chat_attachment_store_delete(string $objectKey): bool
{
    $override = $GLOBALS['videochat_chat_attachment_store_delete'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey) === true;
    }

    if (!function_exists('king_object_store_delete')) {
        return false;
    }

    try {
        return king_object_store_delete($objectKey) === true;
    } catch (Throwable) {
        return false;
    }
}

function videochat_chat_attachment_current_call_bytes(PDO $pdo, string $callId): int
{
    videochat_chat_attachments_bootstrap($pdo);
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT COALESCE(SUM(size_bytes), 0)
FROM call_chat_attachments
WHERE call_id = :call_id
  AND status IN ('draft', 'attached')
SQL
    );
    $statement->execute([':call_id' => $callId]);
    return (int) ($statement->fetchColumn() ?: 0);
}

/**
 * @return array{ok: bool, reason: string, code: string, message: string, details: array<string, mixed>, attachment: array<string, mixed>|null}
 */
function videochat_chat_attachment_upload(PDO $pdo, string $callId, int $userId, string $role, array $payload): array
{
    videochat_chat_attachments_bootstrap($pdo);
    $context = videochat_chat_attachment_call_context($pdo, $callId, $userId, $role);
    if ($context === null) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'code' => 'chat_attachment_forbidden',
            'message' => 'User is not allowed to upload chat attachments for this call.',
            'details' => ['call_id' => $callId],
            'attachment' => null,
        ];
    }

    $parsed = videochat_chat_attachment_parse_upload_payload($payload);
    if (!(bool) ($parsed['ok'] ?? false) || !is_array($parsed['data'] ?? null)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'code' => (string) ($parsed['code'] ?? 'attachment_invalid'),
            'message' => (string) ($parsed['message'] ?? 'Attachment upload payload is invalid.'),
            'details' => is_array($parsed['details'] ?? null) ? $parsed['details'] : [],
            'attachment' => null,
        ];
    }

    $data = $parsed['data'];
    $currentBytes = videochat_chat_attachment_current_call_bytes($pdo, (string) ($context['id'] ?? $callId));
    $attemptedBytes = (int) ($data['size_bytes'] ?? 0);
    $nextBytes = $currentBytes + $attemptedBytes;
    $softQuotaBytes = videochat_chat_attachment_call_soft_quota_bytes();
    $quotaBytes = videochat_chat_attachment_call_hard_quota_bytes();
    if ($nextBytes > $quotaBytes) {
        return [
            'ok' => false,
            'reason' => 'quota_exceeded',
            'code' => 'chat_storage_quota_exceeded',
            'message' => 'Chat attachment storage quota for this call is exceeded.',
            'details' => [
                'call_id' => (string) ($context['id'] ?? $callId),
                'current_bytes' => $currentBytes,
                'attempted_bytes' => $attemptedBytes,
                'soft_quota_bytes' => $softQuotaBytes,
                'quota_bytes' => $quotaBytes,
            ],
            'attachment' => null,
        ];
    }

    $attachmentId = videochat_chat_attachment_generate_id();
    $objectKey = videochat_chat_attachment_object_key(
        (string) ($context['id'] ?? $callId),
        (string) ($context['room_id'] ?? ''),
        $attachmentId,
        (string) ($data['file_name'] ?? 'attachment')
    );

    if (!videochat_chat_attachment_store_put($objectKey, (string) $data['binary'], (string) $data['content_type'])) {
        return [
            'ok' => false,
            'reason' => 'storage_failed',
            'code' => 'chat_attachment_storage_failed',
            'message' => 'Could not store chat attachment in the King Object Store.',
            'details' => ['call_id' => (string) ($context['id'] ?? $callId)],
            'attachment' => null,
        ];
    }

    $createdAt = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_chat_attachments(
    id,
    call_id,
    room_id,
    uploaded_by_user_id,
    original_name,
    content_type,
    size_bytes,
    kind,
    extension,
    object_key,
    status,
    created_at
) VALUES(
    :id,
    :call_id,
    :room_id,
    :uploaded_by_user_id,
    :original_name,
    :content_type,
    :size_bytes,
    :kind,
    :extension,
    :object_key,
    'draft',
    :created_at
)
SQL
    );
    $insert->execute([
        ':id' => $attachmentId,
        ':call_id' => (string) ($context['id'] ?? $callId),
        ':room_id' => (string) ($context['room_id'] ?? ''),
        ':uploaded_by_user_id' => $userId,
        ':original_name' => (string) ($data['original_name'] ?? $data['file_name'] ?? 'attachment'),
        ':content_type' => (string) ($data['content_type'] ?? 'application/octet-stream'),
        ':size_bytes' => (int) ($data['size_bytes'] ?? 0),
        ':kind' => (string) ($data['kind'] ?? 'document'),
        ':extension' => (string) ($data['extension'] ?? ''),
        ':object_key' => $objectKey,
        ':created_at' => $createdAt,
    ]);

    $attachment = videochat_chat_attachment_public_payload([
        'id' => $attachmentId,
        'call_id' => (string) ($context['id'] ?? $callId),
        'room_id' => (string) ($context['room_id'] ?? ''),
        'uploaded_by_user_id' => $userId,
        'original_name' => (string) ($data['original_name'] ?? $data['file_name'] ?? 'attachment'),
        'content_type' => (string) ($data['content_type'] ?? 'application/octet-stream'),
        'size_bytes' => (int) ($data['size_bytes'] ?? 0),
        'kind' => (string) ($data['kind'] ?? 'document'),
        'extension' => (string) ($data['extension'] ?? ''),
        'object_key' => $objectKey,
        'status' => 'draft',
        'created_at' => $createdAt,
    ]);

    return [
        'ok' => true,
        'reason' => 'uploaded',
        'code' => '',
        'message' => '',
        'details' => [
            'quota' => [
                'current_bytes' => $currentBytes,
                'attempted_bytes' => $attemptedBytes,
                'next_bytes' => $nextBytes,
                'soft_quota_bytes' => $softQuotaBytes,
                'hard_quota_bytes' => $quotaBytes,
                'soft_exceeded' => $nextBytes > $softQuotaBytes,
            ],
        ],
        'attachment' => $attachment,
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function videochat_chat_attachment_public_payload(array $row): array
{
    $callId = (string) ($row['call_id'] ?? '');
    $attachmentId = (string) ($row['id'] ?? '');

    return [
        'id' => $attachmentId,
        'name' => (string) ($row['original_name'] ?? 'attachment'),
        'content_type' => (string) ($row['content_type'] ?? 'application/octet-stream'),
        'size_bytes' => (int) ($row['size_bytes'] ?? 0),
        'kind' => (string) ($row['kind'] ?? 'document'),
        'extension' => (string) ($row['extension'] ?? ''),
        'download_url' => '/api/calls/' . rawurlencode($callId) . '/chat/attachments/' . rawurlencode($attachmentId),
    ];
}

/**
 * @param array<int, string> $attachmentIds
 * @return array{ok: bool, error: string, attachments: array<int, array<string, mixed>>}
 */
function videochat_chat_attachment_resolve_for_message(
    PDO $pdo,
    array $attachmentIds,
    string $callId,
    string $roomId,
    int $senderUserId,
    string $messageId
): array {
    videochat_chat_attachments_bootstrap($pdo);
    if ($attachmentIds === []) {
        return ['ok' => true, 'error' => '', 'attachments' => []];
    }
    if (count($attachmentIds) > videochat_chat_attachment_max_count()) {
        return ['ok' => false, 'error' => 'attachment_count_exceeded', 'attachments' => []];
    }

    $attachments = [];
    $imageCount = 0;
    $totalBytes = 0;
    $seen = [];
    foreach ($attachmentIds as $attachmentId) {
        $normalizedId = trim((string) $attachmentId);
        if ($normalizedId === '' || isset($seen[$normalizedId])) {
            return ['ok' => false, 'error' => 'invalid_attachment_ref', 'attachments' => []];
        }
        $seen[$normalizedId] = true;

        $statement = $pdo->prepare(
            <<<'SQL'
SELECT *
FROM call_chat_attachments
WHERE id = :id
  AND call_id = :call_id
  AND room_id = :room_id
  AND uploaded_by_user_id = :uploaded_by_user_id
  AND status = 'draft'
LIMIT 1
SQL
        );
        $statement->execute([
            ':id' => $normalizedId,
            ':call_id' => $callId,
            ':room_id' => $roomId,
            ':uploaded_by_user_id' => $senderUserId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'attachment_not_found', 'attachments' => []];
        }

        if ((string) ($row['kind'] ?? '') === 'image') {
            $imageCount += 1;
        }
        $totalBytes += (int) ($row['size_bytes'] ?? 0);
        $attachments[] = videochat_chat_attachment_public_payload($row);
    }

    if ($imageCount > videochat_chat_attachment_max_images()) {
        return ['ok' => false, 'error' => 'attachment_count_exceeded', 'attachments' => []];
    }
    if ($totalBytes > videochat_chat_attachment_max_message_bytes()) {
        return ['ok' => false, 'error' => 'attachment_too_large', 'attachments' => []];
    }

    $attachedAt = gmdate('c');
    $mark = $pdo->prepare(
        <<<'SQL'
UPDATE call_chat_attachments
SET status = 'attached',
    attached_message_id = :message_id,
    attached_at = :attached_at
WHERE id = :id
  AND status = 'draft'
SQL
    );
    foreach ($attachmentIds as $attachmentId) {
        $mark->execute([
            ':id' => trim((string) $attachmentId),
            ':message_id' => $messageId,
            ':attached_at' => $attachedAt,
        ]);
    }

    return ['ok' => true, 'error' => '', 'attachments' => $attachments];
}

/**
 * @return array{ok: bool, reason: string, code: string, message: string, details: array<string, mixed>}
 */
function videochat_chat_attachment_cancel_draft(PDO $pdo, string $callId, string $attachmentId, int $userId, string $role): array
{
    videochat_chat_attachments_bootstrap($pdo);
    $context = videochat_chat_attachment_call_context($pdo, $callId, $userId, $role);
    if ($context === null) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'code' => 'chat_attachment_forbidden',
            'message' => 'User is not allowed to delete this chat attachment draft.',
            'details' => ['call_id' => $callId],
        ];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_chat_attachments
WHERE id = :id
  AND call_id = :call_id
  AND uploaded_by_user_id = :uploaded_by_user_id
  AND status = 'draft'
LIMIT 1
SQL
    );
    $statement->execute([
        ':id' => trim($attachmentId),
        ':call_id' => (string) ($context['id'] ?? $callId),
        ':uploaded_by_user_id' => $userId,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'code' => 'chat_attachment_not_found',
            'message' => 'Chat attachment draft does not exist or is already attached.',
            'details' => ['attachment_id' => $attachmentId],
        ];
    }

    $objectKey = (string) ($row['object_key'] ?? '');
    if ($objectKey === '' || !videochat_chat_attachment_store_delete($objectKey)) {
        return [
            'ok' => false,
            'reason' => 'storage_failed',
            'code' => 'chat_attachment_storage_failed',
            'message' => 'Could not delete chat attachment draft from the King Object Store.',
            'details' => ['attachment_id' => $attachmentId],
        ];
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_chat_attachments
SET status = 'deleted'
WHERE id = :id
  AND status = 'draft'
SQL
    );
    $update->execute([':id' => trim($attachmentId)]);

    return [
        'ok' => true,
        'reason' => 'deleted',
        'code' => '',
        'message' => '',
        'details' => ['attachment_id' => trim($attachmentId)],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function videochat_chat_attachment_fetch_for_download(PDO $pdo, string $callId, string $attachmentId, int $userId, string $role): ?array
{
    videochat_chat_attachments_bootstrap($pdo);
    $context = videochat_chat_attachment_call_context($pdo, $callId, $userId, $role, ['scheduled', 'active', 'ended']);
    if ($context === null) {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_chat_attachments
WHERE id = :id
  AND call_id = :call_id
  AND status = 'attached'
LIMIT 1
SQL
    );
    $statement->execute([
        ':id' => trim($attachmentId),
        ':call_id' => (string) ($context['id'] ?? $callId),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
