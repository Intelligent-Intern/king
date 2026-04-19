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
 *   date_format: string,
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
    $users = [
        [
            'email' => $adminEmail,
            'display_name' => 'Platform Admin',
            'role' => 'admin',
            'password' => $adminPassword,
            'time_format' => '24h',
            'date_format' => 'dmy_dot',
            'theme' => 'dark',
        ],
        [
            'email' => $userEmail,
            'display_name' => 'Call User',
            'role' => 'user',
            'password' => $userPassword,
            'time_format' => '24h',
            'date_format' => 'dmy_dot',
            'theme' => 'dark',
        ],
    ];

    $deduplicated = [];
    $seenByEmail = [];
    foreach ($users as $user) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '' || isset($seenByEmail[$email])) {
            continue;
        }
        $seenByEmail[$email] = true;
        $deduplicated[] = $user;
    }

    return $deduplicated;
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
SELECT id, role_id, display_name, password_hash, status, time_format, date_format, theme
FROM users
WHERE lower(email) = lower(:email)
LIMIT 1
SQL
    );
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', :time_format, :date_format, :theme, :updated_at)
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
    date_format = :date_format,
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
            if ((string) ($existing['date_format'] ?? '') !== $demoUser['date_format']) {
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
                    ':date_format' => $demoUser['date_format'],
                    ':theme' => $demoUser['theme'],
                    ':updated_at' => gmdate('c'),
                ]);
            }
        } else {
            $passwordHash = password_hash($demoUser['password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Failed to hash demo user password.');
            }

            $insertParams = [
                ':email' => $demoUser['email'],
                ':display_name' => $demoUser['display_name'],
                ':password_hash' => $passwordHash,
                ':role_id' => $roleId,
                ':time_format' => $demoUser['time_format'],
                ':date_format' => $demoUser['date_format'],
                ':theme' => $demoUser['theme'],
                ':updated_at' => gmdate('c'),
            ];

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

        $seeded[] = [
            'email' => $demoUser['email'],
            'display_name' => $demoUser['display_name'],
            'role' => $demoUser['role'],
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

/**
 * @param array<string, array{id: int, email: string, display_name: string, role: string}> $usersByEmail
 * @return array<int, array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   status: string,
 *   owner_email: string,
 *   starts_at: string,
 *   ends_at: string,
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   cancel_message: ?string,
 *   participants: array<int, array{
 *     source: string,
 *     email: string,
 *     display_name: string,
 *     call_role: string,
 *     invite_state: string,
 *     joined_at: ?string,
 *     left_at: ?string
 *   }>
 * }>
 */
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
INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, cancelled_at, cancel_reason, cancel_message, created_at, updated_at)
VALUES(:id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at, :cancelled_at, :cancel_reason, :cancel_message, :created_at, :updated_at)
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

        $callPayload = [
            ':id' => $callId,
            ':room_id' => (string) ($call['room_id'] ?? $callId),
            ':title' => (string) ($call['title'] ?? 'Demo Call'),
            ':owner_user_id' => (int) ($owner['id'] ?? 0),
            ':status' => (string) ($call['status'] ?? 'scheduled'),
            ':starts_at' => (string) ($call['starts_at'] ?? gmdate('c')),
            ':ends_at' => (string) ($call['ends_at'] ?? gmdate('c')),
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
    invite_state TEXT NOT NULL DEFAULT 'invited' CHECK (invite_state IN ('invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled')),
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
        5 => [
            'name' => '0005_remove_global_moderator_role',
            'statements' => [
                <<<'SQL'
UPDATE users
SET role_id = (SELECT id FROM roles WHERE slug = 'user' LIMIT 1)
WHERE role_id = (SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1)
  AND EXISTS (SELECT 1 FROM roles WHERE slug = 'user')
SQL,
                "DELETE FROM roles WHERE slug = 'moderator'",
            ],
        ],
        6 => [
            'name' => '0006_call_participant_roles',
            'statements' => [
                "ALTER TABLE call_participants ADD COLUMN call_role TEXT NOT NULL DEFAULT 'participant' CHECK (call_role IN ('owner', 'moderator', 'participant'))",
                <<<'SQL'
UPDATE call_participants
SET call_role = 'owner'
WHERE source = 'internal'
  AND user_id IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM calls
      WHERE calls.id = call_participants.call_id
        AND calls.owner_user_id = call_participants.user_id
  )
SQL,
                <<<'SQL'
UPDATE call_participants
SET call_role = 'participant'
WHERE call_role IS NULL OR trim(call_role) = ''
SQL,
            ],
        ],
        7 => [
            'name' => '0007_call_access_links',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_access_links (
    id TEXT PRIMARY KEY,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    participant_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    participant_email TEXT,
    invite_code_id TEXT REFERENCES invite_codes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    expires_at TEXT,
    last_used_at TEXT,
    consumed_at TEXT
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_call_id ON call_access_links(call_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_participant_user_id ON call_access_links(participant_user_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_invite_code_id ON call_access_links(invite_code_id)",
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_access_links_call_user
ON call_access_links(call_id, participant_user_id)
WHERE participant_user_id IS NOT NULL
SQL,
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_access_links_call_email
ON call_access_links(call_id, participant_email)
WHERE participant_user_id IS NULL
  AND participant_email IS NOT NULL
  AND trim(participant_email) <> ''
SQL,
            ],
        ],
        8 => [
            'name' => '0008_calls_access_mode',
            'statements' => [
                "ALTER TABLE calls ADD COLUMN access_mode TEXT NOT NULL DEFAULT 'invite_only' CHECK (access_mode IN ('invite_only', 'free_for_all'))",
                <<<'SQL'
UPDATE calls
SET access_mode = 'invite_only'
WHERE access_mode IS NULL
   OR trim(access_mode) = ''
   OR lower(access_mode) NOT IN ('invite_only', 'free_for_all')
SQL,
            ],
        ],
        9 => [
            'name' => '0009_calls_dedicated_room_ids',
            'statements' => [
                <<<'SQL'
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
SELECT
    calls.id,
    CASE
        WHEN trim(coalesce(calls.title, '')) = '' THEN 'Call Room'
        ELSE calls.title
    END,
    'private',
    'active',
    calls.owner_user_id,
    coalesce(nullif(calls.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(calls.updated_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
FROM calls
WHERE lower(trim(coalesce(calls.room_id, ''))) = 'lobby'
SQL,
                <<<'SQL'
UPDATE calls
SET room_id = id
WHERE lower(trim(coalesce(room_id, ''))) = 'lobby'
SQL,
            ],
        ],
        10 => [
            'name' => '0010_user_email_identities',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS user_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    email TEXT NOT NULL,
    is_verified INTEGER NOT NULL DEFAULT 0 CHECK (is_verified IN (0, 1)),
    is_primary INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
    verified_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS user_email_change_tokens (
    id TEXT PRIMARY KEY,
    user_email_id INTEGER NOT NULL REFERENCES user_emails(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_user_emails_email_nocase ON user_emails(lower(email))",
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_emails_primary_per_user
ON user_emails(user_id)
WHERE is_primary = 1
SQL,
                "CREATE INDEX IF NOT EXISTS idx_user_emails_user_id ON user_emails(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_email_change_tokens_user_id ON user_email_change_tokens(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_email_change_tokens_expires_at ON user_email_change_tokens(expires_at)",
                <<<'SQL'
INSERT INTO user_emails(user_id, email, is_verified, is_primary, verified_at, created_at, updated_at)
SELECT
    users.id,
    lower(trim(users.email)),
    1,
    1,
    coalesce(nullif(users.updated_at, ''), nullif(users.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(users.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(users.updated_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
FROM users
WHERE trim(coalesce(users.email, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM user_emails
      WHERE user_emails.user_id = users.id
  )
SQL,
            ],
        ],
        11 => [
            'name' => '0011_users_date_format',
            'statements' => [
                "ALTER TABLE users ADD COLUMN date_format TEXT NOT NULL DEFAULT 'dmy_dot' CHECK (date_format IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot'))",
                <<<'SQL'
UPDATE users
SET date_format = 'dmy_dot'
WHERE date_format IS NULL
   OR trim(date_format) = ''
   OR lower(date_format) NOT IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot')
SQL,
            ],
        ],
        12 => [
            'name' => '0012_call_participant_admission_states',
            'statements' => [
                <<<'SQL'
ALTER TABLE call_participants RENAME TO call_participants_0012_old
SQL,
                <<<'SQL'
CREATE TABLE call_participants (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('internal', 'external')),
    invite_state TEXT NOT NULL DEFAULT 'invited' CHECK (invite_state IN ('invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled')),
    joined_at TEXT,
    left_at TEXT,
    call_role TEXT NOT NULL DEFAULT 'participant' CHECK (call_role IN ('owner', 'moderator', 'participant')),
    PRIMARY KEY (call_id, email)
)
SQL,
                <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at, call_role)
SELECT
    call_id,
    user_id,
    email,
    display_name,
    CASE
        WHEN lower(trim(coalesce(source, ''))) IN ('internal', 'external') THEN lower(trim(source))
        ELSE 'external'
    END,
    CASE
        WHEN lower(trim(coalesce(invite_state, ''))) = 'accepted' THEN 'allowed'
        WHEN lower(trim(coalesce(invite_state, ''))) = 'pending' THEN 'invited'
        WHEN lower(trim(coalesce(invite_state, ''))) IN ('invited', 'allowed', 'declined', 'cancelled') THEN lower(trim(invite_state))
        ELSE 'invited'
    END,
    joined_at,
    left_at,
    CASE
        WHEN lower(trim(coalesce(call_role, ''))) IN ('owner', 'moderator', 'participant') THEN lower(trim(call_role))
        ELSE 'participant'
    END
FROM call_participants_0012_old
SQL,
                <<<'SQL'
DROP TABLE call_participants_0012_old
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_participants_user_id ON call_participants(user_id)",
            ],
        ],
        13 => [
            'name' => '0013_call_chat_attachments',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_attachments (
    id TEXT PRIMARY KEY,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    uploaded_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('image', 'text', 'pdf', 'document')),
    extension TEXT NOT NULL,
    object_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'attached', 'deleted')),
    attached_message_id TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    attached_at TEXT
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_call_id ON call_chat_attachments(call_id, status)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_room_id ON call_chat_attachments(room_id, status)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_uploaded_by ON call_chat_attachments(uploaded_by_user_id, status)",
            ],
        ],
        14 => [
            'name' => '0014_call_chat_archive',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_messages (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id TEXT NOT NULL UNIQUE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_display_name TEXT NOT NULL,
    sender_role TEXT NOT NULL DEFAULT 'user',
    text TEXT NOT NULL,
    message_json TEXT NOT NULL,
    transcript_object_key TEXT NOT NULL UNIQUE,
    server_unix_ms INTEGER NOT NULL,
    server_time TEXT NOT NULL,
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_acl (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    access_role TEXT NOT NULL CHECK (access_role IN ('owner', 'moderator', 'participant', 'admin')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (call_id, user_id)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_call_seq ON call_chat_messages(call_id, seq)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_room_time ON call_chat_messages(room_id, server_unix_ms)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_sender ON call_chat_messages(sender_user_id, seq)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_acl_user_id ON call_chat_acl(user_id)",
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
 *   demo_users: array<int, array{email: string, display_name: string, role: string}>,
 *   demo_calls: array<int, array{id: string, room_id: string, title: string, status: string, owner_email: string, starts_at: string, ends_at: string}>
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
            $message = strtolower($error->getMessage());
            $isMigrationRace = str_contains($message, 'unique constraint failed')
                && str_contains($message, 'schema_migrations.version');
            if ($isMigrationRace) {
                if (!in_array($version, $appliedVersions, true)) {
                    $appliedVersions[] = $version;
                    sort($appliedVersions);
                }
                continue;
            }
            throw $error;
        }

        $appliedVersions[] = $version;
        sort($appliedVersions);
        $newlyApplied++;
    }

    $seededDemoUsers = [];
    $seededDemoCalls = [];
    // HTTP workers bootstrap the same SQLite file; serialize fixed demo IDs.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $seededDemoUsers = videochat_seed_demo_users($pdo);
        $seededDemoCalls = videochat_seed_demo_calls($pdo);
        $pdo->exec('COMMIT');
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->exec('ROLLBACK');
        }
        throw $error;
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
        'demo_users' => $seededDemoUsers,
        'demo_calls' => $seededDemoCalls,
    ];
}
