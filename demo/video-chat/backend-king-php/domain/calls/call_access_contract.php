<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_generate_call_access_uuid(): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable) {
        $bytes = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true), true);
        if (!is_string($bytes) || strlen($bytes) < 16) {
            $bytes = str_repeat("\0", 16);
        }
        $bytes = substr($bytes, 0, 16);
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function videochat_normalize_call_access_id(string $accessId): string
{
    $normalized = strtolower(trim($accessId));
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $normalized) !== 1) {
        return '';
    }

    return $normalized;
}

/**
 * @return array{
 *   id: string,
 *   call_id: string,
 *   participant_user_id: ?int,
 *   participant_email: ?string,
 *   invite_code_id: ?string,
 *   created_by_user_id: ?int,
 *   created_at: string,
 *   expires_at: ?string,
 *   last_used_at: ?string,
 *   consumed_at: ?string
 * }|null
 */
function videochat_fetch_call_access_link(PDO $pdo, string $accessId, ?int $tenantId = null): ?array
{
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return null;
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND tenant_id = :tenant_id' : '';
    $statement = $pdo->prepare(
        <<<SQL
SELECT
    id,
    {$tenantSelect}
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at
FROM call_access_links
WHERE lower(id) = :id
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $normalizedAccessId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $statement->execute($params);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'call_id' => (string) ($row['call_id'] ?? ''),
        'participant_user_id' => is_numeric($row['participant_user_id'] ?? null)
            ? (int) $row['participant_user_id']
            : null,
        'participant_email' => is_string($row['participant_email'] ?? null)
            ? strtolower(trim((string) $row['participant_email']))
            : null,
        'invite_code_id' => is_string($row['invite_code_id'] ?? null) ? (string) $row['invite_code_id'] : null,
        'created_by_user_id' => is_numeric($row['created_by_user_id'] ?? null)
            ? (int) $row['created_by_user_id']
            : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'expires_at' => is_string($row['expires_at'] ?? null) ? (string) $row['expires_at'] : null,
        'last_used_at' => is_string($row['last_used_at'] ?? null) ? (string) $row['last_used_at'] : null,
        'consumed_at' => is_string($row['consumed_at'] ?? null) ? (string) $row['consumed_at'] : null,
    ];
}

function videochat_fetch_call_access_session_binding(PDO $pdo, string $sessionId): ?array
{
    $normalizedSessionId = trim($sessionId);
    if ($normalizedSessionId === '') {
        return null;
    }

    try {
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT
    session_id,
    access_id,
    call_id,
    room_id,
    user_id,
    link_kind,
    issued_at,
    expires_at,
    created_at
FROM call_access_sessions
WHERE session_id = :session_id
LIMIT 1
SQL
        );
        $statement->execute([':session_id' => $normalizedSessionId]);
        $row = $statement->fetch();
    } catch (Throwable $error) {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'no such table') && str_contains($message, 'call_access_sessions')) {
            return null;
        }
        throw $error;
    }

    if (!is_array($row)) {
        return null;
    }

    return [
        'session_id' => (string) ($row['session_id'] ?? ''),
        'access_id' => (string) ($row['access_id'] ?? ''),
        'call_id' => (string) ($row['call_id'] ?? ''),
        'room_id' => (string) ($row['room_id'] ?? ''),
        'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
        'link_kind' => (string) ($row['link_kind'] ?? 'personal'),
        'issued_at' => (string) ($row['issued_at'] ?? ''),
        'expires_at' => (string) ($row['expires_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function videochat_normalize_call_access_email(?string $email): string
{
    $normalized = strtolower(trim((string) $email));
    if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
        return '';
    }

    return $normalized;
}

function videochat_call_access_link_kind(?array $accessLink): string
{
    if (!is_array($accessLink)) {
        return 'personal';
    }

    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    $participantEmail = videochat_normalize_call_access_email(
        is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
    );

    if ($linkedUserId <= 0 && $participantEmail === '') {
        return 'open';
    }

    return 'personal';
}

function videochat_is_call_joinable_status(string $status): bool
{
    $normalized = strtolower(trim($status));
    return in_array($normalized, ['scheduled', 'active'], true);
}

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   display_name: string,
 *   role: string,
 *   status: string,
 *   time_format: string,
 *   date_format: string,
 *   theme: string,
 *   avatar_path: ?string,
 *   account_type: string,
 *   is_guest: bool
 * }|null
 */
function videochat_fetch_active_user_for_call_access(PDO $pdo, int $userId = 0, ?string $email = null, ?int $tenantId = null): ?array
{
    $normalizedEmail = videochat_normalize_call_access_email($email);
    if ($userId <= 0 && $normalizedEmail === '') {
        return null;
    }

    if ($userId > 0) {
        if (is_int($tenantId) && $tenantId > 0 && !videochat_tenant_user_is_member($pdo, $userId, $tenantId)) {
            return null;
        }
        $query = $pdo->prepare(
            <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.password_hash,
    users.time_format,
    users.date_format,
    users.theme,
    users.avatar_path,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id
  AND users.status = 'active'
LIMIT 1
SQL
        );
        $query->execute([':id' => $userId]);
    } else {
        $hasTenantMemberships = is_int($tenantId) && $tenantId > 0
            && videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id');
        $tenantJoin = $hasTenantMemberships
            ? 'INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id'
            : '';
        $tenantWhere = $hasTenantMemberships
            ? 'AND tenant_memberships.tenant_id = :tenant_id AND tenant_memberships.status = \'active\''
            : '';
        $query = $pdo->prepare(
            <<<SQL
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.password_hash,
    users.time_format,
    users.date_format,
    users.theme,
    users.avatar_path,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$tenantJoin}
WHERE lower(users.email) = lower(:email)
  AND users.status = 'active'
  {$tenantWhere}
LIMIT 1
SQL
        );
        $params = [':email' => $normalizedEmail];
        if ($tenantWhere !== '') {
            $params[':tenant_id'] = $tenantId;
        }
        $query->execute($params);
    }

    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    $accountType = videochat_user_account_type(
        is_string($row['email'] ?? null) ? (string) $row['email'] : '',
        $row['password_hash'] ?? null
    );

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => (string) ($row['email'] ?? ''),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'role' => is_string($row['role_slug'] ?? null) ? (string) $row['role_slug'] : 'user',
        'status' => (string) ($row['status'] ?? 'disabled'),
        'time_format' => (string) ($row['time_format'] ?? '24h'),
        'date_format' => (string) ($row['date_format'] ?? 'dmy_dot'),
        'theme' => (string) ($row['theme'] ?? 'dark'),
        'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
        'account_type' => $accountType,
        'is_guest' => $accountType === 'guest',
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>
 * }
 */
function videochat_create_guest_user_for_call_access(PDO $pdo, string $displayName, ?int $tenantId = null): array
{
    $name = trim($displayName);
    if ($name === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['guest_name' => 'required_guest_name'],
            'user' => null,
        ];
    }
    if (strlen($name) > 96) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['guest_name' => 'guest_name_too_long'],
            'user' => null,
        ];
    }

    $roleIdQuery = $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1");
    $roleIdRow = $roleIdQuery !== false ? $roleIdQuery->fetch() : false;
    $roleId = is_array($roleIdRow) ? (int) ($roleIdRow['id'] ?? 0) : 0;
    if ($roleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['role' => 'user_role_not_found'],
            'user' => null,
        ];
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(
    email,
    display_name,
    password_hash,
    role_id,
    status,
    time_format,
    date_format,
    theme,
    avatar_path,
    updated_at
) VALUES(
    :email,
    :display_name,
    NULL,
    :role_id,
    'active',
    '24h',
    'dmy_dot',
    'dark',
    NULL,
    :updated_at
)
SQL
    );

    $createdUserId = 0;
    for ($attempt = 0; $attempt < 6; $attempt += 1) {
        $guestEmail = 'guest+' . str_replace('-', '', videochat_generate_call_access_uuid()) . '@videochat.local';
        try {
            $insert->execute([
                ':email' => $guestEmail,
                ':display_name' => $name,
                ':role_id' => $roleId,
                ':updated_at' => gmdate('c'),
            ]);
            $createdUserId = (int) $pdo->lastInsertId();
            if (is_int($tenantId) && $tenantId > 0) {
                videochat_tenant_attach_user($pdo, $createdUserId, $tenantId);
            }
            break;
        } catch (Throwable $error) {
            if (videochat_is_sqlite_unique_constraint_error($error)) {
                continue;
            }
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'user' => null,
            ];
        }
    }

    if ($createdUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['guest_user' => 'could_not_allocate_unique_guest_identity'],
            'user' => null,
        ];
    }

    $user = videochat_fetch_active_user_for_call_access($pdo, $createdUserId, null, $tenantId);
    if (!is_array($user)) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['guest_user' => 'guest_user_lookup_failed'],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'created',
        'errors' => [],
        'user' => $user,
    ];
}

function videochat_ensure_internal_call_participant(
    PDO $pdo,
    string $callId,
    int $userId,
    string $email,
    string $displayName
): void {
    $check = $pdo->prepare(
        <<<'SQL'
SELECT 1
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $check->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $exists = $check->fetchColumn() !== false;
    if ($exists) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET email = :email,
    display_name = :display_name,
    invite_state = CASE
        WHEN invite_state IN ('declined', 'cancelled') THEN 'invited'
        ELSE invite_state
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $update->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
        ]);
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'participant', 'invited', NULL, NULL)
SQL
    );
    $insert->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
        ':email' => $email,
        ':display_name' => $displayName,
    ]);
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>,
 *   target_user: ?array<string, mixed>,
 *   target_hint: array{participant_email: ?string}
 * }
 */
