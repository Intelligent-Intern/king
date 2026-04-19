<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';
require_once __DIR__ . '/../domain/realtime/chat_attachments.php';

function videochat_chat_attachment_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[chat-attachment-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-chat-attachments-' . bin2hex(random_bytes(6)) . '.sqlite';
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_chat_attachments_bootstrap($pdo);

    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_chat_attachment_contract_assert($adminRoleId > 0 && $userRoleId > 0, 'expected seeded roles');

    $now = gmdate('c');
    $userId = 9101;
    $otherUserId = 9102;
    $ownerUserId = 9100;
    $roomId = 'room-chat-attachments-contract';
    $callId = 'call-chat-attachments-contract';

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO users(id, email, display_name, password_hash, role_id, status, created_at, updated_at)
VALUES(:id, :email, :display_name, :password_hash, :role_id, 'active', :created_at, :updated_at)
SQL
    );
    foreach ([
        [$ownerUserId, 'owner-chat-attachments-contract@example.test', 'Contract Owner', $adminRoleId],
        [$userId, 'user-chat-attachments-contract@example.test', 'Contract User', $userRoleId],
        [$otherUserId, 'other-chat-attachments-contract@example.test', 'Other User', $userRoleId],
    ] as [$id, $email, $displayName, $roleId]) {
        $insertUser->execute([
            ':id' => $id,
            ':email' => $email,
            ':display_name' => $displayName,
            ':password_hash' => password_hash('pw', PASSWORD_DEFAULT),
            ':role_id' => $roleId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, 'Attachment Contract Room', 'private', 'active', :owner_user_id, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':owner_user_id' => $ownerUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO calls(id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, :room_id, 'Attachment Contract Call', 'invite_only', :owner_user_id, 'active', :starts_at, :ends_at, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $callId,
        ':room_id' => $roomId,
        ':owner_user_id' => $ownerUserId,
        ':starts_at' => $now,
        ':ends_at' => gmdate('c', time() + 3600),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', :call_role, 'allowed', :joined_at, NULL)
SQL
    );
    foreach ([
        [$ownerUserId, 'owner-chat-attachments-contract@example.test', 'Contract Owner', 'owner'],
        [$userId, 'user-chat-attachments-contract@example.test', 'Contract User', 'participant'],
    ] as [$participantUserId, $email, $displayName, $callRole]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $participantUserId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':call_role' => $callRole,
            ':joined_at' => $now,
        ]);
    }

    $objects = [];
    $GLOBALS['videochat_chat_attachment_store_put'] = static function (string $objectKey, string $binary, string $contentType) use (&$objects): bool {
        $objects[$objectKey] = [
            'binary' => $binary,
            'content_type' => $contentType,
        ];
        return true;
    };
    $GLOBALS['videochat_chat_attachment_store_get'] = static function (string $objectKey) use (&$objects): string|false {
        return is_array($objects[$objectKey] ?? null) ? (string) $objects[$objectKey]['binary'] : false;
    };
    $GLOBALS['videochat_chat_attachment_store_delete'] = static function (string $objectKey) use (&$objects): bool {
        if (!isset($objects[$objectKey])) {
            return false;
        }
        unset($objects[$objectKey]);
        return true;
    };

    $pngBinary = "\x89PNG\x0d\x0a\x1a\x0a" . 'contract-png';
    $upload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'screen.png',
        'content_type' => 'image/png',
        'content_base64' => base64_encode($pngBinary),
    ]);
    videochat_chat_attachment_contract_assert((bool) ($upload['ok'] ?? false), 'png upload should succeed');
    $attachment = is_array($upload['attachment'] ?? null) ? $upload['attachment'] : [];
    videochat_chat_attachment_contract_assert((string) ($attachment['id'] ?? '') !== '', 'upload returns attachment id');
    videochat_chat_attachment_contract_assert((string) ($attachment['kind'] ?? '') === 'image', 'upload image kind mismatch');
    videochat_chat_attachment_contract_assert((string) ($attachment['download_url'] ?? '') === '/api/calls/' . rawurlencode($callId) . '/chat/attachments/' . rawurlencode((string) $attachment['id']), 'download url mismatch');

    $storedRows = $pdo->query('SELECT * FROM call_chat_attachments')->fetchAll(PDO::FETCH_ASSOC);
    videochat_chat_attachment_contract_assert(count($storedRows) === 1, 'one attachment metadata row expected');
    $objectKey = (string) ($storedRows[0]['object_key'] ?? '');
    videochat_chat_attachment_contract_assert(str_starts_with($objectKey, 'vcchat_' . substr(hash('sha256', $callId), 0, 16) . '_' . substr(hash('sha256', $roomId), 0, 16) . '_'), 'object key should be call and room scoped');
    videochat_chat_attachment_contract_assert(strlen($objectKey) <= 127 && !str_contains($objectKey, '/') && !str_contains($objectKey, '\\'), 'object key must satisfy King object-store id rules');
    videochat_chat_attachment_contract_assert(isset($objects[$objectKey]), 'object store write should use object key');
    videochat_chat_attachment_contract_assert(is_array($upload['details']['quota'] ?? null), 'upload should report call quota details');

    $resolve = videochat_chat_attachment_resolve_for_message($pdo, [(string) $attachment['id']], $callId, $roomId, $userId, 'chat_contract_msg_001');
    videochat_chat_attachment_contract_assert((bool) ($resolve['ok'] ?? false), 'attachment resolve should succeed');
    videochat_chat_attachment_contract_assert(count($resolve['attachments'] ?? []) === 1, 'resolved attachment count mismatch');
    videochat_chat_attachment_contract_assert((string) (($resolve['attachments'][0] ?? [])['name'] ?? '') === 'screen.png', 'resolved attachment name mismatch');

    $downloadRow = videochat_chat_attachment_fetch_for_download($pdo, $callId, (string) $attachment['id'], $userId, 'user');
    videochat_chat_attachment_contract_assert(is_array($downloadRow), 'participant should fetch attached download metadata');
    videochat_chat_attachment_contract_assert(videochat_chat_attachment_store_get((string) ($downloadRow['object_key'] ?? '')) === $pngBinary, 'object store download bytes mismatch');

    $forbiddenDownload = videochat_chat_attachment_fetch_for_download($pdo, $callId, (string) $attachment['id'], $otherUserId, 'user');
    videochat_chat_attachment_contract_assert($forbiddenDownload === null, 'non participant download must be denied');

    $repeatResolve = videochat_chat_attachment_resolve_for_message($pdo, [(string) $attachment['id']], $callId, $roomId, $userId, 'chat_contract_msg_002');
    videochat_chat_attachment_contract_assert(!(bool) ($repeatResolve['ok'] ?? true), 'already attached draft must not be reattached');
    videochat_chat_attachment_contract_assert((string) ($repeatResolve['error'] ?? '') === 'attachment_not_found', 'already attached error mismatch');

    $textUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'notes.md',
        'content_type' => 'text/markdown',
        'content_base64' => base64_encode("# Notes\n\n- one\n- two\n"),
    ]);
    videochat_chat_attachment_contract_assert((bool) ($textUpload['ok'] ?? false), 'markdown upload should succeed');
    videochat_chat_attachment_contract_assert((string) (($textUpload['attachment'] ?? [])['kind'] ?? '') === 'text', 'markdown kind mismatch');

    $cancelUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'cancelled.txt',
        'content_type' => 'text/plain',
        'content_base64' => base64_encode('cancel me'),
    ]);
    videochat_chat_attachment_contract_assert((bool) ($cancelUpload['ok'] ?? false), 'draft upload before cancel should succeed');
    $cancelAttachment = is_array($cancelUpload['attachment'] ?? null) ? $cancelUpload['attachment'] : [];
    $cancelResult = videochat_chat_attachment_cancel_draft($pdo, $callId, (string) ($cancelAttachment['id'] ?? ''), $userId, 'user');
    videochat_chat_attachment_contract_assert((bool) ($cancelResult['ok'] ?? false), 'draft cancel should succeed');
    $cancelStatement = $pdo->prepare('SELECT status, object_key FROM call_chat_attachments WHERE id = :id');
    $cancelStatement->execute([':id' => (string) ($cancelAttachment['id'] ?? '')]);
    $cancelled = $cancelStatement->fetch(PDO::FETCH_ASSOC);
    videochat_chat_attachment_contract_assert(is_array($cancelled) && (string) ($cancelled['status'] ?? '') === 'deleted', 'cancelled draft should be marked deleted');
    videochat_chat_attachment_contract_assert(!isset($objects[(string) ($cancelled['object_key'] ?? '')]), 'cancelled draft should be removed from object store');

    $pdfUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'deck.pdf',
        'content_type' => 'application/pdf',
        'content_base64' => base64_encode("%PDF-1.4\n%contract\n"),
    ]);
    videochat_chat_attachment_contract_assert((bool) ($pdfUpload['ok'] ?? false), 'pdf upload should succeed');

    $docxUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'brief.docx',
        'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'content_base64' => base64_encode("PK\x03\x04[Content_Types].xml word/document.xml"),
    ]);
    videochat_chat_attachment_contract_assert((bool) ($docxUpload['ok'] ?? false), 'docx upload should validate OOXML container');

    $exeUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'payload.exe',
        'content_type' => 'application/octet-stream',
        'content_base64' => base64_encode('MZ' . random_bytes(8)),
    ]);
    videochat_chat_attachment_contract_assert(!(bool) ($exeUpload['ok'] ?? true), 'exe upload should fail');
    videochat_chat_attachment_contract_assert((string) ($exeUpload['code'] ?? '') === 'attachment_type_not_allowed', 'exe error code mismatch');

    $badZipUpload = videochat_chat_attachment_upload($pdo, $callId, $userId, 'user', [
        'file_name' => 'archive.zip',
        'content_type' => 'application/zip',
        'content_base64' => base64_encode("PK\x03\x04anything"),
    ]);
    videochat_chat_attachment_contract_assert(!(bool) ($badZipUpload['ok'] ?? true), 'raw zip upload should fail');
    videochat_chat_attachment_contract_assert((string) ($badZipUpload['code'] ?? '') === 'attachment_type_not_allowed', 'zip error code mismatch');

    unset($GLOBALS['videochat_chat_attachment_store_put'], $GLOBALS['videochat_chat_attachment_store_get'], $GLOBALS['videochat_chat_attachment_store_delete']);
    @unlink($databasePath);

    fwrite(STDOUT, "[chat-attachment-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[chat-attachment-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
