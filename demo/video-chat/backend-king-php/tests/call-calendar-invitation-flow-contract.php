<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/appointment_calendar.php';

function videochat_calendar_invitation_flow_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-calendar-invitation-flow-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_calendar_invitation_flow_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_calendar_invitation_flow_create_registered_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('calendar-invitation-flow-contract', PASSWORD_DEFAULT);
    videochat_calendar_invitation_flow_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be created');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_calendar_invitation_flow_assert($userId > 0, 'registered invitee user id should be positive');
    return $userId;
}

function videochat_calendar_invitation_flow_fetch_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, password_hash, status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($user), "user should exist: {$userId}");
    return $user;
}

function videochat_calendar_invitation_flow_fetch_access(PDO $pdo, string $accessId, ?int $tenantId): array
{
    $access = videochat_fetch_call_access_link($pdo, $accessId, $tenantId);
    videochat_calendar_invitation_flow_assert(is_array($access), "access link should exist: {$accessId}");
    return $access;
}

function videochat_calendar_invitation_flow_fetch_booking(PDO $pdo, string $accessId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, call_id, access_id, first_name, last_name, email, message, status
FROM appointment_bookings
WHERE access_id = :access_id
LIMIT 1
SQL
    );
    $query->execute([':access_id' => $accessId]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($booking), "appointment booking should exist for access: {$accessId}");
    return $booking;
}

function videochat_calendar_invitation_flow_session_user_id(PDO $pdo, string $sessionId): int
{
    $query = $pdo->prepare('SELECT user_id FROM sessions WHERE id = :id LIMIT 1');
    $query->execute([':id' => $sessionId]);
    $userId = $query->fetchColumn();
    return is_numeric($userId) ? (int) $userId : 0;
}

function videochat_calendar_invitation_flow_mutate_uuid(string $uuid): string
{
    $normalized = strtolower(trim($uuid));
    $last = substr($normalized, -1);
    return substr($normalized, 0, -1) . ($last === 'a' ? 'b' : 'a');
}

function videochat_calendar_invitation_flow_booking_payload(string $slotId, string $firstName, string $lastName, string $email): array
{
    return [
        'slot_id' => $slotId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'message' => 'Calendar invitation edge-state contract.',
        'privacy_accepted' => true,
    ];
}

function videochat_calendar_invitation_flow_assert_stale_link_closed(PDO $pdo, string $accessId, array $needles, string $context): void
{
    $resolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_calendar_invitation_flow_assert(!(bool) ($resolution['ok'] ?? true), "{$context} should not resolve");
    videochat_calendar_invitation_flow_assert((string) ($resolution['reason'] ?? '') === 'not_found', "{$context} should fail closed as not_found");
    videochat_calendar_invitation_flow_assert(($resolution['access_link'] ?? null) === null, "{$context} must not expose access link");
    videochat_calendar_invitation_flow_assert(($resolution['call'] ?? null) === null, "{$context} must not expose call");
    videochat_calendar_invitation_flow_assert(($resolution['target_user'] ?? null) === null, "{$context} must not expose target user");

    $sessionId = 'sess_calendar_invite_stale_' . substr(str_replace('-', '', $accessId), 0, 12);
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract']
    );
    videochat_calendar_invitation_flow_assert(!(bool) ($session['ok'] ?? true), "{$context} should not issue a session");
    videochat_calendar_invitation_flow_assert((string) ($session['reason'] ?? '') === 'not_found', "{$context} session should fail closed as not_found");
    videochat_calendar_invitation_flow_assert(($session['session'] ?? null) === null, "{$context} must not expose session data");
    videochat_calendar_invitation_flow_assert(videochat_calendar_invitation_flow_session_user_id($pdo, $sessionId) === 0, "{$context} must not persist session");

    $encoded = json_encode([$resolution, $session], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_calendar_invitation_flow_assert(is_string($encoded), "{$context} should encode");
    foreach ($needles as $needle) {
        $text = trim((string) $needle);
        if ($text !== '') {
            videochat_calendar_invitation_flow_assert(!str_contains($encoded, $text), "{$context} leaked {$text}");
        }
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-calendar-invitation-flow-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-calendar-invitation-flow-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $ownerUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $otherUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $userRoleId = videochat_calendar_invitation_flow_role_id($pdo, 'user');
    videochat_calendar_invitation_flow_assert($tenantId > 0, 'default tenant should exist');
    videochat_calendar_invitation_flow_assert($ownerUserId > 0, 'seeded owner should exist');
    videochat_calendar_invitation_flow_assert($otherUserId > 0, 'seeded non-owner user should exist');
    videochat_calendar_invitation_flow_assert($userRoleId > 0, 'user role should exist');

    $registeredInviteeUserId = videochat_calendar_invitation_flow_create_registered_user(
        $pdo,
        $userRoleId,
        'registered-calendar-invitee@example.test',
        'Registered Calendar Invitee'
    );
    $registeredInvitee = videochat_calendar_invitation_flow_fetch_user($pdo, $registeredInviteeUserId);
    $registeredInviteeEmail = strtolower((string) ($registeredInvitee['email'] ?? ''));

    $day = time() + 14 * 86400;
    $saveResult = videochat_save_appointment_blocks($pdo, $ownerUserId, [
        'settings' => ['slot_minutes' => 60],
        'blocks' => [
            [
                'starts_at' => gmdate('Y-m-d\T09:00:00\Z', $day),
                'ends_at' => gmdate('Y-m-d\T11:00:00\Z', $day),
                'timezone' => 'UTC',
            ],
        ],
    ], $tenantId);
    videochat_calendar_invitation_flow_assert((bool) ($saveResult['ok'] ?? false), 'appointment availability should save');

    $publicCalendarId = (string) (($saveResult['settings'] ?? [])['public_id'] ?? '');
    $slots = videochat_public_appointment_slots($pdo, $publicCalendarId);
    videochat_calendar_invitation_flow_assert((bool) ($slots['ok'] ?? false), 'public slots should load');
    videochat_calendar_invitation_flow_assert(count($slots['slots'] ?? []) >= 2, 'two bookable slots should be exposed');

    $secondEmail = 'grace.calendar-flow@example.test';
    $firstBooking = videochat_book_public_appointment(
        $pdo,
        $publicCalendarId,
        videochat_calendar_invitation_flow_booking_payload((string) (($slots['slots'][0] ?? [])['id'] ?? ''), 'Ada', 'Lovelace', $registeredInviteeEmail)
    );
    $secondBooking = videochat_book_public_appointment(
        $pdo,
        $publicCalendarId,
        videochat_calendar_invitation_flow_booking_payload((string) (($slots['slots'][1] ?? [])['id'] ?? ''), 'Grace', 'Hopper', $secondEmail)
    );
    videochat_calendar_invitation_flow_assert((bool) ($firstBooking['ok'] ?? false), 'first calendar booking should succeed');
    videochat_calendar_invitation_flow_assert((bool) ($secondBooking['ok'] ?? false), 'second calendar booking should succeed');

    $firstAccessId = (string) (($firstBooking['booking'] ?? [])['access_id'] ?? '');
    $secondAccessId = (string) (($secondBooking['booking'] ?? [])['access_id'] ?? '');
    $firstCallId = (string) (($firstBooking['booking'] ?? [])['call_id'] ?? '');
    $secondCallId = (string) (($secondBooking['booking'] ?? [])['call_id'] ?? '');
    videochat_calendar_invitation_flow_assert($firstAccessId !== '' && $secondAccessId !== '', 'both bookings should return access ids');
    videochat_calendar_invitation_flow_assert($firstAccessId !== $secondAccessId, 'multiple invitees must receive different personalized links');
    videochat_calendar_invitation_flow_assert($firstCallId !== '' && $secondCallId !== '' && $firstCallId !== $secondCallId, 'calendar bookings should create separate appointment calls');

    $firstAccess = videochat_calendar_invitation_flow_fetch_access($pdo, $firstAccessId, $tenantId);
    $secondAccess = videochat_calendar_invitation_flow_fetch_access($pdo, $secondAccessId, $tenantId);
    $firstTemporaryUserId = (int) ($firstAccess['participant_user_id'] ?? 0);
    $secondTemporaryUserId = (int) ($secondAccess['participant_user_id'] ?? 0);
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId > 0, 'first link should be bound to a temporary account');
    videochat_calendar_invitation_flow_assert($secondTemporaryUserId > 0, 'second link should be bound to a temporary account');
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId !== $secondTemporaryUserId, 'different invitees should get different temporary accounts');
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId !== $registeredInviteeUserId, 'registered logged-out booking must not bind the access link to the existing account');
    videochat_calendar_invitation_flow_assert((string) ($firstAccess['participant_email'] ?? '') === $registeredInviteeEmail, 'first link should keep form email as metadata');
    videochat_calendar_invitation_flow_assert((string) ($secondAccess['participant_email'] ?? '') === $secondEmail, 'second link should keep form email as metadata');

    $firstTemporaryUser = videochat_calendar_invitation_flow_fetch_user($pdo, $firstTemporaryUserId);
    $secondTemporaryUser = videochat_calendar_invitation_flow_fetch_user($pdo, $secondTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($firstTemporaryUser['display_name'] ?? '') === 'Ada Lovelace', 'temporary account should keep first invitee form name');
    videochat_calendar_invitation_flow_assert((string) ($secondTemporaryUser['display_name'] ?? '') === 'Grace Hopper', 'temporary account should keep second invitee form name');
    foreach ([$firstTemporaryUser, $secondTemporaryUser] as $temporaryUser) {
        $email = strtolower((string) ($temporaryUser['email'] ?? ''));
        videochat_calendar_invitation_flow_assert(str_starts_with($email, 'guest+') && str_ends_with($email, '@videochat.local'), 'temporary account should use synthetic guest email');
        videochat_calendar_invitation_flow_assert(($temporaryUser['password_hash'] ?? null) === null, 'temporary account should not have a password hash');
    }

    $membershipQuery = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM tenant_memberships
WHERE tenant_id = :tenant_id
  AND user_id IN (:first_user_id, :second_user_id)
SQL
    );
    $membershipQuery->execute([
        ':tenant_id' => $tenantId,
        ':first_user_id' => $firstTemporaryUserId,
        ':second_user_id' => $secondTemporaryUserId,
    ]);
    videochat_calendar_invitation_flow_assert((int) $membershipQuery->fetchColumn() === 0, 'calendar temporary accounts must not receive tenant membership');

    $participantQuery = $pdo->prepare(
        <<<'SQL'
SELECT email, display_name, source, call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $participantQuery->execute([':call_id' => $firstCallId, ':user_id' => $firstTemporaryUserId]);
    $participant = $participantQuery->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($participant), 'temporary account should be a call participant');
    videochat_calendar_invitation_flow_assert((string) ($participant['email'] ?? '') === $registeredInviteeEmail, 'participant should retain form email');
    videochat_calendar_invitation_flow_assert((string) ($participant['source'] ?? '') === 'internal', 'temporary account should be internalized for call decisions');
    videochat_calendar_invitation_flow_assert((string) ($participant['call_role'] ?? '') === 'participant', 'temporary invitee should not be elevated');
    videochat_calendar_invitation_flow_assert((string) ($participant['invite_state'] ?? '') === 'invited', 'temporary invitee should start invited');

    $resolve = videochat_resolve_call_access_public($pdo, $firstAccessId);
    videochat_calendar_invitation_flow_assert((bool) ($resolve['ok'] ?? false), 'bound calendar link should resolve');
    videochat_calendar_invitation_flow_assert((int) (($resolve['target_user'] ?? [])['id'] ?? 0) === $firstTemporaryUserId, 'resolve should target the bound temporary account');
    videochat_calendar_invitation_flow_assert((bool) ($resolve['requires_guest_name'] ?? false) === false, 'bound calendar link should not request a guest name');

    $manipulatedSession = videochat_issue_session_for_call_access(
        $pdo,
        videochat_calendar_invitation_flow_mutate_uuid($firstAccessId),
        static fn (): string => 'sess_calendar_invite_manipulated',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract']
    );
    videochat_calendar_invitation_flow_assert(!(bool) ($manipulatedSession['ok'] ?? true), 'manipulated personalized link should be rejected');
    videochat_calendar_invitation_flow_assert((string) ($manipulatedSession['reason'] ?? '') === 'not_found', 'manipulated personalized link should fail closed as not found');

    $wrongAccountSession = videochat_issue_session_for_call_access(
        $pdo,
        $firstAccessId,
        static fn (): string => 'sess_calendar_invite_wrong_account',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract'],
        ['authenticated_user_id' => $otherUserId, 'authenticated_session_id' => 'sess_other_account']
    );
    videochat_calendar_invitation_flow_assert(!(bool) ($wrongAccountSession['ok'] ?? true), 'another authenticated account must not claim the link');
    videochat_calendar_invitation_flow_assert(videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_wrong_account') === 0, 'wrong-account denial must not persist a session');

    $firstSession = videochat_issue_session_for_call_access(
        $pdo,
        $firstAccessId,
        static fn (): string => 'sess_calendar_invite_first',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract']
    );
    $reopenedSession = videochat_issue_session_for_call_access(
        $pdo,
        $firstAccessId,
        static fn (): string => 'sess_calendar_invite_reopen',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract']
    );
    videochat_calendar_invitation_flow_assert((bool) ($firstSession['ok'] ?? false), 'first valid link open should issue session');
    videochat_calendar_invitation_flow_assert((bool) ($reopenedSession['ok'] ?? false), 'reopening same valid link should issue session');
    videochat_calendar_invitation_flow_assert((int) (($firstSession['user'] ?? [])['id'] ?? 0) === $firstTemporaryUserId, 'first session should use bound temporary account');
    videochat_calendar_invitation_flow_assert((int) (($reopenedSession['user'] ?? [])['id'] ?? 0) === $firstTemporaryUserId, 'reopened session should reuse bound temporary account');
    videochat_calendar_invitation_flow_assert((int) (($firstSession['user'] ?? [])['id'] ?? 0) !== $registeredInviteeUserId, 'registered logged-out booking must not auto-login the registered account');
    videochat_calendar_invitation_flow_assert((string) (($firstSession['user'] ?? [])['account_type'] ?? '') === 'guest', 'calendar invite session should be guest scoped');
    videochat_calendar_invitation_flow_assert(videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_first') === $firstTemporaryUserId, 'stored session binding should point to temporary account');
    videochat_calendar_invitation_flow_assert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'")->fetchColumn() === 2, 'reopening a calendar link must not create another temporary account');

    $binding = videochat_validate_call_access_session_binding($pdo, 'sess_calendar_invite_reopen', $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert((bool) ($binding['ok'] ?? false), 'reopened session binding should remain valid before stale state');
    videochat_calendar_invitation_flow_assert((string) ($binding['reason'] ?? '') === 'ok', 'valid binding reason should be ok');

    $secondBookingRow = videochat_calendar_invitation_flow_fetch_booking($pdo, $secondAccessId);
    $pdo->prepare("UPDATE appointment_bookings SET status = 'cancelled', updated_at = :updated_at WHERE access_id = :access_id")->execute([
        ':updated_at' => gmdate('c'),
        ':access_id' => $secondAccessId,
    ]);
    videochat_calendar_invitation_flow_assert_stale_link_closed(
        $pdo,
        $secondAccessId,
        [$secondAccessId, $secondCallId, $secondEmail, 'Grace Hopper', (string) ($secondBookingRow['message'] ?? '')],
        'cancelled calendar appointment link'
    );

    $pdo->prepare('UPDATE appointment_bookings SET call_id = :call_id, updated_at = :updated_at WHERE access_id = :access_id')->execute([
        ':call_id' => $secondCallId,
        ':updated_at' => gmdate('c'),
        ':access_id' => $firstAccessId,
    ]);
    $staleBinding = videochat_validate_call_access_session_binding($pdo, 'sess_calendar_invite_first', $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert(!(bool) ($staleBinding['ok'] ?? true), 'existing session binding should close after appointment call mismatch');
    videochat_calendar_invitation_flow_assert((string) ($staleBinding['reason'] ?? '') === 'call_access_link_invalidated', 'stale binding reason should be invalidated');
    videochat_calendar_invitation_flow_assert_stale_link_closed(
        $pdo,
        $firstAccessId,
        [$firstAccessId, $firstCallId, $secondCallId, $registeredInviteeEmail, 'Ada Lovelace'],
        'personalized link bound to another appointment call'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-calendar-invitation-flow-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-calendar-invitation-flow-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
