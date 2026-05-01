<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';
require_once __DIR__ . '/../domain/realtime/chat_attachments.php';
require_once __DIR__ . '/../domain/realtime/chat_archive.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_chat_archive_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[chat-archive-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_chat_archive_contract_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_chat_archive_contract_error_response(int $status, string $code, string $message, array $details = []): array
{
    return videochat_chat_archive_contract_json_response($status, [
        'status' => 'error',
        'error' => [
            'code' => $code,
            'message' => $message,
            'details' => $details,
        ],
        'time' => gmdate('c'),
    ]);
}

/**
 * @return array{0: array<string, mixed>|null, 1: string|null}
 */
function videochat_chat_archive_contract_decode_json_body(array $request): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-chat-archive-' . bin2hex(random_bytes(6)) . '.sqlite';
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_chat_attachments_bootstrap($pdo);
    videochat_chat_archive_bootstrap($pdo);

    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_chat_archive_contract_assert($adminRoleId > 0 && $userRoleId > 0, 'expected seeded roles');

    $now = gmdate('c');
    $ownerUserId = 9200;
    $participantUserId = 9201;
    $otherUserId = 9202;
    $guestUserId = 9203;
    $adminUserId = 9204;
    $roomId = 'room-chat-archive-contract';
    $callId = 'call-chat-archive-contract';

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO users(id, email, display_name, password_hash, role_id, status, created_at, updated_at)
VALUES(:id, :email, :display_name, :password_hash, :role_id, 'active', :created_at, :updated_at)
SQL
    );
    foreach ([
        [$ownerUserId, 'owner-chat-archive-contract@example.test', 'Archive Owner', password_hash('pw', PASSWORD_DEFAULT), $userRoleId],
        [$participantUserId, 'participant-chat-archive-contract@example.test', 'Archive Participant', password_hash('pw', PASSWORD_DEFAULT), $userRoleId],
        [$otherUserId, 'other-chat-archive-contract@example.test', 'Archive Other', password_hash('pw', PASSWORD_DEFAULT), $userRoleId],
        [$guestUserId, 'guest+' . str_repeat('a', 32) . '@videochat.local', 'Archive Guest', null, $userRoleId],
        [$adminUserId, 'admin-chat-archive-contract@example.test', 'Archive Admin', password_hash('pw', PASSWORD_DEFAULT), $adminRoleId],
    ] as [$id, $email, $displayName, $passwordHash, $roleId]) {
        $insertUser->execute([
            ':id' => $id,
            ':email' => $email,
            ':display_name' => $displayName,
            ':password_hash' => $passwordHash,
            ':role_id' => $roleId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    $pdo->prepare(
        <<<'SQL'
INSERT OR REPLACE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, 'Archive Contract Room', 'private', 'active', :owner_user_id, :created_at, :updated_at)
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
VALUES(:id, :room_id, 'Archive Contract Call', 'invite_only', :owner_user_id, 'active', :starts_at, :ends_at, :created_at, :updated_at)
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
        [$ownerUserId, 'owner-chat-archive-contract@example.test', 'Archive Owner', 'owner'],
        [$participantUserId, 'participant-chat-archive-contract@example.test', 'Archive Participant', 'participant'],
        [$guestUserId, 'guest+' . str_repeat('a', 32) . '@videochat.local', 'Archive Guest', 'participant'],
    ] as [$userId, $email, $displayName, $callRole]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':call_role' => $callRole,
            ':joined_at' => $now,
        ]);
    }

    $objects = [];
    $GLOBALS['videochat_chat_attachment_store_put'] = static function (string $objectKey, string $binary, string $contentType) use (&$objects): bool {
        $objects[$objectKey] = ['binary' => $binary, 'content_type' => $contentType];
        return true;
    };
    $GLOBALS['videochat_chat_attachment_store_get'] = static function (string $objectKey) use (&$objects): string|false {
        return is_array($objects[$objectKey] ?? null) ? (string) $objects[$objectKey]['binary'] : false;
    };
    $GLOBALS['videochat_chat_archive_store_put'] = static function (string $objectKey, string $json, string $contentType) use (&$objects): bool {
        $objects[$objectKey] = ['binary' => $json, 'content_type' => $contentType];
        return true;
    };
    $GLOBALS['videochat_chat_archive_store_get'] = static function (string $objectKey) use (&$objects): string|false {
        return is_array($objects[$objectKey] ?? null) ? (string) $objects[$objectKey]['binary'] : false;
    };

    $pngBinary = "\x89PNG\x0d\x0a\x1a\x0a" . 'archive-png';
    $imageUpload = videochat_chat_attachment_upload($pdo, $callId, $participantUserId, 'user', [
        'file_name' => 'screen.png',
        'content_type' => 'image/png',
        'content_base64' => base64_encode($pngBinary),
    ]);
    videochat_chat_archive_contract_assert((bool) ($imageUpload['ok'] ?? false), 'image attachment upload should succeed');
    $imageAttachmentId = (string) (($imageUpload['attachment'] ?? [])['id'] ?? '');

    $pdfUpload = videochat_chat_attachment_upload($pdo, $callId, $participantUserId, 'user', [
        'file_name' => 'brief.pdf',
        'content_type' => 'application/pdf',
        'content_base64' => base64_encode("%PDF-1.4\narchive\n"),
    ]);
    videochat_chat_archive_contract_assert((bool) ($pdfUpload['ok'] ?? false), 'pdf attachment upload should succeed');
    $pdfAttachmentId = (string) (($pdfUpload['attachment'] ?? [])['id'] ?? '');

    $docUpload = videochat_chat_attachment_upload($pdo, $callId, $participantUserId, 'user', [
        'file_name' => 'brief.docx',
        'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'content_base64' => base64_encode("PK\x03\x04[Content_Types].xml word/document.xml"),
    ]);
    videochat_chat_archive_contract_assert((bool) ($docUpload['ok'] ?? false), 'office attachment upload should succeed');
    $docAttachmentId = (string) (($docUpload['attachment'] ?? [])['id'] ?? '');

    $textUpload = videochat_chat_attachment_upload($pdo, $callId, $participantUserId, 'user', [
        'file_name' => 'notes.md',
        'content_type' => 'text/markdown',
        'content_base64' => base64_encode("# Notes\narchive\n"),
    ]);
    videochat_chat_archive_contract_assert((bool) ($textUpload['ok'] ?? false), 'text attachment upload should succeed');
    $textAttachmentId = (string) (($textUpload['attachment'] ?? [])['id'] ?? '');

    $resolveFirst = videochat_chat_attachment_resolve_for_message(
        $pdo,
        [$imageAttachmentId, $pdfAttachmentId],
        $callId,
        $roomId,
        $participantUserId,
        'chat_archive_msg_001'
    );
    videochat_chat_archive_contract_assert((bool) ($resolveFirst['ok'] ?? false), 'first message attachments should resolve');
    $resolveSecond = videochat_chat_attachment_resolve_for_message(
        $pdo,
        [$docAttachmentId, $textAttachmentId],
        $callId,
        $roomId,
        $participantUserId,
        'chat_archive_msg_002'
    );
    videochat_chat_archive_contract_assert((bool) ($resolveSecond['ok'] ?? false), 'second message attachments should resolve');

    $firstEvent = [
        'type' => 'chat/message',
        'room_id' => $roomId,
        'message' => [
            'id' => 'chat_archive_msg_001',
            'client_message_id' => 'client-1',
            'text' => 'hello archived chat',
            'attachments' => $resolveFirst['attachments'] ?? [],
            'sender' => [
                'user_id' => $participantUserId,
                'display_name' => 'Archive Participant',
                'role' => 'user',
            ],
            'server_unix_ms' => 1776600000001,
            'server_time' => '2026-04-19T12:00:01Z',
        ],
        'time' => '2026-04-19T12:00:01Z',
    ];
    $secondEvent = $firstEvent;
    $secondEvent['message']['id'] = 'chat_archive_msg_002';
    $secondEvent['message']['client_message_id'] = 'client-2';
    $secondEvent['message']['text'] = 'second message with office notes';
    $secondEvent['message']['attachments'] = $resolveSecond['attachments'] ?? [];
    $secondEvent['message']['server_unix_ms'] = 1776600000002;
    $secondEvent['message']['server_time'] = '2026-04-19T12:00:02Z';
    $secondEvent['time'] = '2026-04-19T12:00:02Z';

    $appendFirst = videochat_chat_archive_append_message($pdo, $callId, $roomId, $firstEvent);
    $appendSecond = videochat_chat_archive_append_message($pdo, $callId, $roomId, $secondEvent);
    videochat_chat_archive_contract_assert((bool) ($appendFirst['ok'] ?? false), 'first archive append should succeed');
    videochat_chat_archive_contract_assert((bool) ($appendSecond['ok'] ?? false), 'second archive append should succeed');
    videochat_chat_archive_contract_assert(isset($objects[(string) ($appendFirst['object_key'] ?? '')]), 'archive snapshot should be written to object store');

    $participantArchive = videochat_chat_archive_fetch($pdo, $callId, $participantUserId, 'user', ['limit' => 1]);
    videochat_chat_archive_contract_assert((bool) ($participantArchive['ok'] ?? false), 'participant archive fetch should succeed');
    $archive = is_array($participantArchive['archive'] ?? null) ? $participantArchive['archive'] : [];
    videochat_chat_archive_contract_assert((bool) ($archive['read_only'] ?? false), 'archive must be read-only');
    videochat_chat_archive_contract_assert(count($archive['messages'] ?? []) === 1, 'pagination limit should return one message');
    videochat_chat_archive_contract_assert((bool) (($archive['pagination'] ?? [])['has_next'] ?? false), 'pagination should have next page');
    videochat_chat_archive_contract_assert(count((($archive['messages'][0] ?? [])['attachments'] ?? [])) === 2, 'message should include attachment chips');

    $nextCursor = (int) (($archive['pagination'] ?? [])['next_cursor'] ?? 0);
    $nextPage = videochat_chat_archive_fetch($pdo, $callId, $participantUserId, 'user', ['cursor' => $nextCursor, 'limit' => 50]);
    videochat_chat_archive_contract_assert((bool) ($nextPage['ok'] ?? false), 'next archive page should load');
    videochat_chat_archive_contract_assert(count(($nextPage['archive']['messages'] ?? [])) === 1, 'next page should return second message');

    $allArchive = videochat_chat_archive_fetch($pdo, $callId, $ownerUserId, 'user', ['limit' => 50]);
    $groups = (($allArchive['archive'] ?? [])['files'] ?? [])['groups'] ?? [];
    videochat_chat_archive_contract_assert(count($groups['images'] ?? []) === 1, 'archive files should group images');
    videochat_chat_archive_contract_assert(count($groups['pdfs'] ?? []) === 1, 'archive files should group pdfs');
    videochat_chat_archive_contract_assert(count($groups['office'] ?? []) === 1, 'archive files should group office files');
    videochat_chat_archive_contract_assert(count($groups['text'] ?? []) === 1, 'archive files should group text files');

    $searchArchive = videochat_chat_archive_fetch($pdo, $callId, $participantUserId, 'user', ['q' => 'office']);
    videochat_chat_archive_contract_assert(count(($searchArchive['archive']['messages'] ?? [])) === 1, 'archive text search should filter messages');

    $forbiddenOther = videochat_chat_archive_fetch($pdo, $callId, $otherUserId, 'user', []);
    videochat_chat_archive_contract_assert(!(bool) ($forbiddenOther['ok'] ?? true), 'unrelated user must not fetch archive');
    videochat_chat_archive_contract_assert((string) ($forbiddenOther['reason'] ?? '') === 'forbidden', 'unrelated archive reason mismatch');

    $forbiddenGuest = videochat_chat_archive_fetch($pdo, $callId, $guestUserId, 'user', []);
    videochat_chat_archive_contract_assert(!(bool) ($forbiddenGuest['ok'] ?? true), 'guest must not fetch persistent archive');
    videochat_chat_archive_contract_assert((string) ($forbiddenGuest['reason'] ?? '') === 'forbidden', 'guest archive reason mismatch');

    $adminArchive = videochat_chat_archive_fetch($pdo, $callId, $adminUserId, 'admin', []);
    videochat_chat_archive_contract_assert((bool) ($adminArchive['ok'] ?? false), 'admin should fetch archive by RBAC');

    $pdo->exec("UPDATE calls SET status = 'ended' WHERE id = " . $pdo->quote($callId));
    $endedDownload = videochat_chat_attachment_fetch_for_download($pdo, $callId, $imageAttachmentId, $participantUserId, 'user');
    videochat_chat_archive_contract_assert(is_array($endedDownload), 'registered participant should download archive attachment after call end');
    $endedUpload = videochat_chat_attachment_upload($pdo, $callId, $participantUserId, 'user', [
        'file_name' => 'late.txt',
        'content_type' => 'text/plain',
        'content_base64' => base64_encode('late upload'),
    ]);
    videochat_chat_archive_contract_assert(!(bool) ($endedUpload['ok'] ?? true), 'ended call must not allow new attachment upload');

    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $routeResponse = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/chat-archive',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls/' . $callId . '/chat-archive?limit=1',
            'query' => ['limit' => '1'],
            'body' => '',
        ],
        [
            'ok' => true,
            'user' => [
                'id' => $participantUserId,
                'role' => 'user',
            ],
        ],
        'videochat_chat_archive_contract_json_response',
        'videochat_chat_archive_contract_error_response',
        'videochat_chat_archive_contract_decode_json_body',
        $openDatabase
    );
    videochat_chat_archive_contract_assert(is_array($routeResponse), 'route response should be array');
    videochat_chat_archive_contract_assert((int) ($routeResponse['status'] ?? 0) === 200, 'route GET /chat-archive should return 200');
    $routePayload = json_decode((string) ($routeResponse['body'] ?? ''), true);
    videochat_chat_archive_contract_assert((string) (($routePayload['result']['archive'] ?? [])['call_id'] ?? '') === $callId, 'route archive call id mismatch');

    unset(
        $GLOBALS['videochat_chat_attachment_store_put'],
        $GLOBALS['videochat_chat_attachment_store_get'],
        $GLOBALS['videochat_chat_archive_store_put'],
        $GLOBALS['videochat_chat_archive_store_get']
    );
    @unlink($databasePath);

    fwrite(STDOUT, "[chat-archive-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[chat-archive-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
