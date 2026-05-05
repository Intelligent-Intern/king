<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/localization.php';

/**
 * @return array<int, string>
 */
function videochat_allowed_user_settings_patch_fields(): array
{
    return ['display_name', 'time_format', 'date_format', 'theme', 'avatar_path', 'post_logout_landing_url', 'locale'];
}

function videochat_normalize_post_logout_landing_url(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function videochat_validate_post_logout_landing_url(mixed $value): array
{
    $url = videochat_normalize_post_logout_landing_url($value);
    if ($url === '') {
        return ['ok' => true, 'url' => '', 'reason' => 'default'];
    }

    if (strlen($url) > 2048) {
        return ['ok' => false, 'url' => '', 'reason' => 'too_long'];
    }

    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        return ['ok' => false, 'url' => '', 'reason' => 'must_be_same_origin_path'];
    }

    if (str_contains($url, '\\') || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return ['ok' => false, 'url' => '', 'reason' => 'must_be_safe_path'];
    }

    return ['ok' => true, 'url' => $url, 'reason' => 'ok'];
}

function videochat_fetch_user_post_logout_landing_url(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    $statement = $pdo->prepare('SELECT post_logout_landing_url FROM users WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $userId]);
    $value = $statement->fetchColumn();
    return is_string($value) ? videochat_normalize_post_logout_landing_url($value) : '';
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
 *   locale: string,
 *   direction: string,
 *   supported_locales: array<int, array<string, mixed>>,
 *   avatar_path: ?string,
 *   post_logout_landing_url: string
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
    users.locale,
    users.avatar_path,
    users.post_logout_landing_url,
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

    $localization = videochat_localization_payload($pdo, $row['locale'] ?? null);

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => (string) ($row['email'] ?? ''),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'role' => (string) ($row['role_slug'] ?? 'user'),
        'status' => (string) ($row['status'] ?? 'disabled'),
        'time_format' => (string) ($row['time_format'] ?? '24h'),
        'date_format' => (string) ($row['date_format'] ?? 'dmy_dot'),
        'theme' => (string) ($row['theme'] ?? 'dark'),
        'locale' => (string) ($localization['locale'] ?? 'en'),
        'direction' => (string) ($localization['direction'] ?? 'ltr'),
        'supported_locales' => is_array($localization['supported_locales'] ?? null) ? $localization['supported_locales'] : [],
        'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
        'post_logout_landing_url' => is_string($row['post_logout_landing_url'] ?? null)
            ? videochat_normalize_post_logout_landing_url($row['post_logout_landing_url'])
            : '',
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array<string, mixed>,
 *   errors: array<string, string>
 * }
 */
function videochat_validate_user_settings_patch(array $payload, ?PDO $pdo = null): array
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

    if (array_key_exists('locale', $payload)) {
        $locale = videochat_normalize_locale_code($payload['locale']);
        $isSupported = $locale !== ''
            && ($pdo instanceof PDO
                ? videochat_locale_is_supported($pdo, $locale)
                : videochat_locale_static_definition($locale) !== null);
        if (!$isSupported) {
            $errors['locale'] = 'must_be_supported_locale';
        } else {
            $data['locale'] = $locale;
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

    if (array_key_exists('post_logout_landing_url', $payload)) {
        $landingRaw = $payload['post_logout_landing_url'];
        if ($landingRaw !== null && !is_string($landingRaw)) {
            $errors['post_logout_landing_url'] = 'must_be_string_or_null';
        } else {
            $landingUrl = videochat_validate_post_logout_landing_url($landingRaw);
            if (!(bool) ($landingUrl['ok'] ?? false)) {
                $errors['post_logout_landing_url'] = (string) ($landingUrl['reason'] ?? 'invalid');
            } else {
                $data['post_logout_landing_url'] = (string) ($landingUrl['url'] ?? '');
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

    $validation = videochat_validate_user_settings_patch($payload, $pdo);
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
    locale = :locale,
    avatar_path = :avatar_path,
    post_logout_landing_url = :post_logout_landing_url,
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $update->execute([
        ':display_name' => array_key_exists('display_name', $data) ? (string) $data['display_name'] : (string) $existing['display_name'],
        ':time_format' => array_key_exists('time_format', $data) ? (string) $data['time_format'] : (string) $existing['time_format'],
        ':date_format' => array_key_exists('date_format', $data) ? (string) $data['date_format'] : (string) $existing['date_format'],
        ':theme' => array_key_exists('theme', $data) ? (string) $data['theme'] : (string) $existing['theme'],
        ':locale' => array_key_exists('locale', $data) ? (string) $data['locale'] : (string) ($existing['locale'] ?? 'en'),
        ':avatar_path' => array_key_exists('avatar_path', $data) ? $data['avatar_path'] : $existing['avatar_path'],
        ':post_logout_landing_url' => array_key_exists('post_logout_landing_url', $data)
            ? (string) $data['post_logout_landing_url']
            : (string) ($existing['post_logout_landing_url'] ?? ''),
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

/**
 * @return array<string, mixed>
 */
function videochat_user_settings_payload(array $userSettings): array
{
    return [
        'display_name' => (string) ($userSettings['display_name'] ?? ''),
        'time_format' => (string) ($userSettings['time_format'] ?? '24h'),
        'date_format' => (string) ($userSettings['date_format'] ?? 'dmy_dot'),
        'theme' => (string) ($userSettings['theme'] ?? 'dark'),
        'locale' => (string) ($userSettings['locale'] ?? 'en'),
        'direction' => (string) ($userSettings['direction'] ?? 'ltr'),
        'supported_locales' => is_array($userSettings['supported_locales'] ?? null) ? $userSettings['supported_locales'] : [],
        'avatar_path' => $userSettings['avatar_path'] ?? null,
        'post_logout_landing_url' => (string) ($userSettings['post_logout_landing_url'] ?? ''),
    ];
}
