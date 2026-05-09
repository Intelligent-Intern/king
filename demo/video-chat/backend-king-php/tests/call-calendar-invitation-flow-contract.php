<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/appointment_calendar.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_calendar_invitation_flow_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-calendar-invitation-flow-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_calendar_invitation_flow_source(string $relativePath): string
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $source = is_file($path) ? file_get_contents($path) : false;
    videochat_calendar_invitation_flow_assert(is_string($source), "source file missing: {$relativePath}");
    return $source;
}

function videochat_calendar_invitation_flow_static_contract(): void
{
    $bookingSource = videochat_calendar_invitation_flow_source('domain/calls/appointment_calendar_booking.php');
    $accessContractSource = videochat_calendar_invitation_flow_source('domain/calls/call_access_contract.php');

    videochat_calendar_invitation_flow_assert(
        str_contains($bookingSource, 'videochat_create_guest_user_for_call_access($pdo, $displayName, $tenantId, false)'),
        'calendar booking must create a call-scoped temporary guest account without tenant membership'
    );
    videochat_calendar_invitation_flow_assert(
        str_contains($bookingSource, '\':participant_user_id\' => $temporaryUserId'),
        'calendar access link must persist a server-side temporary-account binding'
    );
    videochat_calendar_invitation_flow_assert(
        str_contains($bookingSource, '\':participant_email\' => $bookingEmail'),
        'calendar access link must keep normalized form email as contact metadata'
    );
    videochat_calendar_invitation_flow_assert(
        str_contains($accessContractSource, '$linkParticipantUserId <= 0 && $linkParticipantEmail !=='),
        'session binding must treat participant_user_id as the stronger identity binding'
    );
}

function videochat_calendar_invitation_flow_fetch_access(PDO $pdo, string $accessId, ?int $tenantId): array
{
    $access = videochat_fetch_call_access_link($pdo, $accessId, $tenantId);
    videochat_calendar_invitation_flow_assert(is_array($access), "access link should exist: {$accessId}");
    return $access;
}

function videochat_calendar_invitation_flow_fetch_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, password_hash, status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($user), "temporary user should exist: {$userId}");
    return $user;
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
    videochat_calendar_invitation_flow_assert(is_string($passwordHash) && $passwordHash !== '', 'registered invitee password hash failed');

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

function videochat_calendar_invitation_flow_fetch_booking(PDO $pdo, string $accessId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, block_id, call_id, access_id, owner_user_id, first_name, last_name, email, message, status
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

function videochat_calendar_invitation_flow_issue_user_session(
    PDO $pdo,
    int $userId,
    string $sessionId,
    int $tenantId
): string {
    $issued = videochat_issue_session_for_user(
        $pdo,
        $userId,
        static fn (): string => $sessionId,
        43_200,
        '127.0.0.1',
        'call-calendar-invitation-flow-contract',
        time(),
        $tenantId
    );
    videochat_calendar_invitation_flow_assert((bool) ($issued['ok'] ?? false), "user session should issue: {$sessionId}");
    return (string) (($issued['session'] ?? [])['id'] ?? $sessionId);
}

function videochat_calendar_invitation_flow_auth(PDO $pdo, string $sessionId): array
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_calendar_invitation_flow_assert((bool) ($auth['ok'] ?? false), "session should authenticate: {$sessionId}");
    return $auth;
}

function videochat_calendar_invitation_flow_participant(PDO $pdo, string $callId, int $userId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, display_name, source, call_role, invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($row), "participant row should exist for {$userId}");
    return $row;
}

function videochat_calendar_invitation_flow_assert_invite_state(
    PDO $pdo,
    string $callId,
    int $userId,
    string $state,
    string $label
): void {
    $participant = videochat_calendar_invitation_flow_participant($pdo, $callId, $userId);
    videochat_calendar_invitation_flow_assert((string) ($participant['invite_state'] ?? '') === $state, "{$label}: invite state should be {$state}");
}

function videochat_calendar_invitation_flow_connection(
    array &$presenceState,
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $connectionId,
    string $socket
): array {
    $auth = videochat_calendar_invitation_flow_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_calendar_invitation_flow_assert((bool) ($resolution['ok'] ?? false), "{$connectionId}: room resolution should succeed");

    $connection = videochat_presence_connection_descriptor(
        (array) ($auth['user'] ?? []),
        $sessionId,
        $connectionId,
        $socket,
        (string) ($resolution['initial_room_id'] ?? videochat_realtime_waiting_room_id())
    );
    $connection['requested_room_id'] = (string) ($resolution['requested_room_id'] ?? '');
    $connection['pending_room_id'] = (string) ($resolution['pending_room_id'] ?? '');
    $connection['requested_call_id'] = $callId;
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
    $join = videochat_presence_join_room($presenceState, $connection, (string) ($connection['room_id'] ?? videochat_realtime_waiting_room_id()));
    $connection = (array) ($join['connection'] ?? $connection);
    $connection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
    $presenceState['connections'][(string) ($connection['connection_id'] ?? $connectionId)] = $connection;

    return $connection;
}

function videochat_calendar_invitation_flow_lobby_command(
    array &$lobbyState,
    array &$presenceState,
    array $connection,
    callable $openDatabase,
    array $payload,
    string $label
): void {
    $command = videochat_lobby_decode_client_frame(json_encode($payload, JSON_UNESCAPED_SLASHES));
    videochat_calendar_invitation_flow_assert((bool) ($command['ok'] ?? false), "{$label}: lobby command should decode");
    $handled = videochat_realtime_handle_lobby_websocket_command(
        $command,
        $connection['socket'] ?? null,
        $lobbyState,
        $presenceState,
        $connection,
        $openDatabase
    );
    videochat_calendar_invitation_flow_assert(is_array($handled), "{$label}: lobby command should be handled");
}

function videochat_calendar_invitation_flow_assert_waiting(
    PDO $pdo,
    callable $openDatabase,
    string $sessionId,
    string $callId,
    string $label
): void {
    $auth = videochat_calendar_invitation_flow_auth($pdo, $sessionId);
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_calendar_invitation_flow_assert((bool) ($resolution['ok'] ?? false), "{$label}: room resolution should succeed");
    videochat_calendar_invitation_flow_assert((string) ($resolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), "{$label}: should start in lobby");
    videochat_calendar_invitation_flow_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$label}: pending room should be the booked call");
}

function videochat_calendar_invitation_flow_mutate_uuid(string $uuid): string
{
    $normalized = strtolower(trim($uuid));
    $last = substr($normalized, -1);
    return substr($normalized, 0, -1) . ($last === 'a' ? 'b' : 'a');
}

function videochat_calendar_invitation_flow_booking_payload(string $slotId, string $firstName, string $lastName, string $email, string $message): array
{
    return [
        'slot_id' => $slotId,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'message' => $message,
        'privacy_accepted' => true,
    ];
}

function videochat_calendar_invitation_flow_core_access_snapshot(array $access): array
{
    return [
        'id' => (string) ($access['id'] ?? ''),
        'call_id' => (string) ($access['call_id'] ?? ''),
        'participant_user_id' => (int) ($access['participant_user_id'] ?? 0),
        'participant_email' => (string) ($access['participant_email'] ?? ''),
        'expires_at' => (string) ($access['expires_at'] ?? ''),
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

    $issuedSessionId = 'sess_calendar_invite_stale_' . substr(str_replace('-', '', $accessId), 0, 12);
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $issuedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'calendar-invitation-flow-contract']
    );
    videochat_calendar_invitation_flow_assert(!(bool) ($session['ok'] ?? true), "{$context} should not issue a session");
    videochat_calendar_invitation_flow_assert((string) ($session['reason'] ?? '') === 'not_found', "{$context} session should fail closed as not_found");
    videochat_calendar_invitation_flow_assert(($session['session'] ?? null) === null, "{$context} must not expose session data");
    videochat_calendar_invitation_flow_assert(videochat_calendar_invitation_flow_session_user_id($pdo, $issuedSessionId) === 0, "{$context} must not persist session");

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
    videochat_calendar_invitation_flow_static_contract();

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-calendar-invitation-flow-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-calendar-invitation-flow-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

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
    videochat_calendar_invitation_flow_assert($registeredInviteeEmail !== '', 'registered invitee email should exist');

    $day = time() + 14 * 86400;
    $blockStart = gmdate('Y-m-d\T09:00:00\Z', $day);
    $blockEnd = gmdate('Y-m-d\T11:00:00\Z', $day);
    $saveResult = videochat_save_appointment_blocks($pdo, $ownerUserId, [
        'settings' => ['slot_minutes' => 60],
        'blocks' => [
            ['starts_at' => $blockStart, 'ends_at' => $blockEnd, 'timezone' => 'UTC'],
        ],
    ], $tenantId);
    videochat_calendar_invitation_flow_assert((bool) ($saveResult['ok'] ?? false), 'appointment availability should save');
    $publicCalendarId = (string) (($saveResult['settings'] ?? [])['public_id'] ?? '');
    videochat_calendar_invitation_flow_assert($publicCalendarId !== '', 'public calendar id should be returned');

    $slots = videochat_public_appointment_slots($pdo, $publicCalendarId);
    videochat_calendar_invitation_flow_assert((bool) ($slots['ok'] ?? false), 'public slots should load');
    videochat_calendar_invitation_flow_assert(count($slots['slots'] ?? []) >= 2, 'two bookable slots should be exposed');

    $firstEmail = $registeredInviteeEmail;
    $secondEmail = 'grace.calendar-flow@example.test';
    $firstBooking = videochat_book_public_appointment(
        $pdo,
        $publicCalendarId,
        videochat_calendar_invitation_flow_booking_payload(
            (string) (($slots['slots'][0] ?? [])['id'] ?? ''),
            'Ada',
            'Lovelace',
            $firstEmail,
            'Please send the secure calendar invitation proof.'
        )
    );
    $secondBooking = videochat_book_public_appointment(
        $pdo,
        $publicCalendarId,
        videochat_calendar_invitation_flow_booking_payload(
            (string) (($slots['slots'][1] ?? [])['id'] ?? ''),
            'Grace',
            'Hopper',
            $secondEmail,
            'Second invitee must receive an unrelated personal link.'
        )
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
    foreach ([$firstAccessId, $secondAccessId] as $accessId) {
        videochat_calendar_invitation_flow_assert(
            preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $accessId) === 1,
            'personalized link id must be a non-sequential v4 uuid'
        );
    }

    $firstAccess = videochat_calendar_invitation_flow_fetch_access($pdo, $firstAccessId, $tenantId);
    $secondAccess = videochat_calendar_invitation_flow_fetch_access($pdo, $secondAccessId, $tenantId);
    $firstTemporaryUserId = (int) ($firstAccess['participant_user_id'] ?? 0);
    $secondTemporaryUserId = (int) ($secondAccess['participant_user_id'] ?? 0);
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId > 0, 'first link should be bound to a temporary account');
    videochat_calendar_invitation_flow_assert($secondTemporaryUserId > 0, 'second link should be bound to a temporary account');
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId !== $secondTemporaryUserId, 'different invitees should get different temporary accounts');
    videochat_calendar_invitation_flow_assert($firstTemporaryUserId !== $registeredInviteeUserId, 'registered logged-out booking must not bind the access link to the existing account');
    videochat_calendar_invitation_flow_assert((string) ($firstAccess['participant_email'] ?? '') === $firstEmail, 'first link should keep first form email');
    videochat_calendar_invitation_flow_assert((string) ($secondAccess['participant_email'] ?? '') === $secondEmail, 'second link should keep second form email');

    $firstTemporaryUser = videochat_calendar_invitation_flow_fetch_user($pdo, $firstTemporaryUserId);
    $secondTemporaryUser = videochat_calendar_invitation_flow_fetch_user($pdo, $secondTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($firstTemporaryUser['display_name'] ?? '') === 'Ada Lovelace', 'temporary account should contain first invitee form name');
    videochat_calendar_invitation_flow_assert((string) ($secondTemporaryUser['display_name'] ?? '') === 'Grace Hopper', 'temporary account should contain second invitee form name');
    foreach ([$firstTemporaryUser, $secondTemporaryUser] as $temporaryUser) {
        $email = strtolower((string) ($temporaryUser['email'] ?? ''));
        videochat_calendar_invitation_flow_assert(str_starts_with($email, 'guest+') && str_ends_with($email, '@videochat.local'), 'temporary account should use synthetic guest email');
        videochat_calendar_invitation_flow_assert(($temporaryUser['password_hash'] ?? null) === null, 'temporary account should not have a password hash');
        videochat_calendar_invitation_flow_assert((string) ($temporaryUser['status'] ?? '') === 'active', 'temporary account should start active');
    }

    $guestTenantMemberships = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM tenant_memberships
WHERE tenant_id = :tenant_id
  AND user_id IN (:first_user_id, :second_user_id)
SQL
    );
    $guestTenantMemberships->execute([
        ':tenant_id' => $tenantId,
        ':first_user_id' => $firstTemporaryUserId,
        ':second_user_id' => $secondTemporaryUserId,
    ]);
    videochat_calendar_invitation_flow_assert((int) $guestTenantMemberships->fetchColumn() === 0, 'calendar temporary accounts must not receive tenant membership');

    $firstParticipant = $pdo->prepare(
        <<<'SQL'
SELECT email, display_name, source, call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $firstParticipant->execute([':call_id' => $firstCallId, ':user_id' => $firstTemporaryUserId]);
    $participantRow = $firstParticipant->fetch(PDO::FETCH_ASSOC);
    videochat_calendar_invitation_flow_assert(is_array($participantRow), 'temporary account should be call participant');
    videochat_calendar_invitation_flow_assert((string) ($participantRow['email'] ?? '') === $firstEmail, 'call participant should retain form email');
    videochat_calendar_invitation_flow_assert((string) ($participantRow['source'] ?? '') === 'internal', 'temporary account should be internalized for call access decisions');
    videochat_calendar_invitation_flow_assert((string) ($participantRow['call_role'] ?? '') === 'participant', 'temporary invitee should not receive elevated call role');
    videochat_calendar_invitation_flow_assert((string) ($participantRow['invite_state'] ?? '') === 'invited', 'temporary invitee should start invited');

    $firstBookingRow = videochat_calendar_invitation_flow_fetch_booking($pdo, $firstAccessId);
    videochat_calendar_invitation_flow_assert((string) ($firstBookingRow['first_name'] ?? '') === 'Ada', 'booking should persist first name');
    videochat_calendar_invitation_flow_assert((string) ($firstBookingRow['last_name'] ?? '') === 'Lovelace', 'booking should persist last name');
    videochat_calendar_invitation_flow_assert((string) ($firstBookingRow['email'] ?? '') === $firstEmail, 'booking should persist form email');
    videochat_calendar_invitation_flow_assert(str_contains((string) ($firstBookingRow['message'] ?? ''), 'secure calendar'), 'booking should persist form message');

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
    videochat_calendar_invitation_flow_assert(!(bool) ($wrongAccountSession['ok'] ?? true), 'server-side account binding must reject another authenticated user');
    videochat_calendar_invitation_flow_assert(
        (string) ($wrongAccountSession['reason'] ?? '') !== '',
        'wrong authenticated account should fail closed with an explicit reason'
    );
    videochat_calendar_invitation_flow_assert(
        videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_wrong_account') === 0,
        'wrong authenticated account denial must not persist a session'
    );

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
    videochat_calendar_invitation_flow_assert(
        (int) (($firstSession['user'] ?? [])['id'] ?? 0) === $firstTemporaryUserId,
        'first link open should use bound temporary account'
    );
    videochat_calendar_invitation_flow_assert(
        (int) (($firstSession['user'] ?? [])['id'] ?? 0) !== $registeredInviteeUserId,
        'registered logged-out link open must not automatically log in the existing registered account'
    );
    videochat_calendar_invitation_flow_assert(
        (string) (($firstSession['user'] ?? [])['account_type'] ?? '') === 'guest',
        'registered logged-out link open should issue a temporary guest session'
    );
    videochat_calendar_invitation_flow_assert(
        (bool) (($firstSession['user'] ?? [])['is_guest'] ?? false) === true,
        'registered logged-out link session should be marked as guest'
    );
    videochat_calendar_invitation_flow_assert(
        videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_first') === $firstTemporaryUserId,
        'stored session binding should point to the temporary user'
    );
    videochat_calendar_invitation_flow_assert(
        (int) (($reopenedSession['user'] ?? [])['id'] ?? 0) === $firstTemporaryUserId,
        'reopening same valid link should reuse same temporary account'
    );
    videochat_calendar_invitation_flow_assert(
        videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_reopen') !== $registeredInviteeUserId,
        'reopening same registered logged-out link must not take over the existing registered account'
    );
    $guestCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'")->fetchColumn();
    videochat_calendar_invitation_flow_assert($guestCount === 2, 'reopening a personalized calendar link must not create another temporary account');

    $binding = videochat_validate_call_access_session_binding($pdo, 'sess_calendar_invite_reopen', $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert((bool) ($binding['ok'] ?? false), 'reopened session binding should remain valid');
    videochat_calendar_invitation_flow_assert((string) ($binding['reason'] ?? '') === 'ok', 'reopened session binding reason should be ok');

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    videochat_calendar_invitation_flow_assert_waiting(
        $pdo,
        $openDatabase,
        'sess_calendar_invite_first',
        $firstCallId,
        'unregistered calendar guest before admission'
    );

    $ownerSessionId = videochat_calendar_invitation_flow_issue_user_session(
        $pdo,
        $ownerUserId,
        'sess_calendar_invite_owner',
        $tenantId
    );
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();

    $ownerConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        $ownerSessionId,
        $firstCallId,
        'conn_calendar_invite_owner',
        'socket_calendar_invite_owner'
    );
    videochat_calendar_invitation_flow_assert((string) ($ownerConnection['room_id'] ?? '') === $firstCallId, 'owner should resolve directly to booked call room');
    videochat_calendar_invitation_flow_assert((bool) ($ownerConnection['can_moderate_call'] ?? false), 'owner should be able to admit calendar guest');

    $guestLobbyConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_first',
        $firstCallId,
        'conn_calendar_invite_guest_lobby',
        'socket_calendar_invite_guest_lobby'
    );
    videochat_calendar_invitation_flow_assert((string) ($guestLobbyConnection['room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'calendar guest should land in lobby before host admission');
    videochat_calendar_invitation_flow_lobby_command(
        $lobbyState,
        $presenceState,
        $guestLobbyConnection,
        $openDatabase,
        ['type' => 'lobby/queue/join', 'room_id' => $firstCallId],
        'calendar guest queue join'
    );
    videochat_calendar_invitation_flow_assert_invite_state($pdo, $firstCallId, $firstTemporaryUserId, 'pending', 'calendar guest queued');

    videochat_calendar_invitation_flow_lobby_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $firstCallId, 'target_user_id' => $firstTemporaryUserId],
        'calendar guest host admission'
    );
    videochat_calendar_invitation_flow_assert_invite_state($pdo, $firstCallId, $firstTemporaryUserId, 'allowed', 'calendar guest admitted');

    $admittedResolution = videochat_realtime_resolve_connection_rooms(
        videochat_calendar_invitation_flow_auth($pdo, 'sess_calendar_invite_first'),
        $firstCallId,
        $openDatabase,
        $firstCallId
    );
    videochat_calendar_invitation_flow_assert((string) ($admittedResolution['initial_room_id'] ?? '') === $firstCallId, 'admitted calendar guest should join booked call room');
    videochat_calendar_invitation_flow_assert((string) ($admittedResolution['pending_room_id'] ?? '') === '', 'admitted calendar guest should not need lobby approval again');

    $guestCallConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_first',
        $firstCallId,
        'conn_calendar_invite_guest_call',
        'socket_calendar_invite_guest_call'
    );
    videochat_realtime_mark_call_participant_joined($openDatabase, $guestCallConnection);
    $joinedParticipant = videochat_calendar_invitation_flow_participant($pdo, $firstCallId, $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($joinedParticipant['invite_state'] ?? '') === 'allowed', 'joined calendar guest should stay allowed');
    videochat_calendar_invitation_flow_assert(trim((string) ($joinedParticipant['joined_at'] ?? '')) !== '', 'joined calendar guest should persist joined_at');

    videochat_presence_remove_connection(
        $presenceState,
        (string) ($guestCallConnection['connection_id'] ?? ''),
        static fn (mixed $socket, array $payload): bool => true
    );
    videochat_realtime_remove_call_presence($openDatabase, $guestCallConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $guestCallConnection, $presenceState);
    $leftParticipant = videochat_calendar_invitation_flow_participant($pdo, $firstCallId, $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($leftParticipant['invite_state'] ?? '') === 'allowed', 'leaving admitted calendar guest should preserve allowed state');
    videochat_calendar_invitation_flow_assert(trim((string) ($leftParticipant['left_at'] ?? '')) !== '', 'leaving calendar guest should persist left_at');

    $rejoinResolution = videochat_realtime_resolve_connection_rooms(
        videochat_calendar_invitation_flow_auth($pdo, 'sess_calendar_invite_first'),
        $firstCallId,
        $openDatabase,
        $firstCallId
    );
    videochat_calendar_invitation_flow_assert((string) ($rejoinResolution['initial_room_id'] ?? '') === $firstCallId, 'admitted calendar guest rejoin should bypass lobby');
    videochat_calendar_invitation_flow_assert((string) ($rejoinResolution['pending_room_id'] ?? '') === '', 'calendar guest rejoin must not require a second approval');

    $guestRejoinConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_first',
        $firstCallId,
        'conn_calendar_invite_guest_rejoin',
        'socket_calendar_invite_guest_rejoin'
    );
    videochat_realtime_mark_call_participant_joined($openDatabase, $guestRejoinConnection);
    $rejoinedParticipant = videochat_calendar_invitation_flow_participant($pdo, $firstCallId, $firstTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($rejoinedParticipant['invite_state'] ?? '') === 'allowed', 'rejoined calendar guest should remain allowed');
    videochat_calendar_invitation_flow_assert(trim((string) ($rejoinedParticipant['left_at'] ?? '')) === '', 'rejoining calendar guest should clear stale left_at');

    $secondAccessBeforeChange = videochat_calendar_invitation_flow_core_access_snapshot($secondAccess);
    $secondBookingBeforeChange = videochat_calendar_invitation_flow_fetch_booking($pdo, $secondAccessId);
    $changedStartsAt = gmdate('Y-m-d\T12:00:00\Z', $day + 86400);
    $changedEndsAt = gmdate('Y-m-d\T13:00:00\Z', $day + 86400);
    $updateFirstAppointment = videochat_update_call($pdo, $firstCallId, $ownerUserId, 'admin', [
        'starts_at' => $changedStartsAt,
        'ends_at' => $changedEndsAt,
    ], $tenantId);
    videochat_calendar_invitation_flow_assert((bool) ($updateFirstAppointment['ok'] ?? false), 'owner should be able to move first appointment call');
    $secondAccessAfterChange = videochat_calendar_invitation_flow_core_access_snapshot(
        videochat_calendar_invitation_flow_fetch_access($pdo, $secondAccessId, $tenantId)
    );
    $secondBookingAfterChange = videochat_calendar_invitation_flow_fetch_booking($pdo, $secondAccessId);
    videochat_calendar_invitation_flow_assert($secondAccessAfterChange === $secondAccessBeforeChange, 'moving one appointment must not modify unrelated personalized invitation link');
    videochat_calendar_invitation_flow_assert($secondBookingAfterChange === $secondBookingBeforeChange, 'moving one appointment must not modify unrelated booking form data');

    $pdo->prepare("UPDATE appointment_bookings SET status = 'cancelled', updated_at = :updated_at WHERE access_id = :access_id")->execute([
        ':updated_at' => gmdate('c'),
        ':access_id' => $secondAccessId,
    ]);
    videochat_calendar_invitation_flow_assert_stale_link_closed(
        $pdo,
        $secondAccessId,
        [$secondAccessId, $secondCallId, $secondEmail, 'Grace Hopper'],
        'cancelled calendar appointment link'
    );

    $pdo->prepare('UPDATE appointment_bookings SET call_id = :call_id, updated_at = :updated_at WHERE access_id = :access_id')->execute([
        ':call_id' => $secondCallId,
        ':updated_at' => gmdate('c'),
        ':access_id' => $firstAccessId,
    ]);
    videochat_calendar_invitation_flow_assert_stale_link_closed(
        $pdo,
        $firstAccessId,
        [$firstAccessId, $firstCallId, $secondCallId, $firstEmail, 'Ada Lovelace'],
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
