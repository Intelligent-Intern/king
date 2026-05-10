<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-king-container-helper.php';

$label = 'call-access-king-container-contract';
$provedTestIds = [
    'e2e_king_001_king_can_join_as_owner',
    'e2e_king_002_king_can_join_as_registered_user',
    'e2e_king_003_king_can_join_as_personalized_guest',
    'e2e_king_004_king_can_join_as_anonymous_guest',
    'e2e_king_005_king_streams_deterministic_dummy_media',
    'e2e_king_006_king_disconnects_gracefully',
    'e2e_king_007_king_simulates_abrupt_disconnect',
    'e2e_king_008_king_simulates_network_loss',
    'e2e_king_009_king_reconnects_same_identity',
    'e2e_king_010_king_exposes_call_state',
    'e2e_king_011_king_exposes_countdown_state',
    'e2e_king_012_king_logs_are_collected_on_failure',
    'e2e_king_013_multiple_king_containers_join_same_call',
    'e2e_king_014_king_containers_terminate_cleanly',
];

function videochat_iam_king_container_contract_count(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return max(0, (int) ($statement->fetchColumn() ?: 0));
}

function videochat_iam_king_container_contract_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare(
        'SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id AND source = \'internal\' LIMIT 1'
    );
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_iam_king_container_contract_personal_guest_session(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $tenantId,
    string $label
): array {
    $guest = videochat_create_guest_user_for_call_access($pdo, 'King Personalized Guest', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($guest['ok'] ?? false), 'personalized guest account should be created', $label);
    $user = is_array($guest['user'] ?? null) ? $guest['user'] : [];
    $userId = (int) ($user['id'] ?? 0);
    videochat_iam_rejoin_contract_assert($userId > 0, 'personalized guest user id should be present', $label);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $userId,
        (string) ($user['email'] ?? ''),
        (string) ($user['display_name'] ?? ''),
        'allowed'
    );

    $link = videochat_create_call_access_link_for_user(
        $pdo,
        $callId,
        $ownerUserId,
        'admin',
        ['link_kind' => 'personal', 'participant_user_id' => $userId],
        $tenantId
    );
    videochat_iam_rejoin_contract_assert((bool) ($link['ok'] ?? false), 'personalized guest access link should be created', $label);
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personalized guest access id should be present', $label);

    $sessionId = 'sess_king_personalized_guest';
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($session['ok'] ?? false), 'personalized guest session should issue', $label);

    return [
        'user_id' => $userId,
        'display_name' => (string) ($user['display_name'] ?? 'King Personalized Guest'),
        'session_id' => $sessionId,
    ];
}

function videochat_iam_king_container_contract_assert_log_event(array $container, string $eventType, string $label): void
{
    foreach ((array) ($container['logs'] ?? []) as $entry) {
        if ((string) ($entry['event_type'] ?? '') === $eventType) {
            return;
        }
    }
    videochat_iam_rejoin_contract_assert(false, 'king container missing log event: ' . $eventType, $label);
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);

    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-king-container');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = (int) $ids['tenant_id'];
    $ownerUserId = (int) $ids['admin_user_id'];
    $organizationId = (int) $ids['organization_id'];
    $registeredUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'king-registered@example.test',
        'King Registered Participant',
        $tenantId,
        $organizationId
    );

    $primaryCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$registeredUserId],
        $tenantId,
        'King Container Primary Call',
        'invite_only'
    );
    $primaryCallId = (string) $primaryCall['call_id'];
    $primaryRoomId = (string) $primaryCall['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $primaryCallId, $registeredUserId, 'allowed');
    $personalGuest = videochat_iam_king_container_contract_personal_guest_session(
        $pdo,
        $primaryCallId,
        $ownerUserId,
        $tenantId,
        $label
    );

    $presenceState = videochat_presence_state_init();
    $startMs = 1_781_000_000_000;
    $owner = videochat_iam_king_container_create('owner', 'owner');
    $registered = videochat_iam_king_container_create('registered', 'registered_user');
    $personalized = videochat_iam_king_container_create('personalized', 'personalized_guest');

    videochat_iam_king_container_join(
        $pdo,
        $presenceState,
        $owner,
        $primaryRoomId,
        $primaryCallId,
        $ownerUserId,
        'King Owner',
        'admin',
        'owner',
        $tenantId,
        $startMs,
        'sess_king_owner'
    );
    videochat_iam_king_container_join(
        $pdo,
        $presenceState,
        $registered,
        $primaryRoomId,
        $primaryCallId,
        $registeredUserId,
        'King Registered Participant',
        'user',
        'participant',
        $tenantId,
        $startMs + 1000,
        'sess_king_registered'
    );
    videochat_iam_king_container_join(
        $pdo,
        $presenceState,
        $personalized,
        $primaryRoomId,
        $primaryCallId,
        (int) $personalGuest['user_id'],
        (string) $personalGuest['display_name'],
        'user',
        'participant',
        $tenantId,
        $startMs + 2000,
        (string) $personalGuest['session_id']
    );

    videochat_iam_rejoin_contract_assert(((array) $owner['identity_state'])['call_role'] === 'owner', 'king owner container should expose owner identity state', $label);
    videochat_iam_rejoin_contract_assert(((array) $registered['identity_state'])['identity_kind'] === 'registered_user', 'king registered container should expose registered identity state', $label);
    videochat_iam_rejoin_contract_assert(((array) $personalized['identity_state'])['identity_kind'] === 'personalized_guest', 'king personalized container should expose personalized guest identity state', $label);

    $initialState = videochat_iam_king_container_call_state($pdo, $presenceState, $registered, $startMs + 3000, 'king_initial_state');
    videochat_iam_rejoin_contract_assert((int) ($initialState['participant_count'] ?? 0) === 3, 'multiple king containers should join the same call', $label);
    videochat_iam_rejoin_contract_assert((string) (($initialState['owner_absence'] ?? [])['status'] ?? '') === 'owner_present', 'king container call state should expose owner-present lifecycle state', $label);

    $ownerFrames = videochat_iam_king_container_stream_dummy_media($owner, 3);
    $ownerFramesAgain = videochat_iam_king_container_dummy_media_frames($owner, 3);
    videochat_iam_rejoin_contract_assert($ownerFrames === $ownerFramesAgain, 'king dummy media frames should be deterministic', $label);
    videochat_iam_rejoin_contract_assert((string) (($owner['media']['mode'] ?? '')) === 'deterministic_dummy_media', 'king dummy media mode should be explicit', $label);

    $gracefulMs = $startMs + 10_000;
    videochat_iam_king_container_graceful_disconnect($pdo, $presenceState, $registered, $gracefulMs);
    videochat_iam_rejoin_contract_assert(!(bool) ($registered['connected'] ?? true), 'king registered container should disconnect gracefully', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_king_container_contract_left_at($pdo, $primaryCallId, $registeredUserId) !== '', 'graceful disconnect should mark participant left_at', $label);

    $reconnectMs = $gracefulMs + 1000;
    videochat_iam_king_container_reconnect_same_identity($pdo, $presenceState, $registered, $reconnectMs);
    videochat_iam_rejoin_contract_assert((int) ($registered['identity_state']['user_id'] ?? 0) === $registeredUserId, 'king reconnect should keep the same identity', $label);
    videochat_iam_rejoin_contract_assert((bool) ($registered['connected'] ?? false), 'king registered container should be connected after same-identity reconnect', $label);

    $abruptMs = $reconnectMs + 10_000;
    videochat_iam_king_container_abrupt_disconnect($pdo, $presenceState, $owner, $abruptMs);
    videochat_iam_king_participant_touch($pdo, (array) $registered['connection'], $abruptMs + 1000);
    $abruptState = videochat_iam_king_container_call_state($pdo, $presenceState, $registered, $abruptMs + 1000, 'king_abrupt_owner_disconnect');
    videochat_iam_rejoin_contract_assert((string) (($abruptState['owner_absence'] ?? [])['status'] ?? '') === 'monitoring', 'king owner abrupt disconnect should start owner-absence monitoring', $label);

    videochat_iam_king_container_reconnect_same_identity($pdo, $presenceState, $owner, $abruptMs + 2000);
    $ownerReturnedState = videochat_iam_king_container_call_state($pdo, $presenceState, $registered, $abruptMs + 3000, 'king_owner_reconnected');
    videochat_iam_rejoin_contract_assert((string) (($ownerReturnedState['owner_absence'] ?? [])['status'] ?? '') === 'owner_present', 'king owner same-identity reconnect should cancel owner absence', $label);

    $networkLastSeenMs = $abruptMs + 5000;
    videochat_iam_king_participant_touch($pdo, (array) $owner['connection'], $networkLastSeenMs);
    videochat_iam_king_participant_touch($pdo, (array) $registered['connection'], $networkLastSeenMs);
    $networkAbsentSinceMs = videochat_iam_king_container_network_loss($owner, $networkLastSeenMs);
    $networkMonitorMs = $networkAbsentSinceMs + 1000;
    videochat_iam_king_participant_touch($pdo, (array) $registered['connection'], $networkMonitorMs);
    $networkState = videochat_iam_king_container_call_state($pdo, $presenceState, $registered, $networkMonitorMs, 'king_owner_network_loss');
    videochat_iam_rejoin_contract_assert((string) (($networkState['owner_absence'] ?? [])['status'] ?? '') === 'monitoring', 'king owner network loss should expose monitoring state', $label);
    videochat_iam_rejoin_contract_assert((int) (($networkState['owner_absence'] ?? [])['absent_since_ms'] ?? 0) === $networkAbsentSinceMs, 'king owner network loss should use stale heartbeat cutoff', $label);

    $countdownMs = $networkAbsentSinceMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, (array) $registered['connection'], $countdownMs);
    $countdownState = videochat_iam_king_container_call_state($pdo, $presenceState, $registered, $countdownMs, 'king_countdown_state');
    videochat_iam_rejoin_contract_assert((string) (($countdownState['owner_absence'] ?? [])['status'] ?? '') === 'countdown', 'king container should expose countdown state', $label);
    videochat_iam_rejoin_contract_assert((int) (($countdownState['owner_absence'] ?? [])['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'king container countdown should expose remaining milliseconds', $label);

    $anonymousCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [],
        $tenantId,
        'King Container Anonymous Call',
        'free_for_all'
    );
    $anonymousSession = videochat_iam_rejoin_contract_issue_open_guest_session(
        $pdo,
        (string) $anonymousCall['call_id'],
        $ownerUserId,
        $tenantId,
        'sess_king_anonymous_guest',
        'King Anonymous Guest',
        $label
    );
    $anonymous = videochat_iam_king_container_create('anonymous', 'anonymous_guest');
    $anonymousPresenceState = videochat_presence_state_init();
    videochat_iam_king_container_join(
        $pdo,
        $anonymousPresenceState,
        $anonymous,
        (string) $anonymousCall['room_id'],
        (string) $anonymousCall['call_id'],
        (int) (($anonymousSession['user'] ?? [])['id'] ?? 0),
        (string) (($anonymousSession['user'] ?? [])['display_name'] ?? 'King Anonymous Guest'),
        'user',
        'participant',
        $tenantId,
        $startMs + 20_000,
        'sess_king_anonymous_guest'
    );
    videochat_iam_rejoin_contract_assert(((array) $anonymous['identity_state'])['identity_kind'] === 'anonymous_guest', 'king anonymous container should expose anonymous identity state', $label);
    $anonymousState = videochat_iam_king_container_call_state($pdo, $anonymousPresenceState, $anonymous, $startMs + 21_000, 'king_anonymous_join');
    videochat_iam_rejoin_contract_assert((int) ($anonymousState['participant_count'] ?? 0) === 1, 'king anonymous container should join an open call', $label);

    $artifactDir = getenv('VIDEOCHAT_KING_PARTICIPANT_ARTIFACT_DIR') ?: sys_get_temp_dir() . '/king-participant-artifacts-' . bin2hex(random_bytes(4));
    $artifactFiles = videochat_iam_king_container_collect_logs([$owner, $registered, $personalized, $anonymous], $artifactDir, 'contract_failure_probe');
    videochat_iam_rejoin_contract_assert(count($artifactFiles) === 5, 'king container log collection should write one log per container plus summary', $label);
    foreach ($artifactFiles as $artifactFile) {
        videochat_iam_rejoin_contract_assert(is_file($artifactFile), 'king container artifact should exist: ' . $artifactFile, $label);
        $contents = (string) file_get_contents($artifactFile);
        videochat_iam_rejoin_contract_assert(!str_contains($contents, '@example.test'), 'king container logs should not expose participant email addresses', $label);
    }

    foreach ([$owner, $registered, $personalized, $anonymous] as $container) {
        videochat_iam_king_container_contract_assert_log_event($container, 'join', $label);
        videochat_iam_king_container_contract_assert_log_event($container, 'media_state', $label);
    }
    videochat_iam_king_container_contract_assert_log_event($owner, 'disconnect', $label);
    videochat_iam_king_container_contract_assert_log_event($owner, 'reconnect', $label);
    videochat_iam_king_container_contract_assert_log_event($owner, 'media_stream', $label);

    $terminateMs = $countdownMs + 30_000;
    videochat_iam_king_container_terminate($pdo, $presenceState, $owner, $terminateMs);
    videochat_iam_king_container_terminate($pdo, $presenceState, $registered, $terminateMs + 1000);
    videochat_iam_king_container_terminate($pdo, $presenceState, $personalized, $terminateMs + 2000);
    videochat_iam_king_container_terminate($pdo, $anonymousPresenceState, $anonymous, $terminateMs + 3000);
    videochat_iam_rejoin_contract_assert((bool) ($owner['terminated'] ?? false), 'king owner container should terminate cleanly', $label);
    videochat_iam_rejoin_contract_assert((bool) ($registered['terminated'] ?? false), 'king registered container should terminate cleanly', $label);
    videochat_iam_rejoin_contract_assert((bool) ($personalized['terminated'] ?? false), 'king personalized container should terminate cleanly', $label);
    videochat_iam_rejoin_contract_assert((bool) ($anonymous['terminated'] ?? false), 'king anonymous container should terminate cleanly', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_king_container_presence_count($pdo, $primaryCallId) === 0, 'clean termination should remove all primary call realtime presence rows', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_king_container_presence_count($pdo, (string) $anonymousCall['call_id']) === 0, 'clean termination should remove anonymous call realtime presence rows', $label);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_king_container_contract_count($pdo, 'SELECT COUNT(*) FROM realtime_presence_connections') === 0,
        'king container proof should leave no realtime presence rows behind',
        $label
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
