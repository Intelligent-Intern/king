<?php

declare(strict_types=1);

require_once __DIR__ . '/../audit/audit_events.php';

function videochat_call_owner_transfer_admin_preserved(PDO $pdo, int $userId, ?int $tenantId): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (function_exists('videochat_user_has_system_admin_call_rights')
        && videochat_user_has_system_admin_call_rights($pdo, $userId, 'admin')
    ) {
        return true;
    }

    if (!function_exists('videochat_tenant_context_for_user')) {
        return false;
    }

    $tenantContext = videochat_tenant_context_for_user($pdo, $userId, $tenantId);
    if (!is_array($tenantContext)) {
        return false;
    }

    $permissions = is_array($tenantContext['permissions'] ?? null) ? $tenantContext['permissions'] : [];
    return (bool) (($permissions['tenant_admin'] ?? false) || ($permissions['platform_admin'] ?? false));
}

function videochat_call_owner_transfer_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND source = 'internal' AND call_role = 'owner'"
    );
    $query->execute([':call_id' => $callId]);

    return (int) $query->fetchColumn();
}

function videochat_transfer_call_owner_with_audit(
    PDO $pdo,
    array $existingCall,
    int $targetUserId,
    int $authUserId,
    string $updatedAt
): array {
    $callId = (string) ($existingCall['id'] ?? '');
    $previousOwnerUserId = (int) ($existingCall['owner_user_id'] ?? 0);
    $tenantId = is_numeric($existingCall['tenant_id'] ?? null) ? (int) $existingCall['tenant_id'] : null;
    if ($callId === '' || $previousOwnerUserId <= 0 || $targetUserId <= 0) {
        return ['ok' => false, 'reason' => 'validation_failed'];
    }

    if (!videochat_audit_bootstrap($pdo)) {
        return ['ok' => false, 'reason' => 'audit_unavailable'];
    }

    $oldOwnerAdminPreserved = videochat_call_owner_transfer_admin_preserved($pdo, $previousOwnerUserId, $tenantId);

    $pdo->beginTransaction();
    try {
        $updateCallOwner = $pdo->prepare(
            'UPDATE calls SET owner_user_id = :owner_user_id, updated_at = :updated_at WHERE id = :id'
        );
        $updateCallOwner->execute([
            ':owner_user_id' => $targetUserId,
            ':updated_at' => $updatedAt,
            ':id' => $callId,
        ]);

        $demotePreviousOwner = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET call_role = 'participant'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $demotePreviousOwner->execute([
            ':call_id' => $callId,
            ':user_id' => $previousOwnerUserId,
        ]);

        $promoteNewOwner = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET call_role = 'owner',
    invite_state = CASE
        WHEN invite_state IN ('invited', 'pending', 'accepted') THEN 'allowed'
        ELSE invite_state
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        );
        $promoteNewOwner->execute([
            ':call_id' => $callId,
            ':user_id' => $targetUserId,
        ]);

        $ownerCount = videochat_call_owner_transfer_owner_count($pdo, $callId);
        $audit = videochat_audit_record_call_owner_transfer(
            $pdo,
            $existingCall,
            $authUserId,
            $previousOwnerUserId,
            $targetUserId,
            [
                'old_owner_admin_preserved' => $oldOwnerAdminPreserved,
                'owner_count' => $ownerCount,
            ]
        );
        if (!(bool) ($audit['ok'] ?? false) || $ownerCount !== 1) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'audit_write_failed'];
        }

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'reason' => 'internal_error'];
    }

    return ['ok' => true, 'reason' => 'updated'];
}
