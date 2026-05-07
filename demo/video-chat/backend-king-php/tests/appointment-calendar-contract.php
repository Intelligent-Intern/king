<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/appointment_calendar.php';

function videochat_appointment_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[appointment-calendar-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-appointment-calendar-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $ownerUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_appointment_contract_assert($ownerUserId > 0, 'expected seeded owner user');

    $firstStart = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
    $firstEnd = gmdate('Y-m-d\TH:i:s\Z', time() + 90000);
    $secondStart = gmdate('Y-m-d\TH:i:s\Z', time() + 93600);
    $secondEnd = gmdate('Y-m-d\TH:i:s\Z', time() + 97200);

    $saveResult = videochat_save_appointment_blocks($pdo, $ownerUserId, [
        'settings' => ['slot_minutes' => 60],
        'blocks' => [
            ['starts_at' => $firstStart, 'ends_at' => $firstEnd, 'timezone' => 'UTC'],
            ['starts_at' => $secondStart, 'ends_at' => $secondEnd, 'timezone' => 'UTC'],
        ],
    ]);
    videochat_appointment_contract_assert($saveResult['ok'] === true, 'valid block save should succeed');
    videochat_appointment_contract_assert(count($saveResult['blocks'] ?? []) === 2, 'block save should return two blocks');
    $publicCalendarId = (string) (($saveResult['settings'] ?? [])['public_id'] ?? '');
    videochat_appointment_contract_assert(
        preg_match('/^[a-f0-9-]{36}$/', $publicCalendarId) === 1,
        'appointment calendar should expose a non-sequential public id'
    );

    $overlapResult = videochat_save_appointment_blocks($pdo, $ownerUserId, [
        'blocks' => [
            ['starts_at' => $firstStart, 'ends_at' => $secondStart, 'timezone' => 'UTC'],
            ['starts_at' => $firstEnd, 'ends_at' => $secondEnd, 'timezone' => 'UTC'],
        ],
    ]);
    videochat_appointment_contract_assert($overlapResult['ok'] === false, 'overlapping blocks should fail');
    videochat_appointment_contract_assert(
        (string) (($overlapResult['errors'] ?? [])['blocks'] ?? '') === 'overlapping_blocks',
        'overlap error should be explicit'
    );

    $publicSlots = videochat_public_appointment_slots($pdo, $publicCalendarId);
    videochat_appointment_contract_assert($publicSlots['ok'] === true, 'public slots should load');
    videochat_appointment_contract_assert(count($publicSlots['slots'] ?? []) === 2, 'public slots should expose both open blocks');
    videochat_appointment_contract_assert(
        (string) (($publicSlots['owner'] ?? [])['id'] ?? '') === '',
        'public slots must not expose internal owner user id'
    );
    $slotId = (string) (($publicSlots['slots'][0] ?? [])['id'] ?? '');
    videochat_appointment_contract_assert($slotId !== '', 'public slot id should be present');

    $invalidBooking = videochat_book_public_appointment($pdo, $publicCalendarId, [
        'slot_id' => $slotId,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'privacy_accepted' => false,
    ]);
    videochat_appointment_contract_assert($invalidBooking['ok'] === false, 'booking without consent should fail');
    videochat_appointment_contract_assert(
        (string) (($invalidBooking['errors'] ?? [])['privacy_accepted'] ?? '') === 'required_privacy_acceptance',
        'privacy consent error mismatch'
    );

    $booking = videochat_book_public_appointment($pdo, $publicCalendarId, [
        'slot_id' => $slotId,
        'salutation' => 'Ms.',
        'title' => '',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.com',
        'message' => 'Please show the small-group demo flow.',
        'privacy_accepted' => true,
    ]);
    videochat_appointment_contract_assert($booking['ok'] === true, 'valid booking should succeed');
    videochat_appointment_contract_assert((string) ($booking['reason'] ?? '') === 'booked', 'booking reason mismatch');
    videochat_appointment_contract_assert(str_starts_with((string) ($booking['join_path'] ?? ''), '/join/'), 'booking should return join path');

    $callId = (string) (($booking['booking'] ?? [])['call_id'] ?? '');
    $accessId = (string) (($booking['booking'] ?? [])['access_id'] ?? '');
    videochat_appointment_contract_assert($callId !== '', 'booking should create call id');
    videochat_appointment_contract_assert($accessId !== '', 'booking should create access id');

    $callCountQuery = $pdo->prepare('SELECT COUNT(*) FROM calls WHERE id = :id');
    $callCountQuery->execute([':id' => $callId]);
    videochat_appointment_contract_assert((int) $callCountQuery->fetchColumn() === 1, 'booking call should persist');

    $accessCountQuery = $pdo->prepare('SELECT COUNT(*) FROM call_access_links WHERE id = :id AND participant_email = :email');
    $accessCountQuery->execute([':id' => $accessId, ':email' => 'ada@example.com']);
    videochat_appointment_contract_assert((int) $accessCountQuery->fetchColumn() === 1, 'booking access link should persist');

    $slotsAfterBooking = videochat_public_appointment_slots($pdo, $publicCalendarId);
    videochat_appointment_contract_assert(count($slotsAfterBooking['slots'] ?? []) === 1, 'booked slot should disappear');

    $duplicateBooking = videochat_book_public_appointment($pdo, $publicCalendarId, [
        'slot_id' => $slotId,
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'privacy_accepted' => true,
    ]);
    videochat_appointment_contract_assert($duplicateBooking['ok'] === false, 'duplicate booking should fail');
    videochat_appointment_contract_assert((string) ($duplicateBooking['reason'] ?? '') === 'conflict', 'duplicate booking reason mismatch');

    $longBlockStart = gmdate('Y-m-d\T08:00:00\Z', time() + 7 * 86400);
    $longBlockEnd = gmdate('Y-m-d\T18:00:00\Z', time() + 7 * 86400);
    $longBlockResult = videochat_save_appointment_blocks($pdo, $ownerUserId, [
        'blocks' => [
            ['starts_at' => $longBlockStart, 'ends_at' => $longBlockEnd, 'timezone' => 'UTC'],
        ],
    ]);
    videochat_appointment_contract_assert($longBlockResult['ok'] === true, 'day-length availability block should save');
    $longBlockSlots = videochat_public_appointment_slots($pdo, $publicCalendarId);
    videochat_appointment_contract_assert(count($longBlockSlots['slots'] ?? []) === 10, 'public calendar should expose day-length availability as call-length slots');

    @unlink($databasePath);
    fwrite(STDOUT, "[appointment-calendar-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[appointment-calendar-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
