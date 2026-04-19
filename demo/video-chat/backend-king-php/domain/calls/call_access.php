<?php

declare(strict_types=1);

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
function videochat_fetch_call_access_link(PDO $pdo, string $accessId): ?array
{
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    id,
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
LIMIT 1
SQL
    );
    $statement->execute([':id' => $normalizedAccessId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
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
 *   avatar_path: ?string
 * }|null
 */
function videochat_fetch_active_user_for_call_access(PDO $pdo, int $userId = 0, ?string $email = null): ?array
{
    $normalizedEmail = videochat_normalize_call_access_email($email);
    if ($userId <= 0 && $normalizedEmail === '') {
        return null;
    }

    if ($userId > 0) {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
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
        $query = $pdo->prepare(
            <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.time_format,
    users.date_format,
    users.theme,
    users.avatar_path,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower(:email)
  AND users.status = 'active'
LIMIT 1
SQL
        );
        $query->execute([':email' => $normalizedEmail]);
    }

    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

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
function videochat_create_guest_user_for_call_access(PDO $pdo, string $displayName): array
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

    $user = videochat_fetch_active_user_for_call_access($pdo, $createdUserId, null);
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
function videochat_resolve_call_access_public(PDO $pdo, string $accessId): array
{
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'invalid_access_id'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
        ];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($accessLink)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_id' => 'not_found'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
        ];
    }

    $expiresAt = is_string($accessLink['expires_at'] ?? null) ? (string) $accessLink['expires_at'] : '';
    if ($expiresAt !== '') {
        $expiresAtUnix = strtotime($expiresAt);
        if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
            return [
                'ok' => false,
                'reason' => 'expired',
                'errors' => ['access_id' => 'expired'],
                'access_link' => null,
                'call' => null,
                'target_user' => null,
                'target_hint' => ['participant_email' => null],
            ];
        }
    }

    $call = videochat_fetch_call_for_update($pdo, (string) ($accessLink['call_id'] ?? ''));
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
            'target_user' => null,
            'target_hint' => ['participant_email' => null],
        ];
    }

    $callStatus = (string) ($call['status'] ?? 'scheduled');
    if (!videochat_is_call_joinable_status($callStatus)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['call_id' => 'call_not_joinable_from_status'],
            'access_link' => $accessLink,
            'call' => videochat_build_call_payload($pdo, $call, 0),
            'target_user' => null,
            'target_hint' => [
                'participant_email' => videochat_normalize_call_access_email(
                    is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
                ) ?: null,
            ],
        ];
    }

    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    $participantEmail = videochat_normalize_call_access_email(
        is_string($accessLink['participant_email'] ?? null) ? (string) $accessLink['participant_email'] : null
    );
    $targetUser = videochat_fetch_active_user_for_call_access(
        $pdo,
        $linkedUserId,
        $participantEmail === '' ? null : $participantEmail
    );

    $touch = $pdo->prepare(
        'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
    );
    $touch->execute([
        ':id' => $normalizedAccessId,
        ':last_used_at' => gmdate('c'),
    ]);

    $freshLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($freshLink)) {
        $freshLink = $accessLink;
    }

    return [
        'ok' => true,
        'reason' => 'resolved',
        'errors' => [],
        'access_link' => $freshLink,
        'call' => videochat_build_call_payload($pdo, $call, is_array($targetUser) ? (int) ($targetUser['id'] ?? 0) : 0),
        'target_user' => $targetUser,
        'target_hint' => ['participant_email' => $participantEmail === '' ? null : $participantEmail],
    ];
}

/**
 * @param callable(): string $issueSessionId
 * @param array{client_ip?: string, user_agent?: string} $requestMeta
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   session: ?array<string, mixed>,
 *   user: ?array<string, mixed>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_issue_session_for_call_access(
    PDO $pdo,
    string $accessId,
    callable $issueSessionId,
    array $requestMeta = [],
    array $options = []
): array {
    $resolve = videochat_resolve_call_access_public($pdo, $accessId);
    if (!(bool) ($resolve['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($resolve['reason'] ?? 'internal_error'),
            'errors' => is_array($resolve['errors'] ?? null) ? $resolve['errors'] : [],
            'session' => null,
            'user' => null,
            'access_link' => is_array($resolve['access_link'] ?? null) ? $resolve['access_link'] : null,
            'call' => is_array($resolve['call'] ?? null) ? $resolve['call'] : null,
        ];
    }

    $accessLink = is_array($resolve['access_link'] ?? null) ? $resolve['access_link'] : null;
    $call = is_array($resolve['call'] ?? null) ? $resolve['call'] : null;
    $targetUser = is_array($resolve['target_user'] ?? null) ? $resolve['target_user'] : null;
    if (!is_array($accessLink) || !is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_link' => 'access_link_or_call_not_found'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $linkKind = videochat_call_access_link_kind($accessLink);
    if ($linkKind === 'open') {
        $guestName = trim((string) ($options['guest_name'] ?? ''));
        $guestCreate = videochat_create_guest_user_for_call_access($pdo, $guestName);
        if (!(bool) ($guestCreate['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($guestCreate['reason'] ?? 'validation_failed'),
                'errors' => is_array($guestCreate['errors'] ?? null) ? $guestCreate['errors'] : ['guest_name' => 'required_guest_name'],
                'session' => null,
                'user' => null,
                'access_link' => $accessLink,
                'call' => $call,
            ];
        }
        $targetUser = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : null;
    }

    if (!is_array($targetUser)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user' => 'not_found_or_inactive'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $userId = (int) ($targetUser['id'] ?? 0);
    $userRole = (string) ($targetUser['role'] ?? 'user');
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['target_user' => 'invalid_target_user'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $callPermission = videochat_get_call_for_user(
        $pdo,
        (string) ($call['id'] ?? ''),
        $userId,
        $userRole
    );
    if ($linkKind === 'open' && !(bool) ($callPermission['ok'] ?? false)) {
        videochat_ensure_internal_call_participant(
            $pdo,
            (string) ($call['id'] ?? ''),
            $userId,
            (string) ($targetUser['email'] ?? ''),
            (string) ($targetUser['display_name'] ?? '')
        );
        $callPermission = videochat_get_call_for_user(
            $pdo,
            (string) ($call['id'] ?? ''),
            $userId,
            $userRole
        );
    }
    if (!(bool) ($callPermission['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['target_user' => 'not_allowed_for_call'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $ttlSeconds = (int) (getenv('VIDEOCHAT_SESSION_TTL_SECONDS') ?: 43_200);
    if ($ttlSeconds < 60) {
        $ttlSeconds = 60;
    } elseif ($ttlSeconds > 2_592_000) {
        $ttlSeconds = 2_592_000;
    }

    $sessionId = trim((string) $issueSessionId());
    if ($sessionId === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => ['session' => 'session_id_generation_failed'],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $issuedAt = gmdate('c');
    $expiresAt = gmdate('c', time() + $ttlSeconds);
    $clientIp = trim((string) ($requestMeta['client_ip'] ?? ''));
    $userAgent = substr(trim((string) ($requestMeta['user_agent'] ?? '')), 0, 500);

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
        );
        $insert->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':issued_at' => $issuedAt,
            ':expires_at' => $expiresAt,
            ':client_ip' => $clientIp === '' ? null : $clientIp,
            ':user_agent' => $userAgent === '' ? null : $userAgent,
        ]);

        $touch = $pdo->prepare(
            <<<'SQL'
UPDATE call_access_links
SET last_used_at = :last_used_at,
    consumed_at = CASE
        WHEN consumed_at IS NULL OR consumed_at = '' THEN :consumed_at
        ELSE consumed_at
    END
WHERE id = :id
SQL
        );
        $touch->execute([
            ':id' => (string) ($accessLink['id'] ?? ''),
            ':last_used_at' => gmdate('c'),
            ':consumed_at' => gmdate('c'),
        ]);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'session' => null,
            'user' => null,
            'access_link' => $accessLink,
            'call' => $call,
        ];
    }

    $freshLink = videochat_fetch_call_access_link($pdo, (string) ($accessLink['id'] ?? ''));
    $freshCall = videochat_get_call_for_user(
        $pdo,
        (string) ($call['id'] ?? ''),
        $userId,
        $userRole
    );

    return [
        'ok' => true,
        'reason' => 'issued',
        'errors' => [],
        'session' => [
            'id' => $sessionId,
            'token' => $sessionId,
            'token_type' => 'session_id',
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $ttlSeconds,
        ],
        'user' => [
            'id' => $userId,
            'email' => (string) ($targetUser['email'] ?? ''),
            'display_name' => (string) ($targetUser['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($targetUser['role'] ?? 'user')),
            'status' => (string) ($targetUser['status'] ?? 'active'),
            'time_format' => (string) ($targetUser['time_format'] ?? '24h'),
            'date_format' => (string) ($targetUser['date_format'] ?? 'dmy_dot'),
            'theme' => (string) ($targetUser['theme'] ?? 'dark'),
            'avatar_path' => is_string($targetUser['avatar_path'] ?? null) ? (string) $targetUser['avatar_path'] : null,
        ],
        'access_link' => is_array($freshLink) ? $freshLink : $accessLink,
        'call' => is_array($freshCall['call'] ?? null) ? $freshCall['call'] : $call,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_create_call_access_link_for_user(
    PDO $pdo,
    string $callId,
    int $authUserId,
    string $authRole,
    array $options = []
): array {
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['call_id' => 'required_call_id'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $callFetch = videochat_get_call_for_user($pdo, $normalizedCallId, $authUserId, $authRole);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($callFetch['reason'] ?? 'forbidden'),
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : null;
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $expiresAt = is_string($call['ends_at'] ?? null) ? trim((string) $call['ends_at']) : '';
    if ($expiresAt === '') {
        $expiresAt = null;
    }

    $callAccessMode = videochat_normalize_call_access_mode((string) ($call['access_mode'] ?? 'invite_only'));
    $linkKindInput = strtolower(trim((string) ($options['link_kind'] ?? '')));
    if ($linkKindInput === '') {
        $linkKind = $callAccessMode === 'free_for_all' ? 'open' : 'personal';
    } elseif (in_array($linkKindInput, ['personal', 'open'], true)) {
        $linkKind = $linkKindInput;
    } else {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'must_be_personal_or_open'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($callAccessMode === 'free_for_all' && $linkKind !== 'open') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'free_for_all_requires_open_link'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($callAccessMode === 'invite_only' && $linkKind !== 'personal') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['link_kind' => 'invite_only_requires_personal_link'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $isOpenLinkRequest = $linkKind === 'open';

    $targetUserId = $isOpenLinkRequest
        ? 0
        : (is_numeric($options['participant_user_id'] ?? null)
            ? (int) $options['participant_user_id']
            : $authUserId);
    if ($targetUserId < 0) {
        $targetUserId = 0;
    }
    $targetEmail = $isOpenLinkRequest ? '' : videochat_normalize_call_access_email(
        is_string($options['participant_email'] ?? null) ? (string) $options['participant_email'] : null
    );

    $ownerUserId = (int) (($call['owner']['user_id'] ?? 0));
    $actsForAnotherTarget = $isOpenLinkRequest || $targetUserId !== $authUserId || ($targetUserId <= 0 && $targetEmail !== '');
    if ($actsForAnotherTarget && !videochat_can_edit_call($authRole, $authUserId, $ownerUserId)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['call_id' => 'not_allowed_for_call'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $participantEmail = null;
    if ($isOpenLinkRequest) {
        $participantEmail = null;
    } elseif ($targetUserId > 0) {
        $targetUser = videochat_fetch_active_user_for_call_access($pdo, $targetUserId, null);
        if (!is_array($targetUser)) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'errors' => ['participant_user_id' => 'user_not_found_or_inactive'],
                'access_link' => null,
                'call' => null,
            ];
        }
        $participantEmail = videochat_normalize_call_access_email((string) ($targetUser['email'] ?? ''));
    } else {
        if ($targetEmail !== '') {
            $participantEmail = $targetEmail;
        } else {
            $authUser = videochat_fetch_active_user_for_call_access($pdo, $authUserId, null);
            $participantEmail = is_array($authUser)
                ? videochat_normalize_call_access_email((string) ($authUser['email'] ?? ''))
                : '';
        }

        if ($participantEmail === '') {
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ['participant_email' => 'required_valid_email'],
                'access_link' => null,
                'call' => null,
            ];
        }
    }

    try {
        $pdo->beginTransaction();

        $existing = false;
        if ($isOpenLinkRequest) {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id IS NULL
  AND (participant_email IS NULL OR trim(participant_email) = '')
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
            ]);
            $existing = $existingQuery->fetch();
        } elseif ($targetUserId > 0) {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id = :participant_user_id
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
                ':participant_user_id' => $targetUserId,
            ]);
            $existing = $existingQuery->fetch();
        } else {
            $existingQuery = $pdo->prepare(
                <<<'SQL'
SELECT id
FROM call_access_links
WHERE call_id = :call_id
  AND participant_user_id IS NULL
  AND lower(participant_email) = lower(:participant_email)
LIMIT 1
SQL
            );
            $existingQuery->execute([
                ':call_id' => $normalizedCallId,
                ':participant_email' => $participantEmail,
            ]);
            $existing = $existingQuery->fetch();
        }

        $accessId = '';
        if (is_array($existing) && is_string($existing['id'] ?? null)) {
            $accessId = strtolower(trim((string) $existing['id']));
        } else {
            $accessId = videochat_generate_call_access_uuid();
            $insert = $pdo->prepare(
                <<<'SQL'
INSERT INTO call_access_links(
    id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at
) VALUES(
    :id,
    :call_id,
    :participant_user_id,
    :participant_email,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL
)
SQL
            );
            $insert->execute([
                ':id' => $accessId,
                ':call_id' => $normalizedCallId,
                ':participant_user_id' => $targetUserId > 0 ? $targetUserId : null,
                ':participant_email' => $participantEmail,
                ':created_by_user_id' => $authUserId,
                ':created_at' => gmdate('c'),
                ':expires_at' => $expiresAt,
            ]);
        }

        $touch = $pdo->prepare(
            'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
        );
        $touch->execute([
            ':id' => $accessId,
            ':last_used_at' => gmdate('c'),
        ]);

        $accessLink = videochat_fetch_call_access_link($pdo, $accessId);
        if (!is_array($accessLink)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'access_link' => null,
                'call' => null,
            ];
        }

        $pdo->commit();

        return [
            'ok' => true,
            'reason' => 'ready',
            'errors' => [],
            'access_link' => $accessLink,
            'call' => $call,
        ];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   access_link: ?array<string, mixed>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_resolve_call_access_for_user(
    PDO $pdo,
    string $accessId,
    int $authUserId,
    string $authRole
): array {
    $normalizedAccessId = videochat_normalize_call_access_id($accessId);
    if ($normalizedAccessId === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['access_id' => 'invalid_access_id'],
            'access_link' => null,
            'call' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $accessLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($accessLink)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['access_id' => 'not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $expiresAt = is_string($accessLink['expires_at'] ?? null) ? (string) $accessLink['expires_at'] : '';
    if ($expiresAt !== '') {
        $expiresAtUnix = strtotime($expiresAt);
        if (!is_int($expiresAtUnix) || $expiresAtUnix <= time()) {
            return [
                'ok' => false,
                'reason' => 'expired',
                'errors' => ['access_id' => 'expired'],
                'access_link' => null,
                'call' => null,
            ];
        }
    }

    $linkedUserId = is_numeric($accessLink['participant_user_id'] ?? null)
        ? (int) $accessLink['participant_user_id']
        : 0;
    if ($linkedUserId > 0 && $linkedUserId !== $authUserId && videochat_normalize_role_slug($authRole) !== 'admin') {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['access_id' => 'not_bound_to_current_user'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $callId = trim((string) ($accessLink['call_id'] ?? ''));
    $callFetch = videochat_get_call_for_user($pdo, $callId, $authUserId, $authRole);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($callFetch['reason'] ?? 'forbidden'),
            'errors' => [],
            'access_link' => null,
            'call' => null,
        ];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : null;
    if (!is_array($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['call_id' => 'call_not_found'],
            'access_link' => null,
            'call' => null,
        ];
    }

    $touch = $pdo->prepare(
        'UPDATE call_access_links SET last_used_at = :last_used_at WHERE id = :id'
    );
    $touch->execute([
        ':id' => $normalizedAccessId,
        ':last_used_at' => gmdate('c'),
    ]);

    $freshLink = videochat_fetch_call_access_link($pdo, $normalizedAccessId);
    if (!is_array($freshLink)) {
        $freshLink = $accessLink;
    }

    return [
        'ok' => true,
        'reason' => 'resolved',
        'errors' => [],
        'access_link' => $freshLink,
        'call' => $call,
    ];
}
