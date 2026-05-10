<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_audit_call_access_events_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[audit-call-access-events-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_audit_call_access_events_by_type(array $events): array
{
    $types = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $types[(string) ($event['event_type'] ?? '')][] = $event;
    }

    return $types;
}

function videochat_audit_call_access_events_payload_has_key(mixed $value, string $needle): bool
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
        if (videochat_audit_call_access_events_payload_has_key($entry, $needle)) {
            return true;
        }
    }

    return false;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[audit-call-access-events-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-audit-call-access-events-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_audit_call_access_events_assert($tenantId > 0, 'default tenant should exist');
    videochat_audit_call_access_events_assert($adminUserId > 0, 'admin user should exist');
    videochat_audit_call_access_events_assert($standardUserId > 0, 'standard user should exist');

    $personalTitle = 'Audit Log Completeness Personal Title';
    $createPersonal = videochat_create_call($pdo, $adminUserId, [
        'title' => $personalTitle,
        'starts_at' => '2026-09-18T09:00:00Z',
        'ends_at' => '2026-09-18T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_audit_call_access_events_assert((bool) ($createPersonal['ok'] ?? false), 'personal call should be created');
    $personalCallId = (string) (($createPersonal['call'] ?? [])['id'] ?? '');
    videochat_audit_call_access_events_assert($personalCallId !== '', 'personal call id should be present');

    $personalAccess = videochat_create_call_access_link_for_user($pdo, $personalCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ], $tenantId);
    videochat_audit_call_access_events_assert((bool) ($personalAccess['ok'] ?? false), 'personal access link should be created');
    $personalAccessId = (string) (($personalAccess['access_link'] ?? [])['id'] ?? '');
    videochat_audit_call_access_events_assert($personalAccessId !== '', 'personal access id should be present');

    $matchedSessionId = 'sess_audit_completeness_matched_issue';
    $matchedAuthSessionId = 'sess_audit_completeness_matched_context';
    $matchedSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => $matchedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'audit-call-access-events-contract'],
        [
            'authenticated_user_id' => $standardUserId,
            'authenticated_session_id' => $matchedAuthSessionId,
        ]
    );
    videochat_audit_call_access_events_assert((bool) ($matchedSession['ok'] ?? false), 'matched personal account should receive a session');

    $switchedVerifiedSessionId = 'sess_audit_completeness_switched_verified';
    $switchedAuthSessionId = $switchedVerifiedSessionId;
    $switchedSessionIssuerCalls = 0;
    $switchedSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static function () use (&$switchedSessionIssuerCalls): string {
            $switchedSessionIssuerCalls += 1;
            return 'sess_audit_completeness_switched_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'audit-call-access-events-contract'],
        [
            'verified_user_id' => $standardUserId,
            'verified_session_id' => $switchedVerifiedSessionId,
            'authenticated_user_id' => $adminUserId,
            'authenticated_session_id' => $switchedAuthSessionId,
        ]
    );
    videochat_audit_call_access_events_assert(!(bool) ($switchedSession['ok'] ?? true), 'switched verified account should be denied');
    videochat_audit_call_access_events_assert((string) ($switchedSession['reason'] ?? '') === 'conflict', 'switched verified account denial should be conflict');
    videochat_audit_call_access_events_assert($switchedSessionIssuerCalls === 0, 'switched verified account must not allocate a session id');

    $wrongAuthSessionId = 'sess_audit_completeness_wrong_context';
    $wrongSessionIssuerCalls = 0;
    $wrongSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static function () use (&$wrongSessionIssuerCalls): string {
            $wrongSessionIssuerCalls += 1;
            return 'sess_audit_completeness_wrong_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'audit-call-access-events-contract'],
        [
            'authenticated_user_id' => $adminUserId,
            'authenticated_session_id' => $wrongAuthSessionId,
            'host_name' => 'Audit Completeness Wrong Host Name',
        ]
    );
    videochat_audit_call_access_events_assert(!(bool) ($wrongSession['ok'] ?? true), 'wrong account should be denied');
    videochat_audit_call_access_events_assert((string) ($wrongSession['reason'] ?? '') === 'forbidden', 'wrong account denial should be forbidden');
    videochat_audit_call_access_events_assert($wrongSessionIssuerCalls === 0, 'wrong account must not allocate a session id');

    $personalAccessLink = is_array($personalAccess['access_link'] ?? null) ? $personalAccess['access_link'] : [];
    $personalCall = is_array($createPersonal['call'] ?? null) ? $createPersonal['call'] : [];
    $correctHostName = 'Audit Completeness Correct Host Name';
    $correctHostAttempt = videochat_call_access_record_host_verification_attempt(
        $pdo,
        $personalAccessLink,
        $personalCall,
        $adminUserId,
        $correctHostName,
        'correct_host_name'
    );
    videochat_audit_call_access_events_assert((bool) ($correctHostAttempt['ok'] ?? false), 'correct host-name verification attempt should audit');

    $accountUpdateSessionId = 'sess_audit_completeness_account_update';
    $accountUpdateDisplayName = 'Audit Completeness Account Update Request';
    $accountUpdate = videochat_call_access_request_account_update_confirmation(
        $pdo,
        $personalAccessId,
        $adminUserId,
        ['display_name' => $accountUpdateDisplayName],
        ['session_id' => $accountUpdateSessionId]
    );
    videochat_audit_call_access_events_assert((bool) ($accountUpdate['ok'] ?? false), 'account-update confirmation request should audit');
    $accountUpdateToken = (string) ($accountUpdate['token'] ?? '');

    foreach ([
        'call_access_host_verification_succeeded' => 'correct_host_name',
        'call_access_host_verification_failed' => 'wrong_host_name',
        'call_access_host_name_rejected' => 'wrong_host_name',
    ] as $legacyHostEventType => $hostOutcome) {
        $hostNameVerified = $hostOutcome === 'correct_host_name';
        $canonicalHostEventType = $hostNameVerified
            ? 'call_access_host_name_verified'
            : 'call_access_host_name_verification_failed';
        $hostAliasAudit = videochat_audit_record_event($pdo, [
            'tenant_id' => $tenantId,
            'event_type' => $legacyHostEventType,
            'actor_user_id' => $adminUserId,
            'target_user_id' => $standardUserId,
            'call_id' => $personalCallId,
            'resource_type' => 'call_access_host_verification',
            'resource_fingerprint' => videochat_audit_fingerprint($personalAccessId),
            'payload' => [
                'audit_scope' => 'iam_call_access',
                'action' => 'verify_host_name',
                'outcome' => $hostOutcome,
                'host_name_verified' => $hostNameVerified,
                'canonical_event_type' => $canonicalHostEventType,
                'legacy_event_types' => [$legacyHostEventType],
                'host_name_logged' => false,
                'raw_link_identifier_logged' => false,
                'raw_session_identifier_logged' => false,
                'foreign_account_data_logged' => false,
            ],
        ]);
        videochat_audit_call_access_events_assert((bool) ($hostAliasAudit['ok'] ?? false), "host alias audit should record {$legacyHostEventType}");
    }

    $openTitle = 'Audit Log Completeness Open Link Title';
    $createOpen = videochat_create_call($pdo, $adminUserId, [
        'title' => $openTitle,
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-09-18T11:00:00Z',
        'ends_at' => '2026-09-18T12:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_audit_call_access_events_assert((bool) ($createOpen['ok'] ?? false), 'open call should be created');
    $openCallId = (string) (($createOpen['call'] ?? [])['id'] ?? '');
    videochat_audit_call_access_events_assert($openCallId !== '', 'open call id should be present');

    $openAccess = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_audit_call_access_events_assert((bool) ($openAccess['ok'] ?? false), 'open access link should be created');
    $openAccessId = (string) (($openAccess['access_link'] ?? [])['id'] ?? '');
    videochat_audit_call_access_events_assert($openAccessId !== '', 'open access id should be present');

    $openSessionId = 'sess_audit_completeness_open_guest_issue';
    $openSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => $openSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'audit-call-access-events-contract'],
        ['guest_name' => 'Audit Completeness Open Guest']
    );
    videochat_audit_call_access_events_assert((bool) ($openSession['ok'] ?? false), 'open guest should receive a temporary session');

    $events = videochat_audit_fetch_events($pdo, ['tenant_id' => $tenantId, 'limit' => 200]);
    $eventsByType = videochat_audit_call_access_events_by_type($events);
    foreach ([
        'call_created',
        'call_access_invitation_created',
        'call_access_link_opened',
        'temporary_account_created',
        'call_access_account_compared',
        'call_access_host_name_verified',
        'call_access_host_name_verification_failed',
        'call_access_account_update_confirmation_requested',
        'call_access_strong_mismatch_denied',
    ] as $eventType) {
        videochat_audit_call_access_events_assert(isset($eventsByType[$eventType]), "audit event missing: {$eventType}");
    }
    videochat_audit_call_access_events_assert(count($eventsByType['call_created']) >= 2, 'both call creations should be audited');
    videochat_audit_call_access_events_assert(count($eventsByType['call_access_invitation_created']) >= 2, 'personal and open invitation creation should be audited');

    $callCreatedPayload = (array) (($eventsByType['call_created'][0] ?? [])['payload'] ?? []);
    videochat_audit_call_access_events_assert((string) ($callCreatedPayload['action'] ?? '') === 'create_call', 'call-created action mismatch');
    videochat_audit_call_access_events_assert((bool) ($callCreatedPayload['title_logged'] ?? true) === false, 'call-created audit must not log titles');

    $invitationEvent = $eventsByType['call_access_invitation_created'][0] ?? [];
    $invitationPayload = (array) (($invitationEvent['payload'] ?? []) ?: []);
    videochat_audit_call_access_events_assert((string) ($invitationEvent['resource_id'] ?? '') === '', 'invitation audit must not persist raw access id');
    videochat_audit_call_access_events_assert((string) ($invitationPayload['action'] ?? '') === 'create_invitation', 'invitation-created action mismatch');

    $linkOpenKinds = [];
    foreach ($eventsByType['call_access_link_opened'] ?? [] as $event) {
        $linkOpenKinds[(string) (($event['payload'] ?? [])['link_kind'] ?? '')] = true;
    }
    videochat_audit_call_access_events_assert(isset($linkOpenKinds['personal']) && isset($linkOpenKinds['open']), 'link-open audit should include personal and open links');

    $temporaryPayload = (array) (($eventsByType['temporary_account_created'][0] ?? [])['payload'] ?? []);
    videochat_audit_call_access_events_assert((string) ($temporaryPayload['source'] ?? '') === 'anonymous_call_access_link', 'temporary account audit source mismatch');
    videochat_audit_call_access_events_assert((bool) ($temporaryPayload['raw_guest_identity_logged'] ?? true) === false, 'temporary account audit must not log raw guest identity');

    $comparisonOutcomes = [];
    foreach ($eventsByType['call_access_account_compared'] ?? [] as $event) {
        $comparisonOutcomes[(string) (($event['payload'] ?? [])['comparison_outcome'] ?? '')] = true;
    }
    videochat_audit_call_access_events_assert(isset($comparisonOutcomes['matched']), 'matched account comparison audit missing');
    videochat_audit_call_access_events_assert(isset($comparisonOutcomes['strong_mismatch']), 'strong mismatch account comparison audit missing');
    $hostVerifiedPayload = (array) ((($eventsByType['call_access_host_name_verified'][0] ?? [])['payload'] ?? []));
    videochat_audit_call_access_events_assert((string) ($hostVerifiedPayload['canonical_event_type'] ?? '') === 'call_access_host_name_verified', 'host success alias should persist canonical event marker');
    videochat_audit_call_access_events_assert((bool) ($hostVerifiedPayload['host_name_logged'] ?? true) === false, 'host success alias must not log host name');
    $hostFailedPayload = (array) ((($eventsByType['call_access_host_name_verification_failed'][0] ?? [])['payload'] ?? []));
    videochat_audit_call_access_events_assert((string) ($hostFailedPayload['canonical_event_type'] ?? '') === 'call_access_host_name_verification_failed', 'host failure alias should persist canonical event marker');
    videochat_audit_call_access_events_assert((bool) ($hostFailedPayload['host_name_logged'] ?? true) === false, 'host failure alias must not log host name');

    $accountUpdatePayload = (array) (($eventsByType['call_access_account_update_confirmation_requested'][0] ?? [])['payload'] ?? []);
    videochat_audit_call_access_events_assert((bool) ($accountUpdatePayload['manual_reentry_required'] ?? false), 'account-update audit should require manual re-entry');
    videochat_audit_call_access_events_assert((bool) ($accountUpdatePayload['confirmation_identifier_logged'] ?? true) === false, 'account-update audit must not log confirmation identifiers');
    videochat_audit_call_access_events_assert((bool) ($accountUpdatePayload['session_identifier_logged'] ?? true) === false, 'account-update audit must not log raw session identifiers');
    videochat_audit_call_access_events_assert(!videochat_audit_call_access_events_payload_has_key($accountUpdatePayload, 'confirmation_token'), 'account-update audit must not log confirmation token');

    foreach ([
        'call_access_host_verification_succeeded' => 'call_access_host_name_verified',
        'call_access_host_verification_failed' => 'call_access_host_name_verification_failed',
        'call_access_host_name_rejected' => 'call_access_host_name_verification_failed',
    ] as $legacyHostEventType => $canonicalHostEventType) {
        $aliasEvents = videochat_audit_fetch_events($pdo, [
            'tenant_id' => $tenantId,
            'event_type' => $legacyHostEventType,
            'limit' => 20,
        ]);
        $aliasTypes = array_map(static fn (array $event): string => (string) ($event['event_type'] ?? ''), $aliasEvents);
        videochat_audit_call_access_events_assert(in_array($canonicalHostEventType, $aliasTypes, true), "host alias filter {$legacyHostEventType} should read canonical {$canonicalHostEventType}");
    }

    $strongMismatchPayload = (array) (($eventsByType['call_access_strong_mismatch_denied'][0] ?? [])['payload'] ?? []);
    videochat_audit_call_access_events_assert((string) ($strongMismatchPayload['mismatch'] ?? '') === 'strong_personalized_link', 'strong mismatch audit reason mismatch');
    videochat_audit_call_access_events_assert((string) ($strongMismatchPayload['stage'] ?? '') === 'verified_user_changed', 'strong mismatch audit stage mismatch');
    videochat_audit_call_access_events_assert((bool) ($strongMismatchPayload['host_name_logged'] ?? true) === false, 'strong mismatch audit must not log host names');
    videochat_audit_call_access_events_assert((bool) ($strongMismatchPayload['foreign_account_data_logged'] ?? true) === false, 'strong mismatch audit must not log foreign account data');
    videochat_audit_call_access_events_assert((bool) ($strongMismatchPayload['raw_link_identifier_logged'] ?? true) === false, 'strong mismatch audit must not log raw link identifiers');
    videochat_audit_call_access_events_assert((bool) ($strongMismatchPayload['raw_session_identifier_logged'] ?? true) === false, 'strong mismatch audit must not log raw session identifiers');

    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_call_access_events_assert(is_string($encodedEvents), 'audit events should JSON encode');
    foreach ([
        $personalAccessId,
        $openAccessId,
        $matchedSessionId,
        $matchedAuthSessionId,
        $switchedVerifiedSessionId,
        $switchedAuthSessionId,
        'sess_audit_completeness_switched_should_not_issue',
        $wrongAuthSessionId,
        $accountUpdateSessionId,
        $accountUpdateToken,
        $openSessionId,
        'sess_audit_completeness_wrong_should_not_issue',
        'Audit Completeness Open Guest',
        'Audit Completeness Wrong Host Name',
        $correctHostName,
        $accountUpdateDisplayName,
        $personalTitle,
        $openTitle,
    ] as $forbiddenText) {
        videochat_audit_call_access_events_assert(!str_contains($encodedEvents, $forbiddenText), 'audit events leaked raw value: ' . $forbiddenText);
    }
    foreach ([
        videochat_audit_fingerprint($personalAccessId),
        videochat_audit_fingerprint($openAccessId),
        videochat_audit_fingerprint($matchedAuthSessionId),
        videochat_audit_fingerprint($switchedAuthSessionId),
        videochat_audit_fingerprint($wrongAuthSessionId),
        videochat_audit_fingerprint($accountUpdateSessionId),
    ] as $requiredFingerprint) {
        videochat_audit_call_access_events_assert(str_contains($encodedEvents, $requiredFingerprint), 'audit events missing fingerprint: ' . $requiredFingerprint);
    }
    foreach ($events as $event) {
        $payload = (array) ($event['payload'] ?? []);
        foreach (['access_id', 'session_id', 'token', 'password', 'sdp', 'ice_candidate'] as $forbiddenKey) {
            videochat_audit_call_access_events_assert(
                !videochat_audit_call_access_events_payload_has_key($payload, $forbiddenKey),
                "audit payload should not contain key {$forbiddenKey}"
            );
        }
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[audit-call-access-events-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[audit-call-access-events-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
