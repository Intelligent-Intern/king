<?php

declare(strict_types=1);

/**
 * #A-batch auth store (#A-1) — simple users + sessions persistence for
 * the model-inference demo. Mirrors the video-chat auth shape (bcrypt
 * via password_hash(PASSWORD_DEFAULT), opaque session ids, SQLite
 * tables) without importing video-chat code.
 *
 * Scope fences pinned in contracts/v1/auth-request.contract.json and
 * contracts/v1/user-session.contract.json:
 *
 *   - Passwords always hashed with PASSWORD_DEFAULT; plaintext never
 *     persists anywhere.
 *   - Session ids are opaque 32-char hex strings (16 random bytes).
 *   - TTL is clamped to [60s, 30d]. 0 / negative / out-of-range values
 *     are replaced with the default 12h.
 *   - No session refresh / rotation in this leaf — logout + re-login
 *     covers the demo's use case.
 *   - Role is flat: "user" | "admin". No path-rule matrix.
 */

function model_inference_auth_allowed_roles(): array
{
    return ['user', 'admin'];
}

function model_inference_auth_allowed_statuses(): array
{
    return ['active', 'disabled'];
}

function model_inference_auth_default_ttl_seconds(): int
{
    $env = getenv('MODEL_INFERENCE_SESSION_TTL_SECONDS');
    if (is_string($env) && ctype_digit(trim($env))) {
        $candidate = (int) trim($env);
        if ($candidate >= 60 && $candidate <= 2592000) {
            return $candidate;
        }
    }
    return 12 * 60 * 60;
}

function model_inference_auth_schema_migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        display_name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'user\' CHECK(role IN (\'user\',\'admin\')),
        status TEXT NOT NULL DEFAULT \'active\' CHECK(status IN (\'active\',\'disabled\')),
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS sessions (
        id TEXT PRIMARY KEY,
        user_id INTEGER NOT NULL,
        issued_at TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        revoked_at TEXT,
        client_ip TEXT,
        user_agent TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sessions_revoked_at ON sessions(revoked_at)');
}

/**
 * Create a new user. `plaintextPassword` is bcrypt-hashed here and
 * discarded. Returns the created user envelope (without password_hash).
 *
 * @throws InvalidArgumentException on validation failure
 * @throws RuntimeException on duplicate username
 * @return array<string, mixed>
 */
function model_inference_auth_create_user(
    PDO $pdo,
    string $username,
    string $plaintextPassword,
    string $displayName,
    string $role = 'user'
): array {
    model_inference_auth_validate_username($username);
    model_inference_auth_validate_password($plaintextPassword);
    if (strlen($displayName) < 1 || strlen($displayName) > 128) {
        throw new InvalidArgumentException('display_name must be 1..128 chars');
    }
    if (!in_array($role, model_inference_auth_allowed_roles(), true)) {
        throw new InvalidArgumentException('role must be one of ' . implode('|', model_inference_auth_allowed_roles()));
    }
    $hash = password_hash($plaintextPassword, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('password_hash returned non-string');
    }
    $now = gmdate('c');
    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, display_name, role, status, created_at, updated_at)
            VALUES (:u, :h, :d, :r, :s, :c, :c)');
        $stmt->execute([
            ':u' => $username, ':h' => $hash, ':d' => $displayName,
            ':r' => $role, ':s' => 'active', ':c' => $now,
        ]);
    } catch (PDOException $e) {
        if (str_contains((string) $e->getMessage(), 'UNIQUE')) {
            throw new RuntimeException('auth:username_taken:' . $username);
        }
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    return model_inference_auth_user_envelope([
        'id' => $id, 'username' => $username, 'display_name' => $displayName,
        'role' => $role, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/**
 * @return array<string, mixed>|null
 */
function model_inference_auth_find_user(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return model_inference_auth_user_envelope($row);
}

/**
 * Verify a (username, plaintext) pair. Returns the user envelope on
 * success, null on any failure. Never reveals *why* (wrong username vs
 * wrong password) — callers surface a generic `invalid_credentials`.
 *
 * @return array<string, mixed>|null
 */
function model_inference_auth_verify_credentials(
    PDO $pdo,
    string $username,
    string $plaintextPassword
): ?array {
    if ($username === '' || $plaintextPassword === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    if ($row === false) {
        // Run a dummy verify anyway to make timing-side-channels a touch
        // less obvious. Demo scope — not a full constant-time guarantee.
        password_verify($plaintextPassword, '$2y$10$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        return null;
    }
    if ((string) ($row['status'] ?? '') !== 'active') {
        return null;
    }
    if (!password_verify($plaintextPassword, (string) $row['password_hash'])) {
        return null;
    }
    return model_inference_auth_user_envelope($row);
}

/**
 * Issue a new opaque session for a user. TTL is clamped to [60s, 30d].
 *
 * @return array<string, mixed>
 */
function model_inference_auth_issue_session(
    PDO $pdo,
    int $userId,
    ?int $ttlSeconds = null,
    ?string $clientIp = null,
    ?string $userAgent = null
): array {
    if ($userId < 1) {
        throw new InvalidArgumentException('user_id must be positive');
    }
    $ttl = $ttlSeconds ?? model_inference_auth_default_ttl_seconds();
    if ($ttl < 60 || $ttl > 2592000) {
        $ttl = model_inference_auth_default_ttl_seconds();
    }

    $token = bin2hex(random_bytes(16)); // 32 hex chars
    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + $ttl);

    $stmt = $pdo->prepare('INSERT INTO sessions (id, user_id, issued_at, expires_at, client_ip, user_agent)
        VALUES (:id, :uid, :iss, :exp, :ip, :ua)');
    $stmt->execute([
        ':id' => $token, ':uid' => $userId,
        ':iss' => $issuedAt, ':exp' => $expiresAt,
        ':ip' => $clientIp, ':ua' => $userAgent,
    ]);

    return [
        'id' => $token,
        'user_id' => $userId,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
        'ttl_seconds' => $ttl,
        'revoked_at' => null,
    ];
}

/**
 * Validate an opaque session token. Returns {session, user} envelope on
 * success, null on any failure. Revoked / expired / unknown tokens all
 * look the same to the caller.
 *
 * @return array{session: array<string, mixed>, user: array<string, mixed>}|null
 */
function model_inference_auth_validate_session(PDO $pdo, string $token): ?array
{
    if ($token === '' || strlen($token) > 128) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT s.*, u.username, u.display_name, u.role, u.status AS user_status
        FROM sessions s
        INNER JOIN users u ON u.id = s.user_id
        WHERE s.id = :id LIMIT 1');
    $stmt->execute([':id' => $token]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    if ($row['revoked_at'] !== null) {
        return null;
    }
    if ((string) ($row['user_status'] ?? '') !== 'active') {
        return null;
    }
    $expiresAt = (string) ($row['expires_at'] ?? '');
    if ($expiresAt === '' || strtotime($expiresAt) === false || strtotime($expiresAt) <= time()) {
        return null;
    }
    return [
        'session' => [
            'id' => (string) $row['id'],
            'user_id' => (int) $row['user_id'],
            'issued_at' => (string) $row['issued_at'],
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ],
        'user' => model_inference_auth_user_envelope([
            'id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'display_name' => (string) $row['display_name'],
            'role' => (string) $row['role'],
            'status' => (string) $row['user_status'],
        ]),
    ];
}

/**
 * Mark a session as revoked. Returns true if a row was updated, false
 * otherwise.
 */
function model_inference_auth_revoke_session(PDO $pdo, string $token): bool
{
    if ($token === '') {
        return false;
    }
    $stmt = $pdo->prepare('UPDATE sessions SET revoked_at = :rev
        WHERE id = :id AND revoked_at IS NULL');
    $stmt->execute([':id' => $token, ':rev' => gmdate('c')]);
    return $stmt->rowCount() > 0;
}

/**
 * Strip sensitive fields (password_hash) from a raw user row and coerce
 * shape for API responses.
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function model_inference_auth_user_envelope(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'display_name' => (string) $row['display_name'],
        'role' => (string) $row['role'],
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
    ];
}

function model_inference_auth_validate_username(string $username): void
{
    $len = strlen($username);
    if ($len < 2 || $len > 64) {
        throw new InvalidArgumentException('username must be 2..64 chars');
    }
    if (preg_match('/^[a-zA-Z0-9_.\\-]+$/', $username) !== 1) {
        throw new InvalidArgumentException('username must match [a-zA-Z0-9_.-]+');
    }
}

function model_inference_auth_validate_password(string $password): void
{
    $len = strlen($password);
    if ($len < 6 || $len > 256) {
        throw new InvalidArgumentException('password must be 6..256 chars');
    }
}

/**
 * Seed demo users from fixtures/demo-users.json at bootstrap. Idempotent:
 * if a user already exists and their stored hash already verifies the
 * fixture's plaintext, the row is left untouched. When a username
 * clashes but the hash doesn't verify, the user has rotated their
 * password out-of-band and the seed MUST NOT overwrite it.
 *
 * Env overrides for each fixture user:
 *   MODEL_INFERENCE_DEMO_<USERNAME_UPPERCASE>_USERNAME
 *   MODEL_INFERENCE_DEMO_<USERNAME_UPPERCASE>_PASSWORD
 *   MODEL_INFERENCE_DEMO_<USERNAME_UPPERCASE>_DISPLAY_NAME
 *   MODEL_INFERENCE_DEMO_<USERNAME_UPPERCASE>_ROLE
 *
 * MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED=1 disables the seed entirely.
 *
 * @return array{seeded: int, skipped: int, preserved: int, source: string}
 */
function model_inference_auth_seed_demo_users(PDO $pdo, ?string $fixturePath = null): array
{
    if (getenv('MODEL_INFERENCE_AUTH_DISABLE_DEMO_SEED') === '1') {
        return ['seeded' => 0, 'skipped' => 0, 'preserved' => 0, 'source' => 'disabled'];
    }
    $fixturePath = $fixturePath ?? (__DIR__ . '/../../fixtures/demo-users.json');
    if (!is_file($fixturePath)) {
        return ['seeded' => 0, 'skipped' => 0, 'preserved' => 0, 'source' => 'missing:' . $fixturePath];
    }
    $raw = file_get_contents($fixturePath);
    if (!is_string($raw)) {
        return ['seeded' => 0, 'skipped' => 0, 'preserved' => 0, 'source' => 'unreadable:' . $fixturePath];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['users']) || !is_array($decoded['users'])) {
        return ['seeded' => 0, 'skipped' => 0, 'preserved' => 0, 'source' => 'invalid:' . $fixturePath];
    }

    $seeded = 0;
    $skipped = 0;
    $preserved = 0;
    foreach ($decoded['users'] as $raw) {
        if (!is_array($raw) || !isset($raw['username'], $raw['password'])) {
            continue;
        }
        $envKey = strtoupper((string) $raw['username']);
        $envUser = getenv('MODEL_INFERENCE_DEMO_' . $envKey . '_USERNAME');
        $envPass = getenv('MODEL_INFERENCE_DEMO_' . $envKey . '_PASSWORD');
        $envDisplay = getenv('MODEL_INFERENCE_DEMO_' . $envKey . '_DISPLAY_NAME');
        $envRole = getenv('MODEL_INFERENCE_DEMO_' . $envKey . '_ROLE');

        $username = is_string($envUser) && $envUser !== '' ? $envUser : (string) $raw['username'];
        $password = is_string($envPass) && $envPass !== '' ? $envPass : (string) $raw['password'];
        $displayName = is_string($envDisplay) && $envDisplay !== ''
            ? $envDisplay
            : (string) ($raw['display_name'] ?? $username);
        $role = is_string($envRole) && $envRole !== ''
            ? $envRole
            : (string) ($raw['role'] ?? 'user');

        if (!in_array($role, model_inference_auth_allowed_roles(), true)) {
            $role = 'user';
        }
        $existing = model_inference_auth_find_user_with_hash($pdo, $username);
        if ($existing === null) {
            try {
                model_inference_auth_create_user($pdo, $username, $password, $displayName, $role);
                $seeded++;
            } catch (Throwable $ignored) {
                // skip malformed fixture rows silently; seed MUST NOT fail boot
            }
            continue;
        }
        if (password_verify($password, (string) $existing['password_hash'])) {
            $skipped++;
        } else {
            $preserved++;
        }
    }
    return [
        'seeded' => $seeded,
        'skipped' => $skipped,
        'preserved' => $preserved,
        'source' => $fixturePath,
    ];
}

/**
 * Internal — find user row including password_hash (only seed uses this).
 * @return array<string, mixed>|null
 */
function model_inference_auth_find_user_with_hash(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}
