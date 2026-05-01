<?php

declare(strict_types=1);

function videochat_chat_archive_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_messages (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id TEXT NOT NULL UNIQUE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_display_name TEXT NOT NULL,
    sender_role TEXT NOT NULL DEFAULT 'user',
    text TEXT NOT NULL,
    message_json TEXT NOT NULL,
    transcript_object_key TEXT NOT NULL UNIQUE,
    server_unix_ms INTEGER NOT NULL,
    server_time TEXT NOT NULL,
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_acl (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    access_role TEXT NOT NULL CHECK (access_role IN ('owner', 'moderator', 'participant', 'admin')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (call_id, user_id)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_messages_call_seq ON call_chat_messages(call_id, seq)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_messages_room_time ON call_chat_messages(room_id, server_unix_ms)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_messages_sender ON call_chat_messages(sender_user_id, seq)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_chat_acl_user_id ON call_chat_acl(user_id)');
}

function videochat_chat_archive_normalize_call_id(string $callId): string
{
    $normalized = trim($callId);
    return preg_match('/^[A-Za-z0-9._-]{1,200}$/', $normalized) === 1 ? $normalized : '';
}

function videochat_chat_archive_object_key(string $callId, string $roomId, string $messageId): string
{
    $callHash = substr(hash('sha256', $callId), 0, 16);
    $roomHash = substr(hash('sha256', $roomId), 0, 16);
    $messageHash = substr(hash('sha256', $messageId), 0, 32);

    return 'vcarch_' . $callHash . '_' . $roomHash . '_' . $messageHash;
}

function videochat_chat_archive_store_put(string $objectKey, string $json): bool
{
    $override = $GLOBALS['videochat_chat_archive_store_put'] ?? null;
    if (is_callable($override)) {
        return $override($objectKey, $json, 'application/json') === true;
    }

    if (!function_exists('king_object_store_put_from_stream')) {
        return false;
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        return false;
    }
    fwrite($stream, $json);
    rewind($stream);

    try {
        return king_object_store_put_from_stream($objectKey, $stream, [
            'content_type' => 'application/json',
            'cache_class' => 'private',
        ]) === true;
    } catch (Throwable) {
        return false;
    } finally {
        fclose($stream);
    }
}

function videochat_chat_archive_store_get(string $objectKey): string|false
{
    $override = $GLOBALS['videochat_chat_archive_store_get'] ?? null;
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

function videochat_chat_archive_file_group(array $attachment): string
{
    $kind = strtolower(trim((string) ($attachment['kind'] ?? 'document')));
    $extension = strtolower(trim((string) ($attachment['extension'] ?? '')));
    if ($kind === 'image') {
        return 'images';
    }
    if ($kind === 'pdf') {
        return 'pdfs';
    }
    if ($kind === 'text' || in_array($extension, ['txt', 'csv', 'md'], true)) {
        return 'text';
    }
    if (in_array($extension, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp'], true)) {
        return 'office';
    }

    return 'documents';
}

function videochat_chat_archive_normalize_file_kind(mixed $value): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['all', 'image', 'pdf', 'office', 'text', 'document'], true) ? $normalized : 'all';
}

function videochat_chat_archive_file_kind_matches(array $attachment, string $fileKind): bool
{
    if ($fileKind === 'all') {
        return true;
    }

    $group = videochat_chat_archive_file_group($attachment);
    return match ($fileKind) {
        'image' => $group === 'images',
        'pdf' => $group === 'pdfs',
        'office' => $group === 'office',
        'text' => $group === 'text',
        'document' => in_array($group, ['office', 'documents'], true),
        default => true,
    };
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   context: array<string, mixed>|null
 * }
 */
function videochat_chat_archive_access_context(PDO $pdo, string $callId, int $userId, string $role): array
{
    $normalizedCallId = videochat_chat_archive_normalize_call_id($callId);
    if ($normalizedCallId === '' || $userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['call_id' => 'invalid_call_id'],
            'context' => null,
        ];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    calls.id,
    calls.room_id,
    calls.owner_user_id,
    calls.status,
    cp.call_role,
    users.email,
    users.password_hash,
    users.display_name
FROM calls
INNER JOIN users ON users.id = :user_id
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = users.id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  AND calls.status IN ('scheduled', 'active', 'ended')
  AND users.status = 'active'
LIMIT 1
SQL
    );
    $statement->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'context' => null,
        ];
    }

    if (function_exists('videochat_user_is_guest_account') && videochat_user_is_guest_account(
        is_string($row['email'] ?? null) ? (string) $row['email'] : '',
        $row['password_hash'] ?? null
    )) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['user' => 'guest_archive_access_denied'],
            'context' => null,
        ];
    }

    $normalizedRole = videochat_normalize_role_slug($role);
    $isAdmin = $normalizedRole === 'admin';
    $isOwner = (int) ($row['owner_user_id'] ?? 0) === $userId;
    $participantRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
    $isParticipant = is_string($row['call_role'] ?? null) && trim((string) $row['call_role']) !== '';

    if (!$isAdmin && !$isOwner && !$isParticipant) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'context' => null,
        ];
    }

    $archiveRole = 'participant';
    if ($isAdmin) {
        $archiveRole = 'admin';
    } elseif ($isOwner) {
        $archiveRole = 'owner';
    } elseif ($participantRole === 'moderator') {
        $archiveRole = 'moderator';
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'context' => [
            'call_id' => (string) ($row['id'] ?? $normalizedCallId),
            'room_id' => (string) ($row['room_id'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'user_id' => $userId,
            'access_role' => $archiveRole,
            'display_name' => (string) ($row['display_name'] ?? ''),
        ],
    ];
}

function videochat_chat_archive_sync_acl(PDO $pdo, string $callId): void
{
    videochat_chat_archive_bootstrap($pdo);
    $normalizedCallId = videochat_chat_archive_normalize_call_id($callId);
    if ($normalizedCallId === '') {
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_chat_acl(call_id, user_id, access_role, created_at)
VALUES(:call_id, :user_id, :access_role, :created_at)
ON CONFLICT(call_id, user_id) DO UPDATE SET access_role = excluded.access_role
SQL
    );
    $createdAt = gmdate('c');

    $ownerQuery = $pdo->prepare('SELECT owner_user_id FROM calls WHERE id = :call_id LIMIT 1');
    $ownerQuery->execute([':call_id' => $normalizedCallId]);
    $ownerUserId = (int) ($ownerQuery->fetchColumn() ?: 0);
    if ($ownerUserId > 0) {
        $insert->execute([
            ':call_id' => $normalizedCallId,
            ':user_id' => $ownerUserId,
            ':access_role' => 'owner',
            ':created_at' => $createdAt,
        ]);
    }

    $participants = $pdo->prepare(
        <<<'SQL'
SELECT user_id, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id IS NOT NULL
  AND source = 'internal'
SQL
    );
    $participants->execute([':call_id' => $normalizedCallId]);
    while (($row = $participants->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $participantUserId = (int) ($row['user_id'] ?? 0);
        if ($participantUserId <= 0) {
            continue;
        }
        $role = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
        $insert->execute([
            ':call_id' => $normalizedCallId,
            ':user_id' => $participantUserId,
            ':access_role' => in_array($role, ['owner', 'moderator'], true) ? $role : 'participant',
            ':created_at' => $createdAt,
        ]);
    }
}

/**
 * @return array{ok: bool, reason: string, message_id: string, object_key: string}
 */
function videochat_chat_archive_append_message(PDO $pdo, string $callId, string $roomId, array $event): array
{
    videochat_chat_archive_bootstrap($pdo);
    if (function_exists('videochat_chat_attachments_bootstrap')) {
        videochat_chat_attachments_bootstrap($pdo);
    }

    $normalizedCallId = videochat_chat_archive_normalize_call_id($callId);
    $normalizedRoomId = trim($roomId);
    if ($normalizedCallId === '' || $normalizedRoomId === '') {
        return ['ok' => false, 'reason' => 'invalid_context', 'message_id' => '', 'object_key' => ''];
    }

    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    $messageId = trim((string) ($message['id'] ?? ''));
    if ($messageId === '') {
        return ['ok' => false, 'reason' => 'missing_message_id', 'message_id' => '', 'object_key' => ''];
    }

    $exists = $pdo->prepare('SELECT transcript_object_key FROM call_chat_messages WHERE message_id = :message_id LIMIT 1');
    $exists->execute([':message_id' => $messageId]);
    $existingObjectKey = $exists->fetchColumn();
    if (is_string($existingObjectKey) && trim($existingObjectKey) !== '') {
        return [
            'ok' => true,
            'reason' => 'already_archived',
            'message_id' => $messageId,
            'object_key' => trim($existingObjectKey),
        ];
    }

    $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];
    $senderUserId = (int) ($sender['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_sender', 'message_id' => $messageId, 'object_key' => ''];
    }

    $messageJson = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($messageJson) || $messageJson === '') {
        return ['ok' => false, 'reason' => 'invalid_message_json', 'message_id' => $messageId, 'object_key' => ''];
    }

    $snapshot = [
        'version' => 1,
        'call_id' => $normalizedCallId,
        'room_id' => $normalizedRoomId,
        'event' => $event,
        'message' => $message,
        'archived_at' => gmdate('c'),
    ];
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($snapshotJson) || $snapshotJson === '') {
        return ['ok' => false, 'reason' => 'invalid_snapshot_json', 'message_id' => $messageId, 'object_key' => ''];
    }

    $objectKey = videochat_chat_archive_object_key($normalizedCallId, $normalizedRoomId, $messageId);
    if (!videochat_chat_archive_store_put($objectKey, $snapshotJson)) {
        return ['ok' => false, 'reason' => 'storage_failed', 'message_id' => $messageId, 'object_key' => $objectKey];
    }

    videochat_chat_archive_sync_acl($pdo, $normalizedCallId);

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO call_chat_messages(
    message_id,
    call_id,
    room_id,
    sender_user_id,
    sender_display_name,
    sender_role,
    text,
    message_json,
    transcript_object_key,
    server_unix_ms,
    server_time,
    snapshot_version,
    created_at
) VALUES(
    :message_id,
    :call_id,
    :room_id,
    :sender_user_id,
    :sender_display_name,
    :sender_role,
    :text,
    :message_json,
    :transcript_object_key,
    :server_unix_ms,
    :server_time,
    1,
    :created_at
)
SQL
    );
    $serverUnixMs = (int) ($message['server_unix_ms'] ?? 0);
    $serverTime = trim((string) ($message['server_time'] ?? ''));
    if ($serverUnixMs <= 0) {
        $serverUnixMs = (int) floor(microtime(true) * 1000);
    }
    if ($serverTime === '') {
        $serverTime = gmdate('c', (int) floor($serverUnixMs / 1000));
    }

    $insert->execute([
        ':message_id' => $messageId,
        ':call_id' => $normalizedCallId,
        ':room_id' => $normalizedRoomId,
        ':sender_user_id' => $senderUserId,
        ':sender_display_name' => trim((string) ($sender['display_name'] ?? '')),
        ':sender_role' => videochat_normalize_role_slug((string) ($sender['role'] ?? 'user')),
        ':text' => (string) ($message['text'] ?? ''),
        ':message_json' => $messageJson,
        ':transcript_object_key' => $objectKey,
        ':server_unix_ms' => $serverUnixMs,
        ':server_time' => $serverTime,
        ':created_at' => gmdate('c'),
    ]);

    return [
        'ok' => true,
        'reason' => 'archived',
        'message_id' => $messageId,
        'object_key' => $objectKey,
    ];
}

/**
 * @param array<int, string> $messageIds
 * @return array<string, array<int, array<string, mixed>>>
 */
function videochat_chat_archive_attachments_by_message(PDO $pdo, string $callId, array $messageIds): array
{
    if ($messageIds === []) {
        return [];
    }

    $placeholders = [];
    $params = [':call_id' => $callId];
    foreach (array_values($messageIds) as $index => $messageId) {
        $placeholder = ':message_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $messageId;
    }

    $statement = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT
    a.*,
    users.display_name AS uploaded_by_display_name
FROM call_chat_attachments a
LEFT JOIN users ON users.id = a.uploaded_by_user_id
WHERE a.call_id = :call_id
  AND a.status = 'attached'
  AND a.attached_message_id IN (%s)
ORDER BY a.attached_at ASC, a.created_at ASC, a.id ASC
SQL
            ,
            implode(', ', $placeholders)
        )
    );
    $statement->execute($params);

    $attachmentsByMessage = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $messageId = (string) ($row['attached_message_id'] ?? '');
        if ($messageId === '') {
            continue;
        }
        $payload = videochat_chat_attachment_public_payload($row);
        $payload['sender'] = [
            'user_id' => (int) ($row['uploaded_by_user_id'] ?? 0),
            'display_name' => (string) ($row['uploaded_by_display_name'] ?? ''),
        ];
        $payload['created_at'] = (string) ($row['created_at'] ?? '');
        $payload['attached_at'] = (string) ($row['attached_at'] ?? '');

        if (!isset($attachmentsByMessage[$messageId])) {
            $attachmentsByMessage[$messageId] = [];
        }
        $attachmentsByMessage[$messageId][] = $payload;
    }

    return $attachmentsByMessage;
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function videochat_chat_archive_group_files(PDO $pdo, string $callId, string $fileKind, string $query, int $senderUserId): array
{
    $groups = [
        'images' => [],
        'pdfs' => [],
        'office' => [],
        'text' => [],
        'documents' => [],
    ];
    $where = [
        "a.call_id = :call_id",
        "a.status = 'attached'",
    ];
    $params = [':call_id' => $callId];
    $normalizedQuery = strtolower(trim($query));
    if ($normalizedQuery !== '') {
        $where[] = "(lower(a.original_name) LIKE :query OR lower(a.content_type) LIKE :query)";
        $params[':query'] = '%' . $normalizedQuery . '%';
    }
    if ($senderUserId > 0) {
        $where[] = 'a.uploaded_by_user_id = :sender_user_id';
        $params[':sender_user_id'] = $senderUserId;
    }

    $statement = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT
    a.*,
    users.display_name AS uploaded_by_display_name
FROM call_chat_attachments a
LEFT JOIN users ON users.id = a.uploaded_by_user_id
WHERE %s
ORDER BY a.attached_at ASC, a.created_at ASC, a.id ASC
LIMIT 200
SQL
            ,
            implode(' AND ', $where)
        )
    );
    $statement->execute($params);

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $payload = videochat_chat_attachment_public_payload($row);
        $payload['sender'] = [
            'user_id' => (int) ($row['uploaded_by_user_id'] ?? 0),
            'display_name' => (string) ($row['uploaded_by_display_name'] ?? ''),
        ];
        $payload['created_at'] = (string) ($row['created_at'] ?? '');
        $payload['attached_at'] = (string) ($row['attached_at'] ?? '');
        if (!videochat_chat_archive_file_kind_matches($payload, $fileKind)) {
            continue;
        }

        $groups[videochat_chat_archive_file_group($payload)][] = $payload;
    }

    return $groups;
}

/**
 * @return array{ok: bool, reason: string, errors: array<string, string>, archive: array<string, mixed>|null}
 */
function videochat_chat_archive_fetch(PDO $pdo, string $callId, int $userId, string $role, array $queryParams = []): array
{
    videochat_chat_archive_bootstrap($pdo);
    if (function_exists('videochat_chat_attachments_bootstrap')) {
        videochat_chat_attachments_bootstrap($pdo);
    }
    videochat_chat_archive_sync_acl($pdo, $callId);

    $access = videochat_chat_archive_access_context($pdo, $callId, $userId, $role);
    if (!(bool) ($access['ok'] ?? false) || !is_array($access['context'] ?? null)) {
        return [
            'ok' => false,
            'reason' => (string) ($access['reason'] ?? 'forbidden'),
            'errors' => is_array($access['errors'] ?? null) ? $access['errors'] : [],
            'archive' => null,
        ];
    }

    $context = $access['context'];
    $normalizedCallId = (string) ($context['call_id'] ?? $callId);
    $cursor = max(0, (int) ($queryParams['cursor'] ?? 0));
    $limit = max(1, min(100, (int) ($queryParams['limit'] ?? 50)));
    $query = strtolower(trim((string) ($queryParams['q'] ?? ($queryParams['query'] ?? ''))));
    $senderUserId = max(0, (int) ($queryParams['sender_user_id'] ?? 0));
    $fileKind = videochat_chat_archive_normalize_file_kind($queryParams['file_kind'] ?? 'all');

    $where = [
        'call_id = :call_id',
        'seq > :cursor',
    ];
    $params = [
        ':call_id' => $normalizedCallId,
        ':cursor' => $cursor,
    ];
    if ($query !== '') {
        $where[] = '(lower(text) LIKE :query OR lower(sender_display_name) LIKE :query)';
        $params[':query'] = '%' . $query . '%';
    }
    if ($senderUserId > 0) {
        $where[] = 'sender_user_id = :sender_user_id';
        $params[':sender_user_id'] = $senderUserId;
    }

    $statement = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT *
FROM call_chat_messages
WHERE %s
ORDER BY seq ASC
LIMIT :limit
SQL
            ,
            implode(' AND ', $where)
        )
    );
    foreach ($params as $key => $value) {
        $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasNext = count($rows) > $limit;
    if ($hasNext) {
        $rows = array_slice($rows, 0, $limit);
    }

    $messageIds = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $messageIds[] = (string) ($row['message_id'] ?? '');
        }
    }
    $attachmentsByMessage = videochat_chat_archive_attachments_by_message($pdo, $normalizedCallId, array_values(array_filter($messageIds)));

    $messages = [];
    $nextCursor = null;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $messageId = (string) ($row['message_id'] ?? '');
        $nextCursor = (int) ($row['seq'] ?? 0);
        $messages[] = [
            'seq' => (int) ($row['seq'] ?? 0),
            'id' => $messageId,
            'text' => (string) ($row['text'] ?? ''),
            'sender' => [
                'user_id' => (int) ($row['sender_user_id'] ?? 0),
                'display_name' => (string) ($row['sender_display_name'] ?? ''),
                'role' => (string) ($row['sender_role'] ?? 'user'),
            ],
            'server_unix_ms' => (int) ($row['server_unix_ms'] ?? 0),
            'server_time' => (string) ($row['server_time'] ?? ''),
            'attachments' => $attachmentsByMessage[$messageId] ?? [],
        ];
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'archive' => [
            'call_id' => $normalizedCallId,
            'room_id' => (string) ($context['room_id'] ?? ''),
            'read_only' => true,
            'access' => [
                'role' => (string) ($context['access_role'] ?? 'participant'),
                'registered_only' => true,
                'guests_allowed' => false,
            ],
            'messages' => $messages,
            'files' => [
                'groups' => videochat_chat_archive_group_files($pdo, $normalizedCallId, $fileKind, $query, $senderUserId),
                'limit' => 200,
            ],
            'pagination' => [
                'cursor' => $cursor,
                'limit' => $limit,
                'returned' => count($messages),
                'has_next' => $hasNext,
                'next_cursor' => $hasNext ? $nextCursor : null,
            ],
            'filters' => [
                'query' => $query,
                'sender_user_id' => $senderUserId,
                'file_kind' => $fileKind,
                'supported_file_kinds' => ['all', 'image', 'pdf', 'office', 'text', 'document'],
            ],
            'retention' => [
                'policy' => 'call_lifetime_until_call_delete_or_dsgvo_request',
                'delete_path' => 'call deletion cascades chat archive metadata; object-store purge is handled by retention cleanup',
            ],
            'export' => [
                'prepared_formats' => ['json', 'md'],
                'enabled' => false,
            ],
        ],
    ];
}
