<?php

declare(strict_types=1);

$contract = 'call-guest-cleanup-invitation-delete-contract';

require_once __DIR__ . '/call-guest-cleanup-lifecycle-helper.php';
require_once __DIR__ . '/../domain/calls/call_access_invalidation.php';

function videochat_call_guest_cleanup_invitation_assert_scope(PDO $pdo, array $context, array $fixture, string $contract): void
{
    $events = videochat_call_guest_cleanup_events($pdo, (int) $context['tenant_id'], (string) $fixture['call_id']);
    videochat_call_guest_cleanup_assert(count($events) === 1, 'invitation cleanup should write exactly one guest cleanup audit event', $contract);

    $payload = is_array(($events[0] ?? [])['payload'] ?? null) ? $events[0]['payload'] : [];
    videochat_call_guest_cleanup_assert((string) ($payload['cleanup_scope'] ?? '') === 'invitation', 'cleanup audit scope should be invitation', $contract);
    videochat_call_guest_cleanup_assert((string) ($payload['access_fingerprint'] ?? '') === videochat_audit_fingerprint((string) $fixture['guest_access_id']), 'cleanup audit should retain only access fingerprint', $contract);
    videochat_call_guest_cleanup_assert(!isset($payload['access_id']), 'cleanup audit must not expose raw access id', $contract);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[{$contract}] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $context = videochat_call_guest_cleanup_bootstrap($contract);
    $pdo = $context['pdo'];
    videochat_call_guest_cleanup_assert($pdo instanceof PDO, 'pdo fixture missing', $contract);

    $fixture = videochat_call_guest_cleanup_create_personal_fixture($pdo, $context, 'invitation delete personal', $contract);

    $invalidation = videochat_invalidate_call_access_invitation(
        $pdo,
        (string) $fixture['guest_access_id'],
        'cancelled',
        (int) $context['admin_user_id'],
        ['invalidation_reason' => 'participant_invite_deleted']
    );
    videochat_call_guest_cleanup_assert((bool) ($invalidation['ok'] ?? false), 'guest invitation invalidation should succeed', $contract);
    videochat_call_guest_cleanup_assert((string) ($invalidation['reason'] ?? '') === 'invalidated', 'guest invitation invalidation result mismatch', $contract);

    $guestCleanup = is_array($invalidation['guest_cleanup'] ?? null) ? $invalidation['guest_cleanup'] : [];
    videochat_call_guest_cleanup_assert_effect($guestCleanup, (int) $fixture['guest_user_id'], $contract);
    videochat_call_guest_cleanup_assert_guest_blocked($pdo, $fixture, $contract);
    videochat_call_guest_cleanup_assert_registered_preserved($pdo, $context, $fixture, $contract, true);
    videochat_call_guest_cleanup_invitation_assert_scope($pdo, $context, $fixture, $contract);
    videochat_call_guest_cleanup_assert_audit($pdo, $context, $fixture, 1, $contract);

    $repeatInvalidation = videochat_invalidate_call_access_invitation(
        $pdo,
        (string) $fixture['guest_access_id'],
        'cancelled',
        (int) $context['admin_user_id'],
        ['invalidation_reason' => 'participant_invite_deleted_repeat']
    );
    videochat_call_guest_cleanup_assert((bool) ($repeatInvalidation['ok'] ?? false), 'repeat invitation invalidation should succeed', $contract);
    videochat_call_guest_cleanup_assert((string) ($repeatInvalidation['reason'] ?? '') === 'already_invalidated', 'repeat invitation invalidation result mismatch', $contract);
    videochat_call_guest_cleanup_assert(count(videochat_call_guest_cleanup_events($pdo, (int) $context['tenant_id'], (string) $fixture['call_id'])) === 1, 'repeat invalidation should not append guest cleanup audit event', $contract);

    $registeredInvalidation = videochat_invalidate_call_access_invitation(
        $pdo,
        (string) $fixture['registered_access_id'],
        'cancelled',
        (int) $context['admin_user_id'],
        ['invalidation_reason' => 'registered_invite_deleted']
    );
    videochat_call_guest_cleanup_assert((bool) ($registeredInvalidation['ok'] ?? false), 'registered invitation invalidation should succeed', $contract);
    $registeredCleanup = is_array($registeredInvalidation['guest_cleanup'] ?? null) ? $registeredInvalidation['guest_cleanup'] : [];
    videochat_call_guest_cleanup_assert((string) ($registeredCleanup['reason'] ?? '') === 'no_guest_account', 'registered invitation must not run destructive guest cleanup', $contract);
    videochat_call_guest_cleanup_assert_registered_preserved($pdo, $context, $fixture, $contract, false);
    videochat_call_guest_cleanup_assert(count(videochat_call_guest_cleanup_events($pdo, (int) $context['tenant_id'], (string) $fixture['call_id'])) === 1, 'registered invalidation should not append guest cleanup audit event', $contract);

    fwrite(STDOUT, "[{$contract}] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[{$contract}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($context['database_path']) && is_string($context['database_path']) && is_file($context['database_path'])) {
        @unlink($context['database_path']);
    }
}
