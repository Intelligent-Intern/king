<?php

declare(strict_types=1);

require_once __DIR__ . '/call_management_contract.php';
require_once __DIR__ . '/call_management_query.php';

/**
 * @return array<int, int>
 */
function videochat_call_owner_transfer_active_organization_ids(PDO $pdo, int $tenantId, int $userId): array
{
    if ($tenantId <= 0 || $userId <= 0) {
        return [];
    }
    if (
        !videochat_tenant_table_has_column($pdo, 'organizations', 'tenant_id')
        || !videochat_tenant_table_has_column($pdo, 'organization_memberships', 'tenant_id')
        || !videochat_tenant_table_has_column($pdo, 'organization_memberships', 'membership_role')
    ) {
        return [];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT organization_memberships.organization_id
FROM organization_memberships
INNER JOIN organizations
    ON organizations.id = organization_memberships.organization_id
   AND organizations.tenant_id = organization_memberships.tenant_id
   AND organizations.status = 'active'
WHERE organization_memberships.tenant_id = :tenant_id
  AND organization_memberships.user_id = :user_id
  AND organization_memberships.status = 'active'
ORDER BY organization_memberships.organization_id ASC
SQL
    );
    $query->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);

    $ids = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $organizationId = (int) ($row['organization_id'] ?? 0);
        if ($organizationId > 0) {
            $ids[$organizationId] = $organizationId;
        }
    }

    return array_values($ids);
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   previous_owner_organization_ids: array<int, int>,
 *   target_organization_ids: array<int, int>
 * }
 */
function videochat_call_owner_transfer_target_boundary_check(
    PDO $pdo,
    array $call,
    int $previousOwnerUserId,
    int $targetUserId,
    ?int $tenantId = null
): array {
    if (videochat_active_user_identity($pdo, $targetUserId) === null) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'active_user_not_found'],
            'previous_owner_organization_ids' => [],
            'target_organization_ids' => [],
        ];
    }

    $callTenantId = is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : 0;
    if ($callTenantId <= 0 && is_int($tenantId) && $tenantId > 0) {
        $callTenantId = $tenantId;
    }
    if ($callTenantId <= 0) {
        return [
            'ok' => true,
            'reason' => 'allowed',
            'errors' => [],
            'previous_owner_organization_ids' => [],
            'target_organization_ids' => [],
        ];
    }

    if (!videochat_tenant_user_is_member($pdo, $targetUserId, $callTenantId)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['target_user_id' => 'forbidden_tenant_boundary'],
            'previous_owner_organization_ids' => [],
            'target_organization_ids' => [],
        ];
    }

    $previousOwnerOrganizationIds = videochat_call_owner_transfer_active_organization_ids($pdo, $callTenantId, $previousOwnerUserId);
    $targetOrganizationIds = videochat_call_owner_transfer_active_organization_ids($pdo, $callTenantId, $targetUserId);
    if ($previousOwnerOrganizationIds !== [] && array_intersect($previousOwnerOrganizationIds, $targetOrganizationIds) === []) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['target_user_id' => 'forbidden_organization_boundary'],
            'previous_owner_organization_ids' => $previousOwnerOrganizationIds,
            'target_organization_ids' => $targetOrganizationIds,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'allowed',
        'errors' => [],
        'previous_owner_organization_ids' => $previousOwnerOrganizationIds,
        'target_organization_ids' => $targetOrganizationIds,
    ];
}

function videochat_call_owner_transfer_current_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND source = 'internal'
  AND call_role = 'owner'
SQL
    );
    $query->execute([':call_id' => $callId]);

    return (int) $query->fetchColumn();
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_update_call_participant_role(
    PDO $pdo,
    string $callId,
    int $targetUserId,
    string $targetRole,
    int $authUserId,
    string $authRole,
    ?int $tenantId = null
): array {
    $isSystemAdmin = videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole);
    $existingCall = videochat_fetch_call_for_update($pdo, $callId, $isSystemAdmin ? null : $tenantId);
    if ($existingCall === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }

    $normalizedTargetRole = videochat_normalize_call_participant_role($targetRole, '');
    if ($normalizedTargetRole === '') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'must_be_owner_or_moderator_or_participant'],
            'call' => null,
        ];
    }

    if ($targetUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'must_be_positive_int'],
            'call' => null,
        ];
    }

    $isOwner = $authUserId > 0 && $authUserId === (int) ($existingCall['owner_user_id'] ?? 0);
    $canAdministerCall = videochat_can_administer_call(
        $pdo,
        (string) ($existingCall['id'] ?? $callId),
        $authRole,
        $authUserId,
        (int) ($existingCall['owner_user_id'] ?? 0),
        $tenantId
    );
    if (!$canAdministerCall) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'call' => null,
        ];
    }

    $currentOwnerUserId = (int) ($existingCall['owner_user_id'] ?? 0);
    if ($normalizedTargetRole === 'owner') {
        if (!$isOwner && !$isSystemAdmin) {
            return [
                'ok' => false,
                'reason' => 'forbidden',
                'errors' => ['role' => 'owner_transfer_requires_current_owner'],
                'call' => null,
            ];
        }

        $transferBoundary = videochat_call_owner_transfer_target_boundary_check(
            $pdo,
            $existingCall,
            $currentOwnerUserId,
            $targetUserId,
            $tenantId
        );
        if (!(bool) ($transferBoundary['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($transferBoundary['reason'] ?? 'forbidden'),
                'errors' => is_array($transferBoundary['errors'] ?? null) ? $transferBoundary['errors'] : [],
                'call' => null,
            ];
        }
    } elseif ($targetUserId === $currentOwnerUserId) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['role' => 'cannot_change_current_owner_role'],
            'call' => null,
        ];
    }

    $targetParticipantQuery = $pdo->prepare(
        <<<'SQL'
SELECT user_id, source, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $targetParticipantQuery->execute([
        ':call_id' => (string) ($existingCall['id'] ?? ''),
        ':user_id' => $targetUserId,
    ]);
    $targetParticipant = $targetParticipantQuery->fetch();
    if (!is_array($targetParticipant)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['target_user_id' => 'must_reference_internal_participant'],
            'call' => null,
        ];
    }

    $normalizedCurrentRole = videochat_normalize_call_participant_role((string) ($targetParticipant['call_role'] ?? 'participant'));
    $currentOwnerCount = $normalizedTargetRole === 'owner'
        ? videochat_call_owner_transfer_current_owner_count($pdo, (string) ($existingCall['id'] ?? ''))
        : 0;
    if (
        $normalizedTargetRole === $normalizedCurrentRole
        && !($normalizedTargetRole === 'owner' && ($targetUserId !== $currentOwnerUserId || $currentOwnerCount !== 1))
    ) {
        return [
            'ok' => true,
            'reason' => 'unchanged',
            'errors' => [],
            'call' => videochat_build_call_payload($pdo, $existingCall, $authUserId),
        ];
    }

    $updatedAt = gmdate('c');
    $pdo->beginTransaction();
    try {
        if ($normalizedTargetRole === 'owner') {
            $updateCallOwner = $pdo->prepare(
                'UPDATE calls SET owner_user_id = :owner_user_id, updated_at = :updated_at WHERE id = :id'
            );
            $updateCallOwner->execute([
                ':owner_user_id' => $targetUserId,
                ':updated_at' => $updatedAt,
                ':id' => (string) ($existingCall['id'] ?? ''),
            ]);

            $demotePreviousOwners = $pdo->prepare(
                <<<'SQL'
UPDATE call_participants
SET call_role = 'participant'
WHERE call_id = :call_id
  AND source = 'internal'
  AND user_id IS NOT NULL
  AND user_id <> :target_user_id
  AND call_role = 'owner'
SQL
            );
            $demotePreviousOwners->execute([
                ':call_id' => (string) ($existingCall['id'] ?? ''),
                ':target_user_id' => $targetUserId,
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
                ':call_id' => (string) ($existingCall['id'] ?? ''),
                ':user_id' => $targetUserId,
            ]);

            if (videochat_call_owner_transfer_current_owner_count($pdo, (string) ($existingCall['id'] ?? '')) !== 1) {
                throw new RuntimeException('owner_transfer_invariant_failed');
            }
        } else {
            $updateParticipantRole = $pdo->prepare(
                <<<'SQL'
UPDATE call_participants
SET call_role = :call_role
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
            );
            $updateParticipantRole->execute([
                ':call_role' => $normalizedTargetRole,
                ':call_id' => (string) ($existingCall['id'] ?? ''),
                ':user_id' => $targetUserId,
            ]);

            $touchCall = $pdo->prepare('UPDATE calls SET updated_at = :updated_at WHERE id = :id');
            $touchCall->execute([
                ':updated_at' => $updatedAt,
                ':id' => (string) ($existingCall['id'] ?? ''),
            ]);
        }

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => null,
        ];
    }

    $resultTenantId = $isSystemAdmin && is_numeric($existingCall['tenant_id'] ?? null)
        ? (int) $existingCall['tenant_id']
        : $tenantId;
    $updatedCall = videochat_fetch_call_for_update($pdo, (string) ($existingCall['id'] ?? ''), $resultTenantId);
    if ($updatedCall === null) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'updated',
        'errors' => [],
        'call' => videochat_build_call_payload($pdo, $updatedCall, $authUserId),
    ];
}
