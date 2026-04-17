<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/users/user_directory.php';

function videochat_admin_user_list_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-list-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-list-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $roleRows = $pdo->query('SELECT id, slug FROM roles')->fetchAll();
    $roleMap = [];
    foreach ($roleRows as $roleRow) {
        if (!is_array($roleRow)) {
            continue;
        }
        $slug = is_string($roleRow['slug'] ?? null) ? trim((string) $roleRow['slug']) : '';
        if ($slug === '') {
            continue;
        }
        $roleMap[$slug] = (int) ($roleRow['id'] ?? 0);
    }

    videochat_admin_user_list_assert(($roleMap['moderator'] ?? 0) > 0, 'moderator role is missing');
    videochat_admin_user_list_assert(($roleMap['user'] ?? 0) > 0, 'user role is missing');

    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, :status, '24h', 'dark', :updated_at)
SQL
    );

    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_admin_user_list_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $insertUser->execute([
        ':email' => 'moderator-alpha@intelligent-intern.com',
        ':display_name' => 'Moderator Alpha',
        ':password_hash' => $moderatorPassword,
        ':role_id' => (int) $roleMap['moderator'],
        ':status' => 'active',
        ':updated_at' => gmdate('c'),
    ]);

    for ($i = 1; $i <= 15; $i++) {
        $password = password_hash('user-pass-' . $i, PASSWORD_DEFAULT);
        videochat_admin_user_list_assert(is_string($password) && $password !== '', 'user password hash failed');
        $insertUser->execute([
            ':email' => sprintf('contract-user-%02d@intelligent-intern.com', $i),
            ':display_name' => sprintf('Contract User %02d', $i),
            ':password_hash' => $password,
            ':role_id' => (int) $roleMap['user'],
            ':status' => $i % 2 === 0 ? 'active' : 'disabled',
            ':updated_at' => gmdate('c'),
        ]);
    }

    $filters = videochat_admin_user_list_filters([
        'query' => '',
        'page' => '1',
        'page_size' => '5',
    ]);
    videochat_admin_user_list_assert($filters['ok'] === true, 'valid filters should pass');
    videochat_admin_user_list_assert(
        (string) ($filters['order'] ?? '') === 'role_then_name_asc',
        'default order should be role_then_name_asc'
    );
    videochat_admin_user_list_assert(
        (string) ($filters['status'] ?? '') === 'all',
        'default status should be all'
    );

    $listing = videochat_admin_list_users(
        $pdo,
        (string) $filters['query'],
        (int) $filters['page'],
        (int) $filters['page_size'],
        (string) $filters['order'],
        (string) $filters['status']
    );
    $rows = $listing['rows'];
    videochat_admin_user_list_assert(is_array($rows), 'listing rows must be an array');
    videochat_admin_user_list_assert(count($rows) === 5, 'page 1 should return page_size users');
    videochat_admin_user_list_assert((int) $listing['total'] >= 18, 'total should include seeded and inserted users');
    videochat_admin_user_list_assert((int) $listing['page_count'] >= 4, 'page_count should match total and page_size');

    $firstRole = (string) ($rows[0]['role'] ?? '');
    $secondRole = (string) ($rows[1]['role'] ?? '');
    videochat_admin_user_list_assert($firstRole === 'admin', 'first listed role should be admin by priority sort');
    videochat_admin_user_list_assert($secondRole === 'moderator', 'second listed role should be moderator by priority sort');

    $pageTwo = videochat_admin_list_users($pdo, '', 2, 5, 'role_then_name_asc', 'all');
    $pageOneIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    $pageTwoIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $pageTwo['rows']);
    $overlap = array_intersect($pageOneIds, $pageTwoIds);
    videochat_admin_user_list_assert($overlap === [], 'page 1 and page 2 ids must not overlap');

    $moderatorSearch = videochat_admin_list_users($pdo, 'moderator alpha', 1, 10, 'role_then_name_asc', 'all');
    videochat_admin_user_list_assert((int) $moderatorSearch['total'] === 1, 'moderator search should find one record');
    videochat_admin_user_list_assert(
        (string) ($moderatorSearch['rows'][0]['email'] ?? '') === 'moderator-alpha@intelligent-intern.com',
        'moderator search result email mismatch'
    );

    $ascSearch = videochat_admin_list_users($pdo, 'contract user', 1, 100, 'role_then_name_asc', 'all');
    $descSearch = videochat_admin_list_users($pdo, 'contract user', 1, 100, 'role_then_name_desc', 'all');
    $ascRows = is_array($ascSearch['rows'] ?? null) ? $ascSearch['rows'] : [];
    $descRows = is_array($descSearch['rows'] ?? null) ? $descSearch['rows'] : [];
    videochat_admin_user_list_assert(count($ascRows) === 15, 'asc search should include all inserted contract users');
    videochat_admin_user_list_assert(count($descRows) === 15, 'desc search should include all inserted contract users');
    videochat_admin_user_list_assert(
        (string) ($ascRows[0]['display_name'] ?? '') === 'Contract User 01',
        'asc order should start with Contract User 01'
    );
    videochat_admin_user_list_assert(
        (string) ($descRows[0]['display_name'] ?? '') === 'Contract User 15',
        'desc order should start with Contract User 15'
    );
    videochat_admin_user_list_assert(
        (string) ($ascRows[14]['display_name'] ?? '') === 'Contract User 15',
        'asc order should end with Contract User 15'
    );
    videochat_admin_user_list_assert(
        (string) ($descRows[14]['display_name'] ?? '') === 'Contract User 01',
        'desc order should end with Contract User 01'
    );

    $activeOnly = videochat_admin_list_users($pdo, 'contract user', 1, 100, 'role_then_name_asc', 'active');
    $disabledOnly = videochat_admin_list_users($pdo, 'contract user', 1, 100, 'role_then_name_asc', 'disabled');
    videochat_admin_user_list_assert(
        (int) ($activeOnly['total'] ?? 0) === 7,
        'active status filter should return 7 contract users'
    );
    videochat_admin_user_list_assert(
        (int) ($disabledOnly['total'] ?? 0) === 8,
        'disabled status filter should return 8 contract users'
    );

    $invalidFilters = videochat_admin_user_list_filters([
        'status' => 'paused',
        'page' => '0',
        'page_size' => '500',
        'order' => 'invalid',
    ]);
    videochat_admin_user_list_assert($invalidFilters['ok'] === false, 'invalid filters should fail');
    videochat_admin_user_list_assert(
        (string) ($invalidFilters['errors']['page'] ?? '') === 'must_be_integer_greater_than_zero',
        'invalid page error mismatch'
    );
    videochat_admin_user_list_assert(
        (string) ($invalidFilters['errors']['page_size'] ?? '') === 'must_be_integer_between_1_and_100',
        'invalid page_size error mismatch'
    );
    videochat_admin_user_list_assert(
        (string) ($invalidFilters['errors']['status'] ?? '') === 'must_be_all_active_or_disabled',
        'invalid status error mismatch'
    );
    videochat_admin_user_list_assert(
        (string) ($invalidFilters['errors']['order'] ?? '') === 'must_be_one_of_role_then_name_asc_or_role_then_name_desc',
        'invalid order error mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-list-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-list-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
