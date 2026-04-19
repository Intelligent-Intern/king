<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';

function videochat_call_create_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-create-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-create-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_create_assert($adminUserId > 0, 'expected seeded admin user');

    $userUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_create_assert($userUserId > 0, 'expected seeded user user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_call_create_assert($moderatorRoleId > 0, 'expected moderator role');

    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_call_create_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $createModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createModerator->execute([
        ':email' => 'moderator-create-call@intelligent-intern.com',
        ':display_name' => 'Moderator Create Call',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_create_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $invalidPayload = videochat_create_call($pdo, $adminUserId, [
        'title' => '',
        'starts_at' => 'not-a-date',
        'ends_at' => '2026-06-01T10:00:00Z',
    ]);
    videochat_call_create_assert($invalidPayload['ok'] === false, 'invalid create payload should fail');
    videochat_call_create_assert($invalidPayload['reason'] === 'validation_failed', 'invalid create reason mismatch');
    videochat_call_create_assert(
        (string) (($invalidPayload['errors'] ?? [])['title'] ?? '') === 'required_title',
        'invalid create title error mismatch'
    );

    $invalidAccessMode = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Invalid Access Mode',
        'starts_at' => '2026-06-01T10:00:00Z',
        'ends_at' => '2026-06-01T11:00:00Z',
        'access_mode' => 'everyone',
    ]);
    videochat_call_create_assert($invalidAccessMode['ok'] === false, 'invalid access_mode should fail');
    videochat_call_create_assert(
        (string) (($invalidAccessMode['errors'] ?? [])['access_mode'] ?? '') === 'must_be_invite_only_or_free_for_all',
        'invalid access_mode error mismatch'
    );

    $invalidInternal = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Invalid Internal User',
        'starts_at' => '2026-06-01T10:00:00Z',
        'ends_at' => '2026-06-01T11:00:00Z',
        'internal_participant_user_ids' => [$userUserId, 999999],
    ]);
    videochat_call_create_assert($invalidInternal['ok'] === false, 'invalid internal participant should fail');
    videochat_call_create_assert($invalidInternal['reason'] === 'validation_failed', 'invalid internal reason mismatch');
    videochat_call_create_assert(
        (string) (($invalidInternal['errors'] ?? [])['internal_participant_user_ids'] ?? '') === 'contains_unknown_or_inactive_user',
        'invalid internal participant error mismatch'
    );

    $validCreate = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Weekly Product Sync',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-06-02T09:00:00Z',
        'ends_at' => '2026-06-02T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId, $moderatorUserId],
        'external_participants' => [
            ['email' => 'guest-a@example.com', 'display_name' => 'Guest A'],
            ['email' => 'guest-b@example.com', 'display_name' => 'Guest B'],
        ],
    ]);
    videochat_call_create_assert($validCreate['ok'] === true, 'valid call create should succeed');
    videochat_call_create_assert($validCreate['reason'] === 'created', 'valid call create reason mismatch');

    $createdCall = is_array($validCreate['call'] ?? null) ? $validCreate['call'] : null;
    videochat_call_create_assert(is_array($createdCall), 'valid call create should return call envelope');
    $callId = (string) ($createdCall['id'] ?? '');
    videochat_call_create_assert($callId !== '', 'created call id should be non-empty');
    videochat_call_create_assert((string) ($createdCall['status'] ?? '') === 'scheduled', 'created call status mismatch');
    videochat_call_create_assert((string) ($createdCall['title'] ?? '') === 'Weekly Product Sync', 'created call title mismatch');
    videochat_call_create_assert(
        (int) (($createdCall['participants']['totals'] ?? [])['total'] ?? 0) === 5,
        'created call participant total mismatch'
    );
    videochat_call_create_assert(
        (int) (($createdCall['participants']['totals'] ?? [])['internal'] ?? 0) === 3,
        'created call internal participant total mismatch'
    );
    videochat_call_create_assert(
        (int) (($createdCall['participants']['totals'] ?? [])['external'] ?? 0) === 2,
        'created call external participant total mismatch'
    );
    videochat_call_create_assert(
        (string) ($createdCall['access_mode'] ?? '') === 'free_for_all',
        'created call access_mode mismatch'
    );

    $callRowQuery = $pdo->prepare('SELECT id, title, owner_user_id, status, access_mode FROM calls WHERE id = :id LIMIT 1');
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_create_assert(is_array($callRow), 'created call row should exist in database');
    videochat_call_create_assert((string) ($callRow['title'] ?? '') === 'Weekly Product Sync', 'persisted call title mismatch');
    videochat_call_create_assert((int) ($callRow['owner_user_id'] ?? 0) === $adminUserId, 'persisted owner mismatch');
    videochat_call_create_assert((string) ($callRow['status'] ?? '') === 'scheduled', 'persisted status mismatch');
    videochat_call_create_assert((string) ($callRow['access_mode'] ?? '') === 'free_for_all', 'persisted access_mode mismatch');

    $participantCountQuery = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id');
    $participantCountQuery->execute([':call_id' => $callId]);
    $participantCount = (int) $participantCountQuery->fetchColumn();
    videochat_call_create_assert($participantCount === 5, 'persisted participant row count mismatch');

    $duplicateExternal = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Duplicate External',
        'starts_at' => '2026-06-03T09:00:00Z',
        'ends_at' => '2026-06-03T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
        'external_participants' => [
            ['email' => 'user@intelligent-intern.com', 'display_name' => 'Duplicate User Email'],
        ],
    ]);
    videochat_call_create_assert($duplicateExternal['ok'] === false, 'duplicate external email should fail');
    videochat_call_create_assert($duplicateExternal['reason'] === 'validation_failed', 'duplicate external reason mismatch');
    videochat_call_create_assert(
        (string) (($duplicateExternal['errors'] ?? [])['external_participants.0.email'] ?? '') === 'duplicates_internal_participant',
        'duplicate external email error mismatch'
    );

    $listFilters = videochat_calls_list_filters([
        'scope' => 'my',
        'status' => 'scheduled',
        'query' => 'weekly product',
        'page' => '1',
        'page_size' => '10',
    ], 'admin');
    videochat_call_create_assert($listFilters['ok'] === true, 'list filters after create should be valid');

    $listAfterCreate = videochat_list_calls($pdo, $adminUserId, $listFilters);
    videochat_call_create_assert((int) $listAfterCreate['total'] === 1, 'created call should appear in filtered calls list');
    videochat_call_create_assert(
        (string) (($listAfterCreate['rows'][0] ?? [])['id'] ?? '') === $callId,
        'created call id should match first list row'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-create-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-create-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
