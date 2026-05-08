<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-restart-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);

    $fixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'restart personal', $contract);
    $cleanup = videochat_invalidate_guest_accounts_for_call($pdo, (string) $fixture['call_id'], (int) $context['tenant_id']);
    videochat_call_guest_cleanup_assert_effect($cleanup, (int) $fixture['guest_user_id'], $contract);

    $databasePath = (string) $context['database_path'];
    unset($pdo);
    $reopened = videochat_open_sqlite_pdo($databasePath);

    videochat_call_guest_cleanup_assert(
        (string) (videochat_call_guest_cleanup_user($reopened, (int) $fixture['guest_user_id'])['status'] ?? '') === 'disabled',
        'temporary guest must stay disabled after reopened database connection',
        $contract
    );
    videochat_call_guest_cleanup_assert_guest_blocked($reopened, $fixture, $contract);

    $issuerCalls = 0;
    $revival = videochat_issue_session_for_call_access(
        $reopened,
        (string) $fixture['guest_access_id'],
        static function () use (&$issuerCalls): string {
            $issuerCalls += 1;
            return 'sess_guest_cleanup_restart_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => $contract . '-restart']
    );
    videochat_call_guest_cleanup_assert(!(bool) ($revival['ok'] ?? true), 'old personalized guest link must not revive after restart', $contract);
    videochat_call_guest_cleanup_assert($issuerCalls === 0, 'restart revival attempt must not allocate a new session id', $contract);
    videochat_call_guest_cleanup_assert(
        (int) $reopened->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_guest_cleanup_restart_should_not_issue'")->fetchColumn() === 0,
        'restart revival attempt must not persist a new session',
        $contract
    );

    $repeat = videochat_invalidate_guest_accounts_for_call($reopened, (string) $fixture['call_id'], (int) $context['tenant_id']);
    videochat_call_guest_cleanup_assert_noop($repeat, $contract);
    videochat_call_guest_cleanup_assert_audit($reopened, $context, $fixture, 2, $contract);
    videochat_call_guest_cleanup_assert_registered_preserved($reopened, $context, $fixture, $contract, true);

    fwrite(STDOUT, "[{$contract}] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($context['database_path']) && is_string($context['database_path']) && is_file($context['database_path'])) {
        @unlink($context['database_path']);
    }
}
