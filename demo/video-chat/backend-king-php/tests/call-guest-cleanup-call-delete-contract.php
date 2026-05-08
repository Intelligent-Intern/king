<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-call-delete-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);

    $fixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'call delete personal', $contract);
    videochat_call_guest_cleanup_mark_joined($pdo, $fixture);

    $delete = videochat_delete_call(
        $pdo,
        (string) $fixture['call_id'],
        (int) $context['admin_user_id'],
        'admin',
        (int) $context['tenant_id']
    );
    videochat_call_guest_cleanup_assert((bool) ($delete['ok'] ?? false), 'call delete should succeed', $contract);
    videochat_call_guest_cleanup_assert((string) ($delete['reason'] ?? '') === 'deleted', 'call delete result mismatch', $contract);
    videochat_call_guest_cleanup_assert_effect(is_array($delete['guest_cleanup'] ?? null) ? $delete['guest_cleanup'] : [], (int) $fixture['guest_user_id'], $contract);
    videochat_call_guest_cleanup_assert((int) $pdo->query("SELECT COUNT(*) FROM calls WHERE id = " . $pdo->quote((string) $fixture['call_id']))->fetchColumn() === 0, 'deleted call row must be removed', $contract);
    videochat_call_guest_cleanup_assert((int) $pdo->query("SELECT COUNT(*) FROM call_access_links WHERE call_id = " . $pdo->quote((string) $fixture['call_id']))->fetchColumn() === 0, 'deleted call access links must be removed', $contract);
    videochat_call_guest_cleanup_assert_guest_blocked($pdo, $fixture, $contract);
    videochat_call_guest_cleanup_assert_registered_preserved($pdo, $context, $fixture, $contract, false);

    $repeat = videochat_invalidate_guest_accounts_for_call($pdo, (string) $fixture['call_id'], (int) $context['tenant_id']);
    videochat_call_guest_cleanup_assert_noop($repeat, $contract);
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
