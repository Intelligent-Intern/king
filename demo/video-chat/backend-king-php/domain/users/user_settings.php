<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/localization.php';
require_once __DIR__ . '/onboarding_progress.php';

/**
 * @return array<int, string>
 */
function videochat_allowed_user_settings_patch_fields(): array
{
    return [
        'display_name',
        'time_format',
        'date_format',
        'theme',
        'avatar_path',
        'post_logout_landing_url',
        'locale',
        'about_me',
        'linkedin_url',
        'x_url',
        'youtube_url',
        'web_app_notifications_enabled',
        'web_app_notification_sound_enabled',
        'web_app_notification_call_invites_enabled',
        'web_app_notification_call_reminders_enabled',
        'web_app_notification_chat_mentions_enabled',
    ];
}

function videochat_normalize_user_settings_bool(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        if ((int) $value === 0) {
            return false;
        }
        if ((int) $value === 1) {
            return true;
        }
        return null;
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return null;
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

function videochat_clean_profile_text(mixed $value, int $maxLength): array
{
    if ($value === null) {
        return ['ok' => true, 'value' => '', 'reason' => 'empty'];
    }
    if (!is_string($value)) {
        return ['ok' => false, 'value' => '', 'reason' => 'must_be_string_or_null'];
    }

    $text = trim($value);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
        return ['ok' => false, 'value' => '', 'reason' => 'must_not_contain_control_chars'];
    }
    if (strlen($text) > $maxLength) {
        return ['ok' => false, 'value' => '', 'reason' => 'too_long'];
    }

    return ['ok' => true, 'value' => $text, 'reason' => 'ok'];
}

/**
 * @param array<int, string> $allowedHosts
 */
function videochat_validate_profile_url(mixed $value, array $allowedHosts): array
{
    if ($value === null) {
        return ['ok' => true, 'url' => '', 'reason' => 'empty'];
    }
    if (!is_string($value)) {
        return ['ok' => false, 'url' => '', 'reason' => 'must_be_string_or_null'];
    }

    $url = trim($value);
    if ($url === '') {
        return ['ok' => true, 'url' => '', 'reason' => 'empty'];
    }
    if (strlen($url) > 2048) {
        return ['ok' => false, 'url' => '', 'reason' => 'too_long'];
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
        return ['ok' => false, 'url' => '', 'reason' => 'must_be_safe_url'];
    }

    $parts = parse_url($url);
    $scheme = is_array($parts) && is_string($parts['scheme'] ?? null) ? strtolower((string) $parts['scheme']) : '';
    $host = is_array($parts) && is_string($parts['host'] ?? null) ? strtolower((string) $parts['host']) : '';
    if ($scheme !== 'https' || $host === '') {
        return ['ok' => false, 'url' => '', 'reason' => 'must_be_https_url'];
    }

    foreach ($allowedHosts as $allowedHost) {
        $normalizedAllowedHost = strtolower(trim($allowedHost));
        if ($normalizedAllowedHost === '') {
            continue;
        }
        if ($host === $normalizedAllowedHost || str_ends_with($host, '.' . $normalizedAllowedHost)) {
            return ['ok' => true, 'url' => $url, 'reason' => 'ok'];
        }
    }

    return ['ok' => false, 'url' => '', 'reason' => 'host_not_allowed'];
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
 *   post_logout_landing_url: string,
 *   about_me: string,
 *   linkedin_url: string,
 *   x_url: string,
 *   youtube_url: string,
 *   web_app_notifications_enabled: bool,
 *   web_app_notification_sound_enabled: bool,
 *   web_app_notification_call_invites_enabled: bool,
 *   web_app_notification_call_reminders_enabled: bool,
 *   web_app_notification_chat_mentions_enabled: bool,
 *   onboarding_completed_tours: array<int, string>,
 * }|null
 */
function videochat_fetch_user_settings(PDO $pdo, int $userId, int $tenantId = 0): ?array
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
    users.about_me,
    users.linkedin_url,
    users.x_url,
    users.youtube_url,
    users.web_app_notifications_enabled,
    users.web_app_notification_sound_enabled,
    users.web_app_notification_call_invites_enabled,
    users.web_app_notification_call_reminders_enabled,
    users.web_app_notification_chat_mentions_enabled,
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
    $onboarding = videochat_fetch_onboarding_progress($pdo, (int) ($row['id'] ?? 0), $tenantId);

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
        'about_me' => is_string($row['about_me'] ?? null) ? (string) $row['about_me'] : '',
        'linkedin_url' => is_string($row['linkedin_url'] ?? null) ? (string) $row['linkedin_url'] : '',
        'x_url' => is_string($row['x_url'] ?? null) ? (string) $row['x_url'] : '',
        'youtube_url' => is_string($row['youtube_url'] ?? null) ? (string) $row['youtube_url'] : '',
        'web_app_notifications_enabled' => ((int) ($row['web_app_notifications_enabled'] ?? 0)) === 1,
        'web_app_notification_sound_enabled' => ((int) ($row['web_app_notification_sound_enabled'] ?? 1)) === 1,
        'web_app_notification_call_invites_enabled' => ((int) ($row['web_app_notification_call_invites_enabled'] ?? 1)) === 1,
        'web_app_notification_call_reminders_enabled' => ((int) ($row['web_app_notification_call_reminders_enabled'] ?? 1)) === 1,
        'web_app_notification_chat_mentions_enabled' => ((int) ($row['web_app_notification_chat_mentions_enabled'] ?? 1)) === 1,
        'onboarding_completed_tours' => $onboarding['completed_tours'],
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

    if (array_key_exists('about_me', $payload)) {
        $about = videochat_clean_profile_text($payload['about_me'], 2000);
        if (!(bool) ($about['ok'] ?? false)) {
            $errors['about_me'] = (string) ($about['reason'] ?? 'invalid');
        } else {
            $data['about_me'] = (string) ($about['value'] ?? '');
        }
    }

    if (array_key_exists('linkedin_url', $payload)) {
        $linkedin = videochat_validate_profile_url($payload['linkedin_url'], ['linkedin.com']);
        if (!(bool) ($linkedin['ok'] ?? false)) {
            $errors['linkedin_url'] = (string) ($linkedin['reason'] ?? 'invalid');
        } else {
            $data['linkedin_url'] = (string) ($linkedin['url'] ?? '');
        }
    }

    if (array_key_exists('x_url', $payload)) {
        $xUrl = videochat_validate_profile_url($payload['x_url'], ['x.com', 'twitter.com']);
        if (!(bool) ($xUrl['ok'] ?? false)) {
            $errors['x_url'] = (string) ($xUrl['reason'] ?? 'invalid');
        } else {
            $data['x_url'] = (string) ($xUrl['url'] ?? '');
        }
    }

    if (array_key_exists('youtube_url', $payload)) {
        $youtube = videochat_validate_profile_url($payload['youtube_url'], ['youtube.com', 'youtu.be']);
        if (!(bool) ($youtube['ok'] ?? false)) {
            $errors['youtube_url'] = (string) ($youtube['reason'] ?? 'invalid');
        } else {
            $data['youtube_url'] = (string) ($youtube['url'] ?? '');
        }
    }

    foreach ([
        'web_app_notifications_enabled',
        'web_app_notification_sound_enabled',
        'web_app_notification_call_invites_enabled',
        'web_app_notification_call_reminders_enabled',
        'web_app_notification_chat_mentions_enabled',
    ] as $booleanField) {
        if (!array_key_exists($booleanField, $payload)) {
            continue;
        }
        $normalizedBool = videochat_normalize_user_settings_bool($payload[$booleanField]);
        if ($normalizedBool === null) {
            $errors[$booleanField] = 'must_be_boolean';
        } else {
            $data[$booleanField] = $normalizedBool;
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
function videochat_update_user_settings(PDO $pdo, int $userId, array $payload, int $tenantId = 0): array
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
    about_me = :about_me,
    linkedin_url = :linkedin_url,
    x_url = :x_url,
    youtube_url = :youtube_url,
    web_app_notifications_enabled = :web_app_notifications_enabled,
    web_app_notification_sound_enabled = :web_app_notification_sound_enabled,
    web_app_notification_call_invites_enabled = :web_app_notification_call_invites_enabled,
    web_app_notification_call_reminders_enabled = :web_app_notification_call_reminders_enabled,
    web_app_notification_chat_mentions_enabled = :web_app_notification_chat_mentions_enabled,
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
        ':about_me' => array_key_exists('about_me', $data) ? (string) $data['about_me'] : (string) ($existing['about_me'] ?? ''),
        ':linkedin_url' => array_key_exists('linkedin_url', $data) ? (string) $data['linkedin_url'] : (string) ($existing['linkedin_url'] ?? ''),
        ':x_url' => array_key_exists('x_url', $data) ? (string) $data['x_url'] : (string) ($existing['x_url'] ?? ''),
        ':youtube_url' => array_key_exists('youtube_url', $data) ? (string) $data['youtube_url'] : (string) ($existing['youtube_url'] ?? ''),
        ':web_app_notifications_enabled' => array_key_exists('web_app_notifications_enabled', $data)
            ? ((bool) $data['web_app_notifications_enabled'] ? 1 : 0)
            : (((bool) ($existing['web_app_notifications_enabled'] ?? false)) ? 1 : 0),
        ':web_app_notification_sound_enabled' => array_key_exists('web_app_notification_sound_enabled', $data)
            ? ((bool) $data['web_app_notification_sound_enabled'] ? 1 : 0)
            : (((bool) ($existing['web_app_notification_sound_enabled'] ?? true)) ? 1 : 0),
        ':web_app_notification_call_invites_enabled' => array_key_exists('web_app_notification_call_invites_enabled', $data)
            ? ((bool) $data['web_app_notification_call_invites_enabled'] ? 1 : 0)
            : (((bool) ($existing['web_app_notification_call_invites_enabled'] ?? true)) ? 1 : 0),
        ':web_app_notification_call_reminders_enabled' => array_key_exists('web_app_notification_call_reminders_enabled', $data)
            ? ((bool) $data['web_app_notification_call_reminders_enabled'] ? 1 : 0)
            : (((bool) ($existing['web_app_notification_call_reminders_enabled'] ?? true)) ? 1 : 0),
        ':web_app_notification_chat_mentions_enabled' => array_key_exists('web_app_notification_chat_mentions_enabled', $data)
            ? ((bool) $data['web_app_notification_chat_mentions_enabled'] ? 1 : 0)
            : (((bool) ($existing['web_app_notification_chat_mentions_enabled'] ?? true)) ? 1 : 0),
        ':updated_at' => gmdate('c'),
        ':id' => $userId,
    ]);

    $updated = videochat_fetch_user_settings($pdo, $userId, $tenantId);
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
 *   revoked_sessions: int
 * }
 */
function videochat_change_user_password(PDO $pdo, int $userId, array $payload, string $currentSessionId = ''): array
{
    if ($userId <= 0) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'revoked_sessions' => 0,
        ];
    }

    $currentPassword = is_string($payload['current_password'] ?? null) ? (string) $payload['current_password'] : '';
    $newPassword = is_string($payload['new_password'] ?? null) ? (string) $payload['new_password'] : '';
    $repeatPassword = is_string($payload['repeat_password'] ?? null) ? (string) $payload['repeat_password'] : '';
    $errors = [];

    if ($currentPassword === '') {
        $errors['current_password'] = 'required_current_password';
    }
    if ($newPassword === '') {
        $errors['new_password'] = 'required_new_password';
    } elseif (strlen($newPassword) < 8) {
        $errors['new_password'] = 'password_too_short';
    } elseif (strlen($newPassword) > 256) {
        $errors['new_password'] = 'password_too_long';
    }
    if ($repeatPassword !== $newPassword) {
        $errors['repeat_password'] = 'must_match_new_password';
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $errors,
            'revoked_sessions' => 0,
        ];
    }

    $query = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id AND status = :status LIMIT 1');
    $query->execute([
        ':id' => $userId,
        ':status' => 'active',
    ]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'revoked_sessions' => 0,
        ];
    }

    $passwordHash = is_string($row['password_hash'] ?? null) ? trim((string) $row['password_hash']) : '';
    if ($passwordHash === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['current_password' => 'password_login_not_available'],
            'revoked_sessions' => 0,
        ];
    }
    if (!password_verify($currentPassword, $passwordHash)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['current_password' => 'current_password_invalid'],
            'revoked_sessions' => 0,
        ];
    }

    $nextHash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($nextHash) || $nextHash === '') {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'revoked_sessions' => 0,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
        $update->execute([
            ':password_hash' => $nextHash,
            ':updated_at' => gmdate('c'),
            ':id' => $userId,
        ]);

        $currentSessionId = trim($currentSessionId);
        if ($currentSessionId !== '') {
            $revoke = $pdo->prepare(
                'UPDATE sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND id <> :session_id AND (revoked_at IS NULL OR revoked_at = \'\')'
            );
            $revoke->execute([
                ':revoked_at' => gmdate('c'),
                ':user_id' => $userId,
                ':session_id' => $currentSessionId,
            ]);
        } else {
            $revoke = $pdo->prepare(
                'UPDATE sessions SET revoked_at = :revoked_at WHERE user_id = :user_id AND (revoked_at IS NULL OR revoked_at = \'\')'
            );
            $revoke->execute([
                ':revoked_at' => gmdate('c'),
                ':user_id' => $userId,
            ]);
        }
        $revokedSessions = $revoke->rowCount();

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'reason' => 'changed',
            'errors' => [],
            'revoked_sessions' => $revokedSessions,
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'revoked_sessions' => 0,
        ];
    }
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
        'about_me' => (string) ($userSettings['about_me'] ?? ''),
        'linkedin_url' => (string) ($userSettings['linkedin_url'] ?? ''),
        'x_url' => (string) ($userSettings['x_url'] ?? ''),
        'youtube_url' => (string) ($userSettings['youtube_url'] ?? ''),
        'web_app_notifications_enabled' => (bool) ($userSettings['web_app_notifications_enabled'] ?? false),
        'web_app_notification_sound_enabled' => (bool) ($userSettings['web_app_notification_sound_enabled'] ?? true),
        'web_app_notification_call_invites_enabled' => (bool) ($userSettings['web_app_notification_call_invites_enabled'] ?? true),
        'web_app_notification_call_reminders_enabled' => (bool) ($userSettings['web_app_notification_call_reminders_enabled'] ?? true),
        'web_app_notification_chat_mentions_enabled' => (bool) ($userSettings['web_app_notification_chat_mentions_enabled'] ?? true),
        'onboarding_completed_tours' => is_array($userSettings['onboarding_completed_tours'] ?? null)
            ? $userSettings['onboarding_completed_tours']
            : [],
    ];
}
