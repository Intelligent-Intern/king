<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/appointment_calendar.php';
require_once __DIR__ . '/../domain/realtime/realtime_connection_contract.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_sync.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';

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

    $join = videochat_presence_join_room(
        $presenceState,
        $connection,
        (string) ($connection['room_id'] ?? videochat_realtime_waiting_room_id()),
        static fn (mixed $socket, array $payload): bool => true
    );
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
): array {
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
    videochat_calendar_invitation_flow_assert(is_string($encoded), "{$label}: lobby command should encode");
    $command = videochat_lobby_decode_client_frame($encoded);
    videochat_calendar_invitation_flow_assert((bool) ($command['ok'] ?? false), "{$label}: lobby command should decode");

    $result = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $connection,
        $command,
        static fn (mixed $socket, array $payload): bool => true
    );
    videochat_calendar_invitation_flow_assert((bool) ($result['ok'] ?? false), "{$label}: lobby command should apply");

    $action = (string) ($result['action'] ?? '');
    if ($action === 'lobby/queue/join' && in_array((string) ($result['state'] ?? ''), ['queued', 'already_queued'], true)) {
        videochat_calendar_invitation_flow_assert(
            videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $connection),
            "{$label}: queued participant should persist pending invite state"
        );
    }

    if (in_array($action, ['lobby/allow', 'lobby/allow_all'], true)) {
        $callId = videochat_realtime_connection_call_id($connection);
        $affectedUserIds = is_array($result['affected_user_ids'] ?? null) ? (array) $result['affected_user_ids'] : [];
        foreach ($affectedUserIds as $affectedUserId) {
            $normalizedUserId = (int) $affectedUserId;
            if ($normalizedUserId > 0) {
                videochat_calendar_invitation_flow_assert(
                    videochat_realtime_mark_call_participant_invite_state_by_user_id($openDatabase, $callId, $normalizedUserId, 'allowed', ['pending']),
                    "{$label}: admitted participant should persist allowed invite state"
                );
            }
        }
    }

    return $result;
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

    $secondSession = videochat_issue_session_for_call_access(
        $pdo,
        $secondAccessId,
        static fn (): string => 'sess_calendar_invite_unregistered',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-calendar-invitation-flow-contract']
    );
    videochat_calendar_invitation_flow_assert((bool) ($secondSession['ok'] ?? false), 'unregistered calendar invite should issue session');
    videochat_calendar_invitation_flow_assert(
        (int) (($secondSession['user'] ?? [])['id'] ?? 0) === $secondTemporaryUserId,
        'unregistered calendar invite session should use the bound temporary account'
    );
    videochat_calendar_invitation_flow_assert(
        (string) (($secondSession['user'] ?? [])['account_type'] ?? '') === 'guest',
        'unregistered calendar invite session should remain guest scoped'
    );
    videochat_calendar_invitation_flow_assert(
        (bool) (($secondSession['user'] ?? [])['is_guest'] ?? false),
        'unregistered calendar invite session should expose guest identity'
    );
    videochat_calendar_invitation_flow_assert(
        videochat_calendar_invitation_flow_session_user_id($pdo, 'sess_calendar_invite_unregistered') === $secondTemporaryUserId,
        'unregistered calendar invite session binding should point to temporary account'
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    videochat_calendar_invitation_flow_assert_waiting(
        $pdo,
        $openDatabase,
        'sess_calendar_invite_unregistered',
        $secondCallId,
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
        $secondCallId,
        'conn_calendar_invite_owner',
        'socket_calendar_invite_owner'
    );
    videochat_calendar_invitation_flow_assert((string) ($ownerConnection['room_id'] ?? '') === $secondCallId, 'owner should resolve directly to booked call room');
    videochat_calendar_invitation_flow_assert((bool) ($ownerConnection['can_moderate_call'] ?? false), 'owner should be able to admit calendar guest');

    $guestLobbyConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_unregistered',
        $secondCallId,
        'conn_calendar_invite_guest_lobby',
        'socket_calendar_invite_guest_lobby'
    );
    videochat_calendar_invitation_flow_assert(
        (string) ($guestLobbyConnection['room_id'] ?? '') === videochat_realtime_waiting_room_id(),
        'unregistered calendar guest should land in lobby before host admission'
    );
    videochat_calendar_invitation_flow_lobby_command(
        $lobbyState,
        $presenceState,
        $guestLobbyConnection,
        $openDatabase,
        ['type' => 'lobby/queue/join', 'room_id' => $secondCallId],
        'unregistered calendar guest queue join'
    );
    videochat_calendar_invitation_flow_assert_invite_state($pdo, $secondCallId, $secondTemporaryUserId, 'pending', 'unregistered calendar guest queued');

    videochat_calendar_invitation_flow_lobby_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        $openDatabase,
        ['type' => 'lobby/allow', 'room_id' => $secondCallId, 'target_user_id' => $secondTemporaryUserId],
        'unregistered calendar guest host admission'
    );
    videochat_calendar_invitation_flow_assert_invite_state($pdo, $secondCallId, $secondTemporaryUserId, 'allowed', 'unregistered calendar guest admitted');

    $admittedResolution = videochat_realtime_resolve_connection_rooms(
        videochat_calendar_invitation_flow_auth($pdo, 'sess_calendar_invite_unregistered'),
        $secondCallId,
        $openDatabase,
        $secondCallId
    );
    videochat_calendar_invitation_flow_assert((string) ($admittedResolution['initial_room_id'] ?? '') === $secondCallId, 'admitted unregistered calendar guest should join booked call room');
    videochat_calendar_invitation_flow_assert((string) ($admittedResolution['pending_room_id'] ?? '') === '', 'admitted unregistered calendar guest should not need lobby approval again');

    $guestCallConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_unregistered',
        $secondCallId,
        'conn_calendar_invite_guest_call',
        'socket_calendar_invite_guest_call'
    );
    videochat_realtime_mark_call_participant_joined($openDatabase, $guestCallConnection);
    $joinedParticipant = videochat_calendar_invitation_flow_participant($pdo, $secondCallId, $secondTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($joinedParticipant['invite_state'] ?? '') === 'allowed', 'joined unregistered calendar guest should stay allowed');
    videochat_calendar_invitation_flow_assert(trim((string) ($joinedParticipant['joined_at'] ?? '')) !== '', 'joined unregistered calendar guest should persist joined_at');

    videochat_presence_remove_connection(
        $presenceState,
        (string) ($guestCallConnection['connection_id'] ?? ''),
        static fn (mixed $socket, array $payload): bool => true
    );
    videochat_realtime_remove_call_presence($openDatabase, $guestCallConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $guestCallConnection, $presenceState);
    $leftParticipant = videochat_calendar_invitation_flow_participant($pdo, $secondCallId, $secondTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($leftParticipant['invite_state'] ?? '') === 'allowed', 'leaving admitted unregistered calendar guest should preserve allowed state');
    videochat_calendar_invitation_flow_assert(trim((string) ($leftParticipant['left_at'] ?? '')) !== '', 'leaving unregistered calendar guest should persist left_at');

    $rejoinResolution = videochat_realtime_resolve_connection_rooms(
        videochat_calendar_invitation_flow_auth($pdo, 'sess_calendar_invite_unregistered'),
        $secondCallId,
        $openDatabase,
        $secondCallId
    );
    videochat_calendar_invitation_flow_assert((string) ($rejoinResolution['initial_room_id'] ?? '') === $secondCallId, 'admitted unregistered calendar guest rejoin should bypass lobby');
    videochat_calendar_invitation_flow_assert((string) ($rejoinResolution['pending_room_id'] ?? '') === '', 'unregistered calendar guest rejoin must not require a second approval');

    $guestRejoinConnection = videochat_calendar_invitation_flow_connection(
        $presenceState,
        $pdo,
        $openDatabase,
        'sess_calendar_invite_unregistered',
        $secondCallId,
        'conn_calendar_invite_guest_rejoin',
        'socket_calendar_invite_guest_rejoin'
    );
    videochat_realtime_mark_call_participant_joined($openDatabase, $guestRejoinConnection);
    $rejoinedParticipant = videochat_calendar_invitation_flow_participant($pdo, $secondCallId, $secondTemporaryUserId);
    videochat_calendar_invitation_flow_assert((string) ($rejoinedParticipant['invite_state'] ?? '') === 'allowed', 'rejoined unregistered calendar guest should remain allowed');
    videochat_calendar_invitation_flow_assert(trim((string) ($rejoinedParticipant['left_at'] ?? '')) === '', 'rejoining unregistered calendar guest should clear stale left_at');

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
