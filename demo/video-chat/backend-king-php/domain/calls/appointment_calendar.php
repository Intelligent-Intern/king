<?php

declare(strict_types=1);

require_once __DIR__ . '/call_management.php';
require_once __DIR__ . '/call_access.php';
require_once __DIR__ . '/appointment_calendar_settings.php';
require_once __DIR__ . '/appointment_calendar_mail.php';
require_once __DIR__ . '/../workspace/workspace_administration.php';

const VIDEOCHAT_APPOINTMENT_MAX_BLOCK_SECONDS = 24 * 60 * 60;
const VIDEOCHAT_APPOINTMENT_PUBLIC_SLOT_LIMIT = 240;

function videochat_appointment_iso_to_utc(mixed $value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($text);
    } catch (Throwable) {
        return '';
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function videochat_appointment_owner(PDO $pdo, int $ownerUserId, ?int $tenantId = null): ?array
{
    if ($ownerUserId <= 0) {
        return null;
    }

    $tenantJoin = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id')
        ? 'INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id'
        : '';
    $tenantWhere = $tenantJoin !== '' ? 'AND tenant_memberships.tenant_id = :tenant_id AND tenant_memberships.status = \'active\'' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT id, email, display_name
FROM users
{$tenantJoin}
WHERE id = :id
  AND status = 'active'
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $ownerUserId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);
    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => strtolower((string) ($row['email'] ?? '')),
        'display_name' => (string) ($row['display_name'] ?? ''),
    ];
}

function videochat_appointment_block_row(array $row, bool $includeBooked = true): array
{
    $payload = [
        'id' => (string) ($row['id'] ?? ''),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'timezone' => videochat_normalize_call_schedule_timezone($row['timezone'] ?? 'UTC'),
        'status' => (string) ($row['status'] ?? 'open'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
    if ($includeBooked) {
        $payload['booked'] = ((int) ($row['booked_count'] ?? 0)) > 0;
    }

    return $payload;
}

function videochat_appointment_slot_seconds(array $settings): int
{
    return videochat_appointment_normalize_slot_minutes($settings['slot_minutes'] ?? 15) * 60;
}

function videochat_appointment_slot_token(string $blockId, int $startsAtUnix, int $endsAtUnix): string
{
    return strtolower($blockId) . '~' . gmdate('YmdHis', $startsAtUnix) . '~' . gmdate('YmdHis', $endsAtUnix);
}

function videochat_parse_appointment_slot_token(string $slotId): ?array
{
    $normalized = strtolower(trim($slotId));
    if (preg_match('/^([a-f0-9-]{36})~([0-9]{14})~([0-9]{14})$/', $normalized, $match) !== 1) {
        return null;
    }

    $startsAtUnix = strtotime($match[2] . ' UTC');
    $endsAtUnix = strtotime($match[3] . ' UTC');
    if (!is_int($startsAtUnix) || !is_int($endsAtUnix) || $endsAtUnix <= $startsAtUnix) {
        return null;
    }

    return [
        'block_id' => (string) $match[1],
        'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $startsAtUnix),
        'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $endsAtUnix),
        'start_unix' => $startsAtUnix,
        'end_unix' => $endsAtUnix,
    ];
}

function videochat_appointment_ranges_overlap(int $leftStart, int $leftEnd, int $rightStart, int $rightEnd): bool
{
    return $leftStart < $rightEnd && $leftEnd > $rightStart;
}

function videochat_appointment_booked_ranges(PDO $pdo, int $ownerUserId, ?int $tenantId = null): array
{
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_bookings', 'tenant_id')
        ? '  AND appointment_bookings.tenant_id = :tenant_id'
        : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT appointment_blocks.starts_at, appointment_blocks.ends_at
FROM appointment_bookings
INNER JOIN appointment_blocks
  ON appointment_blocks.id = appointment_bookings.block_id
WHERE appointment_bookings.owner_user_id = :owner_user_id
  AND appointment_bookings.status = 'booked'
{$tenantWhere}
SQL
    );
    $params = [':owner_user_id' => $ownerUserId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);

    $ranges = [];
    foreach ($query->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $startUnix = strtotime((string) ($row['starts_at'] ?? ''));
        $endUnix = strtotime((string) ($row['ends_at'] ?? ''));
        if (is_int($startUnix) && is_int($endUnix) && $endUnix > $startUnix) {
            $ranges[] = ['start_unix' => $startUnix, 'end_unix' => $endUnix];
        }
    }

    return $ranges;
}

function videochat_append_appointment_public_slots(array &$slots, array $row, int $slotSeconds, array $bookedRanges, int $blockStartUnix, int $blockEndUnix, int $nowUnix): void
{
    if ($blockEndUnix <= $blockStartUnix) {
        return;
    }
    $slotStartUnix = $blockStartUnix;
    if ($slotStartUnix <= $nowUnix) {
        $slotStartUnix += (int) ceil(($nowUnix - $slotStartUnix + 1) / $slotSeconds) * $slotSeconds;
    }

    for (; $slotStartUnix + $slotSeconds <= $blockEndUnix; $slotStartUnix += $slotSeconds) {
        $slotEndUnix = $slotStartUnix + $slotSeconds;
        foreach ($bookedRanges as $range) {
            if (videochat_appointment_ranges_overlap($slotStartUnix, $slotEndUnix, (int) $range['start_unix'], (int) $range['end_unix'])) {
                continue 2;
            }
        }

        $blockId = (string) ($row['id'] ?? '');
        $slots[] = [
            'id' => videochat_appointment_slot_token($blockId, $slotStartUnix, $slotEndUnix),
            'block_id' => $blockId,
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $slotStartUnix),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $slotEndUnix),
            'timezone' => videochat_normalize_call_schedule_timezone($row['timezone'] ?? 'UTC'),
        ];
        if (count($slots) >= VIDEOCHAT_APPOINTMENT_PUBLIC_SLOT_LIMIT) {
            return;
        }
    }
}

function videochat_append_recurring_appointment_public_slots(array &$slots, array $row, int $slotSeconds, array $bookedRanges, int $nowUnix): void
{
    $timezone = new DateTimeZone(videochat_normalize_call_schedule_timezone($row['timezone'] ?? 'UTC'));
    $sourceStartUnix = strtotime((string) ($row['starts_at'] ?? ''));
    $sourceEndUnix = strtotime((string) ($row['ends_at'] ?? ''));
    if (!is_int($sourceStartUnix) || !is_int($sourceEndUnix) || $sourceEndUnix <= $sourceStartUnix) {
        return;
    }

    $sourceStart = (new DateTimeImmutable('@' . $sourceStartUnix))->setTimezone($timezone);
    $durationSeconds = $sourceEndUnix - $sourceStartUnix;
    $weekdayOffset = ((int) $sourceStart->format('N')) - 1;
    $nowLocal = (new DateTimeImmutable('@' . $nowUnix))->setTimezone($timezone);
    $weekStart = $nowLocal->modify('monday this week')->setTime(0, 0, 0);

    for ($week = 0; $week < 54 && count($slots) < VIDEOCHAT_APPOINTMENT_PUBLIC_SLOT_LIMIT; $week++) {
        $candidateDay = $weekStart->modify('+' . ($week * 7 + $weekdayOffset) . ' days');
        $candidateStart = $candidateDay->setTime(
            (int) $sourceStart->format('H'),
            (int) $sourceStart->format('i'),
            (int) $sourceStart->format('s')
        );
        $blockStartUnix = $candidateStart->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        videochat_append_appointment_public_slots($slots, $row, $slotSeconds, $bookedRanges, $blockStartUnix, $blockStartUnix + $durationSeconds, $nowUnix);
    }
}

function videochat_list_appointment_blocks(PDO $pdo, int $ownerUserId, ?int $tenantId = null): array
{
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
        ? '  AND appointment_blocks.tenant_id = :tenant_id'
        : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    appointment_blocks.*,
    COUNT(appointment_bookings.id) AS booked_count
FROM appointment_blocks
LEFT JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
  AND appointment_blocks.status = 'open'
{$tenantWhere}
GROUP BY appointment_blocks.id
ORDER BY appointment_blocks.starts_at ASC, appointment_blocks.id ASC
SQL
    );
    $params = [':owner_user_id' => $ownerUserId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);

    $blocks = [];
    foreach ($query->fetchAll() as $row) {
        if (is_array($row)) {
            $blocks[] = videochat_appointment_block_row($row);
        }
    }

    return $blocks;
}

function videochat_validate_appointment_blocks_payload(array $payload): array
{
    $rawBlocks = $payload['blocks'] ?? null;
    if (!is_array($rawBlocks)) {
        return ['ok' => false, 'blocks' => [], 'errors' => ['blocks' => 'required_array']];
    }
    if (count($rawBlocks) > 200) {
        return ['ok' => false, 'blocks' => [], 'errors' => ['blocks' => 'too_many_blocks']];
    }

    $blocks = [];
    $errors = [];
    foreach (array_values($rawBlocks) as $index => $block) {
        if (!is_array($block)) {
            $errors["blocks.{$index}"] = 'must_be_object';
            continue;
        }

        $startsAt = videochat_appointment_iso_to_utc($block['starts_at'] ?? '');
        $endsAt = videochat_appointment_iso_to_utc($block['ends_at'] ?? '');
        $startUnix = $startsAt === '' ? false : strtotime($startsAt);
        $endUnix = $endsAt === '' ? false : strtotime($endsAt);
        if (!is_int($startUnix) || !is_int($endUnix) || $endUnix <= $startUnix) {
            $errors["blocks.{$index}.range"] = 'required_valid_future_range';
            continue;
        }
        if (($endUnix - $startUnix) > VIDEOCHAT_APPOINTMENT_MAX_BLOCK_SECONDS) {
            $errors["blocks.{$index}.range"] = 'block_too_long';
            continue;
        }

        $blocks[] = [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => videochat_normalize_call_schedule_timezone($block['timezone'] ?? 'UTC'),
            'start_unix' => $startUnix,
            'end_unix' => $endUnix,
        ];
    }

    usort($blocks, static fn (array $a, array $b): int => $a['start_unix'] <=> $b['start_unix']);
    for ($index = 1; $index < count($blocks); $index++) {
        if ((int) $blocks[$index]['start_unix'] < (int) $blocks[$index - 1]['end_unix']) {
            $errors['blocks'] = 'overlapping_blocks';
            break;
        }
    }

    return [
        'ok' => $errors === [],
        'blocks' => array_map(static function (array $block): array {
            unset($block['start_unix'], $block['end_unix']);
            return $block;
        }, $blocks),
        'errors' => $errors,
    ];
}

function videochat_save_appointment_blocks(PDO $pdo, int $ownerUserId, array $payload, ?int $tenantId = null): array
{
    if (videochat_appointment_owner($pdo, $ownerUserId, $tenantId) === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found'], 'blocks' => []];
    }

    $validation = videochat_validate_appointment_blocks_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors'] ?? [], 'blocks' => []];
    }
    $settingsValidation = videochat_validate_appointment_settings_payload($payload['settings'] ?? null);
    if (!(bool) ($settingsValidation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $settingsValidation['errors'] ?? [], 'blocks' => []];
    }

    $existingSettings = videochat_get_or_create_appointment_settings($pdo, $ownerUserId, $tenantId);
    $blocks = is_array($validation['blocks'] ?? null) ? $validation['blocks'] : [];
    $now = gmdate('c');

    try {
        $pdo->beginTransaction();
        $blockTenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
            ? '  AND tenant_id = :tenant_id'
            : '';
        $delete = $pdo->prepare(
            <<<SQL
DELETE FROM appointment_blocks
WHERE owner_user_id = :owner_user_id
{$blockTenantWhere}
  AND id NOT IN (
      SELECT block_id
      FROM appointment_bookings
      WHERE status = 'booked'
  )
SQL
        );
        $deleteParams = [':owner_user_id' => $ownerUserId];
        if ($blockTenantWhere !== '') {
            $deleteParams[':tenant_id'] = $tenantId;
        }
        $delete->execute($deleteParams);

        $tenantColumn = $blockTenantWhere !== '' ? ', tenant_id' : '';
        $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
        $insert = $pdo->prepare(
            <<<SQL
INSERT INTO appointment_blocks(id, owner_user_id, starts_at, ends_at, timezone, status, created_at, updated_at{$tenantColumn})
VALUES(:id, :owner_user_id, :starts_at, :ends_at, :timezone, 'open', :created_at, :updated_at{$tenantValue})
SQL
        );
        foreach ($blocks as $block) {
            $insertParams = [
                ':id' => videochat_generate_call_id(),
                ':owner_user_id' => $ownerUserId,
                ':starts_at' => (string) $block['starts_at'],
                ':ends_at' => (string) $block['ends_at'],
                ':timezone' => (string) $block['timezone'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            if ($tenantColumn !== '') {
                $insertParams[':tenant_id'] = $tenantId;
            }
            $insert->execute($insertParams);
        }
        $settings = is_array($settingsValidation['settings'] ?? null)
            ? (array) $settingsValidation['settings']
            : $existingSettings;
        $existingSettings = videochat_save_appointment_settings(
            $pdo,
            $ownerUserId,
            (string) ($existingSettings['public_id'] ?? ''),
            $settings,
            $tenantId
        );

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'internal_error', 'errors' => [], 'blocks' => []];
    }

    return [
        'ok' => true,
        'reason' => 'saved',
        'errors' => [],
        'blocks' => videochat_list_appointment_blocks($pdo, $ownerUserId, $tenantId),
        'settings' => $existingSettings,
    ];
}

function videochat_public_appointment_slots(PDO $pdo, string $publicId): array
{
    $calendar = videochat_get_appointment_settings_by_public_id($pdo, $publicId);
    if ($calendar === null) {
        return ['ok' => false, 'reason' => 'not_found', 'owner' => null, 'slots' => []];
    }
    $ownerUserId = (int) ($calendar['owner_user_id'] ?? 0);
    $tenantId = is_numeric($calendar['tenant_id'] ?? null) ? (int) $calendar['tenant_id'] : null;
    $settings = is_array($calendar['settings'] ?? null) ? $calendar['settings'] : [];
    $slotMode = videochat_appointment_normalize_slot_mode($settings['slot_mode'] ?? 'selected_dates');

    if ($slotMode === 'recurring_weekly') {
        $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
            ? '  AND appointment_blocks.tenant_id = :tenant_id'
            : '';
        $query = $pdo->prepare(
            <<<SQL
SELECT appointment_blocks.*
FROM appointment_blocks
LEFT JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
  AND appointment_blocks.status = 'open'
{$tenantWhere}
  AND appointment_bookings.id IS NULL
ORDER BY appointment_blocks.starts_at ASC, appointment_blocks.id ASC
LIMIT 120
SQL
        );
        $params = [':owner_user_id' => $ownerUserId];
        if ($tenantWhere !== '') {
            $params[':tenant_id'] = $tenantId;
        }
        $query->execute($params);
    } else {
        $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
            ? '  AND appointment_blocks.tenant_id = :tenant_id'
            : '';
        $query = $pdo->prepare(
            <<<SQL
SELECT appointment_blocks.*
FROM appointment_blocks
LEFT JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
  AND appointment_blocks.status = 'open'
{$tenantWhere}
  AND appointment_blocks.ends_at > :now
  AND appointment_bookings.id IS NULL
ORDER BY appointment_blocks.starts_at ASC, appointment_blocks.id ASC
LIMIT 120
SQL
        );
        $params = [
            ':owner_user_id' => $ownerUserId,
            ':now' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if ($tenantWhere !== '') {
            $params[':tenant_id'] = $tenantId;
        }
        $query->execute($params);
    }

    $slots = [];
    $slotSeconds = videochat_appointment_slot_seconds($settings);
    $bookedRanges = videochat_appointment_booked_ranges($pdo, $ownerUserId, $tenantId);
    $nowUnix = time();
    foreach ($query->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $blockStartUnix = strtotime((string) ($row['starts_at'] ?? ''));
        $blockEndUnix = strtotime((string) ($row['ends_at'] ?? ''));
        if (!is_int($blockStartUnix) || !is_int($blockEndUnix) || $blockEndUnix <= $blockStartUnix) {
            continue;
        }
        if ($slotMode === 'recurring_weekly') {
            videochat_append_recurring_appointment_public_slots($slots, $row, $slotSeconds, $bookedRanges, $nowUnix);
        } else {
            videochat_append_appointment_public_slots($slots, $row, $slotSeconds, $bookedRanges, $blockStartUnix, $blockEndUnix, $nowUnix);
        }
        if (count($slots) >= VIDEOCHAT_APPOINTMENT_PUBLIC_SLOT_LIMIT) {
            break;
        }
    }

    return [
        'ok' => true,
        'reason' => 'loaded',
        'owner' => [
            'display_name' => (string) ($calendar['owner_display_name'] ?? ''),
        ],
        'settings' => videochat_appointment_public_settings_payload($settings),
        'slots' => $slots,
    ];
}

function videochat_validate_public_appointment_booking_payload(array $payload): array
{
    $slotId = strtolower(trim((string) ($payload['slot_id'] ?? ($payload['block_id'] ?? ''))));
    $firstName = videochat_appointment_clean_text($payload['first_name'] ?? '', 80);
    $lastName = videochat_appointment_clean_text($payload['last_name'] ?? '', 80);
    $email = videochat_appointment_normalize_email($payload['email'] ?? '');
    $errors = [];

    if ($slotId === '' || strlen($slotId) > 80) {
        $errors['slot_id'] = 'required_slot';
    }
    if ($firstName === '') {
        $errors['first_name'] = 'required_first_name';
    }
    if ($lastName === '') {
        $errors['last_name'] = 'required_last_name';
    }
    if ($email === '') {
        $errors['email'] = 'required_valid_email';
    }
    if (!videochat_appointment_truthy($payload['privacy_accepted'] ?? false)) {
        $errors['privacy_accepted'] = 'required_privacy_acceptance';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'data' => [
            'slot_id' => $slotId,
            'salutation' => videochat_appointment_clean_text($payload['salutation'] ?? '', 40),
            'title' => videochat_appointment_clean_text($payload['title'] ?? '', 60),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'message' => videochat_appointment_clean_text($payload['message'] ?? '', 2000),
        ],
    ];
}

function videochat_book_public_appointment(PDO $pdo, string $publicId, array $payload): array
{
    $calendar = videochat_get_appointment_settings_by_public_id($pdo, $publicId);
    if ($calendar === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found']];
    }
    $ownerUserId = (int) ($calendar['owner_user_id'] ?? 0);
    $tenantId = is_numeric($calendar['tenant_id'] ?? null) ? (int) $calendar['tenant_id'] : null;
    $owner = videochat_appointment_owner($pdo, $ownerUserId, $tenantId);
    if ($owner === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found']];
    }
    $calendarSettings = is_array($calendar['settings'] ?? null) ? (array) $calendar['settings'] : [];
    $slotSeconds = videochat_appointment_slot_seconds($calendarSettings);

    $validation = videochat_validate_public_appointment_booking_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors'] ?? []];
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $slot = videochat_parse_appointment_slot_token((string) ($data['slot_id'] ?? ''));
    $blockId = (string) (($slot['block_id'] ?? null) ?: ($data['slot_id'] ?? ''));
    $now = gmdate('c');
    $bookingId = videochat_generate_call_id();
    $callId = videochat_generate_call_id();
    $accessId = videochat_generate_call_access_uuid();

    try {
        $pdo->beginTransaction();
        $blockTenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
            ? '  AND tenant_id = :tenant_id'
            : '';
        $blockQuery = $pdo->prepare(
            <<<SQL
SELECT *
FROM appointment_blocks
WHERE id = :id
  AND owner_user_id = :owner_user_id
  AND status = 'open'
{$blockTenantWhere}
LIMIT 1
SQL
        );
        $blockParams = [':id' => $blockId, ':owner_user_id' => $ownerUserId];
        if ($blockTenantWhere !== '') {
            $blockParams[':tenant_id'] = $tenantId;
        }
        $blockQuery->execute($blockParams);
        $block = $blockQuery->fetch();
        if (!is_array($block)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found', 'errors' => ['slot_id' => 'slot_not_found']];
        }

        $blockStartsAt = (string) ($block['starts_at'] ?? '');
        $blockEndsAt = (string) ($block['ends_at'] ?? '');
        $startsAt = (string) (($slot['starts_at'] ?? null) ?: $blockStartsAt);
        $endsAt = (string) (($slot['ends_at'] ?? null) ?: $blockEndsAt);
        $blockStartsAtUnix = strtotime($blockStartsAt);
        $blockEndsAtUnix = strtotime($blockEndsAt);
        $startsAtUnix = strtotime($startsAt);
        $endsAtUnix = strtotime($endsAt);
        if (!is_int($blockStartsAtUnix) || !is_int($blockEndsAtUnix) || !is_int($startsAtUnix) || !is_int($endsAtUnix)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_unavailable']];
        }
        $isSlotInBlock = $startsAtUnix >= $blockStartsAtUnix && $endsAtUnix <= $blockEndsAtUnix;
        $isSlotDuration = ($endsAtUnix - $startsAtUnix) === $slotSeconds;
        $isSlotAligned = (($startsAtUnix - $blockStartsAtUnix) % $slotSeconds) === 0;
        if ($startsAtUnix <= time() || $endsAtUnix <= $startsAtUnix || !$isSlotInBlock || ($slot !== null && (!$isSlotDuration || !$isSlotAligned))) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_unavailable']];
        }

        foreach (videochat_appointment_booked_ranges($pdo, $ownerUserId, $tenantId) as $range) {
            if (videochat_appointment_ranges_overlap($startsAtUnix, $endsAtUnix, (int) $range['start_unix'], (int) $range['end_unix'])) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_already_booked']];
            }
        }

        $bookingBlockId = (string) ($block['id'] ?? '');
        if ($startsAt !== $blockStartsAt || $endsAt !== $blockEndsAt) {
            $bookingBlockId = videochat_generate_call_id();
            $tenantColumn = $blockTenantWhere !== '' ? ', tenant_id' : '';
            $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
            $insertBookingBlock = $pdo->prepare(
                <<<SQL
INSERT INTO appointment_blocks(id, owner_user_id, starts_at, ends_at, timezone, status, created_at, updated_at{$tenantColumn})
VALUES(:id, :owner_user_id, :starts_at, :ends_at, :timezone, 'open', :created_at, :updated_at{$tenantValue})
SQL
            );
            $bookingBlockParams = [
                ':id' => $bookingBlockId,
                ':owner_user_id' => $ownerUserId,
                ':starts_at' => $startsAt,
                ':ends_at' => $endsAt,
                ':timezone' => videochat_normalize_call_schedule_timezone($block['timezone'] ?? 'UTC'),
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            if ($tenantColumn !== '') {
                $bookingBlockParams[':tenant_id'] = $tenantId;
            }
            $insertBookingBlock->execute($bookingBlockParams);
        }

        $displayName = trim((string) $data['first_name'] . ' ' . (string) $data['last_name']);
        $callTitle = 'Video call with ' . $displayName;
        $schedule = videochat_build_call_schedule_metadata($startsAt, $endsAt, $block['timezone'] ?? 'UTC', false);
        $status = $startsAtUnix <= time() && time() < $endsAtUnix ? 'active' : 'scheduled';

        $roomTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'rooms', 'tenant_id') ? ', tenant_id' : '';
        $roomTenantValue = $roomTenantColumn !== '' ? ', :tenant_id' : '';
        $insertRoom = $pdo->prepare(
            <<<SQL
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at{$roomTenantColumn})
VALUES(:id, :name, 'private', 'active', :owner_user_id, :created_at, :updated_at{$roomTenantValue})
SQL
        );
        $roomParams = [
            ':id' => $callId,
            ':name' => $callTitle,
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($roomTenantColumn !== '') {
            $roomParams[':tenant_id'] = $tenantId;
        }
        $insertRoom->execute($roomParams);

        $callTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id') ? ', tenant_id' : '';
        $callTenantValue = $callTenantColumn !== '' ? ', :tenant_id' : '';
        $insertCall = $pdo->prepare(
            <<<SQL
INSERT INTO calls(
    id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at{$callTenantColumn}
) VALUES(
    :id, :room_id, :title, 'invite_only', :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, 0,
    NULL, NULL, NULL, :created_at, :updated_at{$callTenantValue}
)
SQL
        );
        $callParams = [
            ':id' => $callId,
            ':room_id' => $callId,
            ':title' => $callTitle,
            ':owner_user_id' => $ownerUserId,
            ':status' => $status,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':schedule_timezone' => (string) ($schedule['timezone'] ?? 'UTC'),
            ':schedule_date' => (string) ($schedule['date'] ?? ''),
            ':schedule_duration_minutes' => (int) ($schedule['duration_minutes'] ?? 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($callTenantColumn !== '') {
            $callParams[':tenant_id'] = $tenantId;
        }
        $insertCall->execute($callParams);

        $insertParticipant = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, NULL, NULL)
SQL
        );
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $ownerUserId,
            ':email' => (string) $owner['email'],
            ':display_name' => (string) $owner['display_name'],
            ':source' => 'internal',
            ':call_role' => 'owner',
            ':invite_state' => 'allowed',
        ]);
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => null,
            ':email' => (string) $data['email'],
            ':display_name' => $displayName,
            ':source' => 'external',
            ':call_role' => 'participant',
            ':invite_state' => 'invited',
        ]);

        $accessTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
        $accessTenantValue = $accessTenantColumn !== '' ? ', :tenant_id' : '';
        $insertAccess = $pdo->prepare(
            <<<SQL
INSERT INTO call_access_links(
    id, call_id, participant_user_id, participant_email, invite_code_id,
    created_by_user_id, created_at, expires_at, last_used_at, consumed_at{$accessTenantColumn}
) VALUES(
    :id, :call_id, NULL, :participant_email, NULL,
    :owner_user_id, :created_at, :expires_at, NULL, NULL{$accessTenantValue}
)
SQL
        );
        $accessParams = [
            ':id' => $accessId,
            ':call_id' => $callId,
            ':participant_email' => (string) $data['email'],
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':expires_at' => $endsAt,
        ];
        if ($accessTenantColumn !== '') {
            $accessParams[':tenant_id'] = $tenantId;
        }
        $insertAccess->execute($accessParams);

        $bookingTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_bookings', 'tenant_id') ? ', tenant_id' : '';
        $bookingTenantValue = $bookingTenantColumn !== '' ? ', :tenant_id' : '';
        $insertBooking = $pdo->prepare(
            <<<SQL
INSERT INTO appointment_bookings(
    id, block_id, call_id, access_id, owner_user_id, salutation, title,
    first_name, last_name, email, message, privacy_accepted, privacy_accepted_at,
    status, created_at, updated_at{$bookingTenantColumn}
) VALUES(
    :id, :block_id, :call_id, :access_id, :owner_user_id, :salutation, :title,
    :first_name, :last_name, :email, :message, 1, :privacy_accepted_at,
    'booked', :created_at, :updated_at{$bookingTenantValue}
)
SQL
        );
        $bookingParams = [
            ':id' => $bookingId,
            ':block_id' => $bookingBlockId,
            ':call_id' => $callId,
            ':access_id' => $accessId,
            ':owner_user_id' => $ownerUserId,
            ':salutation' => (string) $data['salutation'],
            ':title' => (string) $data['title'],
            ':first_name' => (string) $data['first_name'],
            ':last_name' => (string) $data['last_name'],
            ':email' => (string) $data['email'],
            ':message' => (string) $data['message'],
            ':privacy_accepted_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($bookingTenantColumn !== '') {
            $bookingParams[':tenant_id'] = $tenantId;
        }
        $insertBooking->execute($bookingParams);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'internal_error', 'errors' => []];
    }

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    $publicCall = is_array($call) ? [
        'id' => (string) ($call['id'] ?? $callId),
        'room_id' => (string) ($call['room_id'] ?? $callId),
        'title' => (string) ($call['title'] ?? 'Video call'),
        'starts_at' => (string) ($call['starts_at'] ?? ''),
        'ends_at' => (string) ($call['ends_at'] ?? ''),
        'status' => (string) ($call['status'] ?? 'scheduled'),
    ] : null;
    $calendarMailSettings = is_array($calendar['settings'] ?? null) ? (array) $calendar['settings'] : [];
    $mailSettings = [
        ...videochat_workspace_mail_transport_settings($pdo, $tenantId),
        'mail_subject_template' => (string) ($calendarMailSettings['mail_subject_template'] ?? videochat_default_appointment_email_subject_template()),
        'mail_body_template' => (string) ($calendarMailSettings['mail_body_template'] ?? videochat_default_appointment_email_body_template()),
    ];
    $notifications = videochat_send_appointment_booking_notifications(
        $mailSettings,
        $owner,
        $data,
        [
            'call_id' => $callId,
            'access_id' => $accessId,
            'call_title' => $callTitle,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => (string) ($block['timezone'] ?? 'UTC'),
        ]
    );

    return [
        'ok' => true,
        'reason' => 'booked',
        'errors' => [],
        'booking' => [
            'id' => $bookingId,
            'slot_id' => (string) ($data['slot_id'] ?? ''),
            'call_id' => $callId,
            'access_id' => $accessId,
            'starts_at' => $call['starts_at'] ?? '',
            'ends_at' => $call['ends_at'] ?? '',
            'join_path' => '/join/' . $accessId,
        ],
        'call' => $publicCall,
        'join_path' => '/join/' . $accessId,
        'notifications' => $notifications,
    ];
}
