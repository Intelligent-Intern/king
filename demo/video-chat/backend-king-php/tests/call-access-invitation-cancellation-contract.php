<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-invitation-invalidation-helper.php';

$label = 'call-access-invitation-cancellation-contract';

try {
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_invitation_invalidation_bootstrap_database('videochat-call-access-invitation-cancellation');

    $fixture = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Invitation Cancellation Secret Title'
    );
    videochat_iam_invitation_invalidation_set_invite_state($pdo, $fixture, 'allowed');
    $sessionId = videochat_iam_invitation_invalidation_session_id($fixture, 'callcancel');
    $issuedSession = videochat_issue_session_for_call_access(
        $pdo,
        (string) ($fixture['access_id'] ?? ''),
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_invitation_invalidation_assert((bool) ($issuedSession['ok'] ?? false), 'personalized link should issue before cancellation', $label);

    $cancelled = videochat_cancel_call($pdo, (string) ($fixture['call_id'] ?? ''), (int) ($fixture['admin_user_id'] ?? 0), 'admin', [
        'cancel_reason' => 'contract cancellation',
        'cancel_message' => 'Focused cancellation proof',
    ], (int) ($fixture['tenant_id'] ?? 0));
    videochat_iam_invitation_invalidation_assert((bool) ($cancelled['ok'] ?? false), 'call cancellation should succeed', $label);
    videochat_iam_invitation_invalidation_assert((string) (($cancelled['call'] ?? [])['status'] ?? '') === 'cancelled', 'call should be cancelled', $label);

    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($fixture['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'cancelled-call access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'call cancellation should invalidate personalized participant link', $label);

    $staleSession = videochat_validate_session_token($pdo, $sessionId);
    videochat_iam_invitation_invalidation_assert(!(bool) ($staleSession['ok'] ?? true), 'stale call-access session must fail after cancellation', $label);
    videochat_iam_invitation_invalidation_assert((string) ($staleSession['reason'] ?? '') === 'call_access_link_invalidated', 'stale session reason mismatch after cancellation', $label);

    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $fixture,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );

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
