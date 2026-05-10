<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';
require_once __DIR__ . '/../calls/call_management_contract.php';
require_once __DIR__ . '/../calls/call_management_query.php';
require_once __DIR__ . '/realtime_connection_contract.php';
require_once __DIR__ . '/realtime_presence.php';

/**
 * @return array{
 *   call_id: string,
 *   call_role: string,
 *   effective_call_role: string,
 *   invite_state: string,
 *   joined_at: string,
 *   left_at: string,
 *   can_moderate: bool,
 *   can_manage_owner: bool
 * }
 */
function videochat_realtime_call_role_context_for_room_user(
    PDO $pdo,
    string $roomId,
    int $userId,
    string $preferredCallId = '',
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $isAdmin = videochat_normalize_role_slug($authRole) === 'admin';
    $fallback = [
        'call_id' => '',
        'call_role' => 'participant',
        'effective_call_role' => 'participant',
        'invite_state' => 'invited',
        'joined_at' => '',
        'left_at' => '',
        'can_moderate' => false,
        'can_manage_owner' => false,
    ];
    if ($normalizedRoomId === '' || $userId <= 0) {
        return $fallback;
    }

    $callsHaveAccessMode = videochat_tenant_table_has_column($pdo, 'calls', 'access_mode');
    $callsHaveTenantId = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id');
    $accessModeSelect = $callsHaveAccessMode
        ? 'calls.access_mode'
        : "'invite_only' AS access_mode";
    $tenantSelect = $callsHaveTenantId
        ? 'calls.tenant_id,'
        : 'NULL AS tenant_id,';
    $freeForAllPredicate = $callsHaveAccessMode
        ? "      OR calls.access_mode = 'free_for_all'\n"
        : '';
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && $callsHaveTenantId
        ? '  AND calls.tenant_id = :tenant_id'
        : '';
    $contextFromRow = static function (array $row, bool $isOrganizationAdmin) use ($userId, $isAdmin): array {
        $isFreeForAll = videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only') === 'free_for_all';
        $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
        if ((int) ($row['owner_user_id'] ?? 0) === $userId) {
            $callRole = 'owner';
        }
        $effectiveCallRole = $isAdmin ? 'owner' : ($isOrganizationAdmin && $callRole !== 'owner' ? 'moderator' : $callRole);
        $inviteState = $isOrganizationAdmin
            ? 'allowed'
            : videochat_realtime_normalize_call_invite_state(
                $row['invite_state'] ?? ($isFreeForAll ? 'allowed' : 'invited')
            );

        return [
            'call_id' => (string) ($row['id'] ?? ''),
            'call_role' => $callRole,
            'effective_call_role' => $effectiveCallRole,
            'invite_state' => $inviteState,
            'joined_at' => trim((string) ($row['joined_at'] ?? '')),
            'left_at' => trim((string) ($row['left_at'] ?? '')),
            'can_moderate' => $isAdmin || $isOrganizationAdmin || in_array($callRole, ['owner', 'moderator'], true),
            'can_manage_owner' => $isAdmin || $callRole === 'owner',
        ];
    };
    if ($normalizedPreferredCallId !== '' && $normalizedRoomId !== '' && $userId > 0) {
        $preferredQuery = $pdo->prepare(
            <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    {$accessModeSelect},
    calls.owner_user_id,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  AND calls.room_id = :room_id
{$tenantWhere}
  AND calls.status IN ('active', 'scheduled')
  AND (
      CAST(:is_admin AS INTEGER) = 1
      OR
      calls.owner_user_id = :user_id
      OR cp.user_id IS NOT NULL
{$freeForAllPredicate}
  )
LIMIT 1
SQL
        );
        $params = [
            ':call_id' => $normalizedPreferredCallId,
            ':room_id' => $normalizedRoomId,
            ':user_id' => $userId,
            ':is_admin' => $isAdmin ? 1 : 0,
        ];
        if ($tenantWhere !== '') {
            $params[':tenant_id'] = $tenantId;
        }
        $preferredQuery->execute($params);
        $preferredRow = $preferredQuery->fetch();
        if (is_array($preferredRow)) {
            return $contextFromRow(
                $preferredRow,
                !$isAdmin && videochat_user_is_organization_admin_for_call($pdo, $preferredRow, $userId, $tenantId)
            );
        }

        $organizationAdminPreferredQuery = $pdo->prepare(
            <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    {$accessModeSelect},
    calls.owner_user_id,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.id = :call_id
  AND calls.room_id = :room_id
{$tenantWhere}
  AND calls.status IN ('active', 'scheduled')
LIMIT 1
SQL
        );
        $organizationAdminPreferredParams = [
            ':call_id' => $normalizedPreferredCallId,
            ':room_id' => $normalizedRoomId,
            ':user_id' => $userId,
        ];
        if ($tenantWhere !== '') {
            $organizationAdminPreferredParams[':tenant_id'] = $tenantId;
        }
        $organizationAdminPreferredQuery->execute($organizationAdminPreferredParams);
        $organizationAdminPreferredRow = $organizationAdminPreferredQuery->fetch();
        if (
            is_array($organizationAdminPreferredRow)
            && videochat_user_is_organization_admin_for_call($pdo, $organizationAdminPreferredRow, $userId, $tenantId)
        ) {
            return $contextFromRow($organizationAdminPreferredRow, true);
        }

        return $fallback;
    }

    $query = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    {$accessModeSelect},
    calls.owner_user_id,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.room_id = :room_id
{$tenantWhere}
  AND calls.status IN ('active', 'scheduled')
  AND (
      CAST(:is_admin AS INTEGER) = 1
      OR
      calls.owner_user_id = :user_id
      OR cp.user_id IS NOT NULL
{$freeForAllPredicate}
  )
ORDER BY
    CASE calls.status
        WHEN 'active' THEN 0
        ELSE 1
    END ASC,
    calls.starts_at ASC,
    calls.created_at ASC
LIMIT 1
SQL
    );
    $params = [
        ':room_id' => $normalizedRoomId,
        ':user_id' => $userId,
        ':is_admin' => $isAdmin ? 1 : 0,
    ];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $query->execute($params);
    $row = $query->fetch();
    if (is_array($row)) {
        return $contextFromRow(
            $row,
            !$isAdmin && videochat_user_is_organization_admin_for_call($pdo, $row, $userId, $tenantId)
        );
    }

    $organizationAdminQuery = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    {$accessModeSelect},
    calls.owner_user_id,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at
FROM calls
LEFT JOIN call_participants cp
    ON cp.call_id = calls.id
   AND cp.user_id = :user_id
   AND cp.source = 'internal'
WHERE calls.room_id = :room_id
{$tenantWhere}
  AND calls.status IN ('active', 'scheduled')
ORDER BY
    CASE calls.status
        WHEN 'active' THEN 0
        ELSE 1
    END ASC,
    calls.starts_at ASC,
    calls.created_at ASC
SQL
    );
    $organizationAdminParams = [
        ':room_id' => $normalizedRoomId,
        ':user_id' => $userId,
    ];
    if ($tenantWhere !== '') {
        $organizationAdminParams[':tenant_id'] = $tenantId;
    }
    $organizationAdminQuery->execute($organizationAdminParams);
    $candidateRows = $organizationAdminQuery->fetchAll();
    foreach ($candidateRows as $candidateRow) {
        if (!is_array($candidateRow)) {
            continue;
        }
        if (videochat_user_is_organization_admin_for_call($pdo, $candidateRow, $userId, $tenantId)) {
            return $contextFromRow($candidateRow, true);
        }
    }

    return $fallback;
}
