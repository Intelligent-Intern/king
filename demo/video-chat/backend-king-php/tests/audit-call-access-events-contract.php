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

    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_audit_call_access_events_assert(is_string($encodedEvents), 'audit events should JSON encode');
    foreach ([
        $personalAccessId,
        $openAccessId,
        $matchedSessionId,
        $matchedAuthSessionId,
        $wrongAuthSessionId,
        $openSessionId,
        'sess_audit_completeness_wrong_should_not_issue',
        'Audit Completeness Open Guest',
        'Audit Completeness Wrong Host Name',
        $personalTitle,
        $openTitle,
    ] as $forbiddenText) {
        videochat_audit_call_access_events_assert(!str_contains($encodedEvents, $forbiddenText), 'audit events leaked raw value: ' . $forbiddenText);
    }
    foreach ([
        videochat_audit_fingerprint($personalAccessId),
        videochat_audit_fingerprint($openAccessId),
        videochat_audit_fingerprint($matchedAuthSessionId),
        videochat_audit_fingerprint($wrongAuthSessionId),
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
