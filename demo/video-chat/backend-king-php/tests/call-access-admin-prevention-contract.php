<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_admin_prevention_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-admin-prevention-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_admin_prevention_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_access_admin_prevention_session(string $id): callable
{
    return static fn (): string => $id;
}

function videochat_call_access_admin_prevention_participant(PDO $pdo, string $callId, int $userId): ?array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT user_id, source, call_role, invite_state
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

    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

function videochat_call_access_admin_prevention_assert_no_admin(
    PDO $pdo,
    string $label,
    string $sessionId,
    string $callId,
    int $userId,
    bool $expectGuest
): void {
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_call_access_admin_prevention_assert((bool) ($auth['ok'] ?? false), "{$label}: session should authenticate");

    $user = is_array($auth['user'] ?? null) ? $auth['user'] : [];
    $tenant = is_array($auth['tenant'] ?? null) ? $auth['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    videochat_call_access_admin_prevention_assert((int) ($user['id'] ?? 0) === $userId, "{$label}: authenticated user mismatch");
    videochat_call_access_admin_prevention_assert((bool) ($user['is_guest'] ?? false) === $expectGuest, "{$label}: guest flag mismatch");
    videochat_call_access_admin_prevention_assert((string) ($user['role'] ?? '') === 'user', "{$label}: link-issued user must keep user role");
    videochat_call_access_admin_prevention_assert((bool) ($permissions['platform_admin'] ?? false) === false, "{$label}: platform_admin must stay false");
    videochat_call_access_admin_prevention_assert((bool) ($permissions['tenant_admin'] ?? false) === false, "{$label}: tenant_admin must stay false");

    $participant = videochat_call_access_admin_prevention_participant($pdo, $callId, $userId);
    videochat_call_access_admin_prevention_assert(is_array($participant), "{$label}: participant row should exist");
    videochat_call_access_admin_prevention_assert((string) ($participant['call_role'] ?? '') === 'participant', "{$label}: call_role must stay participant");
    videochat_call_access_admin_prevention_assert((string) ($participant['call_role'] ?? '') !== 'owner', "{$label}: owner role must not be granted");
    videochat_call_access_admin_prevention_assert((string) ($participant['call_role'] ?? '') !== 'moderator', "{$label}: moderator role must not be granted");
    videochat_call_access_admin_prevention_assert(
        videochat_user_has_system_admin_call_rights($pdo, $userId, (string) ($user['role'] ?? 'user')) === false,
        "{$label}: system-admin rights must not be available"
    );

    $context = videochat_call_role_context_for_room_user($pdo, $callId, $userId);
    videochat_call_access_admin_prevention_assert((string) ($context['call_role'] ?? '') === 'participant', "{$label}: role context should remain participant");
    videochat_call_access_admin_prevention_assert((string) ($context['effective_call_role'] ?? '') === 'participant', "{$label}: effective role should remain participant");
    videochat_call_access_admin_prevention_assert((bool) ($context['can_moderate'] ?? false) === false, "{$label}: moderation rights must stay false");
    videochat_call_access_admin_prevention_assert((bool) ($context['can_manage_owner'] ?? false) === false, "{$label}: owner-management rights must stay false");

    $call = videochat_fetch_call_for_update($pdo, $callId);
    videochat_call_access_admin_prevention_assert(is_array($call), "{$label}: call should exist");
    videochat_call_access_admin_prevention_assert(
        videochat_can_administer_call(
            $pdo,
            $callId,
            (string) ($user['role'] ?? 'user'),
            $userId,
            (int) ($call['owner_user_id'] ?? 0),
            is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : null
        ) === false,
        "{$label}: can_administer_call must stay false"
    );
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-admin-prevention-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-admin-prevention-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_admin_prevention_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_admin_prevention_assert($standardUserId > 0, 'expected seeded standard user');
    videochat_call_access_admin_prevention_assert(videochat_call_access_admin_prevention_role_id($pdo, 'user') > 0, 'expected user role');

    $personalCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Admin Prevention Personalized Link',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_admin_prevention_assert((bool) ($personalCall['ok'] ?? false), 'personalized call should be created');
    $personalCallId = (string) (($personalCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_admin_prevention_assert($personalCallId !== '', 'personalized call id should be present');

    $personalLink = videochat_create_call_access_link_for_user($pdo, $personalCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ]);
    videochat_call_access_admin_prevention_assert((bool) ($personalLink['ok'] ?? false), 'personalized link should be created');
    $personalAccessId = (string) (($personalLink['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_admin_prevention_assert($personalAccessId !== '', 'personalized access id should be present');

    $personalSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        videochat_call_access_admin_prevention_session('sess_admin_prevention_personal_normal'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-admin-prevention-contract'],
        ['authenticated_user_id' => $standardUserId]
    );
    videochat_call_access_admin_prevention_assert((bool) ($personalSession['ok'] ?? false), 'normal user personalized-link session should issue');
    videochat_call_access_admin_prevention_assert((int) (($personalSession['user'] ?? [])['id'] ?? 0) === $standardUserId, 'personalized link must bind the normal user');
    videochat_call_access_admin_prevention_assert_no_admin(
        $pdo,
        'normal personalized link',
        'sess_admin_prevention_personal_normal',
        $personalCallId,
        $standardUserId,
        false
    );

    $openCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Admin Prevention Anonymous Link',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-10-02T09:00:00Z',
        'ends_at' => '2026-10-02T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_access_admin_prevention_assert((bool) ($openCall['ok'] ?? false), 'anonymous/open call should be created');
    $openCallId = (string) (($openCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_admin_prevention_assert($openCallId !== '', 'anonymous/open call id should be present');

    $openLink = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ]);
    videochat_call_access_admin_prevention_assert((bool) ($openLink['ok'] ?? false), 'anonymous/open link should be created');
    $openAccessId = (string) (($openLink['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_admin_prevention_assert($openAccessId !== '', 'anonymous/open access id should be present');

    $guestCountBeforeOpenJoin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    $openSession = videochat_issue_session_for_call_access(
        $pdo,
        $openAccessId,
        videochat_call_access_admin_prevention_session('sess_admin_prevention_open_account'),
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-admin-prevention-contract'],
        [
            'authenticated_user_id' => $standardUserId,
            'guest_name' => 'Anonymous Admin Prevention Guest',
        ]
    );
    videochat_call_access_admin_prevention_assert((bool) ($openSession['ok'] ?? false), 'anonymous/open account session should issue');
    $openUserId = (int) (($openSession['user'] ?? [])['id'] ?? 0);
    videochat_call_access_admin_prevention_assert($openUserId === $standardUserId, 'anonymous/open link must keep the logged-in normal account');
    $guestCountAfterOpenJoin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
    videochat_call_access_admin_prevention_assert($guestCountAfterOpenJoin === $guestCountBeforeOpenJoin, 'logged-in anonymous/open link must not create a temporary guest identity');
    videochat_call_access_admin_prevention_assert_no_admin(
        $pdo,
        'anonymous open link account',
        'sess_admin_prevention_open_account',
        $openCallId,
        $standardUserId,
        false
    );
    $openParticipant = videochat_call_access_admin_prevention_participant($pdo, $openCallId, $standardUserId);
    videochat_call_access_admin_prevention_assert(
        is_array($openParticipant) && (string) ($openParticipant['invite_state'] ?? '') === 'pending',
        'anonymous/open logged-in account should wait for host admission'
    );
    videochat_call_access_admin_prevention_assert(
        videochat_can_administer_call($pdo, $openCallId, 'user', $standardUserId, $adminUserId) === false,
        'logged-in normal account must not gain call-admin rights from anonymous/open link'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-admin-prevention-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-admin-prevention-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
