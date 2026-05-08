<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/calls/call_guest_lifecycle.php';

function videochat_call_guest_lifecycle_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-guest-lifecycle-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_guest_lifecycle_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, password_hash, status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_call_guest_lifecycle_cleanup_events(PDO $pdo, int $tenantId, string $callId): array
{
    return videochat_audit_fetch_events($pdo, [
        'tenant_id' => $tenantId,
        'call_id' => $callId,
        'event_type' => 'guest_account_cleanup',
        'limit' => 20,
    ]);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-guest-lifecycle-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-guest-lifecycle-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $registeredUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_guest_lifecycle_assert($tenantId > 0 && $adminUserId > 0 && $registeredUserId > 0, 'fixture ids missing');

    $registeredBefore = videochat_call_guest_lifecycle_user($pdo, $registeredUserId);
    videochat_call_guest_lifecycle_assert((string) ($registeredBefore['status'] ?? '') === 'active', 'registered fixture must start active');

    $createPersonalCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Guest Lifecycle Personal Link',
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($createPersonalCall['ok'] ?? false), 'personal call should be created');
    $personalCallId = (string) (($createPersonalCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_lifecycle_assert($personalCallId !== '', 'personal call id missing');

    $personalGuestCreate = videochat_create_guest_user_for_call_access($pdo, 'Personal Guest', $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($personalGuestCreate['ok'] ?? false), 'personal guest should be created');
    $personalGuest = is_array($personalGuestCreate['user'] ?? null) ? $personalGuestCreate['user'] : [];
    $personalGuestId = (int) ($personalGuest['id'] ?? 0);
    videochat_call_guest_lifecycle_assert($personalGuestId > 0 && (bool) ($personalGuest['is_guest'] ?? false), 'personal guest user missing');
    videochat_ensure_internal_call_participant(
        $pdo,
        $personalCallId,
        $personalGuestId,
        (string) ($personalGuest['email'] ?? ''),
        (string) ($personalGuest['display_name'] ?? ''),
        'allowed'
    );

    $personalGuestAccess = videochat_create_call_access_link_for_user($pdo, $personalCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $personalGuestId,
    ], $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($personalGuestAccess['ok'] ?? false), 'personal guest access link should be created');
    $personalGuestAccessId = (string) (($personalGuestAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_guest_lifecycle_assert($personalGuestAccessId !== '', 'personal guest access id missing');

    $registeredAccess = videochat_create_call_access_link_for_user($pdo, $personalCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $registeredUserId,
    ], $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($registeredAccess['ok'] ?? false), 'registered personal access link should be created');
    $registeredAccessId = (string) (($registeredAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_guest_lifecycle_assert($registeredAccessId !== '', 'registered access id missing');

    $personalGuestSessionId = 'sess_guest_lifecycle_personal_guest';
    $personalGuestSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalGuestAccessId,
        static fn (): string => $personalGuestSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-lifecycle-personal']
    );
    videochat_call_guest_lifecycle_assert((bool) ($personalGuestSession['ok'] ?? false), 'personal guest session should be issued before cleanup');
    videochat_call_guest_lifecycle_assert((int) (($personalGuestSession['user'] ?? [])['id'] ?? 0) === $personalGuestId, 'personal guest session user mismatch');

    $registeredSessionId = 'sess_guest_lifecycle_registered';
    $registeredSession = videochat_issue_session_for_call_access(
        $pdo,
        $registeredAccessId,
        static fn (): string => $registeredSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-lifecycle-registered']
    );
    videochat_call_guest_lifecycle_assert((bool) ($registeredSession['ok'] ?? false), 'registered session should be issued before cleanup');
    videochat_call_guest_lifecycle_assert((int) (($registeredSession['user'] ?? [])['id'] ?? 0) === $registeredUserId, 'registered session user mismatch');

    $personalCleanup = videochat_invalidate_guest_accounts_for_call($pdo, $personalCallId, $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($personalCleanup['ok'] ?? false), 'personal call guest cleanup should succeed');
    videochat_call_guest_lifecycle_assert(in_array($personalGuestId, $personalCleanup['guest_user_ids'] ?? [], true), 'personal cleanup should include guest user');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanup['invalidated_guests'] ?? 0) === 1, 'personal cleanup should invalidate exactly one guest');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanup['revoked_sessions'] ?? 0) === 1, 'personal cleanup should revoke stale guest session');
    videochat_call_guest_lifecycle_assert((bool) (((($personalCleanup['audit_events'] ?? [])[0] ?? [])['ok'] ?? false)), 'personal cleanup should return successful audit metadata');

    $personalCleanupEvents = videochat_call_guest_lifecycle_cleanup_events($pdo, $tenantId, $personalCallId);
    videochat_call_guest_lifecycle_assert(count($personalCleanupEvents) === 1, 'personal cleanup should persist one audit event');
    $personalCleanupPayload = is_array(($personalCleanupEvents[0] ?? [])['payload'] ?? null) ? $personalCleanupEvents[0]['payload'] : [];
    videochat_call_guest_lifecycle_assert((string) ($personalCleanupPayload['cleanup_result'] ?? '') === 'invalidated', 'personal cleanup audit result mismatch');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanupPayload['guest_user_count'] ?? 0) === 1, 'personal cleanup audit guest count mismatch');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanupPayload['invalidated_guest_count'] ?? 0) === 1, 'personal cleanup audit invalidated count mismatch');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanupPayload['revoked_session_count'] ?? 0) === 1, 'personal cleanup audit revoked session count mismatch');
    videochat_call_guest_lifecycle_assert((bool) ($personalCleanupPayload['had_effect'] ?? false), 'personal cleanup audit should show destructive effect');
    videochat_call_guest_lifecycle_assert((bool) ($personalCleanupPayload['idempotent_safe'] ?? false), 'personal cleanup audit should document idempotent-safe behavior');

    $personalGuestAfterCleanup = videochat_call_guest_lifecycle_user($pdo, $personalGuestId);
    videochat_call_guest_lifecycle_assert((string) ($personalGuestAfterCleanup['status'] ?? '') === 'disabled', 'personal guest must remain invalidated after cleanup');

    $stalePersonalAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $personalGuestSessionId . '&room=' . $personalCallId . '&call_id=' . $personalCallId,
            'headers' => ['Authorization' => 'Bearer ' . $personalGuestSessionId],
        ],
        'websocket'
    );
    videochat_call_guest_lifecycle_assert(!(bool) ($stalePersonalAuth['ok'] ?? true), 'stale personalized guest browser session must not authenticate');

    $stalePersonalLink = videochat_issue_session_for_call_access(
        $pdo,
        $personalGuestAccessId,
        static fn (): string => 'sess_guest_lifecycle_personal_revival_attempt',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-lifecycle-personal-retry']
    );
    videochat_call_guest_lifecycle_assert(!(bool) ($stalePersonalLink['ok'] ?? true), 'stale personalized link must not revive invalidated guest');
    videochat_call_guest_lifecycle_assert((string) ($stalePersonalLink['reason'] ?? '') === 'not_found', 'stale personalized link should fail as missing inactive target');
    $personalGuestAfterRetry = videochat_call_guest_lifecycle_user($pdo, $personalGuestId);
    videochat_call_guest_lifecycle_assert((string) ($personalGuestAfterRetry['status'] ?? '') === 'disabled', 'personal guest must stay disabled after stale link retry');

    $personalCleanupRepeat = videochat_invalidate_guest_accounts_for_call($pdo, $personalCallId, $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($personalCleanupRepeat['ok'] ?? false), 'repeat personal cleanup should succeed');
    videochat_call_guest_lifecycle_assert((string) ($personalCleanupRepeat['reason'] ?? '') === 'no_guest_accounts', 'repeat personal cleanup should be a no-op');
    videochat_call_guest_lifecycle_assert((array) ($personalCleanupRepeat['guest_user_ids'] ?? []) === [], 'repeat personal cleanup should not rediscover disabled guests');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanupRepeat['invalidated_guests'] ?? -1) === 0, 'repeat personal cleanup should not invalidate again');
    videochat_call_guest_lifecycle_assert((int) ($personalCleanupRepeat['revoked_sessions'] ?? -1) === 0, 'repeat personal cleanup should not revoke sessions again');
    videochat_call_guest_lifecycle_assert((bool) (((($personalCleanupRepeat['audit_events'] ?? [])[0] ?? [])['ok'] ?? false)), 'repeat personal cleanup should still be audit-logged');
    videochat_call_guest_lifecycle_assert((string) (videochat_call_guest_lifecycle_user($pdo, $personalGuestId)['status'] ?? '') === 'disabled', 'repeat cleanup must keep guest disabled');
    $personalSessionRevokedCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = " . $pdo->quote($personalGuestSessionId) . " AND revoked_at IS NOT NULL AND revoked_at <> ''")->fetchColumn();
    videochat_call_guest_lifecycle_assert($personalSessionRevokedCount === 1, 'repeat cleanup must leave exactly one revoked guest session row');

    $personalCleanupEventsAfterRepeat = videochat_call_guest_lifecycle_cleanup_events($pdo, $tenantId, $personalCallId);
    videochat_call_guest_lifecycle_assert(count($personalCleanupEventsAfterRepeat) === 2, 'repeat personal cleanup should append exactly one audit event');
    $repeatPayload = is_array(($personalCleanupEventsAfterRepeat[1] ?? [])['payload'] ?? null) ? $personalCleanupEventsAfterRepeat[1]['payload'] : [];
    videochat_call_guest_lifecycle_assert((string) ($repeatPayload['cleanup_result'] ?? '') === 'no_guest_accounts', 'repeat cleanup audit result mismatch');
    videochat_call_guest_lifecycle_assert((int) ($repeatPayload['guest_user_count'] ?? -1) === 0, 'repeat cleanup audit guest count mismatch');
    videochat_call_guest_lifecycle_assert((int) ($repeatPayload['invalidated_guest_count'] ?? -1) === 0, 'repeat cleanup audit invalidated count mismatch');
    videochat_call_guest_lifecycle_assert((int) ($repeatPayload['revoked_session_count'] ?? -1) === 0, 'repeat cleanup audit revoked session count mismatch');
    videochat_call_guest_lifecycle_assert(!(bool) ($repeatPayload['had_effect'] ?? true), 'repeat cleanup audit should show no destructive effect');
    videochat_call_guest_lifecycle_assert((bool) ($repeatPayload['idempotent_safe'] ?? false), 'repeat cleanup audit should document idempotent-safe behavior');

    $encodedPersonalCleanupEvents = json_encode($personalCleanupEventsAfterRepeat, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_guest_lifecycle_assert(is_string($encodedPersonalCleanupEvents), 'personal cleanup audit events should encode');
    foreach ([$personalGuestSessionId, $personalGuestAccessId, (string) ($personalGuest['email'] ?? '')] as $forbiddenAuditText) {
        if ($forbiddenAuditText === '') {
            continue;
        }
        videochat_call_guest_lifecycle_assert(
            !str_contains($encodedPersonalCleanupEvents, $forbiddenAuditText),
            'guest cleanup audit must not leak raw guest/session/access identifiers: ' . $forbiddenAuditText
        );
    }
    videochat_call_guest_lifecycle_assert(
        str_contains($encodedPersonalCleanupEvents, videochat_audit_fingerprint($personalCallId)),
        'guest cleanup audit should retain call fingerprint for audit correlation'
    );

    $registeredAfterPersonalCleanup = videochat_call_guest_lifecycle_user($pdo, $registeredUserId);
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterPersonalCleanup['status'] ?? '') === 'active', 'guest cleanup must not disable registered user');
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterPersonalCleanup['display_name'] ?? '') === (string) ($registeredBefore['display_name'] ?? ''), 'guest cleanup must not alter registered profile');
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterPersonalCleanup['password_hash'] ?? '') === (string) ($registeredBefore['password_hash'] ?? ''), 'guest cleanup must not alter registered password hash');

    $registeredAuthAfterCleanup = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $registeredSessionId . '&room=' . $personalCallId . '&call_id=' . $personalCallId,
            'headers' => ['Authorization' => 'Bearer ' . $registeredSessionId],
        ],
        'websocket'
    );
    videochat_call_guest_lifecycle_assert((bool) ($registeredAuthAfterCleanup['ok'] ?? false), 'registered call-access session must survive guest cleanup');

    $createOpenCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Guest Lifecycle Open Link',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-02T09:00:00Z',
        'ends_at' => '2026-10-02T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($createOpenCall['ok'] ?? false), 'open call should be created');
    $openCallId = (string) (($createOpenCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_lifecycle_assert($openCallId !== '', 'open call id missing');

    $openAccess = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ], $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($openAccess['ok'] ?? false), 'open access link should be created');
    $openAccessId = (string) (($openAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_guest_lifecycle_assert($openAccessId !== '', 'open access id missing');

    $oldOpenSessionId = 'sess_guest_lifecycle_open_old';
    $oldOpenSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => $oldOpenSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-lifecycle-open-old'],
        ['guest_name' => 'Open Guest']
    );
    videochat_call_guest_lifecycle_assert((bool) ($oldOpenSession['ok'] ?? false), 'open guest session should be issued before cleanup');
    $oldOpenGuestId = (int) (($oldOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_call_guest_lifecycle_assert($oldOpenGuestId > 0 && (bool) (($oldOpenSession['user'] ?? [])['is_guest'] ?? false), 'old open guest user missing');

    $openCleanup = videochat_invalidate_guest_accounts_for_call($pdo, $openCallId, $tenantId);
    videochat_call_guest_lifecycle_assert((bool) ($openCleanup['ok'] ?? false), 'open call guest cleanup should succeed');
    videochat_call_guest_lifecycle_assert(in_array($oldOpenGuestId, $openCleanup['guest_user_ids'] ?? [], true), 'open cleanup should include old open guest');
    videochat_call_guest_lifecycle_assert((int) ($openCleanup['invalidated_guests'] ?? 0) === 1, 'open cleanup should invalidate exactly one guest');
    videochat_call_guest_lifecycle_assert((bool) (((($openCleanup['audit_events'] ?? [])[0] ?? [])['ok'] ?? false)), 'open cleanup should return successful audit metadata');

    $staleOpenAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $oldOpenSessionId . '&room=' . $openCallId . '&call_id=' . $openCallId,
            'headers' => ['Authorization' => 'Bearer ' . $oldOpenSessionId],
        ],
        'websocket'
    );
    videochat_call_guest_lifecycle_assert(!(bool) ($staleOpenAuth['ok'] ?? true), 'stale open-link guest browser session must not authenticate');

    $newOpenSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        static fn (): string => 'sess_guest_lifecycle_open_new',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-lifecycle-open-new'],
        ['guest_name' => 'Open Guest']
    );
    videochat_call_guest_lifecycle_assert((bool) ($newOpenSession['ok'] ?? false), 'open link may create a fresh guest after cleanup');
    $newOpenGuestId = (int) (($newOpenSession['user'] ?? [])['id'] ?? 0);
    videochat_call_guest_lifecycle_assert($newOpenGuestId > 0 && $newOpenGuestId !== $oldOpenGuestId, 'stale open link must not revive the old guest account');
    videochat_call_guest_lifecycle_assert((string) (videochat_call_guest_lifecycle_user($pdo, $oldOpenGuestId)['status'] ?? '') === 'disabled', 'old open guest must remain disabled after open link reuse');
    videochat_call_guest_lifecycle_assert((string) (videochat_call_guest_lifecycle_user($pdo, $newOpenGuestId)['status'] ?? '') === 'active', 'fresh open guest must be active');

    $registeredAfterOpenCleanup = videochat_call_guest_lifecycle_user($pdo, $registeredUserId);
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterOpenCleanup['status'] ?? '') === 'active', 'open guest cleanup must not disable registered user');
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterOpenCleanup['display_name'] ?? '') === (string) ($registeredBefore['display_name'] ?? ''), 'open guest cleanup must not alter registered profile');
    videochat_call_guest_lifecycle_assert((string) ($registeredAfterOpenCleanup['password_hash'] ?? '') === (string) ($registeredBefore['password_hash'] ?? ''), 'open guest cleanup must not alter registered password hash');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-guest-lifecycle-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-guest-lifecycle-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
