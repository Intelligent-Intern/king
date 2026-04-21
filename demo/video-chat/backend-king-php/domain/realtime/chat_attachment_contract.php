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
