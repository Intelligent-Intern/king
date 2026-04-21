<?php

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function videochat_allowed_user_settings_patch_fields(): array
{
    return ['display_name', 'time_format', 'date_format', 'theme', 'avatar_path'];
}

/**
 * @return array<int, string>
 */
function videochat_supported_user_date_formats(): array
{
    return [
        'dmy_dot',
        'dmy_slash',
        'dmy_dash',
        'ymd_dash',
        'ymd_slash',
        'ymd_dot',
        'ymd_compact',
        'mdy_slash',
        'mdy_dash',
        'mdy_dot',
    ];
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
function videochat_fetch_user_settings(PDO $pdo, int $userId): ?array
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
    users.date_format,
    users.theme,
    users.avatar_path,
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
        'date_format' => (string) ($row['date_format'] ?? 'dmy_dot'),
        'theme' => (string) ($row['theme'] ?? 'dark'),
        'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array<string, mixed>,
 *   errors: array<string, string>
 * }
 */
function videochat_validate_user_settings_patch(array $payload): array
{
    $errors = [];
    $data = [];
    $allowedPatchFields = videochat_allowed_user_settings_patch_fields();

    foreach ($payload as $field => $_value) {
        $fieldName = is_string($field) ? trim($field) : (string) $field;
        if ($fieldName === '' || !in_array($fieldName, $allowedPatchFields, true)) {
            $errors[$fieldName === '' ? 'payload' : $fieldName] = 'field_not_updatable';
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

    if (array_key_exists('time_format', $payload)) {
        $timeFormat = strtolower(trim((string) $payload['time_format']));
        if (!in_array($timeFormat, ['24h', '12h'], true)) {
            $errors['time_format'] = 'must_be_24h_or_12h';
        } else {
            $data['time_format'] = $timeFormat;
        }
    }

    if (array_key_exists('date_format', $payload)) {
        $dateFormat = strtolower(trim((string) $payload['date_format']));
        if (!in_array($dateFormat, videochat_supported_user_date_formats(), true)) {
            $errors['date_format'] = 'must_be_supported_date_format';
        } else {
            $data['date_format'] = $dateFormat;
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
function videochat_update_user_settings(PDO $pdo, int $userId, array $payload): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $existing = videochat_fetch_user_settings($pdo, $userId);
    if ($existing === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'user' => null,
        ];
    }

    $validation = videochat_validate_user_settings_patch($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'user' => null,
        ];
    }

    $data = $validation['data'];
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET display_name = :display_name,
    time_format = :time_format,
    date_format = :date_format,
    theme = :theme,
    avatar_path = :avatar_path,
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $update->execute([
        ':display_name' => array_key_exists('display_name', $data) ? (string) $data['display_name'] : (string) $existing['display_name'],
        ':time_format' => array_key_exists('time_format', $data) ? (string) $data['time_format'] : (string) $existing['time_format'],
        ':date_format' => array_key_exists('date_format', $data) ? (string) $data['date_format'] : (string) $existing['date_format'],
        ':theme' => array_key_exists('theme', $data) ? (string) $data['theme'] : (string) $existing['theme'],
        ':avatar_path' => array_key_exists('avatar_path', $data) ? $data['avatar_path'] : $existing['avatar_path'],
        ':updated_at' => gmdate('c'),
        ':id' => $userId,
    ]);

    $updated = videochat_fetch_user_settings($pdo, $userId);
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
