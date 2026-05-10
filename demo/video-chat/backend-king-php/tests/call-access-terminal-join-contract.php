<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_terminal_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-terminal-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_terminal_join_set_call_status(PDO $pdo, string $callId, string $status): void
{
    $update = $pdo->prepare('UPDATE calls SET status = :status, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':id' => $callId,
        ':status' => $status,
        ':updated_at' => gmdate('c'),
    ]);
    videochat_call_access_terminal_join_assert($update->rowCount() === 1, "call should transition to {$status}");
}

function videochat_call_access_terminal_join_create_link(
    PDO $pdo,
    string $callId,
    int $creatorUserId,
    int $participantUserId,
    int $tenantId,
    string $kind = 'personal'
): string {
    $options = ['link_kind' => $kind];
    if ($kind === 'personal') {
        $options['participant_user_id'] = $participantUserId;
    }

    $created = videochat_create_call_access_link_for_user($pdo, $callId, $creatorUserId, 'admin', $options, $tenantId);
    videochat_call_access_terminal_join_assert((bool) ($created['ok'] ?? false), "{$kind} access link should be created before terminal state");
    $accessId = (string) (($created['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_terminal_join_assert($accessId !== '', "{$kind} access id should be present");

    return $accessId;
}

function videochat_call_access_terminal_join_create_call(
    PDO $pdo,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId,
    string $title,
    string $accessMode = 'invite_only'
): string {
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'access_mode' => $accessMode,
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => $participantUserId > 0 ? [$participantUserId] : [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_terminal_join_assert((bool) ($created['ok'] ?? false), "{$title} should be created");
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_access_terminal_join_assert($callId !== '', "{$title} id should be present");

    return $callId;
}

function videochat_call_access_terminal_join_create_active_user(PDO $pdo, int $tenantId, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_access_terminal_join_assert($roleId > 0, 'user role should exist');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => password_hash('terminal-join-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_call_access_terminal_join_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, 'member');

    return $userId;
}

function videochat_call_access_terminal_join_assert_no_payload(array $result, string $label): void
{
    videochat_call_access_terminal_join_assert(!(bool) ($result['ok'] ?? true), "{$label} should be denied");
    videochat_call_access_terminal_join_assert(($result['access_link'] ?? null) === null, "{$label} must redact access link");
    videochat_call_access_terminal_join_assert(($result['call'] ?? null) === null, "{$label} must redact call payload");
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-terminal-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-terminal-join-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_terminal_join_assert($tenantId > 0 && $adminUserId > 0 && $standardUserId > 0, 'seed ids should exist');

    $disabledUserCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        $standardUserId,
        $tenantId,
        'Terminal Join Disabled User'
    );
    $disabledUserAccessId = videochat_call_access_terminal_join_create_link($pdo, $disabledUserCallId, $adminUserId, $standardUserId, $tenantId);
    $preDisable = videochat_resolve_call_access_public($pdo, $disabledUserAccessId);
    videochat_call_access_terminal_join_assert((bool) ($preDisable['ok'] ?? false), 'active personalized target should resolve before user disable');

    $pdo->prepare('UPDATE users SET status = \'disabled\', updated_at = :updated_at WHERE id = :id')->execute([
        ':id' => $standardUserId,
        ':updated_at' => gmdate('c'),
    ]);
    videochat_call_access_terminal_join_assert(
        !(bool) (videochat_decide_call_access_for_user($pdo, $disabledUserCallId, $standardUserId, 'user', $tenantId)['allowed'] ?? true),
        'disabled registered user must not pass direct join decision'
    );
    $disabledPublic = videochat_resolve_call_access_public($pdo, $disabledUserAccessId);
    videochat_call_access_terminal_join_assert_no_payload($disabledPublic, 'disabled user public personalized resolve');
    videochat_call_access_terminal_join_assert((string) ($disabledPublic['reason'] ?? '') === 'not_found', 'disabled user public resolve should be safe not_found');
    $disabledSession = videochat_issue_session_for_call_access(
        $pdo,
        $disabledUserAccessId,
        static fn (): string => 'sess_terminal_disabled_user',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-terminal-join-contract']
    );
    videochat_call_access_terminal_join_assert_no_payload($disabledSession, 'disabled user session issue');
    videochat_call_access_terminal_join_assert(($disabledSession['session'] ?? null) === null, 'disabled user session must not include session');
    videochat_call_access_terminal_join_assert(($disabledSession['user'] ?? null) === null, 'disabled user session must not include user');
    videochat_call_access_terminal_join_assert((string) ($disabledSession['reason'] ?? '') === 'not_found', 'disabled user session should be safe not_found');

    $pdo->prepare('UPDATE users SET status = \'active\', updated_at = :updated_at WHERE id = :id')->execute([
        ':id' => $standardUserId,
        ':updated_at' => gmdate('c'),
    ]);

    $deletedUserId = videochat_call_access_terminal_join_create_active_user(
        $pdo,
        $tenantId,
        'terminal-deleted-user-' . bin2hex(random_bytes(4)) . '@example.test',
        'Terminal Deleted User'
    );
    $deletedUserCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        $deletedUserId,
        $tenantId,
        'Terminal Join Deleted User'
    );
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $deleteUser->execute([':id' => $deletedUserId]);
    videochat_call_access_terminal_join_assert($deleteUser->rowCount() === 1, 'registered user delete should remove one row');
    videochat_call_access_terminal_join_assert(
        !(bool) (videochat_decide_call_access_for_user($pdo, $deletedUserCallId, $deletedUserId, 'user', $tenantId)['allowed'] ?? true),
        'deleted registered user must not pass direct join decision'
    );

    $endedCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        $standardUserId,
        $tenantId,
        'Terminal Join Ended Personal'
    );
    $endedAccessId = videochat_call_access_terminal_join_create_link($pdo, $endedCallId, $adminUserId, $standardUserId, $tenantId);
    $endedOpenCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        0,
        $tenantId,
        'Terminal Join Ended Open',
        'free_for_all'
    );
    $endedOpenAccessId = videochat_call_access_terminal_join_create_link($pdo, $endedOpenCallId, $adminUserId, 0, $tenantId, 'open');
    videochat_call_access_terminal_join_set_call_status($pdo, $endedCallId, 'ended');
    videochat_call_access_terminal_join_set_call_status($pdo, $endedOpenCallId, 'ended');

    foreach ([
        'ended personal public resolve' => $endedAccessId,
        'ended open public resolve' => $endedOpenAccessId,
    ] as $label => $accessId) {
        $resolve = videochat_resolve_call_access_public($pdo, $accessId);
        videochat_call_access_terminal_join_assert_no_payload($resolve, $label);
        videochat_call_access_terminal_join_assert((string) ($resolve['reason'] ?? '') === 'conflict', "{$label} should be conflict");
        videochat_call_access_terminal_join_assert(
            (string) (($resolve['errors'] ?? [])['call_id'] ?? '') === 'call_not_joinable_from_status',
            "{$label} should expose only safe terminal field error"
        );
    }
    $endedAuthenticated = videochat_resolve_call_access_for_user($pdo, $endedAccessId, $standardUserId, 'user', $tenantId);
    videochat_call_access_terminal_join_assert_no_payload($endedAuthenticated, 'ended authenticated personalized resolve');
    videochat_call_access_terminal_join_assert((string) ($endedAuthenticated['reason'] ?? '') === 'conflict', 'ended authenticated resolve should be conflict');
    $endedSession = videochat_issue_session_for_call_access(
        $pdo,
        $endedOpenAccessId,
        static fn (): string => 'sess_terminal_ended_open',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-terminal-join-contract'],
        ['guest_name' => 'Ended Guest']
    );
    videochat_call_access_terminal_join_assert_no_payload($endedSession, 'ended open session issue');
    videochat_call_access_terminal_join_assert(($endedSession['session'] ?? null) === null, 'ended open session must not include session');
    videochat_call_access_terminal_join_assert(($endedSession['user'] ?? null) === null, 'ended open session must not include user');

    $deletedCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        $standardUserId,
        $tenantId,
        'Terminal Join Deleted Personal'
    );
    $deletedAccessId = videochat_call_access_terminal_join_create_link($pdo, $deletedCallId, $adminUserId, $standardUserId, $tenantId);
    $deletedOpenCallId = videochat_call_access_terminal_join_create_call(
        $pdo,
        $adminUserId,
        0,
        $tenantId,
        'Terminal Join Deleted Open',
        'free_for_all'
    );
    $deletedOpenAccessId = videochat_call_access_terminal_join_create_link($pdo, $deletedOpenCallId, $adminUserId, 0, $tenantId, 'open');
    videochat_call_access_terminal_join_assert((bool) (videochat_delete_call($pdo, $deletedCallId, $adminUserId, 'admin', $tenantId)['ok'] ?? false), 'personal call delete should succeed');
    videochat_call_access_terminal_join_assert((bool) (videochat_delete_call($pdo, $deletedOpenCallId, $adminUserId, 'admin', $tenantId)['ok'] ?? false), 'open call delete should succeed');

    foreach ([
        'deleted personal public resolve' => $deletedAccessId,
        'deleted open public resolve' => $deletedOpenAccessId,
    ] as $label => $accessId) {
        $resolve = videochat_resolve_call_access_public($pdo, $accessId);
        videochat_call_access_terminal_join_assert_no_payload($resolve, $label);
        videochat_call_access_terminal_join_assert((string) ($resolve['reason'] ?? '') === 'not_found', "{$label} should be safe not_found");
    }
    $deletedAuthenticated = videochat_resolve_call_access_for_user($pdo, $deletedAccessId, $standardUserId, 'user', $tenantId);
    videochat_call_access_terminal_join_assert_no_payload($deletedAuthenticated, 'deleted authenticated personalized resolve');
    videochat_call_access_terminal_join_assert((string) ($deletedAuthenticated['reason'] ?? '') === 'not_found', 'deleted authenticated resolve should be not_found');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-terminal-join-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-terminal-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
