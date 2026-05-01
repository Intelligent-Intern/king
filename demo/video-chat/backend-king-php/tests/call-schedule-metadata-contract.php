<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';

function videochat_call_schedule_metadata_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-schedule-metadata-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $projection = videochat_build_call_schedule_metadata(
        '2026-06-02T22:30:00+00:00',
        '2026-06-02T23:45:00+00:00',
        'Europe/Berlin',
        false
    );
    videochat_call_schedule_metadata_assert($projection['timezone'] === 'Europe/Berlin', 'projection timezone mismatch');
    videochat_call_schedule_metadata_assert($projection['date'] === '2026-06-03', 'projection local date mismatch');
    videochat_call_schedule_metadata_assert($projection['starts_at_local'] === '2026-06-03T00:30:00+02:00', 'projection local start mismatch');
    videochat_call_schedule_metadata_assert($projection['ends_at_local'] === '2026-06-03T01:45:00+02:00', 'projection local end mismatch');
    videochat_call_schedule_metadata_assert($projection['duration_minutes'] === 75, 'projection duration mismatch');
    videochat_call_schedule_metadata_assert($projection['all_day'] === false, 'projection all_day mismatch');

    $invalidTimezone = videochat_validate_create_call_payload([
        'title' => 'Invalid Schedule Timezone',
        'starts_at' => '2026-06-02T09:00:00Z',
        'ends_at' => '2026-06-02T10:00:00Z',
        'schedule_timezone' => 'Mars/Olympus',
    ]);
    videochat_call_schedule_metadata_assert($invalidTimezone['ok'] === false, 'invalid schedule timezone should fail');
    videochat_call_schedule_metadata_assert(
        (string) (($invalidTimezone['errors'] ?? [])['schedule_timezone'] ?? '') === 'required_valid_timezone',
        'invalid schedule timezone error mismatch'
    );

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[call-schedule-metadata-contract] SKIP persistence: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-schedule-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_schedule_metadata_assert($adminUserId > 0, 'expected seeded admin user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Schedule Metadata Call',
        'starts_at' => '2026-06-02T22:30:00Z',
        'ends_at' => '2026-06-02T23:45:00Z',
        'schedule_timezone' => 'Europe/Berlin',
    ]);
    videochat_call_schedule_metadata_assert($created['ok'] === true, 'schedule create should succeed');
    $createdCall = is_array($created['call'] ?? null) ? $created['call'] : [];
    $callId = (string) ($createdCall['id'] ?? '');
    videochat_call_schedule_metadata_assert($callId !== '', 'created schedule call id should be non-empty');
    $createdSchedule = is_array($createdCall['schedule'] ?? null) ? $createdCall['schedule'] : [];
    videochat_call_schedule_metadata_assert((string) ($createdSchedule['timezone'] ?? '') === 'Europe/Berlin', 'created schedule timezone mismatch');
    videochat_call_schedule_metadata_assert((string) ($createdSchedule['date'] ?? '') === '2026-06-03', 'created schedule date mismatch');
    videochat_call_schedule_metadata_assert((int) ($createdSchedule['duration_minutes'] ?? 0) === 75, 'created schedule duration mismatch');

    $createdRowQuery = $pdo->prepare(
        <<<'SQL'
SELECT schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day
FROM calls
WHERE id = :id
LIMIT 1
SQL
    );
    $createdRowQuery->execute([':id' => $callId]);
    $createdRow = $createdRowQuery->fetch();
    videochat_call_schedule_metadata_assert(is_array($createdRow), 'created schedule row should exist');
    videochat_call_schedule_metadata_assert((string) ($createdRow['schedule_timezone'] ?? '') === 'Europe/Berlin', 'persisted schedule timezone mismatch');
    videochat_call_schedule_metadata_assert((string) ($createdRow['schedule_date'] ?? '') === '2026-06-03', 'persisted schedule date mismatch');
    videochat_call_schedule_metadata_assert((int) ($createdRow['schedule_duration_minutes'] ?? 0) === 75, 'persisted schedule duration mismatch');
    videochat_call_schedule_metadata_assert((int) ($createdRow['schedule_all_day'] ?? -1) === 0, 'persisted schedule all_day mismatch');

    $filters = videochat_calls_list_filters([
        'scope' => 'my',
        'status' => 'scheduled',
        'query' => 'schedule metadata',
        'page' => '1',
        'page_size' => '10',
    ], 'admin');
    videochat_call_schedule_metadata_assert($filters['ok'] === true, 'schedule list filters should pass');
    $listing = videochat_list_calls($pdo, $adminUserId, $filters);
    $listedSchedule = is_array(($listing['rows'][0] ?? [])['schedule'] ?? null) ? $listing['rows'][0]['schedule'] : [];
    videochat_call_schedule_metadata_assert((string) ($listedSchedule['date'] ?? '') === '2026-06-03', 'listed schedule date mismatch');

    $updated = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'starts_at' => '2026-12-24T23:30:00Z',
        'ends_at' => '2026-12-25T01:00:00Z',
        'schedule_timezone' => 'Asia/Tokyo',
        'schedule_all_day' => true,
    ]);
    videochat_call_schedule_metadata_assert($updated['ok'] === true, 'schedule update should succeed');
    $updatedSchedule = is_array(($updated['call'] ?? [])['schedule'] ?? null) ? $updated['call']['schedule'] : [];
    videochat_call_schedule_metadata_assert((string) ($updatedSchedule['timezone'] ?? '') === 'Asia/Tokyo', 'updated schedule timezone mismatch');
    videochat_call_schedule_metadata_assert((string) ($updatedSchedule['date'] ?? '') === '2026-12-25', 'updated schedule date mismatch');
    videochat_call_schedule_metadata_assert((int) ($updatedSchedule['duration_minutes'] ?? 0) === 90, 'updated schedule duration mismatch');
    videochat_call_schedule_metadata_assert(($updatedSchedule['all_day'] ?? null) === true, 'updated schedule all_day mismatch');

    $fetchedCall = videochat_fetch_call_for_update($pdo, $callId);
    videochat_call_schedule_metadata_assert(is_array($fetchedCall), 'updated schedule fetch should return call');
    $fetchedPayload = videochat_build_call_payload($pdo, $fetchedCall, $adminUserId);
    videochat_call_schedule_metadata_assert(
        (string) (($fetchedPayload['schedule'] ?? [])['date'] ?? '') === '2026-12-25',
        'fetched payload schedule date mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-schedule-metadata-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-schedule-metadata-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
