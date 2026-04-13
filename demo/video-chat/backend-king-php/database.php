<?php

declare(strict_types=1);

function videochat_open_sqlite_pdo(string $databasePath): PDO
{
    $trimmedPath = trim($databasePath);
    if ($trimmedPath === '') {
        throw new InvalidArgumentException('VIDEOCHAT_KING_DB_PATH must not be empty.');
    }

    $directory = dirname($trimmedPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create sqlite directory: %s', $directory));
    }

    $pdo = new PDO('sqlite:' . $trimmedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    return $pdo;
}

/**
 * @return array<int, array{
 *   email: string,
 *   display_name: string,
 *   role: string,
 *   password: string,
 *   time_format: string,
 *   theme: string
 * }>
 */
function videochat_demo_user_blueprint(): array
{
    $adminEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_ADMIN_EMAIL') ?: 'admin@intelligent-intern.com')));
    $userEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_USER_EMAIL') ?: 'user@intelligent-intern.com')));
    $adminPassword = trim((string) (getenv('VIDEOCHAT_DEMO_ADMIN_PASSWORD') ?: 'admin123'));
    $userPassword = trim((string) (getenv('VIDEOCHAT_DEMO_USER_PASSWORD') ?: 'user123'));

    if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_ADMIN_EMAIL must be a valid email address.');
    }
    if ($userEmail === '' || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_USER_EMAIL must be a valid email address.');
    }
    if ($adminPassword === '') {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_ADMIN_PASSWORD must not be empty.');
    }
    if ($userPassword === '') {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_USER_PASSWORD must not be empty.');
    }

    return [
        [
            'email' => $adminEmail,
            'display_name' => 'Platform Admin',
            'role' => 'admin',
            'password' => $adminPassword,
            'time_format' => '24h',
            'theme' => 'dark',
        ],
        [
            'email' => $userEmail,
            'display_name' => 'Call User',
            'role' => 'user',
            'password' => $userPassword,
            'time_format' => '24h',
            'theme' => 'dark',
        ],
    ];
}

/**
 * @return array<int, array{email: string, display_name: string, role: string}>
 */
function videochat_seed_demo_users(PDO $pdo): array
{
    $roles = [];
    $roleRows = $pdo->query('SELECT id, slug FROM roles');
    foreach ($roleRows as $row) {
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        if ($slug === '') {
            continue;
        }
        $roles[$slug] = (int) ($row['id'] ?? 0);
    }

    $selectUser = $pdo->prepare(
        <<<'SQL'
SELECT id, role_id, display_name, password_hash, status, time_format, theme
FROM users
WHERE lower(email) = lower(:email)
LIMIT 1
SQL
    );
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', :time_format, :theme, :updated_at)
SQL
    );
    $updateUser = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET display_name = :display_name,
    password_hash = :password_hash,
    role_id = :role_id,
    status = 'active',
    time_format = :time_format,
    theme = :theme,
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

        $selectUser->execute([':email' => $demoUser['email']]);
        $existing = $selectUser->fetch();

        $passwordHash = null;
        $needsUpdate = false;
        if (is_array($existing)) {
            $existingHash = is_string($existing['password_hash'] ?? null) ? trim((string) $existing['password_hash']) : '';
            $hashValid = $existingHash !== '' && password_verify($demoUser['password'], $existingHash);
            $hashNeedsRehash = $hashValid && password_needs_rehash($existingHash, PASSWORD_DEFAULT);

            if (!$hashValid || $hashNeedsRehash) {
                $passwordHash = password_hash($demoUser['password'], PASSWORD_DEFAULT);
                if (!is_string($passwordHash) || $passwordHash === '') {
                    throw new RuntimeException('Failed to hash demo user password.');
                }
                $needsUpdate = true;
            } else {
                $passwordHash = $existingHash;
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
            if ((string) ($existing['theme'] ?? '') !== $demoUser['theme']) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updateUser->execute([
                    ':id' => (int) $existing['id'],
                    ':display_name' => $demoUser['display_name'],
                    ':password_hash' => $passwordHash,
                    ':role_id' => $roleId,
                    ':time_format' => $demoUser['time_format'],
                    ':theme' => $demoUser['theme'],
                    ':updated_at' => gmdate('c'),
                ]);
            }
        } else {
            $passwordHash = password_hash($demoUser['password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Failed to hash demo user password.');
            }

            $insertUser->execute([
                ':email' => $demoUser['email'],
                ':display_name' => $demoUser['display_name'],
                ':password_hash' => $passwordHash,
                ':role_id' => $roleId,
                ':time_format' => $demoUser['time_format'],
                ':theme' => $demoUser['theme'],
                ':updated_at' => gmdate('c'),
            ]);
        }

        $seeded[] = [
            'email' => $demoUser['email'],
            'display_name' => $demoUser['display_name'],
            'role' => $demoUser['role'],
        ];
    }

    return $seeded;
}

/**
 * @return array<int, array{name: string, statements: array<int, string>}>
 */
function videochat_sqlite_migrations(): array
{
    return [
        1 => [
            'name' => '0001_identity_and_sessions',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    is_system INTEGER NOT NULL DEFAULT 1 CHECK (is_system IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    avatar_path TEXT,
    time_format TEXT NOT NULL DEFAULT '24h' CHECK (time_format IN ('24h', '12h')),
    theme TEXT NOT NULL DEFAULT 'dark',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    issued_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    client_ip TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at)",
                "CREATE INDEX IF NOT EXISTS idx_sessions_revoked_at ON sessions(revoked_at)",
                <<<'SQL'
INSERT OR IGNORE INTO roles (slug, name, description, is_system) VALUES
  ('admin', 'Admin', 'Platform administrator', 1),
  ('moderator', 'Moderator', 'Room/call moderation role', 1),
  ('user', 'User', 'Standard workspace user', 1)
SQL,
            ],
        ],
        2 => [
            'name' => '0002_rooms_and_memberships',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS rooms (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS room_memberships (
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    membership_role TEXT NOT NULL DEFAULT 'member' CHECK (membership_role IN ('owner', 'moderator', 'member')),
    joined_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    left_at TEXT,
    PRIMARY KEY (room_id, user_id)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_rooms_status ON rooms(status)",
                "CREATE INDEX IF NOT EXISTS idx_room_memberships_user_id ON room_memberships(user_id)",
                <<<'SQL'
INSERT OR IGNORE INTO rooms (id, name, visibility, status) VALUES
  ('lobby', 'Lobby', 'public', 'active')
SQL,
            ],
        ],
        3 => [
            'name' => '0003_calls_invites_and_participants',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS calls (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    title TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'active', 'ended', 'cancelled')),
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    cancelled_at TEXT,
    cancel_reason TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_participants (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('internal', 'external')),
    invite_state TEXT NOT NULL DEFAULT 'pending' CHECK (invite_state IN ('pending', 'accepted', 'declined', 'cancelled')),
    joined_at TEXT,
    left_at TEXT,
    PRIMARY KEY (call_id, email)
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS invite_codes (
    id TEXT PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    scope TEXT NOT NULL CHECK (scope IN ('room', 'call')),
    room_id TEXT REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    call_id TEXT REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    issued_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    expires_at TEXT NOT NULL,
    redeemed_at TEXT,
    redeemed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    max_redemptions INTEGER NOT NULL DEFAULT 1,
    redemption_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (
        (scope = 'room' AND room_id IS NOT NULL AND call_id IS NULL)
        OR (scope = 'call' AND call_id IS NOT NULL AND room_id IS NULL)
    )
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_calls_room_id ON calls(room_id)",
                "CREATE INDEX IF NOT EXISTS idx_calls_owner_user_id ON calls(owner_user_id)",
                "CREATE INDEX IF NOT EXISTS idx_calls_status ON calls(status)",
                "CREATE INDEX IF NOT EXISTS idx_call_participants_user_id ON call_participants(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_invite_codes_code ON invite_codes(code)",
                "CREATE INDEX IF NOT EXISTS idx_invite_codes_expires_at ON invite_codes(expires_at)",
            ],
        ],
        4 => [
            'name' => '0004_calls_cancel_message',
            'statements' => [
                "ALTER TABLE calls ADD COLUMN cancel_message TEXT",
            ],
        ],
    ];
}

/**
 * @return array{
 *   path: string,
 *   schema_version: int,
 *   migrations_total: int,
 *   migrations_applied: int,
 *   migrations_newly_applied: int,
 *   migrations_pending: int,
 *   applied_versions: array<int, int>,
 *   table_count: int,
 *   table_names: array<int, string>,
 *   journal_mode: string,
 *   demo_users: array<int, array{email: string, display_name: string, role: string}>
 * }
 */
function videochat_bootstrap_sqlite(string $databasePath): array
{
    $trimmedPath = trim($databasePath);
    $pdo = videochat_open_sqlite_pdo($trimmedPath);
    $journalMode = (string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();

    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    applied_at TEXT NOT NULL
)
SQL
    );

    $appliedVersions = [];
    $appliedRows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC');
    foreach ($appliedRows as $row) {
        $appliedVersions[] = (int) ($row['version'] ?? 0);
    }

    $migrationMap = videochat_sqlite_migrations();
    ksort($migrationMap);

    $newlyApplied = 0;
    foreach ($migrationMap as $version => $migration) {
        if (in_array($version, $appliedVersions, true)) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            foreach ($migration['statements'] as $sql) {
                $pdo->exec($sql);
            }

            $insert = $pdo->prepare(
                'INSERT INTO schema_migrations(version, name, applied_at) VALUES(:version, :name, :applied_at)'
            );
            $insert->execute([
                ':version' => $version,
                ':name' => $migration['name'],
                ':applied_at' => gmdate('c'),
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        $appliedVersions[] = $version;
        sort($appliedVersions);
        $newlyApplied++;
    }

    $seededDemoUsers = videochat_seed_demo_users($pdo);

    $tableNames = [];
    $tableRows = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
    );
    foreach ($tableRows as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $tableNames[] = $name;
        }
    }

    $schemaVersion = empty($appliedVersions) ? 0 : max($appliedVersions);
    $migrationTotal = count($migrationMap);
    $migrationApplied = count($appliedVersions);

    return [
        'path' => $trimmedPath,
        'schema_version' => $schemaVersion,
        'migrations_total' => $migrationTotal,
        'migrations_applied' => $migrationApplied,
        'migrations_newly_applied' => $newlyApplied,
        'migrations_pending' => max($migrationTotal - $migrationApplied, 0),
        'applied_versions' => $appliedVersions,
        'table_count' => count($tableNames),
        'table_names' => $tableNames,
        'journal_mode' => strtoupper($journalMode),
        'demo_users' => $seededDemoUsers,
    ];
}
