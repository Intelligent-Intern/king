<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_session_fixation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-session-fixation-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-session-fixation-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-session-fixation-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_session_fixation_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_session_fixation_assert($standardUserId > 0, 'expected seeded standard user');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'call-access-session-fixation-contract')
SQL
    );
    $now = time();
    $insertSession->execute([
        ':id' => 'sess_existing_admin_fixation',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', $now - 30),
        ':expires_at' => gmdate('c', $now + 3600),
    ]);

    $createPrimary = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Session Fixation Primary',
        'starts_at' => '2026-09-01T09:00:00Z',
        'ends_at' => '2026-09-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_session_fixation_assert((bool) ($createPrimary['ok'] ?? false), 'primary call should be created');
    $primaryCallId = (string) (($createPrimary['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_fixation_assert($primaryCallId !== '', 'primary call id should be present');

    $createSecondary = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Session Fixation Secondary',
        'starts_at' => '2026-09-02T09:00:00Z',
        'ends_at' => '2026-09-02T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_session_fixation_assert((bool) ($createSecondary['ok'] ?? false), 'secondary call should be created');
    $secondaryCallId = (string) (($createSecondary['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_fixation_assert($secondaryCallId !== '', 'secondary call id should be present');

    $personalAccess = videochat_create_call_access_link_for_user($pdo, $primaryCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ]);
    videochat_call_access_session_fixation_assert((bool) ($personalAccess['ok'] ?? false), 'personal access link should be created');
    $personalAccessId = (string) (($personalAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_fixation_assert($personalAccessId !== '', 'personal access id should be present');

    $secondaryAccess = videochat_create_call_access_link_for_user($pdo, $secondaryCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ]);
    videochat_call_access_session_fixation_assert((bool) ($secondaryAccess['ok'] ?? false), 'secondary access link should be created');
    $secondaryAccessId = (string) (($secondaryAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_fixation_assert($secondaryAccessId !== '', 'secondary access id should be present');

    $fixationAttempt = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_existing_admin_fixation',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'fixation-attempt'],
        []
    );
    videochat_call_access_session_fixation_assert((bool) ($fixationAttempt['ok'] ?? true) === false, 'existing session id must not be reused');
    videochat_call_access_session_fixation_assert((string) ($fixationAttempt['reason'] ?? '') === 'conflict', 'session id reuse should be a conflict');
    videochat_call_access_session_fixation_assert((string) (($fixationAttempt['errors'] ?? [])['session'] ?? '') === 'session_id_not_available', 'session id reuse error mismatch');
    $reusedBindingCount = (int) $pdo->query("SELECT COUNT(*) FROM call_access_sessions WHERE session_id = 'sess_existing_admin_fixation'")->fetchColumn();
    videochat_call_access_session_fixation_assert($reusedBindingCount === 0, 'existing session id must not gain a call access binding');
    $existingAdminAuth = videochat_validate_session_token($pdo, 'sess_existing_admin_fixation');
    videochat_call_access_session_fixation_assert((bool) ($existingAdminAuth['ok'] ?? false), 'existing admin session should remain valid');
    videochat_call_access_session_fixation_assert((int) (($existingAdminAuth['user'] ?? [])['id'] ?? 0) === $adminUserId, 'existing admin session must not be rebound');

    $loginSwitch = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_should_not_issue_login_switch',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'login-switch'],
        [
            'verified_user_id' => $standardUserId,
            'authenticated_user_id' => $adminUserId,
            'verified_session_id' => 'sess_verified_standard',
            'authenticated_session_id' => 'sess_existing_admin_fixation',
        ]
    );
    videochat_call_access_session_fixation_assert((bool) ($loginSwitch['ok'] ?? true) === false, 'login switch should not issue a session');
    videochat_call_access_session_fixation_assert((string) ($loginSwitch['reason'] ?? '') === 'conflict', 'login switch should be a conflict');
    videochat_call_access_session_fixation_assert((string) (($loginSwitch['errors'] ?? [])['auth'] ?? '') === 'session_context_changed', 'login switch error mismatch');
    $loginSwitchRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_should_not_issue_login_switch'")->fetchColumn();
    videochat_call_access_session_fixation_assert($loginSwitchRows === 0, 'login switch must not persist a session');

    $wrongAccount = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_should_not_issue_wrong_account',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'wrong-account'],
        ['authenticated_user_id' => $adminUserId]
    );
    videochat_call_access_session_fixation_assert((bool) ($wrongAccount['ok'] ?? true) === false, 'wrong logged-in account should not issue a session');
    videochat_call_access_session_fixation_assert((string) ($wrongAccount['reason'] ?? '') === 'forbidden', 'wrong account should be forbidden');
    videochat_call_access_session_fixation_assert((string) (($wrongAccount['errors'] ?? [])['auth'] ?? '') === 'not_bound_to_current_user', 'wrong account error mismatch');

    $validSessionId = 'sess_call_access_fixation_valid';
    $validIssue = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => $validSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'same-account'],
        [
            'verified_user_id' => $standardUserId,
            'authenticated_user_id' => $standardUserId,
            'verified_session_id' => 'sess_verified_standard',
            'authenticated_session_id' => 'sess_verified_standard',
        ]
    );
    videochat_call_access_session_fixation_assert((bool) ($validIssue['ok'] ?? false), 'same-account personal link should issue');
    videochat_call_access_session_fixation_assert((int) (($validIssue['user'] ?? [])['id'] ?? 0) === $standardUserId, 'valid access session should bind standard user');
    $validAuth = videochat_validate_session_token($pdo, $validSessionId);
    videochat_call_access_session_fixation_assert((bool) ($validAuth['ok'] ?? false), 'fresh call access session should authenticate');

    $tamperedSessionId = 'sess_call_access_fixation_tampered';
    $tamperIssue = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => $tamperedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'tamper-setup'],
        ['authenticated_user_id' => $standardUserId]
    );
    videochat_call_access_session_fixation_assert((bool) ($tamperIssue['ok'] ?? false), 'tamper setup session should issue');
    $pdo->prepare('UPDATE call_access_sessions SET access_id = :access_id WHERE session_id = :session_id')->execute([
        ':access_id' => $secondaryAccessId,
        ':session_id' => $tamperedSessionId,
    ]);
    $tamperedAuth = videochat_validate_session_token($pdo, $tamperedSessionId);
    videochat_call_access_session_fixation_assert((bool) ($tamperedAuth['ok'] ?? true) === false, 'tampered access binding should not authenticate');
    videochat_call_access_session_fixation_assert((string) ($tamperedAuth['reason'] ?? '') === 'call_access_binding_mismatch', 'tampered binding reason mismatch');
    videochat_call_access_session_fixation_assert(videochat_fetch_call_access_session_binding($pdo, $tamperedSessionId) === null, 'tampered binding should be quarantined from binding fetch');

    $pdo->prepare('UPDATE call_access_links SET expires_at = :expires_at WHERE id = :id')->execute([
        ':expires_at' => gmdate('c', $now - 60),
        ':id' => $personalAccessId,
    ]);
    $staleAuth = videochat_validate_session_token($pdo, $validSessionId);
    videochat_call_access_session_fixation_assert((bool) ($staleAuth['ok'] ?? true) === false, 'expired access link should invalidate existing access session');
    videochat_call_access_session_fixation_assert((string) ($staleAuth['reason'] ?? '') === 'call_access_link_expired', 'expired access link auth reason mismatch');
    videochat_call_access_session_fixation_assert(videochat_fetch_call_access_session_binding($pdo, $validSessionId) === null, 'expired access link binding should be quarantined from binding fetch');

    fwrite(STDOUT, "[call-access-session-fixation-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-session-fixation-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
