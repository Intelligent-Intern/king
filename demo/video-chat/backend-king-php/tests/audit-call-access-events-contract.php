<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';

$label = 'audit-call-access-events-contract';

function videochat_audit_events_contract_event_types(array $events): array
{
    $types = [];
    foreach ($events as $event) {
        if (is_array($event)) {
            $types[(string) ($event['event_type'] ?? '')][] = $event;
        }
    }

    return $types;
}

function videochat_audit_events_contract_payload_has_key(mixed $value, string $needle): bool
{
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return false;
    }

    foreach ($value as $key => $entry) {
        if ((string) $key === $needle) {
            return true;
        }
        if (videochat_audit_events_contract_payload_has_key($entry, $needle)) {
            return true;
        }
    }

    return false;
}

function videochat_audit_events_contract_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND call_role = 'owner'
SQL
    );
    $query->execute([':call_id' => $callId]);

    return (int) $query->fetchColumn();
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    $outboxPath = sys_get_temp_dir() . '/videochat-audit-call-access-events-outbox-' . bin2hex(random_bytes(6)) . '.log';
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_FORCE_OUTBOX=1');
    putenv('VIDEOCHAT_EMAIL_OUTBOX_PATH=' . $outboxPath);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-audit-call-access-events');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $ownerUserId = $ids['admin_user_id'];
    $organizationId = $ids['organization_id'];
    $participantUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'audit-events-participant@example.test',
        'Audit Events Participant',
        $tenantId,
        $organizationId
    );
    $nextOwnerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'audit-events-next-owner@example.test',
        'Audit Events Next Owner',
        $tenantId,
        $organizationId
    );
    $waitingUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'audit-events-waiting@example.test',
        'Audit Events Waiting',
        $tenantId,
        $organizationId
    );
    $wrongUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'audit-events-wrong-account@example.test',
        'Audit Events Wrong Account',
        $tenantId,
        $organizationId
    );

    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$participantUserId, $nextOwnerUserId],
        $tenantId,
        'IAM Audit Events Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $participantUserId, 'allowed');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $participantUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($access['ok'] ?? false), 'personal access link should be created', $label);
    $accessLink = is_array($access['access_link'] ?? null) ? $access['access_link'] : [];
    $accessId = (string) ($accessLink['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personal access id should be present', $label);

    $openCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [],
        $tenantId,
        'IAM Audit Events Anonymous Open Link',
        'free_for_all'
    );
    $openGuest = videochat_iam_rejoin_contract_issue_open_guest_session(
        $pdo,
        $openCall['call_id'],
        $ownerUserId,
        $tenantId,
        'sess_audit_events_open_guest',
        'Audit Events Open Guest',
        $label
    );
    $openAccessId = (string) (($openGuest['access_link'] ?? [])['id'] ?? '');
    $openGuestUserId = (int) (($openGuest['user'] ?? [])['id'] ?? 0);
    videochat_iam_rejoin_contract_assert($openAccessId !== '' && $openGuestUserId > 0, 'anonymous open link should create access and temporary account audit context', $label);

    $participantAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $participantUserId,
        $tenantId,
        'sess_audit_events_participant',
        $label
    );
    $wrongAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $wrongUserId,
        $tenantId,
        'sess_audit_events_wrong_account',
        $label
    );
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key] ??= [];
        $frames[$key][] = $payload;
        return true;
    };
    $openDatabase = static fn (): PDO => $pdo;

    $ownerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'Audit Events Owner',
        'admin',
        'audit-owner',
        $tenantId
    );
    $participantConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'Audit Events Participant',
        'user',
        'audit-participant-join',
        $tenantId,
        true,
        'sess_audit_events_participant'
    );
    videochat_audit_record_call_participant_joined($pdo, $tenantId, $callId, $participantUserId, $participantUserId, [
        'room_id' => $roomId,
        'session_id' => 'sess_audit_events_participant',
        'call_role' => 'participant',
        'reason' => 'direct_join',
    ]);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $participantUserId) === '',
        'joined participant should be active before leave',
        $label
    );

    videochat_presence_remove_connection($presenceState, (string) ($participantConnection['connection_id'] ?? ''), $sender);
    videochat_realtime_remove_call_presence($openDatabase, $participantConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $participantConnection, $presenceState);
    videochat_audit_record_call_participant_left($pdo, $tenantId, $callId, $participantUserId, $participantUserId, [
        'room_id' => $roomId,
        'session_id' => 'sess_audit_events_participant',
        'call_role' => 'participant',
        'reason' => 'explicit_leave',
    ]);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $participantUserId) !== '',
        'leave should mark participant left_at before rejoin',
        $label
    );

    videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'Audit Events Participant',
        'user',
        'audit-participant-rejoin',
        $tenantId,
        true,
        'sess_audit_events_participant'
    );
    videochat_audit_record_call_participant_rejoined($pdo, $tenantId, $callId, $participantUserId, $participantUserId, [
        'room_id' => $roomId,
        'session_id' => 'sess_audit_events_participant',
        'call_role' => 'participant',
        'reason' => 'same_session_rejoin',
    ]);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $participantUserId) === '',
        'rejoin should clear stale left_at',
        $label
    );

    videochat_iam_rejoin_contract_admit_user($lobbyState, $roomId, $waitingUserId, 'Audit Events Waiting');
    $ownerKick = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        videochat_iam_rejoin_contract_lobby_command('lobby/kick', $roomId, $waitingUserId, $label),
        $sender
    );
    videochat_iam_rejoin_contract_assert((bool) ($ownerKick['ok'] ?? false), 'owner should kick admitted user before audit', $label);
    videochat_audit_record_call_participant_kicked($pdo, $tenantId, $callId, $ownerUserId, $waitingUserId, [
        'room_id' => $roomId,
        'lobby_action' => 'lobby/kick',
        'previous_state' => 'admitted',
    ]);

    $ownerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $nextOwnerUserId,
        'owner',
        $ownerUserId,
        'admin',
        $tenantId
    );
    videochat_iam_rejoin_contract_assert((bool) ($ownerTransfer['ok'] ?? false), 'owner transfer should succeed before audit', $label);
    videochat_iam_rejoin_contract_assert(videochat_audit_events_contract_owner_count($pdo, $callId) === 1, 'owner transfer should leave exactly one owner', $label);
    videochat_audit_record_call_owner_transferred($pdo, $tenantId, $callId, $ownerUserId, $ownerUserId, $nextOwnerUserId, [
        'actor_role' => 'admin',
        'old_owner_admin_preserved' => true,
    ]);

    $targetUser = ['id' => $participantUserId];
    $callPayload = ['id' => $callId, 'room_id' => $roomId, 'tenant_id' => $tenantId];
    $wrongSessionId = 'sess_audit_events_wrong_account';
    $deniedSessionId = 'sess_audit_events_denied_should_not_issue';
    $wrongHostName = 'Audit Events Wrong Host Secret';
    $strongMismatch = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $deniedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        [
            'authenticated_user_id' => (int) (($wrongAuth['user']['id'] ?? null) ?: $wrongUserId),
            'authenticated_session_id' => $wrongSessionId,
            'host_name' => $wrongHostName,
        ]
    );
    videochat_iam_rejoin_contract_assert(
        !(bool) ($strongMismatch['ok'] ?? true) && (string) ($strongMismatch['reason'] ?? '') === 'forbidden',
        'strong mismatch should deny session issuance before audit',
        $label
    );
    videochat_audit_record_call_access_strong_mismatch($pdo, $accessLink, $callPayload, $targetUser, $wrongUserId, 'session_host_verification', [
        'session_id' => $wrongSessionId,
        'denial_reason' => 'wrong_host_name',
        'host_name_verified' => false,
    ]);
    $correctHostName = 'Audit Events Owner';
    $correctHostAttempt = videochat_call_access_record_host_verification_attempt($pdo, $accessLink, $callPayload, $wrongUserId, $correctHostName, 'correct_host_name');
    videochat_iam_rejoin_contract_assert((bool) ($correctHostAttempt['ok'] ?? false), 'correct host-name verification attempt should audit', $label);

    $matchedIssue = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => 'sess_audit_events_matched_link',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        [
            'authenticated_user_id' => $participantUserId,
            'authenticated_session_id' => 'sess_audit_events_participant',
            'verified_user_id' => $participantUserId,
            'verified_session_id' => 'sess_audit_events_participant',
        ]
    );
    videochat_iam_rejoin_contract_assert((bool) ($matchedIssue['ok'] ?? false), 'matched personalized account should issue before matched comparison audit', $label);

    $accountUpdate = videochat_call_access_request_account_update_confirmation(
        $pdo,
        $accessId,
        $wrongUserId,
        ['display_name' => 'Audit Events Account Update Request'],
        ['session_id' => $wrongSessionId, 'frontend_origin' => 'https://app.kingrt.test']
    );
    videochat_iam_rejoin_contract_assert((bool) ($accountUpdate['ok'] ?? false), 'account-update confirmation request should be accepted before audit fetch', $label);

    videochat_iam_rejoin_contract_disable_tenant_membership($pdo, $tenantId, $participantUserId);
    videochat_iam_rejoin_contract_assert(
        !videochat_tenant_user_is_member($pdo, $participantUserId, $tenantId),
        'membership removal should revoke tenant membership before audit',
        $label
    );
    videochat_audit_record_membership_removal($pdo, $tenantId, $participantUserId, $ownerUserId, [
        'removed_scopes' => ['tenant', 'organization', 'group'],
        'call_id' => $callId,
        'access_id' => $accessId,
        'call_scoped_invitation_preserved' => true,
    ]);

    $inviteInvalidationSessionId = 'sess_audit_events_invite_invalidation_should_not_leak';
    $inviteInvalidation = videochat_invalidate_call_access_invitation($pdo, $accessId, 'cancelled', $ownerUserId, [
        'session_id' => $inviteInvalidationSessionId,
        'invalidation_reason' => 'audit_contract_invite_cancelled',
    ]);
    videochat_iam_rejoin_contract_assert((bool) ($inviteInvalidation['ok'] ?? false), 'invite invalidation should write an audit event', $label);
    videochat_iam_rejoin_contract_assert(videochat_call_access_link_is_invalidated($pdo, $accessLink), 'invite should be invalidated before audit fetch', $label);

    $events = videochat_audit_fetch_events($pdo, ['tenant_id' => $tenantId, 'limit' => 200]);
    $eventsByType = videochat_audit_events_contract_event_types($events);
    foreach ([
        'call_created',
        'call_access_invitation_created',
        'call_access_link_opened',
        'temporary_account_created',
        'call_access_account_compared',
        'call_access_duplicate_personalized_link_review',
        'call_access_strong_mismatch_denied',
        'call_access_host_name_verified',
        'call_access_host_name_verification_failed',
        'call_access_account_update_confirmation_requested',
        'call_access_invitation_invalidated',
        'call_participant_joined',
        'call_participant_left',
        'call_participant_rejoined',
        'call_participant_kicked',
        'call_owner_transferred',
        'membership_removed',
    ] as $eventType) {
        videochat_iam_rejoin_contract_assert(isset($eventsByType[$eventType]), "audit event missing: {$eventType}", $label);
    }
    $callCreatedPayload = (array) (($eventsByType['call_created'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($callCreatedPayload['action'] ?? '') === 'create_call', 'call creation audit action mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($callCreatedPayload['title_logged'] ?? true) === false, 'call creation audit must not log title', $label);
    $invitationCreatedEvent = $eventsByType['call_access_invitation_created'][0] ?? [];
    $invitationCreatedPayload = (array) (($invitationCreatedEvent['payload'] ?? []) ?: []);
    videochat_iam_rejoin_contract_assert((string) ($invitationCreatedEvent['resource_id'] ?? '') === '', 'invitation creation audit must not persist raw access id', $label);
    videochat_iam_rejoin_contract_assert((string) ($invitationCreatedPayload['action'] ?? '') === 'create_invitation', 'invitation creation audit action mismatch', $label);
    $linkOpenKinds = [];
    foreach ($eventsByType['call_access_link_opened'] ?? [] as $event) {
        $linkOpenKinds[(string) (($event['payload'] ?? [])['link_kind'] ?? '')] = true;
    }
    videochat_iam_rejoin_contract_assert(isset($linkOpenKinds['personal']) && isset($linkOpenKinds['open']), 'link-open audit should include personalized and anonymous/open links', $label);
    $temporaryPayload = (array) (($eventsByType['temporary_account_created'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($temporaryPayload['source'] ?? '') === 'anonymous_call_access_link', 'temporary account audit source mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($temporaryPayload['raw_guest_identity_logged'] ?? true) === false, 'temporary account audit must not log raw guest identity', $label);
    $comparisonOutcomes = [];
    foreach ($eventsByType['call_access_account_compared'] ?? [] as $event) {
        $comparisonOutcomes[(string) (($event['payload'] ?? [])['comparison_outcome'] ?? '')] = true;
    }
    videochat_iam_rejoin_contract_assert(isset($comparisonOutcomes['strong_mismatch']) && isset($comparisonOutcomes['matched']), 'account comparison audit should include mismatch and matched outcomes', $label);
    $hostVerifiedPayload = (array) (($eventsByType['call_access_host_name_verified'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($hostVerifiedPayload['outcome'] ?? '') === 'correct_host_name', 'successful host-name audit outcome mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($hostVerifiedPayload['host_name_logged'] ?? true) === false, 'successful host-name audit must not log host name', $label);
    $accountUpdatePayload = (array) (($eventsByType['call_access_account_update_confirmation_requested'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((bool) ($accountUpdatePayload['manual_reentry_required'] ?? false), 'account-update audit should require manual re-entry', $label);
    videochat_iam_rejoin_contract_assert(!videochat_audit_events_contract_payload_has_key($accountUpdatePayload, 'confirmation_token'), 'account-update audit must not log confirmation token', $label);

    $rejoinPayload = (array) (($eventsByType['call_participant_rejoined'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((bool) ($rejoinPayload['rejoin'] ?? false), 'rejoin audit should mark rejoin=true', $label);
    $kickEvent = $eventsByType['call_participant_kicked'][0] ?? [];
    videochat_iam_rejoin_contract_assert((int) ($kickEvent['actor_user_id'] ?? 0) === $ownerUserId, 'kick audit actor mismatch', $label);
    videochat_iam_rejoin_contract_assert((int) ($kickEvent['target_user_id'] ?? 0) === $waitingUserId, 'kick audit target mismatch', $label);
    videochat_iam_rejoin_contract_assert((string) (($kickEvent['payload'] ?? [])['action'] ?? '') === 'kick', 'kick audit action mismatch', $label);
    $ownerTransferPayload = (array) (($eventsByType['call_owner_transferred'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((bool) ($ownerTransferPayload['exactly_one_owner_required'] ?? false), 'owner transfer audit should pin exactly one owner', $label);
    videochat_iam_rejoin_contract_assert((int) ($ownerTransferPayload['new_owner_user_id'] ?? 0) === $nextOwnerUserId, 'owner transfer audit new owner mismatch', $label);
    $mismatchPayload = (array) (($eventsByType['call_access_strong_mismatch_denied'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($mismatchPayload['mismatch'] ?? '') === 'strong_personalized_link', 'strong mismatch audit reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($mismatchPayload['host_name_logged'] ?? true) === false, 'strong mismatch audit must not log host name', $label);
    videochat_iam_rejoin_contract_assert((bool) ($mismatchPayload['foreign_account_data_logged'] ?? true) === false, 'strong mismatch audit must not log foreign data', $label);
    $membershipPayload = (array) (($eventsByType['membership_removed'][0] ?? [])['payload'] ?? []);
    videochat_iam_rejoin_contract_assert((bool) ($membershipPayload['call_scoped_invitation_preserved'] ?? false), 'membership removal audit should preserve call-scoped invitation marker', $label);
    videochat_iam_rejoin_contract_assert((bool) ($membershipPayload['organization_rights_preserved'] ?? true) === false, 'membership removal audit must not preserve org rights', $label);
    $inviteInvalidationEvent = $eventsByType['call_access_invitation_invalidated'][0] ?? [];
    $inviteInvalidationPayload = (array) (($inviteInvalidationEvent['payload'] ?? null) ?: []);
    videochat_iam_rejoin_contract_assert((string) ($inviteInvalidationEvent['resource_id'] ?? '') === '', 'invite invalidation audit must not persist raw access id', $label);
    videochat_iam_rejoin_contract_assert((string) ($inviteInvalidationPayload['action'] ?? '') === 'invalidate_invitation', 'invite invalidation audit action mismatch', $label);
    videochat_iam_rejoin_contract_assert((string) ($inviteInvalidationPayload['invalidation_reason'] ?? '') === 'audit_contract_invite_cancelled', 'invite invalidation audit reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((string) ($inviteInvalidationPayload['invite_state'] ?? '') === 'cancelled', 'invite invalidation audit state mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($inviteInvalidationPayload['raw_link_identifier_logged'] ?? true) === false, 'invite invalidation audit must not log raw link id', $label);
    videochat_iam_rejoin_contract_assert((bool) ($inviteInvalidationPayload['raw_credential_identifier_logged'] ?? true) === false, 'invite invalidation audit must not log raw session data', $label);
    videochat_iam_rejoin_contract_assert((bool) ($inviteInvalidationPayload['raw_guest_identity_logged'] ?? true) === false, 'invite invalidation audit must not log raw guest data', $label);

    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_iam_rejoin_contract_assert(is_string($encodedEvents), 'audit events should JSON encode', $label);
    foreach ([
        $accessId,
        $wrongSessionId,
        $deniedSessionId,
        $inviteInvalidationSessionId,
        'sess_audit_events_open_guest',
        'sess_audit_events_matched_link',
        $wrongHostName,
        $correctHostName,
        $openAccessId,
    ] as $forbiddenText) {
        videochat_iam_rejoin_contract_assert(!str_contains($encodedEvents, $forbiddenText), 'audit events leaked raw value: ' . $forbiddenText, $label);
    }
    foreach ([
        videochat_audit_fingerprint($accessId),
        videochat_audit_fingerprint($wrongSessionId),
        videochat_audit_fingerprint($inviteInvalidationSessionId),
        videochat_audit_fingerprint($openAccessId),
        videochat_audit_fingerprint($callId . ':' . $participantUserId),
    ] as $requiredFingerprint) {
        videochat_iam_rejoin_contract_assert(str_contains($encodedEvents, $requiredFingerprint), 'audit events missing fingerprint: ' . $requiredFingerprint, $label);
    }
    foreach ($events as $event) {
        $payload = (array) ($event['payload'] ?? []);
        foreach (['access_id', 'session_id', 'token', 'password', 'sdp', 'ice_candidate'] as $forbiddenKey) {
            videochat_iam_rejoin_contract_assert(
                !videochat_audit_events_contract_payload_has_key($payload, $forbiddenKey),
                "audit payload should not contain key {$forbiddenKey}",
                $label
            );
        }
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    putenv('VIDEOCHAT_CALL_ACCESS_ACCOUNT_CONFIRMATION_FORCE_OUTBOX');
    putenv('VIDEOCHAT_EMAIL_OUTBOX_PATH');
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
    if (isset($outboxPath) && is_string($outboxPath) && is_file($outboxPath)) {
        @unlink($outboxPath);
    }
}
