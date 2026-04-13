<?php

declare(strict_types=1);

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
 *   journal_mode: string
 * }
 */
function videochat_bootstrap_sqlite(string $databasePath): array
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
    $journalMode = (string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();
    $pdo->exec('PRAGMA synchronous = NORMAL');

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
    ];
}
