<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_stt.php';

function videochat_call_stt_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-stt-contract] FAIL: {$message}\n");
    exit(1);
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "[call-stt-contract] SKIP sqlite PDO driver unavailable\n");
    exit(0);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-stt-' . bin2hex(random_bytes(6)) . '.sqlite';
    $tempDir = sys_get_temp_dir() . '/videochat-call-stt-audio-' . bin2hex(random_bytes(6));
    $commandPath = sys_get_temp_dir() . '/videochat-call-stt-command-' . bin2hex(random_bytes(6)) . '.php';
    $archiveObjects = [];
    $GLOBALS['videochat_chat_archive_store_put'] = static function (string $objectKey, string $json) use (&$archiveObjects): bool {
        $archiveObjects[$objectKey] = $json;
        return true;
    };

    mkdir($tempDir, 0700, true);
    file_put_contents(
        $commandPath,
        <<<'PHP'
<?php
declare(strict_types=1);
$audioPath = (string) ($argv[1] ?? '');
if ($audioPath === '' || !is_file($audioPath)) {
    fwrite(STDERR, 'missing audio file');
    exit(7);
}
echo json_encode(['text' => 'Transcript from local mic chunk'], JSON_UNESCAPED_SLASHES);
PHP
    );

    putenv('VIDEOCHAT_STT_ACTIVE=1');
    putenv('VIDEOCHAT_STT_PROVIDER=command');
    putenv('VIDEOCHAT_STT_COMMAND=' . PHP_BINARY . ' ' . $commandPath . ' {audio}');
    putenv('VIDEOCHAT_STT_MODEL=/models/test-stt.bin');
    putenv('VIDEOCHAT_STT_TEMP_DIR=' . $tempDir);
    putenv('VIDEOCHAT_STT_MAX_BYTES=4096');
    putenv('VIDEOCHAT_STT_MIN_SPEECH_BYTES=8');

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $adminUserId = (int) $pdo->query("SELECT users.id FROM users INNER JOIN roles ON roles.id = users.role_id WHERE roles.slug = 'admin' ORDER BY users.id ASC LIMIT 1")->fetchColumn();
    $participantUserId = (int) $pdo->query("SELECT users.id FROM users INNER JOIN roles ON roles.id = users.role_id WHERE roles.slug = 'user' ORDER BY users.id ASC LIMIT 1")->fetchColumn();
    videochat_call_stt_contract_assert($adminUserId > 0 && $participantUserId > 0, 'expected seeded admin and user');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'STT Contract Call',
        'starts_at' => '2026-06-02T09:00:00Z',
        'ends_at' => '2026-06-02T10:00:00Z',
        'internal_participant_user_ids' => [$participantUserId],
    ]);
    videochat_call_stt_contract_assert((bool) ($createCall['ok'] ?? false), 'call create should succeed');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_stt_contract_assert($callId !== '', 'created call id should be present');

    $config = videochat_stt_config();
    $initialState = videochat_stt_read_call_state($pdo, $callId, $participantUserId, 'user', $config);
    videochat_call_stt_contract_assert((bool) ($initialState['ok'] ?? false), 'participant should read STT state');
    videochat_call_stt_contract_assert((bool) (($initialState['state'] ?? [])['enabled'] ?? true) === false, 'initial call STT state should be disabled');
    videochat_call_stt_contract_assert((bool) (($initialState['state'] ?? [])['can_control'] ?? true) === false, 'plain participant must not control STT');

    $forbiddenToggle = videochat_stt_set_call_state($pdo, $callId, $participantUserId, 'user', ['enabled' => true], $config);
    videochat_call_stt_contract_assert(!(bool) ($forbiddenToggle['ok'] ?? true), 'plain participant toggle should fail');
    videochat_call_stt_contract_assert((string) ($forbiddenToggle['reason'] ?? '') === 'forbidden', 'plain participant toggle reason mismatch');

    $disabledUpload = videochat_process_call_stt_chunk($pdo, $callId, $participantUserId, 'user', str_repeat("\x11", 64), $config, 'stt:test-disabled');
    videochat_call_stt_contract_assert(!(bool) ($disabledUpload['ok'] ?? true), 'upload while call STT disabled should fail');
    videochat_call_stt_contract_assert((string) ($disabledUpload['reason'] ?? '') === 'call_stt_disabled', 'disabled upload reason mismatch');

    $enable = videochat_stt_set_call_state($pdo, $callId, $adminUserId, 'admin', ['enabled' => true], $config);
    videochat_call_stt_contract_assert((bool) ($enable['ok'] ?? false), 'admin should enable call STT');
    videochat_call_stt_contract_assert((bool) (($enable['state'] ?? [])['enabled'] ?? false), 'enabled state should be true');

    $filtered = videochat_process_call_stt_chunk($pdo, $callId, $participantUserId, 'user', str_repeat("\x00", 64), $config, 'stt:test-silent');
    videochat_call_stt_contract_assert((bool) ($filtered['ok'] ?? false), 'silent upload should be accepted as filtered');
    videochat_call_stt_contract_assert((string) ($filtered['state'] ?? '') === 'filtered', 'silent upload state mismatch');

    $upload = videochat_process_call_stt_chunk($pdo, $callId, $participantUserId, 'user', str_repeat("\x20", 96), $config, 'stt:test-live');
    videochat_call_stt_contract_assert((bool) ($upload['ok'] ?? false), 'enabled participant upload should succeed');
    videochat_call_stt_contract_assert((string) ($upload['state'] ?? '') === 'archived', 'upload state should be archived');
    $message = is_array($upload['message'] ?? null) ? $upload['message'] : [];
    videochat_call_stt_contract_assert((string) ($message['type'] ?? '') === 'chat/message', 'STT payload should use chat/message shape');
    videochat_call_stt_contract_assert((string) (($message['message'] ?? [])['text'] ?? '') === 'Transcript from local mic chunk', 'transcript text mismatch');
    videochat_call_stt_contract_assert((string) (($message['message'] ?? [])['source'] ?? '') === 'call_stt', 'message source mismatch');
    videochat_call_stt_contract_assert((int) ((($message['message'] ?? [])['sender'] ?? [])['user_id'] ?? 0) === $participantUserId, 'transcript sender mismatch');

    $archiveCount = (int) $pdo->query("SELECT COUNT(*) FROM call_chat_messages WHERE message_id LIKE 'chat_stt_%'")->fetchColumn();
    videochat_call_stt_contract_assert($archiveCount === 1, 'exactly one STT chat archive row expected');
    $brokerCount = (int) $pdo->query("SELECT COUNT(*) FROM realtime_chat_events")->fetchColumn();
    videochat_call_stt_contract_assert($brokerCount === 1, 'STT transcript should be inserted into realtime chat broker');
    videochat_call_stt_contract_assert($archiveObjects !== [], 'STT transcript archive snapshot should be stored');
    videochat_call_stt_contract_assert((glob($tempDir . '/videochat-stt-*') ?: []) === [], 'temporary audio chunks must be deleted');

    videochat_stt_set_call_state($pdo, $callId, $adminUserId, 'admin', ['enabled' => false], $config);
    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
    @unlink($commandPath);
    @rmdir($tempDir);
    unset($GLOBALS['videochat_chat_archive_store_put']);

    fwrite(STDOUT, "[call-stt-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-stt-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
