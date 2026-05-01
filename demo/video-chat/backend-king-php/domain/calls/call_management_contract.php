<?php

declare(strict_types=1);

function videochat_generate_call_id(): string
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

function videochat_normalize_call_access_mode(mixed $value, string $fallback = 'invite_only'): string
{
    $fallbackNormalized = strtolower(trim($fallback));
    if (!in_array($fallbackNormalized, ['invite_only', 'free_for_all'], true)) {
        $fallbackNormalized = 'invite_only';
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['invite_only', 'free_for_all'], true)) {
        return $normalized;
    }

    return $fallbackNormalized;
}

function videochat_normalize_call_invite_state(mixed $value, string $fallback = 'invited'): string
{
    $fallbackNormalized = strtolower(trim($fallback));
    if ($fallbackNormalized !== '' && !in_array($fallbackNormalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        $fallbackNormalized = 'invited';
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        return $normalized;
    }

    return $fallbackNormalized;
}

function videochat_normalize_call_schedule_timezone(mixed $value, string $fallback = 'UTC'): string
{
    $fallbackTimezone = trim($fallback);
    if ($fallbackTimezone === '') {
        $fallbackTimezone = 'UTC';
    }

    try {
        new DateTimeZone($fallbackTimezone);
    } catch (Throwable) {
        $fallbackTimezone = 'UTC';
    }

    $timezone = trim((string) $value);
    if ($timezone === '') {
        return $fallbackTimezone;
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable) {
        return $fallbackTimezone;
    }

    return $timezone;
}

/**
 * @return array{ok: bool, timezone: string, error: ?string}
 */
function videochat_validate_call_schedule_timezone(mixed $value): array
{
    $timezone = trim((string) $value);
    if ($timezone === '') {
        return [
            'ok' => false,
            'timezone' => 'UTC',
            'error' => 'required_valid_timezone',
        ];
    }

    if (strlen($timezone) > 80) {
        return [
            'ok' => false,
            'timezone' => 'UTC',
            'error' => 'timezone_too_long',
        ];
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable) {
        return [
            'ok' => false,
            'timezone' => 'UTC',
            'error' => 'required_valid_timezone',
        ];
    }

    return [
        'ok' => true,
        'timezone' => $timezone,
        'error' => null,
    ];
}

/**
 * @return array{ok: bool, all_day: bool, error: ?string}
 */
function videochat_validate_call_schedule_all_day(mixed $value): array
{
    if (is_bool($value)) {
        return ['ok' => true, 'all_day' => $value, 'error' => null];
    }
    if (is_int($value)) {
        if ($value === 0 || $value === 1) {
            return ['ok' => true, 'all_day' => $value === 1, 'error' => null];
        }
        return ['ok' => false, 'all_day' => false, 'error' => 'must_be_boolean'];
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return ['ok' => true, 'all_day' => true, 'error' => null];
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
            return ['ok' => true, 'all_day' => false, 'error' => null];
        }
    }

    return ['ok' => false, 'all_day' => false, 'error' => 'must_be_boolean'];
}

/**
 * @return array{
 *   timezone: string,
 *   date: string,
 *   starts_at_local: string,
 *   ends_at_local: string,
 *   duration_minutes: int,
 *   all_day: bool
 * }
 */
function videochat_build_call_schedule_metadata(
    string $startsAt,
    string $endsAt,
    mixed $timezone = 'UTC',
    mixed $allDay = false
): array {
    $timezoneId = videochat_normalize_call_schedule_timezone($timezone, 'UTC');
    $timezoneObject = new DateTimeZone($timezoneId);
    $startsAtUnix = strtotime($startsAt);
    $endsAtUnix = strtotime($endsAt);
    if (!is_int($startsAtUnix)) {
        $startsAtUnix = 0;
    }
    if (!is_int($endsAtUnix)) {
        $endsAtUnix = $startsAtUnix;
    }

    $startsAtLocal = (new DateTimeImmutable('@' . $startsAtUnix))->setTimezone($timezoneObject);
    $endsAtLocal = (new DateTimeImmutable('@' . $endsAtUnix))->setTimezone($timezoneObject);
    $allDayValidation = videochat_validate_call_schedule_all_day($allDay);
    $durationSeconds = max(0, $endsAtUnix - $startsAtUnix);

    return [
        'timezone' => $timezoneId,
        'date' => $startsAtLocal->format('Y-m-d'),
        'starts_at_local' => $startsAtLocal->format('Y-m-d\TH:i:sP'),
        'ends_at_local' => $endsAtLocal->format('Y-m-d\TH:i:sP'),
        'duration_minutes' => intdiv($durationSeconds, 60),
        'all_day' => (bool) $allDayValidation['all_day'],
    ];
}

/**
 * @return array{
 *   timezone: string,
 *   date: string,
 *   starts_at_local: string,
 *   ends_at_local: string,
 *   duration_minutes: int,
 *   all_day: bool
 * }
 */
function videochat_call_schedule_from_row(array $row): array
{
    $schedule = videochat_build_call_schedule_metadata(
        (string) ($row['starts_at'] ?? ''),
        (string) ($row['ends_at'] ?? ''),
        $row['schedule_timezone'] ?? 'UTC',
        $row['schedule_all_day'] ?? false
    );

    $persistedDate = trim((string) ($row['schedule_date'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $persistedDate) === 1) {
        $schedule['date'] = $persistedDate;
    }

    $persistedDuration = filter_var($row['schedule_duration_minutes'] ?? null, FILTER_VALIDATE_INT);
    if (is_int($persistedDuration) && $persistedDuration > 0) {
        $schedule['duration_minutes'] = $persistedDuration;
    }

    return $schedule;
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     room_id: string,
 *     title: string,
 *     access_mode: string,
 *     starts_at: string,
 *     ends_at: string,
 *     schedule: array{
 *       timezone: string,
 *       date: string,
 *       starts_at_local: string,
 *       ends_at_local: string,
 *       duration_minutes: int,
 *       all_day: bool
 *     },
 *     internal_participant_user_ids: array<int, int>,
 *     external_participants: array<int, array{email: string, display_name: string}>
 *   },
 *   errors: array<string, string>
 * }
 */
function videochat_validate_create_call_payload(array $payload): array
{
    $errors = [];

    $accessModeInput = strtolower(trim((string) ($payload['access_mode'] ?? 'invite_only')));
    $accessMode = videochat_normalize_call_access_mode($accessModeInput, 'invite_only');
    if (!in_array($accessModeInput, ['invite_only', 'free_for_all'], true)) {
        $errors['access_mode'] = 'must_be_invite_only_or_free_for_all';
    }

    $roomId = trim((string) ($payload['room_id'] ?? 'lobby'));
    if ($roomId === '') {
        $errors['room_id'] = 'required_room_id';
    } elseif (strlen($roomId) > 120) {
        $errors['room_id'] = 'room_id_too_long';
    }

    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $errors['title'] = 'required_title';
    } elseif (strlen($title) > 200) {
        $errors['title'] = 'title_too_long';
    }

    $startsAtRaw = trim((string) ($payload['starts_at'] ?? ''));
    $endsAtRaw = trim((string) ($payload['ends_at'] ?? ''));
    $startsAtUnix = strtotime($startsAtRaw);
    $endsAtUnix = strtotime($endsAtRaw);
    if ($startsAtRaw === '' || !is_int($startsAtUnix)) {
        $errors['starts_at'] = 'required_valid_datetime';
    }
    if ($endsAtRaw === '' || !is_int($endsAtUnix)) {
        $errors['ends_at'] = 'required_valid_datetime';
    }
    if (!isset($errors['starts_at']) && !isset($errors['ends_at']) && $endsAtUnix <= $startsAtUnix) {
        $errors['ends_at'] = 'must_be_after_starts_at';
    }

    $timezone = 'UTC';
    if (array_key_exists('schedule_timezone', $payload) || array_key_exists('timezone', $payload)) {
        $timezoneField = array_key_exists('schedule_timezone', $payload) ? 'schedule_timezone' : 'timezone';
        $timezoneValidation = videochat_validate_call_schedule_timezone($payload[$timezoneField] ?? '');
        if (!(bool) $timezoneValidation['ok']) {
            $errors[$timezoneField] = (string) $timezoneValidation['error'];
        }
        $timezone = (string) $timezoneValidation['timezone'];
    }

    $scheduleAllDay = false;
    if (array_key_exists('schedule_all_day', $payload)) {
        $allDayValidation = videochat_validate_call_schedule_all_day($payload['schedule_all_day']);
        if (!(bool) $allDayValidation['ok']) {
            $errors['schedule_all_day'] = (string) $allDayValidation['error'];
        }
        $scheduleAllDay = (bool) $allDayValidation['all_day'];
    }

    $internalIdsRaw = $payload['internal_participant_user_ids'] ?? [];
    if (!is_array($internalIdsRaw)) {
        $errors['internal_participant_user_ids'] = 'must_be_array';
        $internalIdsRaw = [];
    }
    $internalIds = [];
    foreach ($internalIdsRaw as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id <= 0) {
            $errors['internal_participant_user_ids'] = 'must_contain_positive_int_ids';
            break;
        }
        $internalIds[$id] = $id;
    }
    $internalIds = array_values($internalIds);

    $externalRaw = $payload['external_participants'] ?? [];
    if (!is_array($externalRaw)) {
        $errors['external_participants'] = 'must_be_array';
        $externalRaw = [];
    }

    $externalParticipants = [];
    $externalEmailMap = [];
    foreach ($externalRaw as $index => $row) {
        if (!is_array($row)) {
            $errors["external_participants.{$index}"] = 'must_be_object';
            continue;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $displayName = trim((string) ($row['display_name'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors["external_participants.{$index}.email"] = 'required_valid_email';
            continue;
        }
        if ($displayName === '') {
            $errors["external_participants.{$index}.display_name"] = 'required_display_name';
            continue;
        }
        if (strlen($displayName) > 120) {
            $errors["external_participants.{$index}.display_name"] = 'display_name_too_long';
            continue;
        }
        if (isset($externalEmailMap[$email])) {
            $errors["external_participants.{$index}.email"] = 'duplicate_email';
            continue;
        }

        $externalEmailMap[$email] = true;
        $externalParticipants[] = [
            'email' => $email,
            'display_name' => $displayName,
        ];
    }

    $startsAt = is_int($startsAtUnix) ? gmdate('c', $startsAtUnix) : '';
    $endsAt = is_int($endsAtUnix) ? gmdate('c', $endsAtUnix) : '';
    $schedule = $startsAt !== '' && $endsAt !== ''
        ? videochat_build_call_schedule_metadata($startsAt, $endsAt, $timezone, $scheduleAllDay)
        : videochat_build_call_schedule_metadata('', '', $timezone, $scheduleAllDay);

    return [
        'ok' => $errors === [],
        'data' => [
            'room_id' => $roomId,
            'title' => $title,
            'access_mode' => $accessMode,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'schedule' => $schedule,
            'internal_participant_user_ids' => $internalIds,
            'external_participants' => $externalParticipants,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{id: int, email: string, display_name: string}|null
 */
function videochat_active_user_identity(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, email, display_name
FROM users
WHERE id = :id AND status = 'active'
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
        'email' => strtolower((string) ($row['email'] ?? '')),
        'display_name' => (string) ($row['display_name'] ?? ''),
    ];
}

/**
 * @param array<int, int> $userIds
 * @return array<int, array{id: int, email: string, display_name: string}>
 */
function videochat_active_internal_users(PDO $pdo, array $userIds): array
{
    $result = [];
    foreach ($userIds as $userId) {
        $identity = videochat_active_user_identity($pdo, (int) $userId);
        if ($identity === null) {
            continue;
        }
        $result[$identity['id']] = $identity;
    }

    return $result;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>
 * }
 */
