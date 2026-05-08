<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-call-end-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);

    $fixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'call end personal', $contract);
    videochat_call_guest_cleanup_mark_joined($pdo, $fixture);

    $end = videochat_end_call(
        $pdo,
        (string) $fixture['call_id'],
        (int) $context['admin_user_id'],
        'admin',
        (int) $context['tenant_id']
    );
    videochat_call_guest_cleanup_assert((bool) ($end['ok'] ?? false), 'call end should succeed', $contract);
    videochat_call_guest_cleanup_assert((string) ($end['reason'] ?? '') === 'ended', 'call end result mismatch', $contract);
    videochat_call_guest_cleanup_assert((string) ((($end['call'] ?? [])['status'] ?? '')) === 'ended', 'call status should be ended', $contract);
    videochat_call_guest_cleanup_assert_effect(is_array($end['guest_cleanup'] ?? null) ? $end['guest_cleanup'] : [], (int) $fixture['guest_user_id'], $contract);
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
