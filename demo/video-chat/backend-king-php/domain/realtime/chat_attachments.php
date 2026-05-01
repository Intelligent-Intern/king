<?php

declare(strict_types=1);

require_once __DIR__ . '/chat_attachment_contract.php';
require_once __DIR__ . '/chat_attachment_storage.php';

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
