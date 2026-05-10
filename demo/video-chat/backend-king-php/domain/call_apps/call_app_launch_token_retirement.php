<?php

declare(strict_types=1);

function videochat_call_app_retire_launch_tokens_for_grant(PDO $pdo, int $tenantId, int $sessionRowId, array $grant, string $now): int
{
    $grantState = (string) ($grant['grant_state'] ?? '');
    $previousGrantState = (string) ($grant['previous_grant_state'] ?? '');
    $grantStateChanged = (bool) ($grant['grant_state_changed'] ?? false);
    $permissionActionsChanged = (bool) ($grant['permission_actions_changed'] ?? false);
    $shouldRetire = $grantState === 'denied'
        || $grantStateChanged
        || ($grantState === 'allowed' && $previousGrantState === 'allowed' && $permissionActionsChanged);
    if (!$shouldRetire) {
        return 0;
    }
    if ((string) ($grant['subject_type'] ?? '') !== 'user' || (int) ($grant['user_id'] ?? 0) <= 0) {
        return 0;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_app_launch_tokens
SET revoked_at = :revoked_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_session_id = :app_session_id
  AND issued_to_user_id = :issued_to_user_id
  AND (revoked_at IS NULL OR trim(revoked_at) = '')
SQL
    );
    $statement->execute([
        ':revoked_at' => $now,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':app_session_id' => $sessionRowId,
        ':issued_to_user_id' => (int) $grant['user_id'],
    ]);
    return max(0, (int) $statement->rowCount());
}
