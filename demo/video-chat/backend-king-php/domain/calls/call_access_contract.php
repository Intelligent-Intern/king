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

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   is_call_access_session: bool,
 *   binding: ?array<string, mixed>
 * }
 */
function videochat_validate_call_access_session_binding(
    PDO $pdo,
    string $sessionId,
    ?int $userId = null,
    ?int $nowUnix = null
): array {
    $normalizedSessionId = trim($sessionId);
    if ($normalizedSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
            'is_call_access_session' => false,
            'binding' => null,
        ];
    }

    try {
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT
    call_access_sessions.session_id,
    call_access_sessions.access_id,
    call_access_sessions.call_id,
    call_access_sessions.room_id,
    call_access_sessions.user_id,
    call_access_sessions.link_kind,
    call_access_sessions.issued_at,
    call_access_sessions.expires_at,
    call_access_sessions.created_at,
    call_access_links.id AS link_id,
    call_access_links.call_id AS link_call_id,
    call_access_links.participant_user_id AS link_participant_user_id,
    call_access_links.participant_email AS link_participant_email,
    call_access_links.expires_at AS link_expires_at,
    calls.id AS resolved_call_id,
    calls.room_id AS resolved_room_id,
    calls.status AS resolved_call_status,
    users.email AS resolved_user_email
FROM call_access_sessions
LEFT JOIN call_access_links ON call_access_links.id = call_access_sessions.access_id
LEFT JOIN calls ON calls.id = call_access_sessions.call_id
LEFT JOIN users ON users.id = call_access_sessions.user_id
WHERE call_access_sessions.session_id = :session_id
LIMIT 1
SQL
        );
        $statement->execute([':session_id' => $normalizedSessionId]);
        $row = $statement->fetch();
    } catch (Throwable $error) {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'no such table') && str_contains($message, 'call_access_sessions')) {
            return [
                'ok' => true,
                'reason' => 'not_applicable',
                'is_call_access_session' => false,
                'binding' => null,
            ];
        }
        throw $error;
    }

    if (!is_array($row)) {
        return [
            'ok' => true,
            'reason' => 'not_applicable',
            'is_call_access_session' => false,
            'binding' => null,
        ];
    }

    $binding = [
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

    $fail = static function (string $reason) use ($binding): array {
        return [
            'ok' => false,
            'reason' => $reason,
            'is_call_access_session' => true,
            'binding' => $binding,
        ];
    };

    $bindingUserId = (int) ($binding['user_id'] ?? 0);
    if ($userId !== null && $userId > 0 && $bindingUserId !== $userId) {
        return $fail('call_access_session_user_mismatch');
    }

    $linkKind = videochat_call_access_link_kind([
        'participant_user_id' => is_numeric($row['link_participant_user_id'] ?? null)
            ? (int) $row['link_participant_user_id']
            : null,
        'participant_email' => is_string($row['link_participant_email'] ?? null)
            ? (string) $row['link_participant_email']
            : null,
    ]);
    if (
        (string) ($binding['session_id'] ?? '') === ''
        || (string) ($binding['access_id'] ?? '') === ''
        || (string) ($binding['call_id'] ?? '') === ''
        || (string) ($binding['room_id'] ?? '') === ''
        || $bindingUserId <= 0
    ) {
        return $fail('call_access_binding_mismatch');
    }
    if (!is_string($row['link_id'] ?? null) || trim((string) $row['link_id']) === '') {
        return $fail('call_access_link_invalidated');
    }
    if (videochat_call_access_link_is_invalidated($pdo, [
        'call_id' => (string) ($row['link_call_id'] ?? ''),
        'participant_user_id' => is_numeric($row['link_participant_user_id'] ?? null)
            ? (int) $row['link_participant_user_id']
            : null,
        'participant_email' => is_string($row['link_participant_email'] ?? null)
            ? (string) $row['link_participant_email']
            : null,
    ])) {
        return $fail('call_access_link_invalidated');
    }
    if (!is_string($row['resolved_call_id'] ?? null) || trim((string) $row['resolved_call_id']) === '') {
        return $fail('call_access_binding_mismatch');
    }
    if ((string) ($row['link_call_id'] ?? '') !== (string) ($binding['call_id'] ?? '')) {
        return $fail('call_access_binding_mismatch');
    }
    if ((string) ($row['resolved_call_id'] ?? '') !== (string) ($binding['call_id'] ?? '')) {
        return $fail('call_access_binding_mismatch');
    }
    if ((string) ($row['resolved_room_id'] ?? '') !== (string) ($binding['room_id'] ?? '')) {
        return $fail('call_access_binding_mismatch');
    }
    if (!videochat_is_call_joinable_status((string) ($row['resolved_call_status'] ?? ''))) {
        return $fail('call_access_call_not_joinable');
    }
    if ($linkKind !== (string) ($binding['link_kind'] ?? 'personal')) {
        return $fail('call_access_binding_mismatch');
    }

    $currentUnix = $nowUnix ?? time();
    $bindingExpiresAtUnix = strtotime((string) ($binding['expires_at'] ?? ''));
    if (!is_int($bindingExpiresAtUnix) || $bindingExpiresAtUnix <= $currentUnix) {
        return $fail('call_access_session_expired');
    }
    $linkExpiresAt = is_string($row['link_expires_at'] ?? null) ? trim((string) $row['link_expires_at']) : '';
    if ($linkExpiresAt !== '') {
        $linkExpiresAtUnix = strtotime($linkExpiresAt);
        if (!is_int($linkExpiresAtUnix) || $linkExpiresAtUnix <= $currentUnix) {
            return $fail('call_access_link_expired');
        }
    }

    $linkParticipantUserId = is_numeric($row['link_participant_user_id'] ?? null)
        ? (int) $row['link_participant_user_id']
        : 0;
    $linkParticipantEmail = videochat_normalize_call_access_email(
        is_string($row['link_participant_email'] ?? null) ? (string) $row['link_participant_email'] : null
    );
    $userEmail = videochat_normalize_call_access_email(
        is_string($row['resolved_user_email'] ?? null) ? (string) $row['resolved_user_email'] : null
    );
    if ($linkKind === 'personal') {
        if ($linkParticipantUserId > 0 && $linkParticipantUserId !== $bindingUserId) {
            return $fail('call_access_binding_mismatch');
        }
        if ($linkParticipantEmail !== '' && $linkParticipantEmail !== $userEmail) {
            return $fail('call_access_binding_mismatch');
        }
    } elseif ($linkParticipantUserId > 0 || $linkParticipantEmail !== '') {
        return $fail('call_access_binding_mismatch');
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'is_call_access_session' => true,
        'binding' => $binding,
    ];
}

function videochat_fetch_call_access_session_binding(PDO $pdo, string $sessionId): ?array
{
    $validation = videochat_validate_call_access_session_binding($pdo, $sessionId);
    if (!(bool) ($validation['ok'] ?? false) || !(bool) ($validation['is_call_access_session'] ?? false)) {
        return null;
    }

    return is_array($validation['binding'] ?? null) ? $validation['binding'] : null;
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

function videochat_call_access_participant_invite_state(PDO $pdo, array $accessLink): string
{
    if (videochat_call_access_link_kind($accessLink) !== 'personal') {
        return '';
    }

    $callId = trim((string) ($accessLink['call_id'] ?? ''));
    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    $participantEmail = videochat_normalize_call_access_email(
        is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
    );
    if ($callId === '' || ($linkedUserId <= 0 && $participantEmail === '')) {
        return '';
    }

    if ($linkedUserId > 0) {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
ORDER BY CASE WHEN source = 'internal' THEN 0 ELSE 1 END ASC
LIMIT 1
SQL
        );
        $query->execute([
            ':call_id' => $callId,
            ':user_id' => $linkedUserId,
        ]);
    } else {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT invite_state
FROM call_participants
WHERE call_id = :call_id
  AND lower(email) = lower(:email)
ORDER BY CASE WHEN source = 'external' THEN 0 ELSE 1 END ASC
LIMIT 1
SQL
        );
        $query->execute([
            ':call_id' => $callId,
            ':email' => $participantEmail,
        ]);
    }

    $row = $query->fetch();
    if (!is_array($row)) {
        return '';
    }

    return strtolower(trim((string) ($row['invite_state'] ?? '')));
}

function videochat_call_access_link_is_invalidated(PDO $pdo, array $accessLink): bool
{
    return in_array(videochat_call_access_participant_invite_state($pdo, $accessLink), ['cancelled', 'declined'], true);
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
function videochat_fetch_active_user_for_call_access(
    PDO $pdo,
    int $userId = 0,
    ?string $email = null,
    ?int $tenantId = null,
    bool $requireTenantMembership = true
): ?array
{
    $normalizedEmail = videochat_normalize_call_access_email($email);
    if ($userId <= 0 && $normalizedEmail === '') {
        return null;
    }

    if ($userId > 0) {
        if ($requireTenantMembership && is_int($tenantId) && $tenantId > 0 && !videochat_tenant_user_is_member($pdo, $userId, $tenantId)) {
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
        $hasTenantMemberships = $requireTenantMembership && is_int($tenantId) && $tenantId > 0
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
    string $displayName,
    string $inviteState = 'invited'
): void {
    $normalizedInviteState = strtolower(trim($inviteState));
    if (!in_array($normalizedInviteState, ['invited', 'allowed'], true)) {
        $normalizedInviteState = 'invited';
    }

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
        WHEN :invite_state = 'allowed' THEN 'allowed'
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
            ':invite_state' => $normalizedInviteState,
        ]);
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'participant', :invite_state, NULL, NULL)
ON CONFLICT(call_id, email) DO UPDATE SET
    user_id = excluded.user_id,
    display_name = excluded.display_name,
    source = 'internal',
    call_role = CASE
        WHEN call_participants.call_role = 'owner' THEN 'owner'
        ELSE call_participants.call_role
    END,
    invite_state = CASE
        WHEN excluded.invite_state = 'allowed' THEN 'allowed'
        WHEN call_participants.invite_state IN ('declined', 'cancelled') THEN 'invited'
        ELSE call_participants.invite_state
    END,
    left_at = NULL
SQL
    );
    $insert->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
        ':email' => $email,
        ':display_name' => $displayName,
        ':invite_state' => $normalizedInviteState,
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
