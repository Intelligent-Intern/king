<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-explicit-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);

    $fixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'explicit personal', $contract);
    $cleanup = videochat_invalidate_guest_accounts_for_call($pdo, (string) $fixture['call_id'], (int) $context['tenant_id']);
    videochat_call_guest_cleanup_assert_effect($cleanup, (int) $fixture['guest_user_id'], $contract);
    videochat_call_guest_cleanup_assert_guest_blocked($pdo, $fixture, $contract);
    videochat_call_guest_cleanup_assert_registered_preserved($pdo, $context, $fixture, $contract, true);

    $repeat = videochat_invalidate_guest_accounts_for_call($pdo, (string) $fixture['call_id'], (int) $context['tenant_id']);
    videochat_call_guest_cleanup_assert_noop($repeat, $contract);
    videochat_call_guest_cleanup_assert(
        (string) (videochat_call_guest_cleanup_user($pdo, (int) $fixture['guest_user_id'])['status'] ?? '') === 'disabled',
        'repeat cleanup must keep temporary guest disabled',
        $contract
    );
    $revokedCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = " . $pdo->quote((string) $fixture['guest_session_id']) . " AND revoked_at IS NOT NULL AND revoked_at <> ''")->fetchColumn();
    videochat_call_guest_cleanup_assert($revokedCount === 1, 'repeat cleanup must leave exactly one revoked guest session row', $contract);
    videochat_call_guest_cleanup_assert_audit($pdo, $context, $fixture, 2, $contract);

    fwrite(STDOUT, "[{$contract}] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($context['database_path']) && is_string($context['database_path']) && is_file($context['database_path'])) {
        @unlink($context['database_path']);
    }
}
