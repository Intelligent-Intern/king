<?php

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function videochat_admin_allowed_roles(): array
{
    return ['admin', 'user'];
}

/**
 * @return array<int, string>
 */
function videochat_admin_allowed_update_fields(): array
{
    return [
        'email',
        'display_name',
        'role',
        'password',
        'status',
        'time_format',
        'theme',
        'avatar_path',
    ];
}

/**
 * @return array<string, int>
 */
function videochat_admin_role_id_map(PDO $pdo): array
{
    $map = [];
    $rows = $pdo->query('SELECT id, slug FROM roles')->fetchAll();
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $map[$slug] = (int) ($row['id'] ?? 0);
    }

    return $map;
}

/**
 * @return array{
 *   id: int,
 *   email: string,
 *   display_name: string,
 *   role: string,
 *   status: string,
 *   time_format: string,
 *   theme: string,
 *   avatar_path: ?string,
 *   created_at: string,
 *   updated_at: string
 * }|null
 */
function videochat_admin_fetch_user_by_id(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.time_format,
    users.theme,
    users.avatar_path,
    users.created_at,
    users.updated_at,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :id
LIMIT 1
SQL
    );
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => (string) ($row['email'] ?? ''),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'role' => (string) ($row['role_slug'] ?? 'user'),
        'status' => (string) ($row['status'] ?? 'disabled'),
        'time_format' => (string) ($row['time_format'] ?? '24h'),
        'theme' => (string) ($row['theme'] ?? 'dark'),
        'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     email: string,
 *     display_name: string,
 *     role: string,
 *     password: string,
 *     status: string,
 *     time_format: string,
 *     theme: string,
 *     avatar_path: ?string
 *   },
 *   errors: array<string, string>
 * }
 */
function videochat_admin_validate_create_user_payload(array $payload): array
{
    $errors = [];

    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'required_valid_email';
    } elseif (strlen($email) > 320) {
        $errors['email'] = 'email_too_long';
    }

    $displayName = trim((string) ($payload['display_name'] ?? ''));
    if ($displayName === '') {
        $errors['display_name'] = 'required_display_name';
    } elseif (strlen($displayName) > 120) {
        $errors['display_name'] = 'display_name_too_long';
    }

    $role = 'user';
    if (array_key_exists('role', $payload)) {
        $candidateRole = strtolower(trim((string) $payload['role']));
        if ($candidateRole === '' || !in_array($candidateRole, videochat_admin_allowed_roles(), true)) {
            $errors['role'] = 'required_valid_role';
        } else {
            $role = $candidateRole;
        }
    }

    $password = (string) ($payload['password'] ?? '');
    if (trim($password) === '') {
        $errors['password'] = 'required_password';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'password_too_short';
    } elseif (strlen($password) > 256) {
        $errors['password'] = 'password_too_long';
    }

    if (array_key_exists('password_repeat', $payload)) {
        $passwordRepeat = (string) ($payload['password_repeat'] ?? '');
        if ($passwordRepeat !== $password) {
            $errors['password_repeat'] = 'must_match_password';
        }
    }

    $status = strtolower(trim((string) ($payload['status'] ?? 'active')));
    if (!in_array($status, ['active', 'disabled'], true)) {
        $errors['status'] = 'must_be_active_or_disabled';
    }

    $timeFormat = strtolower(trim((string) ($payload['time_format'] ?? '24h')));
    if (!in_array($timeFormat, ['24h', '12h'], true)) {
        $errors['time_format'] = 'must_be_24h_or_12h';
    }

    $theme = trim((string) ($payload['theme'] ?? 'dark'));
    if ($theme === '') {
        $errors['theme'] = 'required_theme';
    } elseif (strlen($theme) > 64) {
        $errors['theme'] = 'theme_too_long';
    }

    $avatarPath = null;
    if (array_key_exists('avatar_path', $payload)) {
        $avatarRaw = $payload['avatar_path'];
        if ($avatarRaw !== null && !is_string($avatarRaw)) {
            $errors['avatar_path'] = 'must_be_string_or_null';
        } else {
            $trimmedAvatar = trim((string) ($avatarRaw ?? ''));
            if ($trimmedAvatar !== '' && strlen($trimmedAvatar) > 512) {
                $errors['avatar_path'] = 'avatar_path_too_long';
            } else {
                $avatarPath = $trimmedAvatar === '' ? null : $trimmedAvatar;
            }
        }
    }

    return [
        'ok' => $errors === [],
        'data' => [
            'email' => $email,
            'display_name' => $displayName,
            'role' => $role,
            'password' => $password,
            'status' => $status,
            'time_format' => $timeFormat,
            'theme' => $theme,
            'avatar_path' => $avatarPath,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array<string, mixed>,
 *   errors: array<string, string>
 * }
 */
function videochat_admin_validate_update_user_payload(array $payload): array
{
    $errors = [];
    $data = [];
    $allowedUpdateFields = videochat_admin_allowed_update_fields();

    foreach ($payload as $field => $_value) {
        $fieldName = is_string($field) ? trim($field) : (string) $field;
        if ($fieldName === '' || !in_array($fieldName, $allowedUpdateFields, true)) {
            $errors[$fieldName === '' ? 'payload' : $fieldName] = 'field_not_updatable';
        }
    }

    if (array_key_exists('email', $payload)) {
        $email = strtolower(trim((string) $payload['email']));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'required_valid_email';
        } elseif (strlen($email) > 320) {
            $errors['email'] = 'email_too_long';
        } else {
            $data['email'] = $email;
        }
    }

    if (array_key_exists('display_name', $payload)) {
        $displayName = trim((string) $payload['display_name']);
        if ($displayName === '') {
            $errors['display_name'] = 'required_display_name';
        } elseif (strlen($displayName) > 120) {
            $errors['display_name'] = 'display_name_too_long';
        } else {
            $data['display_name'] = $displayName;
        }
    }

    if (array_key_exists('role', $payload)) {
        $role = strtolower(trim((string) $payload['role']));
        if (!in_array($role, videochat_admin_allowed_roles(), true)) {
            $errors['role'] = 'required_valid_role';
        } else {
            $data['role'] = $role;
        }
    }

    if (array_key_exists('password', $payload)) {
        $password = (string) $payload['password'];
        if (trim($password) === '') {
            $errors['password'] = 'required_password';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'password_too_short';
        } elseif (strlen($password) > 256) {
            $errors['password'] = 'password_too_long';
        } else {
            $data['password'] = $password;
        }
    }

    if (array_key_exists('status', $payload)) {
        $status = strtolower(trim((string) $payload['status']));
        if (!in_array($status, ['active', 'disabled'], true)) {
            $errors['status'] = 'must_be_active_or_disabled';
        } else {
            $data['status'] = $status;
        }
    }

    if (array_key_exists('time_format', $payload)) {
        $timeFormat = strtolower(trim((string) $payload['time_format']));
        if (!in_array($timeFormat, ['24h', '12h'], true)) {
            $errors['time_format'] = 'must_be_24h_or_12h';
        } else {
            $data['time_format'] = $timeFormat;
        }
    }

    if (array_key_exists('theme', $payload)) {
        $theme = trim((string) $payload['theme']);
        if ($theme === '') {
            $errors['theme'] = 'required_theme';
        } elseif (strlen($theme) > 64) {
            $errors['theme'] = 'theme_too_long';
        } else {
            $data['theme'] = $theme;
        }
    }

    if (array_key_exists('avatar_path', $payload)) {
        $avatarRaw = $payload['avatar_path'];
        if ($avatarRaw !== null && !is_string($avatarRaw)) {
            $errors['avatar_path'] = 'must_be_string_or_null';
        } else {
            $trimmedAvatar = trim((string) ($avatarRaw ?? ''));
            if ($trimmedAvatar !== '' && strlen($trimmedAvatar) > 512) {
                $errors['avatar_path'] = 'avatar_path_too_long';
            } else {
                $data['avatar_path'] = $trimmedAvatar === '' ? null : $trimmedAvatar;
            }
        }
    }

    if ($data === []) {
        $errors['payload'] = 'at_least_one_supported_field_required';
    }

    return [
        'ok' => $errors === [],
        'data' => $data,
        'errors' => $errors,
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
function videochat_admin_create_user(PDO $pdo, array $payload): array
{
    $validation = videochat_admin_validate_create_user_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'user' => null,
        ];
    }

    $data = $validation['data'];
    $roleMap = videochat_admin_role_id_map($pdo);
    $roleId = (int) ($roleMap[(string) $data['role']] ?? 0);
    if ($roleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'required_valid_role'],
            'user' => null,
        ];
    }

    $existingQuery = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) LIMIT 1');
    $existingQuery->execute([':email' => (string) $data['email']]);
    if ($existingQuery->fetch() !== false) {
        return [
            'ok' => false,
            'reason' => 'email_conflict',
            'errors' => ['email' => 'already_exists'],
            'user' => null,
        ];
    }

    $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
    if (!is_string($passwordHash) || $passwordHash === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    try {
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, avatar_path, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, :status, :time_format, :theme, :avatar_path, :updated_at)
SQL
        );
        $insert->execute([
            ':email' => (string) $data['email'],
            ':display_name' => (string) $data['display_name'],
            ':password_hash' => $passwordHash,
            ':role_id' => $roleId,
            ':status' => (string) $data['status'],
            ':time_format' => (string) $data['time_format'],
            ':theme' => (string) $data['theme'],
            ':avatar_path' => $data['avatar_path'],
            ':updated_at' => gmdate('c'),
        ]);
        $createdUserId = (int) $pdo->lastInsertId();
    } catch (PDOException $error) {
        $message = strtolower($error->getMessage());
        if (str_contains($message, 'unique') && str_contains($message, 'users.email')) {
            return [
                'ok' => false,
                'reason' => 'email_conflict',
                'errors' => ['email' => 'already_exists'],
                'user' => null,
            ];
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    $created = videochat_admin_fetch_user_by_id($pdo, $createdUserId);
    if ($created === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'created',
        'errors' => [],
        'user' => $created,
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
function videochat_admin_update_user(PDO $pdo, int $userId, array $payload): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $validation = videochat_admin_validate_update_user_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'user' => null,
        ];
    }

    $data = $validation['data'];
    $nextEmail = array_key_exists('email', $data) ? (string) $data['email'] : (string) $existing['email'];
    if (strtolower($nextEmail) !== strtolower((string) $existing['email'])) {
        $emailQuery = $pdo->prepare('SELECT id FROM users WHERE lower(email) = lower(:email) AND id <> :id LIMIT 1');
        $emailQuery->execute([
            ':email' => $nextEmail,
            ':id' => $userId,
        ]);
        if ($emailQuery->fetch() !== false) {
            return [
                'ok' => false,
                'reason' => 'email_conflict',
                'errors' => ['email' => 'already_exists'],
                'user' => null,
            ];
        }
    }

    $roleMap = videochat_admin_role_id_map($pdo);
    $nextRole = array_key_exists('role', $data) ? (string) $data['role'] : (string) $existing['role'];
    $nextRoleId = (int) ($roleMap[$nextRole] ?? 0);
    if ($nextRoleId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'required_valid_role'],
            'user' => null,
        ];
    }

    $passwordHash = null;
    if (array_key_exists('password', $data)) {
        $passwordHash = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        if (!is_string($passwordHash) || $passwordHash === '') {
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'user' => null,
            ];
        }
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET email = :email,
    display_name = :display_name,
    role_id = :role_id,
    status = :status,
    time_format = :time_format,
    theme = :theme,
    avatar_path = :avatar_path,
    password_hash = COALESCE(:password_hash, password_hash),
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $update->execute([
        ':email' => $nextEmail,
        ':display_name' => array_key_exists('display_name', $data) ? (string) $data['display_name'] : (string) $existing['display_name'],
        ':role_id' => $nextRoleId,
        ':status' => array_key_exists('status', $data) ? (string) $data['status'] : (string) $existing['status'],
        ':time_format' => array_key_exists('time_format', $data) ? (string) $data['time_format'] : (string) $existing['time_format'],
        ':theme' => array_key_exists('theme', $data) ? (string) $data['theme'] : (string) $existing['theme'],
        ':avatar_path' => array_key_exists('avatar_path', $data) ? $data['avatar_path'] : $existing['avatar_path'],
        ':password_hash' => $passwordHash,
        ':updated_at' => gmdate('c'),
        ':id' => $userId,
    ]);

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'updated',
        'errors' => [],
        'user' => $updated,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>,
 *   revoked_sessions: int
 * }
 */
function videochat_admin_deactivate_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    $revokedSessions = 0;
    if ((string) $existing['status'] !== 'disabled') {
        $disable = $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $disable->execute([
            ':status' => 'disabled',
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);

        $revokeSessions = $pdo->prepare(
            'UPDATE sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND (revoked_at IS NULL OR revoked_at = \'\')'
        );
        $revokeSessions->execute([
            ':revoked_at' => gmdate('c'),
            ':user_id' => $userId,
        ]);
        $revokedSessions = $revokeSessions->rowCount();
    }

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'revoked_sessions' => 0,
        ];
    }

    return [
        'ok' => true,
        'reason' => (string) $existing['status'] === 'disabled' ? 'already_disabled' : 'deactivated',
        'errors' => [],
        'user' => $updated,
        'revoked_sessions' => $revokedSessions,
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
function videochat_admin_reactivate_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    if ((string) $existing['status'] !== 'active') {
        $reactivate = $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id');
        $reactivate->execute([
            ':status' => 'active',
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);
    }

    $updated = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($updated === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => (string) $existing['status'] === 'active' ? 'already_active' : 'reactivated',
        'errors' => [],
        'user' => $updated,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   user: ?array<string, mixed>,
 *   deleted_calls: int,
 *   deleted_invite_codes: int
 * }
 */
function videochat_admin_delete_user(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    $existing = videochat_admin_fetch_user_by_id($pdo, $userId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    $deletedCalls = 0;
    $deletedInviteCodes = 0;
    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $deleteCalls = $pdo->prepare('DELETE FROM calls WHERE owner_user_id = :owner_user_id');
        $deleteCalls->execute([
            ':owner_user_id' => $userId,
        ]);
        $deletedCalls = $deleteCalls->rowCount();

        $deleteInviteCodes = $pdo->prepare('DELETE FROM invite_codes WHERE issued_by_user_id = :issued_by_user_id');
        $deleteInviteCodes->execute([
            ':issued_by_user_id' => $userId,
        ]);
        $deletedInviteCodes = $deleteInviteCodes->rowCount();

        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $deleteUser->execute([
            ':id' => $userId,
        ]);

        if ($deleteUser->rowCount() !== 1) {
            throw new RuntimeException('delete_user_row_count_mismatch');
        }

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'user' => null,
            'deleted_calls' => 0,
            'deleted_invite_codes' => 0,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'deleted',
        'errors' => [],
        'user' => $existing,
        'deleted_calls' => $deletedCalls,
        'deleted_invite_codes' => $deletedInviteCodes,
    ];
}
