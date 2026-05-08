<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-invitation-invalidation-helper.php';

$label = 'call-access-invalidation-contract';

function videochat_call_access_invalidation_contract_restart_probe(array $argv, string $label): void
{
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    $databasePath = (string) ($argv[2] ?? '');
    $fixturePath = (string) ($argv[3] ?? '');
    videochat_iam_invitation_invalidation_assert($databasePath !== '' && is_file($databasePath), 'restart probe database is missing', $label);
    videochat_iam_invitation_invalidation_assert($fixturePath !== '' && is_file($fixturePath), 'restart probe fixture is missing', $label);

    $fixture = json_decode((string) file_get_contents($fixturePath), true);
    videochat_iam_invitation_invalidation_assert(is_array($fixture), 'restart probe fixture should decode', $label);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($fixture['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'restart probe should refetch invalidated link from disk', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'restart probe should preserve invalidated classification', $label);
    videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions(
        $pdo,
        $fixture,
        $label,
        'application-restart-ci'
    );
}

function videochat_call_access_invalidation_contract_assert_restart_survives(
    string $databasePath,
    array $fixture,
    string $label
): void {
    $fixturePath = sys_get_temp_dir() . '/videochat-call-access-invalidation-restart-' . bin2hex(random_bytes(6)) . '.json';
    $encoded = json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_iam_invitation_invalidation_assert(is_string($encoded), 'restart fixture should encode', $label);
    file_put_contents($fixturePath, $encoded);

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__FILE__) . ' --restart-probe '
        . escapeshellarg($databasePath) . ' '
        . escapeshellarg($fixturePath);
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);
    @unlink($fixturePath);

    videochat_iam_invitation_invalidation_assert(
        $exitCode === 0,
        'restart probe failed: ' . implode("\n", $output),
        $label
    );
}

if (($argv[1] ?? '') === '--restart-probe') {
    try {
        videochat_call_access_invalidation_contract_restart_probe($argv, $label);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, "[{$label}] RESTART ERROR: " . $error->getMessage() . "\n");
        exit(1);
    }
}

try {
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_invitation_invalidation_bootstrap_database('videochat-call-access-invalidation');

    $beforeUse = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Secret Title'
    );
    $beforeUseInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $beforeUse);
    videochat_iam_invitation_invalidation_assert((bool) ($beforeUseInvalidation['ok'] ?? false), 'cancelled invite should be audit-loggable before use', $label);
    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($beforeUse['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'invalidated access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'domain should classify cancelled participant invite as invalidated', $label);
    videochat_iam_invitation_invalidation_assert_audit_logged(
        $pdo,
        $beforeUse,
        $label,
        'participant_invite_cancelled'
    );
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $beforeUse,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );
    videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions(
        $pdo,
        $beforeUse,
        $label,
        'invalidated-before-use'
    );

    $afterUse = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Rejoin Secret Title'
    );
    videochat_iam_invitation_invalidation_assert_existing_session_rejected_after_cancel($pdo, $afterUse, $label);
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $afterUse,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );

    $restart = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Restart Secret Title'
    );
    $restartInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $restart, [
        'invalidation_reason' => 'participant_invite_cancelled_before_restart',
    ]);
    videochat_iam_invitation_invalidation_assert((bool) ($restartInvalidation['ok'] ?? false), 'restart fixture invite should be invalidated before process restart', $label);
    $restartInvalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($restart['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($restartInvalidatedLink), 'restart invalidated access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $restartInvalidatedLink), 'restart fixture should classify as invalidated before child process', $label);
    videochat_call_access_invalidation_contract_assert_restart_survives($databasePath, $restart, $label);

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
