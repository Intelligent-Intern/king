<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-invitation-invalidation-helper.php';

$label = 'call-access-expired-personalized-link-contract';

try {
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_invitation_invalidation_bootstrap_database('videochat-call-access-expired-personalized-link');

    $freshExpiry = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Expired Personalized Link Secret Title'
    );
    videochat_iam_invitation_invalidation_expire_link($pdo, $freshExpiry);
    $expiredLink = videochat_fetch_call_access_link($pdo, (string) ($freshExpiry['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($expiredLink), 'expired access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(!videochat_call_access_link_is_invalidated($pdo, $expiredLink), 'expiry should not masquerade as participant cancellation', $label);
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $freshExpiry,
        $label,
        'expired',
        410,
        'call_access_expired'
    );

    $staleExpiry = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Expired Personalized Rejoin Secret Title'
    );
    videochat_iam_invitation_invalidation_assert_existing_session_rejected_after_expiry($pdo, $staleExpiry, $label);
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $staleExpiry,
        $label,
        'expired',
        410,
        'call_access_expired'
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
