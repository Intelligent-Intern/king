<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-invitation-invalidation-helper.php';

$label = 'call-access-invalidation-contract';

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
