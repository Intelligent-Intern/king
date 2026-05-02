<?php

declare(strict_types=1);

require_once __DIR__ . '/call_management.php';
require_once __DIR__ . '/call_access.php';

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

function videochat_appointment_clean_text(mixed $value, int $maxLength): string
{
    $text = preg_replace('/\s+/u', ' ', trim((string) $value));
    if (!is_string($text)) {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxLength);
    }

    return substr($text, 0, $maxLength);
}

function videochat_appointment_normalize_email(mixed $value): string
{
    $email = strtolower(trim((string) $value));
    if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }

    return $email;
}

function videochat_appointment_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function videochat_appointment_owner(PDO $pdo, int $ownerUserId): ?array
{
    if ($ownerUserId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, email, display_name
FROM users
WHERE id = :id
  AND status = 'active'
LIMIT 1
SQL
    );
    $query->execute([':id' => $ownerUserId]);
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

function videochat_list_appointment_blocks(PDO $pdo, int $ownerUserId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    appointment_blocks.*,
    COUNT(appointment_bookings.id) AS booked_count
FROM appointment_blocks
LEFT JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
  AND appointment_blocks.status = 'open'
GROUP BY appointment_blocks.id
ORDER BY appointment_blocks.starts_at ASC, appointment_blocks.id ASC
SQL
    );
    $query->execute([':owner_user_id' => $ownerUserId]);

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
        if (($endUnix - $startUnix) > 8 * 60 * 60) {
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

function videochat_save_appointment_blocks(PDO $pdo, int $ownerUserId, array $payload): array
{
    if (videochat_appointment_owner($pdo, $ownerUserId) === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found'], 'blocks' => []];
    }

    $validation = videochat_validate_appointment_blocks_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors'] ?? [], 'blocks' => []];
    }

    $blocks = is_array($validation['blocks'] ?? null) ? $validation['blocks'] : [];
    if ($blocks !== []) {
        $bookedQuery = $pdo->prepare(
            <<<'SQL'
SELECT appointment_blocks.starts_at, appointment_blocks.ends_at
FROM appointment_blocks
INNER JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
SQL
        );
        $bookedQuery->execute([':owner_user_id' => $ownerUserId]);
        $bookedBlocks = $bookedQuery->fetchAll();
        foreach ($blocks as $block) {
            $startUnix = strtotime((string) ($block['starts_at'] ?? ''));
            $endUnix = strtotime((string) ($block['ends_at'] ?? ''));
            if (!is_int($startUnix) || !is_int($endUnix)) {
                continue;
            }
            foreach ($bookedBlocks as $bookedBlock) {
                if (!is_array($bookedBlock)) {
                    continue;
                }
                $bookedStartUnix = strtotime((string) ($bookedBlock['starts_at'] ?? ''));
                $bookedEndUnix = strtotime((string) ($bookedBlock['ends_at'] ?? ''));
                if (is_int($bookedStartUnix) && is_int($bookedEndUnix) && $startUnix < $bookedEndUnix && $endUnix > $bookedStartUnix) {
                    return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['blocks' => 'overlaps_booked_slot'], 'blocks' => []];
                }
            }
        }
    }
    $now = gmdate('c');

    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare(
            <<<'SQL'
DELETE FROM appointment_blocks
WHERE owner_user_id = :owner_user_id
  AND id NOT IN (
      SELECT block_id
      FROM appointment_bookings
      WHERE status = 'booked'
  )
SQL
        );
        $delete->execute([':owner_user_id' => $ownerUserId]);

        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO appointment_blocks(id, owner_user_id, starts_at, ends_at, timezone, status, created_at, updated_at)
VALUES(:id, :owner_user_id, :starts_at, :ends_at, :timezone, 'open', :created_at, :updated_at)
SQL
        );
        foreach ($blocks as $block) {
            $insert->execute([
                ':id' => videochat_generate_call_id(),
                ':owner_user_id' => $ownerUserId,
                ':starts_at' => (string) $block['starts_at'],
                ':ends_at' => (string) $block['ends_at'],
                ':timezone' => (string) $block['timezone'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }

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
        'blocks' => videochat_list_appointment_blocks($pdo, $ownerUserId),
    ];
}

function videochat_public_appointment_slots(PDO $pdo, int $ownerUserId): array
{
    $owner = videochat_appointment_owner($pdo, $ownerUserId);
    if ($owner === null) {
        return ['ok' => false, 'reason' => 'not_found', 'owner' => null, 'slots' => []];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT appointment_blocks.*
FROM appointment_blocks
LEFT JOIN appointment_bookings
  ON appointment_bookings.block_id = appointment_blocks.id
 AND appointment_bookings.status = 'booked'
WHERE appointment_blocks.owner_user_id = :owner_user_id
  AND appointment_blocks.status = 'open'
  AND appointment_blocks.starts_at > :now
  AND appointment_bookings.id IS NULL
ORDER BY appointment_blocks.starts_at ASC, appointment_blocks.id ASC
LIMIT 120
SQL
    );
    $query->execute([
        ':owner_user_id' => $ownerUserId,
        ':now' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    $slots = [];
    foreach ($query->fetchAll() as $row) {
        if (is_array($row)) {
            $slot = videochat_appointment_block_row($row, false);
            unset($slot['owner_user_id'], $slot['status'], $slot['created_at'], $slot['updated_at']);
            $slots[] = $slot;
        }
    }

    return [
        'ok' => true,
        'reason' => 'loaded',
        'owner' => [
            'id' => (int) ($owner['id'] ?? $ownerUserId),
            'display_name' => (string) ($owner['display_name'] ?? ''),
        ],
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

function videochat_book_public_appointment(PDO $pdo, int $ownerUserId, array $payload): array
{
    $owner = videochat_appointment_owner($pdo, $ownerUserId);
    if ($owner === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found']];
    }

    $validation = videochat_validate_public_appointment_booking_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors'] ?? []];
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $now = gmdate('c');
    $bookingId = videochat_generate_call_id();
    $callId = videochat_generate_call_id();
    $accessId = videochat_generate_call_access_uuid();

    try {
        $pdo->beginTransaction();
        $blockQuery = $pdo->prepare(
            <<<'SQL'
SELECT *
FROM appointment_blocks
WHERE id = :id
  AND owner_user_id = :owner_user_id
  AND status = 'open'
LIMIT 1
SQL
        );
        $blockQuery->execute([':id' => (string) $data['slot_id'], ':owner_user_id' => $ownerUserId]);
        $block = $blockQuery->fetch();
        if (!is_array($block)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found', 'errors' => ['slot_id' => 'slot_not_found']];
        }

        $startsAt = (string) ($block['starts_at'] ?? '');
        $endsAt = (string) ($block['ends_at'] ?? '');
        $startsAtUnix = strtotime($startsAt);
        $endsAtUnix = strtotime($endsAt);
        if (!is_int($startsAtUnix) || !is_int($endsAtUnix) || $startsAtUnix <= time() || $endsAtUnix <= $startsAtUnix) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_unavailable']];
        }

        $existing = $pdo->prepare('SELECT id FROM appointment_bookings WHERE block_id = :block_id AND status = "booked" LIMIT 1');
        $existing->execute([':block_id' => (string) $block['id']]);
        if (is_array($existing->fetch())) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_already_booked']];
        }

        $displayName = trim((string) $data['first_name'] . ' ' . (string) $data['last_name']);
        $callTitle = 'Demo call with ' . $displayName;
        $schedule = videochat_build_call_schedule_metadata($startsAt, $endsAt, $block['timezone'] ?? 'UTC', false);
        $status = $startsAtUnix <= time() && time() < $endsAtUnix ? 'active' : 'scheduled';

        $insertRoom = $pdo->prepare(
            <<<'SQL'
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :name, 'private', 'active', :owner_user_id, :created_at, :updated_at)
SQL
        );
        $insertRoom->execute([
            ':id' => $callId,
            ':name' => $callTitle,
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $insertCall = $pdo->prepare(
            <<<'SQL'
INSERT INTO calls(
    id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at
) VALUES(
    :id, :room_id, :title, 'invite_only', :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, 0,
    NULL, NULL, NULL, :created_at, :updated_at
)
SQL
        );
        $insertCall->execute([
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
        ]);

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

        $insertAccess = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_access_links(
    id, call_id, participant_user_id, participant_email, invite_code_id,
    created_by_user_id, created_at, expires_at, last_used_at, consumed_at
) VALUES(
    :id, :call_id, NULL, :participant_email, NULL,
    :owner_user_id, :created_at, :expires_at, NULL, NULL
)
SQL
        );
        $insertAccess->execute([
            ':id' => $accessId,
            ':call_id' => $callId,
            ':participant_email' => (string) $data['email'],
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':expires_at' => $endsAt,
        ]);

        $insertBooking = $pdo->prepare(
            <<<'SQL'
INSERT INTO appointment_bookings(
    id, block_id, call_id, access_id, owner_user_id, salutation, title,
    first_name, last_name, email, message, privacy_accepted, privacy_accepted_at,
    status, created_at, updated_at
) VALUES(
    :id, :block_id, :call_id, :access_id, :owner_user_id, :salutation, :title,
    :first_name, :last_name, :email, :message, 1, :privacy_accepted_at,
    'booked', :created_at, :updated_at
)
SQL
        );
        $insertBooking->execute([
            ':id' => $bookingId,
            ':block_id' => (string) $block['id'],
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
        ]);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'internal_error', 'errors' => []];
    }

    $call = videochat_fetch_call_for_update($pdo, $callId);
    $publicCall = is_array($call) ? [
        'id' => (string) ($call['id'] ?? $callId),
        'room_id' => (string) ($call['room_id'] ?? $callId),
        'title' => (string) ($call['title'] ?? 'Demo call'),
        'starts_at' => (string) ($call['starts_at'] ?? ''),
        'ends_at' => (string) ($call['ends_at'] ?? ''),
        'status' => (string) ($call['status'] ?? 'scheduled'),
    ] : null;

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
    ];
}
