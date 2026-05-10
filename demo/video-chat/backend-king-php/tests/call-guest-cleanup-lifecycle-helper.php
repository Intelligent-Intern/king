<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/calls/call_guest_lifecycle.php';

function videochat_call_guest_cleanup_assert(bool $condition, string $message, string $contract): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[{$contract}] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_guest_cleanup_token(string $label): string
{
    $token = strtolower(preg_replace('/[^a-z0-9]+/', '_', $label) ?? '');
    $token = trim($token, '_');
    return $token === '' ? bin2hex(random_bytes(4)) : $token;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_guest_cleanup_bootstrap(string $contract): array
{
    $databasePath = sys_get_temp_dir() . '/videochat-' . videochat_call_guest_cleanup_token($contract) . '-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $registeredUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_guest_cleanup_assert($tenantId > 0 && $adminUserId > 0 && $registeredUserId > 0, 'fixture ids missing', $contract);

    return [
        'database_path' => $databasePath,
        'pdo' => $pdo,
        'tenant_id' => $tenantId,
        'admin_user_id' => $adminUserId,
        'registered_user_id' => $registeredUserId,
        'registered_before' => videochat_call_guest_cleanup_user($pdo, $registeredUserId),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_guest_cleanup_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name, password_hash, status FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_call_guest_cleanup_events(PDO $pdo, int $tenantId, string $callId): array
{
    return videochat_audit_fetch_events($pdo, [
        'tenant_id' => $tenantId,
        'call_id' => $callId,
        'event_type' => 'guest_account_cleanup',
        'limit' => 20,
    ]);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_guest_cleanup_create_personal_fixture(PDO $pdo, array $context, string $label, string $contract): array
{
    $tenantId = (int) $context['tenant_id'];
    $adminUserId = (int) $context['admin_user_id'];
    $registeredUserId = (int) $context['registered_user_id'];
    $token = videochat_call_guest_cleanup_token($label);

    $created = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Guest Cleanup ' . $label,
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$registeredUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($created['ok'] ?? false), 'personal cleanup call should be created', $contract);
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_guest_cleanup_assert($callId !== '', 'personal cleanup call id missing', $contract);

    $guestCreate = videochat_create_guest_user_for_call_access($pdo, 'Cleanup Guest ' . $label, $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($guestCreate['ok'] ?? false), 'temporary guest should be created', $contract);
    $guest = is_array($guestCreate['user'] ?? null) ? $guestCreate['user'] : [];
    $guestUserId = (int) ($guest['id'] ?? 0);
    videochat_call_guest_cleanup_assert($guestUserId > 0 && (bool) ($guest['is_guest'] ?? false), 'temporary guest user missing', $contract);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $guestUserId,
        (string) ($guest['email'] ?? ''),
        (string) ($guest['display_name'] ?? ''),
        'allowed'
    );

    $guestAccess = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $guestUserId,
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($guestAccess['ok'] ?? false), 'temporary guest access link should be created', $contract);
    $guestAccessId = (string) (($guestAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_guest_cleanup_assert($guestAccessId !== '', 'temporary guest access id missing', $contract);

    $registeredAccess = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $registeredUserId,
    ], $tenantId);
    videochat_call_guest_cleanup_assert((bool) ($registeredAccess['ok'] ?? false), 'registered access link should be created', $contract);
    $registeredAccessId = (string) (($registeredAccess['access_link'] ?? [])['id'] ?? '');

    $guestSessionId = 'sess_guest_cleanup_' . $token . '_guest';
    $guestSession = videochat_issue_session_for_call_access(
        $pdo,
        $guestAccessId,
        static fn (): string => $guestSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-guest']
    );
    videochat_call_guest_cleanup_assert((bool) ($guestSession['ok'] ?? false), 'temporary guest session should be issued before cleanup', $contract);

    $registeredSessionId = 'sess_guest_cleanup_' . $token . '_registered';
    $registeredSession = videochat_issue_session_for_call_access(
        $pdo,
        $registeredAccessId,
        static fn (): string => $registeredSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-registered']
    );
    videochat_call_guest_cleanup_assert((bool) ($registeredSession['ok'] ?? false), 'registered session should be issued before cleanup', $contract);

    return [
        'call_id' => $callId,
        'guest_user_id' => $guestUserId,
        'guest_email' => (string) ($guest['email'] ?? ''),
        'guest_access_id' => $guestAccessId,
        'guest_session_id' => $guestSessionId,
        'registered_user_id' => $registeredUserId,
        'registered_access_id' => $registeredAccessId,
        'registered_session_id' => $registeredSessionId,
    ];
}

function videochat_call_guest_cleanup_mark_joined(PDO $pdo, array $fixture): void
{
    $markJoined = $pdo->prepare(
        'UPDATE call_participants SET joined_at = :joined_at, left_at = NULL WHERE call_id = :call_id AND user_id IN (:guest_user_id, :registered_user_id)'
    );
    $markJoined->execute([
        ':joined_at' => '2026-10-01T09:15:00Z',
        ':call_id' => (string) $fixture['call_id'],
        ':guest_user_id' => (int) $fixture['guest_user_id'],
        ':registered_user_id' => (int) ($fixture['registered_user_id'] ?? 0),
    ]);
}

function videochat_call_guest_cleanup_assert_effect(array $cleanup, int $guestUserId, string $contract): void
{
    videochat_call_guest_cleanup_assert((bool) ($cleanup['ok'] ?? false), 'guest cleanup should succeed', $contract);
    videochat_call_guest_cleanup_assert(in_array($guestUserId, $cleanup['guest_user_ids'] ?? [], true), 'guest cleanup should include the temporary guest', $contract);
    videochat_call_guest_cleanup_assert((int) ($cleanup['invalidated_guests'] ?? 0) === 1, 'guest cleanup should invalidate exactly one guest', $contract);
    videochat_call_guest_cleanup_assert((int) ($cleanup['revoked_sessions'] ?? 0) === 1, 'guest cleanup should revoke exactly one guest session', $contract);
}

function videochat_call_guest_cleanup_assert_noop(array $cleanup, string $contract): void
{
    videochat_call_guest_cleanup_assert((bool) ($cleanup['ok'] ?? false), 'repeat guest cleanup should succeed', $contract);
    videochat_call_guest_cleanup_assert((string) ($cleanup['reason'] ?? '') === 'no_guest_accounts', 'repeat guest cleanup should be a no-op', $contract);
    videochat_call_guest_cleanup_assert((array) ($cleanup['guest_user_ids'] ?? []) === [], 'repeat guest cleanup should not rediscover disabled guests', $contract);
    videochat_call_guest_cleanup_assert((int) ($cleanup['invalidated_guests'] ?? -1) === 0, 'repeat guest cleanup should not invalidate again', $contract);
    videochat_call_guest_cleanup_assert((int) ($cleanup['revoked_sessions'] ?? -1) === 0, 'repeat guest cleanup should not revoke sessions again', $contract);
}

function videochat_call_guest_cleanup_assert_guest_blocked(PDO $pdo, array $fixture, string $contract): void
{
    $guestUserId = (int) $fixture['guest_user_id'];
    videochat_call_guest_cleanup_assert(
        (string) (videochat_call_guest_cleanup_user($pdo, $guestUserId)['status'] ?? '') === 'disabled',
        'temporary guest must be disabled after cleanup',
        $contract
    );

    $staleAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $fixture['guest_session_id'] . '&room=' . $fixture['call_id'] . '&call_id=' . $fixture['call_id'],
            'headers' => ['Authorization' => 'Bearer ' . $fixture['guest_session_id']],
        ],
        'websocket'
    );
    videochat_call_guest_cleanup_assert(!(bool) ($staleAuth['ok'] ?? true), 'stale temporary guest session must not authenticate', $contract);

    $staleLink = videochat_issue_session_for_call_access(
        $pdo,
        (string) $fixture['guest_access_id'],
        static fn (): string => 'sess_guest_cleanup_rejoin_' . bin2hex(random_bytes(4)),
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-stale-link']
    );
    videochat_call_guest_cleanup_assert(!(bool) ($staleLink['ok'] ?? true), 'stale temporary guest link must not rejoin after cleanup', $contract);
}

function videochat_call_guest_cleanup_assert_registered_preserved(PDO $pdo, array $context, array $fixture, string $contract, bool $expectSessionValid): void
{
    $registeredUserId = (int) $context['registered_user_id'];
    $before = is_array($context['registered_before'] ?? null) ? $context['registered_before'] : [];
    $after = videochat_call_guest_cleanup_user($pdo, $registeredUserId);
    videochat_call_guest_cleanup_assert((string) ($after['status'] ?? '') === 'active', 'registered user must remain active', $contract);
    videochat_call_guest_cleanup_assert((string) ($after['display_name'] ?? '') === (string) ($before['display_name'] ?? ''), 'registered profile must not change', $contract);
    videochat_call_guest_cleanup_assert((string) ($after['password_hash'] ?? '') === (string) ($before['password_hash'] ?? ''), 'registered password hash must not change', $contract);

    if (!$expectSessionValid) {
        return;
    }

    $registeredAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $fixture['registered_session_id'] . '&room=' . $fixture['call_id'] . '&call_id=' . $fixture['call_id'],
            'headers' => ['Authorization' => 'Bearer ' . $fixture['registered_session_id']],
        ],
        'websocket'
    );
    videochat_call_guest_cleanup_assert((bool) ($registeredAuth['ok'] ?? false), 'registered call-access session must survive explicit guest cleanup', $contract);
}

function videochat_call_guest_cleanup_assert_audit(PDO $pdo, array $context, array $fixture, int $expectedEvents, string $contract): void
{
    $events = videochat_call_guest_cleanup_events($pdo, (int) $context['tenant_id'], (string) $fixture['call_id']);
    videochat_call_guest_cleanup_assert(count($events) === $expectedEvents, 'guest cleanup audit event count mismatch', $contract);
    $firstPayload = is_array(($events[0] ?? [])['payload'] ?? null) ? $events[0]['payload'] : [];
    videochat_call_guest_cleanup_assert((string) ($firstPayload['cleanup_result'] ?? '') === 'invalidated', 'cleanup audit result mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($firstPayload['guest_user_count'] ?? 0) === 1, 'cleanup audit guest count mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($firstPayload['invalidated_guest_count'] ?? 0) === 1, 'cleanup audit invalidated count mismatch', $contract);
    videochat_call_guest_cleanup_assert((int) ($firstPayload['revoked_session_count'] ?? 0) === 1, 'cleanup audit revoked session count mismatch', $contract);
    videochat_call_guest_cleanup_assert((bool) ($firstPayload['had_effect'] ?? false), 'cleanup audit should show destructive effect', $contract);
    videochat_call_guest_cleanup_assert((bool) ($firstPayload['idempotent_safe'] ?? false), 'cleanup audit should document idempotence', $contract);

    if ($expectedEvents > 1) {
        $repeatPayload = is_array(($events[$expectedEvents - 1] ?? [])['payload'] ?? null) ? $events[$expectedEvents - 1]['payload'] : [];
        videochat_call_guest_cleanup_assert((string) ($repeatPayload['cleanup_result'] ?? '') === 'no_guest_accounts', 'repeat cleanup audit result mismatch', $contract);
        videochat_call_guest_cleanup_assert((int) ($repeatPayload['guest_user_count'] ?? -1) === 0, 'repeat cleanup audit guest count mismatch', $contract);
        videochat_call_guest_cleanup_assert((int) ($repeatPayload['invalidated_guest_count'] ?? -1) === 0, 'repeat cleanup audit invalidated count mismatch', $contract);
        videochat_call_guest_cleanup_assert((int) ($repeatPayload['revoked_session_count'] ?? -1) === 0, 'repeat cleanup audit revoked session count mismatch', $contract);
        videochat_call_guest_cleanup_assert(!(bool) ($repeatPayload['had_effect'] ?? true), 'repeat cleanup audit should show no destructive effect', $contract);
    }

    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_guest_cleanup_assert(is_string($encodedEvents), 'guest cleanup audit events should encode', $contract);
    foreach ([$fixture['guest_session_id'] ?? '', $fixture['guest_access_id'] ?? '', $fixture['guest_email'] ?? ''] as $forbiddenText) {
        $text = (string) $forbiddenText;
        if ($text === '') {
            continue;
        }
        videochat_call_guest_cleanup_assert(!str_contains($encodedEvents, $text), 'guest cleanup audit must not leak raw guest/session/access identifiers', $contract);
    }
    videochat_call_guest_cleanup_assert(str_contains($encodedEvents, videochat_audit_fingerprint((string) $fixture['call_id'])), 'guest cleanup audit should retain call fingerprint', $contract);
}
