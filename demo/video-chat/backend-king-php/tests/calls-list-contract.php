<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../call_directory.php';

function videochat_calls_list_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[calls-list-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-calls-list-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $adminUserId = (int) $adminQuery->fetchColumn();
    videochat_calls_list_assert($adminUserId > 0, 'expected seeded admin user');

    $userQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $standardUserId = (int) $userQuery->fetchColumn();
    videochat_calls_list_assert($standardUserId > 0, 'expected seeded standard user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_calls_list_assert($moderatorRoleId > 0, 'expected moderator role');

    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_calls_list_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $createModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createModerator->execute([
        ':email' => 'moderator-calls@intelligent-intern.com',
        ':display_name' => 'Moderator Calls',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_calls_list_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, room_id, title, owner_user_id, status, starts_at, ends_at, cancelled_at, cancel_reason, created_at, updated_at
) VALUES(
    :id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at, :cancelled_at, :cancel_reason, :created_at, :updated_at
)
SQL
    );

    $calls = [
        [
            'id' => 'call-001',
            'room_id' => 'lobby',
            'title' => 'Architecture Sync',
            'owner_user_id' => $adminUserId,
            'status' => 'scheduled',
            'starts_at' => '2026-05-01T09:00:00Z',
            'ends_at' => '2026-05-01T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
        ],
        [
            'id' => 'call-002',
            'room_id' => 'lobby',
            'title' => 'User Launch Prep',
            'owner_user_id' => $standardUserId,
            'status' => 'active',
            'starts_at' => '2026-05-02T09:00:00Z',
            'ends_at' => '2026-05-02T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
        ],
        [
            'id' => 'call-003',
            'room_id' => 'lobby',
            'title' => 'Moderator Retro',
            'owner_user_id' => $moderatorUserId,
            'status' => 'ended',
            'starts_at' => '2026-05-03T09:00:00Z',
            'ends_at' => '2026-05-03T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
        ],
        [
            'id' => 'call-004',
            'room_id' => 'lobby',
            'title' => 'Cancelled Fire Drill',
            'owner_user_id' => $adminUserId,
            'status' => 'cancelled',
            'starts_at' => '2026-05-04T09:00:00Z',
            'ends_at' => '2026-05-04T09:30:00Z',
            'cancelled_at' => '2026-05-03T12:00:00Z',
            'cancel_reason' => 'cancelled by owner',
        ],
        [
            'id' => 'call-005',
            'room_id' => 'lobby',
            'title' => 'Architecture Deep Dive',
            'owner_user_id' => $moderatorUserId,
            'status' => 'scheduled',
            'starts_at' => '2026-05-05T09:00:00Z',
            'ends_at' => '2026-05-05T10:00:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
        ],
    ];

    foreach ($calls as $call) {
        $insertCall->execute([
            ':id' => $call['id'],
            ':room_id' => $call['room_id'],
            ':title' => $call['title'],
            ':owner_user_id' => $call['owner_user_id'],
            ':status' => $call['status'],
            ':starts_at' => $call['starts_at'],
            ':ends_at' => $call['ends_at'],
            ':cancelled_at' => $call['cancelled_at'],
            ':cancel_reason' => $call['cancel_reason'],
            ':created_at' => $call['starts_at'],
            ':updated_at' => $call['starts_at'],
        ]);
    }

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :invite_state, :joined_at, :left_at)
SQL
    );
    $insertParticipant->execute([
        ':call_id' => 'call-001',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-001',
        ':user_id' => null,
        ':email' => 'guest-architecture@example.com',
        ':display_name' => 'Guest Architecture',
        ':source' => 'external',
        ':invite_state' => 'pending',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-002',
        ':user_id' => $adminUserId,
        ':email' => 'admin@intelligent-intern.com',
        ':display_name' => 'Platform Admin',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-003',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-004',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);

    $adminFilters = videochat_calls_list_filters([
        'scope' => 'all',
        'page' => '1',
        'page_size' => '2',
    ], 'admin');
    videochat_calls_list_assert($adminFilters['ok'] === true, 'valid admin filter set should pass');
    videochat_calls_list_assert((string) $adminFilters['effective_scope'] === 'all', 'admin scope all should remain all');

    $adminListing = videochat_list_calls($pdo, $adminUserId, $adminFilters);
    videochat_calls_list_assert((int) $adminListing['total'] === 5, 'admin total calls mismatch');
    videochat_calls_list_assert((int) $adminListing['page_count'] === 3, 'admin page count mismatch');
    videochat_calls_list_assert(count($adminListing['rows']) === 2, 'admin page size row count mismatch');
    videochat_calls_list_assert((string) ($adminListing['rows'][0]['id'] ?? '') === 'call-001', 'admin first row order mismatch');
    videochat_calls_list_assert((string) ($adminListing['rows'][1]['id'] ?? '') === 'call-002', 'admin second row order mismatch');
    videochat_calls_list_assert(
        (int) (($adminListing['rows'][0]['participants'] ?? [])['total'] ?? 0) === 2,
        'participants total mismatch for call-001'
    );
    videochat_calls_list_assert(
        (int) (($adminListing['rows'][0]['participants'] ?? [])['internal'] ?? 0) === 1,
        'participants internal mismatch for call-001'
    );
    videochat_calls_list_assert(
        (int) (($adminListing['rows'][0]['participants'] ?? [])['external'] ?? 0) === 1,
        'participants external mismatch for call-001'
    );

    $adminPageTwoFilters = videochat_calls_list_filters([
        'scope' => 'all',
        'page' => '2',
        'page_size' => '2',
    ], 'admin');
    $adminPageTwo = videochat_list_calls($pdo, $adminUserId, $adminPageTwoFilters);
    videochat_calls_list_assert(count($adminPageTwo['rows']) === 2, 'admin page two size mismatch');
    videochat_calls_list_assert((string) ($adminPageTwo['rows'][0]['id'] ?? '') === 'call-003', 'admin page two first row mismatch');
    videochat_calls_list_assert((string) ($adminPageTwo['rows'][1]['id'] ?? '') === 'call-004', 'admin page two second row mismatch');
    videochat_calls_list_assert(
        (($adminPageTwo['rows'][1]['my_participation'] ?? null) === false),
        'cancelled call must not report active participation'
    );

    $architectureFilters = videochat_calls_list_filters([
        'scope' => 'all',
        'query' => 'architecture',
        'page' => '1',
        'page_size' => '10',
    ], 'admin');
    $architectureListing = videochat_list_calls($pdo, $adminUserId, $architectureFilters);
    videochat_calls_list_assert((int) $architectureListing['total'] === 2, 'architecture query total mismatch');
    videochat_calls_list_assert((string) ($architectureListing['rows'][0]['id'] ?? '') === 'call-001', 'architecture first row mismatch');
    videochat_calls_list_assert((string) ($architectureListing['rows'][1]['id'] ?? '') === 'call-005', 'architecture second row mismatch');

    $userScopeAllFilters = videochat_calls_list_filters([
        'scope' => 'all',
        'page' => '1',
        'page_size' => '10',
    ], 'user');
    videochat_calls_list_assert((string) $userScopeAllFilters['effective_scope'] === 'my', 'user scope all should degrade to my');
    $userMyListing = videochat_list_calls($pdo, $standardUserId, $userScopeAllFilters);
    videochat_calls_list_assert((int) $userMyListing['total'] === 3, 'user my-scope total mismatch');
    videochat_calls_list_assert((string) ($userMyListing['rows'][0]['id'] ?? '') === 'call-001', 'user my-scope row 1 mismatch');
    videochat_calls_list_assert((string) ($userMyListing['rows'][1]['id'] ?? '') === 'call-002', 'user my-scope row 2 mismatch');
    videochat_calls_list_assert((string) ($userMyListing['rows'][2]['id'] ?? '') === 'call-003', 'user my-scope row 3 mismatch');
    videochat_calls_list_assert(
        !in_array('call-004', array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $userMyListing['rows']), true),
        'cancelled call should be excluded from join-based my scope'
    );

    $userActiveFilters = videochat_calls_list_filters([
        'scope' => 'my',
        'status' => 'active',
        'page' => '1',
        'page_size' => '10',
    ], 'user');
    $userActiveListing = videochat_list_calls($pdo, $standardUserId, $userActiveFilters);
    videochat_calls_list_assert((int) $userActiveListing['total'] === 1, 'user active filter total mismatch');
    videochat_calls_list_assert((string) ($userActiveListing['rows'][0]['id'] ?? '') === 'call-002', 'user active row mismatch');

    $invalidFilters = videochat_calls_list_filters([
        'status' => 'unknown',
        'scope' => 'tenant',
        'page' => '0',
        'page_size' => '500',
    ], 'admin');
    videochat_calls_list_assert($invalidFilters['ok'] === false, 'invalid calls filter set should fail');
    videochat_calls_list_assert(
        (string) ($invalidFilters['errors']['status'] ?? '') === 'must_be_all_or_valid_call_status',
        'invalid status filter error mismatch'
    );
    videochat_calls_list_assert(
        (string) ($invalidFilters['errors']['scope'] ?? '') === 'must_be_my_or_all',
        'invalid scope filter error mismatch'
    );
    videochat_calls_list_assert(
        (string) ($invalidFilters['errors']['page'] ?? '') === 'must_be_integer_greater_than_zero',
        'invalid page filter error mismatch'
    );
    videochat_calls_list_assert(
        (string) ($invalidFilters['errors']['page_size'] ?? '') === 'must_be_integer_between_1_and_100',
        'invalid page_size filter error mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[calls-list-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[calls-list-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
