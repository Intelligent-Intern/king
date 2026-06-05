<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../domain/users/user_email_identity.php';
require_once __DIR__ . '/../domain/calls/call_guest_lifecycle.php';

function videochat_demo_user_blueprint(): array
{
    $defaults = [
        'id' => null,
        'role' => 'admin',
        'status' => 'active',
        'time_format' => '24h',
        'date_format' => 'dmy_dot',
        'theme' => 'dark',
        'theme_editor_enabled' => false,
    ];
    $users = [
        [1, 'admin@intelligent-intern.com', 'Platform Admin', '$2y$12$eWqYrUVeEtNXT1Q8lxWCy.N.p.agjIHS7UxqabiTChq6ttd7MLDW2', true],
        [null, 'alex@kingrt.com', 'Alex', '$2y$12$UdhVH3ClhfpMocFOhrUqkeNZ0BzYvgg0s9sUwRTMwuPZ9n3xNKZC6', false],
        [null, 'roland@kingrt.com', 'Roland', '$2y$12$Jfc9u7l7kk4YsTFw9ci84.AHnz.rPK5bQmasBY/Vn6DpI6nAxOGyS', false],
        [null, 'pierre@intelligent-intern.com', 'Pierre', '$2y$12$jKwY39g/sSDevIx.atItm.fhqUnx/5qfNz9wAwUtA/zQmsx0Z4OZK', false],
        [null, 'alexander@intelligent-intern.com', 'Alexander', '$2y$12$y.P32eyHwIwEfQqhmx5U4OWNxBgroyTGxYd9ZleTRW3Z.ua0Ur.U6', false],
        [null, 'benjamin@intelligent-intern.com', 'Benjamin', '$2y$12$Ulte13wX9P3k3A42ZPH5zOZy45J4a52sxvjLVADtoqZwKYvx2xRo2', false],
        [null, 'jendrik@kingrt.com', 'Jendrik', '$2y$12$tZ2nRuTCcGJmBNDsudYtUu9MEw7OkYebOnilnr8Sr1MU.Fo6/0wQG', false],
        [null, 'julius@intelligent-intern.com', 'Julius', '$2y$12$/b/Tw0vjwG4lch1LCDYuc.MvaXnYTkQFGpTFDuZHBBAlT9kIyFN7m', false],
        [null, 'mascha@kingrt.com', 'mascha', '$2y$12$7Zz1ITPJx2lJUYNbzm4WxuMVu1O3ZefYFff0SeuQ81ISOpJyPIRDu', false],
        [null, 'mathias@intelligent-intern.com', 'Mathias', '$2y$12$4oOrrmqmlx.zy6ovWkOc0uw8g9C3.L/jMl78/JnWmLJtmxYZEZ27.', false],
        [null, 'michael@kingrt.com', 'Micha', '$2y$12$I8O.j9bEl6js0p6iFWW33uB3Z2sNY16Kpsf5v2ujCQzmm/U.RxARu', false],
        [null, 'paul@kingrt.com', 'Paul', '$2y$12$eKbR5mmEEC5nyfjful2o6OqPpiKH28lPZco7aClqoY02GCpkk77wW', false],
        [null, 'chris@intelligent-intern.com', 'Chris', '$2y$12$5oa/OkYRhTr0ERiqThEmLOvPKkP36.hTPQw80lmEpcc3oO1xHf/tO', false],
        [null, 'hans-joerg@intelligent-intern.com', 'Hans Joerg', '$2y$12$igyH/chNV88/c/eBS1WcwuBIGxTWgiOHNoBN432ALD7/TJxFgsKhy', false],
        [null, 'lukas@kingrt.com', 'Lukas', '$2y$12$F1KmEBHKPS/C9DPO4z93WuTKT9xC5z0jzXzoGRP.zX9Hj8R5no6v6', false],
        [null, 'sam@intelligent-intern.com', 'Sam', '$2y$12$KnPuNtiPVx/1cJL521ZHG.C2z4o4bC18X1H7uZ4Xhx7A7P9du.f0K', false],
    ];

    return array_map(
        static fn (array $user): array => [
            ...$defaults,
            'id' => $user[0],
            'email' => $user[1],
            'display_name' => $user[2],
            'password_hash' => $user[3],
            'is_superadmin' => $user[4],
        ],
        $users
    );
}

function videochat_seed_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if (!is_string($safeTable) || $safeTable === '') {
        return false;
    }
    try {
        $columns = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
        foreach ($columns ?: [] as $column) {
            if (strcasecmp((string) ($column['name'] ?? ''), $columnName) === 0) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

function videochat_demo_user_emails(): array
{
    return array_values(array_map(
        static fn (array $user): string => strtolower(trim((string) ($user['email'] ?? ''))),
        videochat_demo_user_blueprint()
    ));
}

function videochat_seed_strict_user_pruning_enabled(): bool
{
    $raw = getenv('VIDEOCHAT_SEED_RETAINED_USERS_ONLY');
    if ($raw === false || trim((string) $raw) === '') {
        return true;
    }

    return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'off', 'no'], true);
}

function videochat_seed_prune_user_ids(PDO $pdo, array $userIds, ?int $tenantId = null): int
{
    $deleted = 0;
    foreach (array_values(array_unique(array_map('intval', $userIds))) as $userId) {
        if ($userId <= 0) {
            continue;
        }
        $result = videochat_admin_delete_user($pdo, $userId, $tenantId);
        if ((bool) ($result['ok'] ?? false) && (string) ($result['reason'] ?? '') === 'deleted') {
            $deleted++;
            continue;
        }

        $disable = $pdo->prepare(
            'UPDATE users SET status = \'disabled\', updated_at = :updated_at WHERE id = :id'
        );
        $disable->execute([':updated_at' => gmdate('c'), ':id' => $userId]);
        $revoke = $pdo->prepare(
            'UPDATE sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND (revoked_at IS NULL OR revoked_at = \'\')'
        );
        $revoke->execute([':revoked_at' => gmdate('c'), ':user_id' => $userId]);
    }

    return $deleted;
}

function videochat_seed_prune_non_retained_users(PDO $pdo): int
{
    if (!videochat_seed_strict_user_pruning_enabled()) {
        return 0;
    }

    $emails = videochat_demo_user_emails();
    if ($emails === []) {
        return 0;
    }
    $placeholders = implode(', ', array_fill(0, count($emails), '?'));
    $query = $pdo->prepare(
        <<<SQL
SELECT id
FROM users
WHERE lower(email) NOT IN ({$placeholders})
  AND NOT (
      lower(email) LIKE 'guest+%@videochat.local'
      AND coalesce(password_hash, '') = ''
  )
SQL
    );
    $query->execute($emails);
    $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);

    return videochat_seed_prune_user_ids($pdo, $ids, null);
}

function videochat_seed_default_governance_roles(PDO $pdo): array
{
    $hasTables = $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('tenants', 'governance_roles')")->fetchColumn();
    if ((int) $hasTables !== 2) {
        return [];
    }

    $roles = [
        ['key' => 'administrator', 'name' => 'Administrator', 'description' => 'Workspace administrators with account and governance access.'],
        ['key' => 'user', 'name' => 'User', 'description' => 'Standard retained user role for governance assignments.'],
        ['key' => 'guest', 'name' => 'Guest', 'description' => 'Temporary external call participant role.'],
    ];
    $tenantRows = $pdo->query('SELECT id FROM tenants ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_roles(tenant_id, public_id, key, name, description, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :key, :name, :description, 'active', :created_at, :updated_at)
SQL
    );
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE governance_roles
SET name = :name,
    description = :description,
    status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND lower(key) = lower(:key)
SQL
    );

    $seeded = [];
    foreach ($tenantRows as $tenantRow) {
        $tenantId = (int) ($tenantRow['id'] ?? 0);
        if ($tenantId <= 0) {
            continue;
        }
        foreach ($roles as $index => $role) {
            $now = gmdate('c');
            $publicNumber = 100000 + ($tenantId * 10) + $index + 1;
            $params = [
                ':tenant_id' => $tenantId,
                ':public_id' => sprintf('00000000-0000-4000-8000-%012d', $publicNumber),
                ':key' => $role['key'],
                ':name' => $role['name'],
                ':description' => $role['description'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            $insert->execute($params);
            $update->execute([
                ':tenant_id' => $tenantId,
                ':key' => $role['key'],
                ':name' => $role['name'],
                ':description' => $role['description'],
                ':updated_at' => $now,
            ]);
            $seeded[] = ['tenant_id' => $tenantId, 'key' => $role['key'], 'name' => $role['name']];
        }
    }

    return $seeded;
}

/**
 * @return array<int, array{email: string, display_name: string, role: string}>
 */
function videochat_seed_demo_users(PDO $pdo): array
{
    videochat_seed_prune_non_retained_users($pdo);

    $roles = [];
    $roleRows = $pdo->query('SELECT id, slug FROM roles');
    foreach ($roleRows as $row) {
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        if ($slug === '') {
            continue;
        }
        $roles[$slug] = (int) ($row['id'] ?? 0);
    }

    $hasDateFormat = videochat_seed_table_has_column($pdo, 'users', 'date_format');
    $hasThemeEditor = videochat_seed_table_has_column($pdo, 'users', 'theme_editor_enabled');
    $hasSuperAdmin = videochat_seed_table_has_column($pdo, 'users', 'is_superadmin');
    $dateSelect = $hasDateFormat ? 'date_format' : "'dmy_dot' AS date_format";
    $themeEditorSelect = $hasThemeEditor ? 'theme_editor_enabled' : '0 AS theme_editor_enabled';
    $superAdminSelect = $hasSuperAdmin ? 'is_superadmin' : '0 AS is_superadmin';

    $selectUser = $pdo->prepare(
        <<<SQL
SELECT id, role_id, display_name, password_hash, status, time_format, {$dateSelect}, theme, {$themeEditorSelect}, {$superAdminSelect}
FROM users
WHERE lower(email) = lower(:email)
LIMIT 1
SQL
    );

    $dateColumn = $hasDateFormat ? ', date_format' : '';
    $dateValue = $hasDateFormat ? ', :date_format' : '';
    $themeEditorColumn = $hasThemeEditor ? ', theme_editor_enabled' : '';
    $themeEditorValue = $hasThemeEditor ? ', :theme_editor_enabled' : '';
    $superAdminColumn = $hasSuperAdmin ? ', is_superadmin' : '';
    $superAdminValue = $hasSuperAdmin ? ', :is_superadmin' : '';
    $dateSet = $hasDateFormat ? "    date_format = :date_format,\n" : '';
    $themeEditorSet = $hasThemeEditor ? "    theme_editor_enabled = :theme_editor_enabled,\n" : '';
    $superAdminSet = $hasSuperAdmin ? "    is_superadmin = :is_superadmin,\n" : '';
    $updateUser = $pdo->prepare(
        <<<SQL
UPDATE users
SET display_name = :display_name,
    password_hash = :password_hash,
    role_id = :role_id,
    status = 'active',
    time_format = :time_format,
{$dateSet}
    theme = :theme,
{$themeEditorSet}
{$superAdminSet}
    updated_at = :updated_at
WHERE id = :id
SQL
    );

    $seeded = [];
    foreach (videochat_demo_user_blueprint() as $demoUser) {
        $roleId = (int) ($roles[$demoUser['role']] ?? 0);
        if ($roleId <= 0) {
            throw new RuntimeException(sprintf('Missing role slug in roles table: %s', $demoUser['role']));
        }
        $passwordHash = trim((string) ($demoUser['password_hash'] ?? ''));
        if ($passwordHash === '') {
            throw new RuntimeException(sprintf('Missing retained password hash for user: %s', $demoUser['email']));
        }
        $fixedId = is_int($demoUser['id'] ?? null) ? (int) $demoUser['id'] : null;
        $isSuperAdmin = (bool) ($demoUser['is_superadmin'] ?? false);

        $selectUser->execute([':email' => $demoUser['email']]);
        $existing = $selectUser->fetch();

        $needsUpdate = false;
        if (is_array($existing)) {
            $existingHash = is_string($existing['password_hash'] ?? null) ? (string) $existing['password_hash'] : '';
            if ($existingHash !== $passwordHash) {
                $needsUpdate = true;
            }
            if ((int) ($existing['role_id'] ?? 0) !== $roleId) {
                $needsUpdate = true;
            }
            if ((string) ($existing['display_name'] ?? '') !== $demoUser['display_name']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['status'] ?? '') !== 'active') {
                $needsUpdate = true;
            }
            if ((string) ($existing['time_format'] ?? '') !== $demoUser['time_format']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['date_format'] ?? '') !== $demoUser['date_format']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['theme'] ?? '') !== $demoUser['theme']) {
                $needsUpdate = true;
            }
            if ($hasThemeEditor && (((int) ($existing['theme_editor_enabled'] ?? 0)) === 1) !== (bool) ($demoUser['theme_editor_enabled'] ?? false)) {
                $needsUpdate = true;
            }
            if ($hasSuperAdmin && (((int) ($existing['is_superadmin'] ?? 0)) === 1) !== $isSuperAdmin) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $params = [
                    ':id' => (int) $existing['id'],
                    ':display_name' => $demoUser['display_name'],
                    ':password_hash' => $passwordHash,
                    ':role_id' => $roleId,
                    ':time_format' => $demoUser['time_format'],
                    ':theme' => $demoUser['theme'],
                    ':updated_at' => gmdate('c'),
                ];
                if ($hasDateFormat) {
                    $params[':date_format'] = $demoUser['date_format'];
                }
                if ($hasThemeEditor) {
                    $params[':theme_editor_enabled'] = (bool) ($demoUser['theme_editor_enabled'] ?? false) ? 1 : 0;
                }
                if ($hasSuperAdmin) {
                    $params[':is_superadmin'] = $isSuperAdmin ? 1 : 0;
                }
                $updateUser->execute($params);
            }
        } else {
            $idColumn = $fixedId !== null && $fixedId > 0 ? 'id, ' : '';
            $idValue = $fixedId !== null && $fixedId > 0 ? ':id, ' : '';
            $insertUser = $pdo->prepare(
                <<<SQL
INSERT INTO users({$idColumn}email, display_name, password_hash, role_id, status, time_format{$dateColumn}, theme{$themeEditorColumn}{$superAdminColumn}, updated_at)
VALUES({$idValue}:email, :display_name, :password_hash, :role_id, 'active', :time_format{$dateValue}, :theme{$themeEditorValue}{$superAdminValue}, :updated_at)
SQL
            );
            $insertParams = [
                ':email' => $demoUser['email'],
                ':display_name' => $demoUser['display_name'],
                ':password_hash' => $passwordHash,
                ':role_id' => $roleId,
                ':time_format' => $demoUser['time_format'],
                ':theme' => $demoUser['theme'],
                ':updated_at' => gmdate('c'),
            ];
            if ($fixedId !== null && $fixedId > 0) {
                $insertParams[':id'] = $fixedId;
            }
            if ($hasDateFormat) {
                $insertParams[':date_format'] = $demoUser['date_format'];
            }
            if ($hasThemeEditor) {
                $insertParams[':theme_editor_enabled'] = (bool) ($demoUser['theme_editor_enabled'] ?? false) ? 1 : 0;
            }
            if ($hasSuperAdmin) {
                $insertParams[':is_superadmin'] = $isSuperAdmin ? 1 : 0;
            }

            try {
                $insertUser->execute($insertParams);
            } catch (Throwable $error) {
                $message = strtolower($error->getMessage());
                $isEmailRace = str_contains($message, 'unique constraint failed')
                    && str_contains($message, 'users.email');
                if (!$isEmailRace) {
                    throw $error;
                }
                // Another bootstrap process inserted the same demo user between SELECT and INSERT.
                // Treat as successful seed and continue.
            }
        }
        if (function_exists('videochat_ensure_primary_user_email')) {
            $lookup = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
            $lookup->execute([':email' => $demoUser['email']]);
            $userId = (int) $lookup->fetchColumn();
            if ($userId > 0) {
                videochat_ensure_primary_user_email($pdo, $userId);
            }
        }

        $seeded[] = [
            'email' => $demoUser['email'],
            'display_name' => $demoUser['display_name'],
            'role' => $demoUser['role'],
            'is_superadmin' => $isSuperAdmin,
        ];
    }

    return $seeded;
}

function videochat_demo_seed_calls_enabled(): bool
{
    $raw = getenv('VIDEOCHAT_DEMO_SEED_CALLS');
    if ($raw === false) {
        return false;
    }

    $normalized = strtolower(trim((string) $raw));
    if ($normalized === '') {
        return false;
    }

    return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
}

function videochat_demo_call_blueprint(array $usersByEmail, ?int $nowUnix = null): array
{
    if ($usersByEmail === []) {
        return [];
    }

    $effectiveNow = $nowUnix ?? time();
    $adminEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_ADMIN_EMAIL') ?: 'admin@intelligent-intern.com')));
    $userEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_USER_EMAIL') ?: 'user@intelligent-intern.com')));

    if (!isset($usersByEmail[$adminEmail])) {
        return [];
    }

    $internalEmails = [$adminEmail];
    if ($userEmail !== $adminEmail && isset($usersByEmail[$userEmail])) {
        $internalEmails[] = $userEmail;
    }

    $baseInternalParticipants = [];
    foreach ($internalEmails as $index => $email) {
        $user = $usersByEmail[$email] ?? null;
        if (!is_array($user)) {
            continue;
        }

        $baseInternalParticipants[] = [
            'source' => 'internal',
            'email' => strtolower(trim((string) ($user['email'] ?? ''))),
            'display_name' => (string) ($user['display_name'] ?? 'User'),
            'call_role' => $index === 0 ? 'owner' : ($index === 1 ? 'moderator' : 'participant'),
            'invite_state' => $index === 0 ? 'allowed' : 'invited',
            'joined_at' => null,
            'left_at' => null,
        ];
    }

    $activeParticipants = [];
    foreach ($baseInternalParticipants as $participant) {
        $participant['joined_at'] = gmdate('c', $effectiveNow - 600);
        $participant['invite_state'] = 'allowed';
        $activeParticipants[] = $participant;
    }

    $architectureCallId = 'demo-call-architecture-sync';
    $platformCallId = 'demo-call-platform-standup';
    $retroCallId = 'demo-call-retro-weekly';

    return [
        [
            'id' => $architectureCallId,
            'room_id' => $architectureCallId,
            'title' => 'Architecture Sync',
            'status' => 'scheduled',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow + 3600),
            'ends_at' => gmdate('c', $effectiveNow + 7200),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => [
                ...$baseInternalParticipants,
                [
                    'source' => 'external',
                    'email' => 'guest.architecture@example.com',
                    'display_name' => 'Guest Architect',
                    'call_role' => 'participant',
                    'invite_state' => 'invited',
                    'joined_at' => null,
                    'left_at' => null,
                ],
            ],
        ],
        [
            'id' => $platformCallId,
            'room_id' => $platformCallId,
            'title' => 'Platform Standup',
            'status' => 'active',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow - 900),
            'ends_at' => gmdate('c', $effectiveNow + 2700),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => $activeParticipants,
        ],
        [
            'id' => $retroCallId,
            'room_id' => $retroCallId,
            'title' => 'Weekly Retrospective',
            'status' => 'ended',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow - 7200),
            'ends_at' => gmdate('c', $effectiveNow - 3600),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => $baseInternalParticipants,
        ],
    ];
}

/**
 * @return array{
 *   schedule_timezone: string,
 *   schedule_date: string,
 *   schedule_duration_minutes: int,
 *   schedule_all_day: int
 * }
 */
function videochat_demo_call_schedule_columns(string $startsAt, string $endsAt): array
{
    $startsAtUnix = strtotime($startsAt);
    $endsAtUnix = strtotime($endsAt);
    if (!is_int($startsAtUnix)) {
        $startsAtUnix = 0;
    }
    if (!is_int($endsAtUnix)) {
        $endsAtUnix = $startsAtUnix;
    }

    return [
        'schedule_timezone' => 'UTC',
        'schedule_date' => gmdate('Y-m-d', $startsAtUnix),
        'schedule_duration_minutes' => intdiv(max(0, $endsAtUnix - $startsAtUnix), 60),
        'schedule_all_day' => 0,
    ];
}

/**
 * @return array<int, array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   status: string,
 *   owner_email: string,
 *   starts_at: string,
 *   ends_at: string
 * }>
 */
function videochat_seed_demo_calls(PDO $pdo): array
{
    if (!videochat_demo_seed_calls_enabled()) {
        return [];
    }

    $userRows = $pdo->query(
        <<<'SQL'
SELECT users.id, users.email, users.display_name, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.status = 'active'
SQL
    )->fetchAll();

    $usersByEmail = [];
    if (is_array($userRows)) {
        foreach ($userRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $usersByEmail[$email] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
                'display_name' => (string) ($row['display_name'] ?? $email),
                'role' => (string) ($row['role_slug'] ?? 'user'),
            ];
        }
    }

    $blueprint = videochat_demo_call_blueprint($usersByEmail);
    if ($blueprint === []) {
        return [];
    }

    $selectCall = $pdo->prepare('SELECT id FROM calls WHERE id = :id LIMIT 1');
    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, room_id, title, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at
)
VALUES(
    :id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, :schedule_all_day,
    :cancelled_at, :cancel_reason, :cancel_message, :created_at, :updated_at
)
SQL
    );
    $updateCall = $pdo->prepare(
        <<<'SQL'
UPDATE calls
SET room_id = :room_id,
    title = :title,
    owner_user_id = :owner_user_id,
    status = :status,
    starts_at = :starts_at,
    ends_at = :ends_at,
    schedule_timezone = :schedule_timezone,
    schedule_date = :schedule_date,
    schedule_duration_minutes = :schedule_duration_minutes,
    schedule_all_day = :schedule_all_day,
    cancelled_at = :cancelled_at,
    cancel_reason = :cancel_reason,
    cancel_message = :cancel_message,
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $insertRoom = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :name, 'private', 'active', :created_by_user_id, :created_at, :updated_at)
SQL
    );
    $deleteParticipants = $pdo->prepare('DELETE FROM call_participants WHERE call_id = :call_id');
    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
    );

    $seeded = [];
    foreach ($blueprint as $call) {
        $callId = trim((string) ($call['id'] ?? ''));
        $ownerEmail = strtolower(trim((string) ($call['owner_email'] ?? '')));
        $owner = $usersByEmail[$ownerEmail] ?? null;
        if ($callId === '' || !is_array($owner) || (int) ($owner['id'] ?? 0) <= 0) {
            continue;
        }

        $startsAt = (string) ($call['starts_at'] ?? gmdate('c'));
        $endsAt = (string) ($call['ends_at'] ?? gmdate('c'));
        $scheduleColumns = videochat_demo_call_schedule_columns($startsAt, $endsAt);

        $callPayload = [
            ':id' => $callId,
            ':room_id' => (string) ($call['room_id'] ?? $callId),
            ':title' => (string) ($call['title'] ?? 'Demo Call'),
            ':owner_user_id' => (int) ($owner['id'] ?? 0),
            ':status' => (string) ($call['status'] ?? 'scheduled'),
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':schedule_timezone' => $scheduleColumns['schedule_timezone'],
            ':schedule_date' => $scheduleColumns['schedule_date'],
            ':schedule_duration_minutes' => $scheduleColumns['schedule_duration_minutes'],
            ':schedule_all_day' => $scheduleColumns['schedule_all_day'],
            ':cancelled_at' => is_string($call['cancelled_at'] ?? null) ? (string) $call['cancelled_at'] : null,
            ':cancel_reason' => is_string($call['cancel_reason'] ?? null) ? (string) $call['cancel_reason'] : null,
            ':cancel_message' => is_string($call['cancel_message'] ?? null) ? (string) $call['cancel_message'] : null,
            ':created_at' => gmdate('c'),
            ':updated_at' => gmdate('c'),
        ];
        $updateCallPayload = $callPayload;
        unset($updateCallPayload[':created_at']);

        $insertRoom->execute([
            ':id' => (string) $callPayload[':room_id'],
            ':name' => (string) $callPayload[':title'],
            ':created_by_user_id' => (int) ($owner['id'] ?? 0),
            ':created_at' => (string) $callPayload[':created_at'],
            ':updated_at' => (string) $callPayload[':updated_at'],
        ]);

        $selectCall->execute([':id' => $callId]);
        $existing = $selectCall->fetch();
        if (is_array($existing)) {
            $updateCall->execute($updateCallPayload);
        } else {
            $insertCall->execute($callPayload);
        }

        $deleteParticipants->execute([':call_id' => $callId]);
        $participants = is_array($call['participants'] ?? null) ? $call['participants'] : [];

        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $source = strtolower(trim((string) ($participant['source'] ?? '')));
            $email = strtolower(trim((string) ($participant['email'] ?? '')));
            if ($email === '' || !in_array($source, ['internal', 'external'], true)) {
                continue;
            }

            $internalUser = $usersByEmail[$email] ?? null;
            $userId = null;
            $displayName = trim((string) ($participant['display_name'] ?? ''));

            if ($source === 'internal') {
                if (!is_array($internalUser) || (int) ($internalUser['id'] ?? 0) <= 0) {
                    continue;
                }
                $userId = (int) ($internalUser['id'] ?? 0);
                if ($displayName === '') {
                    $displayName = (string) ($internalUser['display_name'] ?? $email);
                }
            } elseif ($displayName === '') {
                $displayName = $email;
            }

            $inviteState = strtolower(trim((string) ($participant['invite_state'] ?? 'invited')));
            if (!in_array($inviteState, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
                $inviteState = 'invited';
            }

            $callRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
            if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                $callRole = 'participant';
            }
            if ($source !== 'internal') {
                $callRole = 'participant';
            } elseif ($email === $ownerEmail) {
                $callRole = 'owner';
            }

            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => $userId,
                ':email' => $email,
                ':display_name' => $displayName,
                ':source' => $source,
                ':call_role' => $callRole,
                ':invite_state' => $inviteState,
                ':joined_at' => is_string($participant['joined_at'] ?? null) ? (string) $participant['joined_at'] : null,
                ':left_at' => is_string($participant['left_at'] ?? null) ? (string) $participant['left_at'] : null,
            ]);
        }

        $seeded[] = [
            'id' => $callId,
            'room_id' => (string) ($call['room_id'] ?? $callId),
            'title' => (string) ($call['title'] ?? 'Demo Call'),
            'status' => (string) ($call['status'] ?? 'scheduled'),
            'owner_email' => $ownerEmail,
            'starts_at' => (string) ($call['starts_at'] ?? gmdate('c')),
            'ends_at' => (string) ($call['ends_at'] ?? gmdate('c')),
        ];
    }

    return $seeded;
}

/**
 * @return array<int, array{name: string, statements: array<int, string>}>
 */
