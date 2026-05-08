<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
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
