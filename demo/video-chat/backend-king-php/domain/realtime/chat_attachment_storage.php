<?php

declare(strict_types=1);

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
    $statement->bindValue(':call_id', $normalizedCallId, PDO::PARAM_STR);
    $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $statement->bindValue(':is_admin', videochat_normalize_role_slug($role) === 'admin' ? 1 : 0, PDO::PARAM_INT);
    foreach ($statusParams as $placeholder => $status) {
        $statement->bindValue($placeholder, $status, PDO::PARAM_STR);
    }
    $statement->execute();
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
        return videochat_chat_attachment_local_store_put($objectKey, $binary);
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return videochat_chat_attachment_local_store_put($objectKey, $binary);
    }
    fwrite($stream, $binary);
    rewind($stream);

    try {
        $stored = king_object_store_put_from_stream($objectKey, $stream, [
            'content_type' => $contentType,
            'cache_class' => 'private',
        ]) === true;
        return $stored || videochat_chat_attachment_local_store_put($objectKey, $binary);
    } catch (Throwable) {
        return videochat_chat_attachment_local_store_put($objectKey, $binary);
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
        return videochat_chat_attachment_local_store_get($objectKey);
    }

    try {
        $stored = king_object_store_get($objectKey);
        return is_string($stored) ? $stored : videochat_chat_attachment_local_store_get($objectKey);
    } catch (Throwable) {
        return videochat_chat_attachment_local_store_get($objectKey);
    }
}

function videochat_chat_attachment_store_delete(string $objectKey): bool
{
    $override = $GLOBALS['videochat_chat_attachment_store_delete'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey) === true;
    }

    if (!function_exists('king_object_store_delete')) {
        return videochat_chat_attachment_local_store_delete($objectKey);
    }

    try {
        $deleted = king_object_store_delete($objectKey) === true;
        $localDeleted = videochat_chat_attachment_local_store_delete($objectKey);
        return $deleted || $localDeleted;
    } catch (Throwable) {
        return videochat_chat_attachment_local_store_delete($objectKey);
    }
}

function videochat_chat_attachment_local_store_path(string $objectKey): ?string
{
    $normalizedKey = trim($objectKey);
    if ($normalizedKey === '' || preg_match('/^[A-Za-z0-9._-]{1,127}$/', $normalizedKey) !== 1) {
        return null;
    }

    $root = trim((string) ($GLOBALS['videochat_chat_attachment_object_store_root'] ?? (getenv('VIDEOCHAT_OBJECT_STORE_ROOT') ?: '')));
    if ($root === '') {
        return null;
    }
    if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
        return null;
    }

    return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedKey;
}

function videochat_chat_attachment_local_store_put(string $objectKey, string $binary): bool
{
    $path = videochat_chat_attachment_local_store_path($objectKey);
    if (!is_string($path)) {
        return false;
    }

    return @file_put_contents($path, $binary, LOCK_EX) !== false;
}

function videochat_chat_attachment_local_store_get(string $objectKey): string|false
{
    $path = videochat_chat_attachment_local_store_path($objectKey);
    if (!is_string($path) || !is_file($path)) {
        return false;
    }

    $payload = @file_get_contents($path);
    return is_string($payload) ? $payload : false;
}

function videochat_chat_attachment_local_store_delete(string $objectKey): bool
{
    $path = videochat_chat_attachment_local_store_path($objectKey);
    if (!is_string($path) || !is_file($path)) {
        return false;
    }

    return @unlink($path);
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
