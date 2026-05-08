<?php

declare(strict_types=1);

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
function videochat_realtime_call_role_context_fallback(): array
{
    return [
        'call_id' => '',
        'call_role' => 'participant',
        'effective_call_role' => 'participant',
        'invite_state' => 'invited',
        'joined_at' => '',
        'left_at' => '',
        'can_moderate' => false,
        'can_manage_owner' => false,
    ];
}

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
function videochat_realtime_call_role_context_from_row(
    array $row,
    int $userId,
    bool $isSystemAdmin,
    bool $isOrganizationAdmin
): array {
    $isFreeForAll = videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only') === 'free_for_all';
    $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
    if ((int) ($row['owner_user_id'] ?? 0) === $userId) {
        $callRole = 'owner';
    }

    if ($isSystemAdmin) {
        $effectiveCallRole = 'owner';
    } elseif ($isOrganizationAdmin && $callRole !== 'owner') {
        $effectiveCallRole = 'moderator';
    } else {
        $effectiveCallRole = $callRole;
    }

    $inviteState = ($isSystemAdmin || $isOrganizationAdmin)
        ? 'allowed'
        : videochat_realtime_normalize_call_invite_state(
            $row['invite_state'] ?? ($isFreeForAll ? 'allowed' : 'invited')
        );
    $canModerate = $isSystemAdmin || $isOrganizationAdmin || in_array($callRole, ['owner', 'moderator'], true);
    $canManageOwner = $isSystemAdmin || $callRole === 'owner';

    return [
        'call_id' => (string) ($row['id'] ?? ''),
        'call_role' => $callRole,
        'effective_call_role' => $effectiveCallRole,
        'invite_state' => $inviteState,
        'joined_at' => trim((string) ($row['joined_at'] ?? '')),
        'left_at' => trim((string) ($row['left_at'] ?? '')),
        'can_moderate' => $canModerate,
        'can_manage_owner' => $canManageOwner,
    ];
}

function videochat_realtime_call_role_context_row_allows_user(
    array $row,
    int $userId,
    bool $isSystemAdmin,
    bool $isOrganizationAdmin
): bool {
    if ($isSystemAdmin || $isOrganizationAdmin) {
        return true;
    }
    if ((int) ($row['owner_user_id'] ?? 0) === $userId) {
        return true;
    }
    if (is_numeric($row['participant_user_id'] ?? null) && (int) $row['participant_user_id'] === $userId) {
        return true;
    }

    return videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only') === 'free_for_all';
}

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
    $fallback = videochat_realtime_call_role_context_fallback();
    if ($normalizedRoomId === '' || $userId <= 0) {
        return $fallback;
    }

    $callsHaveAccessMode = videochat_tenant_table_has_column($pdo, 'calls', 'access_mode');
    $accessModeSelect = $callsHaveAccessMode ? 'calls.access_mode' : "'invite_only' AS access_mode";
    $tenantSelect = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
        ? 'calls.tenant_id,'
        : 'NULL AS tenant_id,';
    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
        ? '  AND calls.tenant_id = :tenant_id'
        : '';
    $isSystemAdmin = videochat_user_has_system_admin_call_rights($pdo, $userId, $authRole);

    $selectSql = <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    {$accessModeSelect},
    calls.owner_user_id,
    cp.user_id AS participant_user_id,
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
SQL;

    $params = [
        ':room_id' => $normalizedRoomId,
        ':user_id' => $userId,
    ];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }

    if ($normalizedPreferredCallId !== '') {
        $query = $pdo->prepare($selectSql . "\n  AND calls.id = :call_id\nLIMIT 1");
        $params[':call_id'] = $normalizedPreferredCallId;
        $query->execute($params);
        $row = $query->fetch();
        if (!is_array($row)) {
            return $fallback;
        }

        $isOrganizationAdmin = !$isSystemAdmin && videochat_user_is_organization_admin_for_call($pdo, $row, $userId, $tenantId);
        if (!videochat_realtime_call_role_context_row_allows_user($row, $userId, $isSystemAdmin, $isOrganizationAdmin)) {
            return $fallback;
        }

        return videochat_realtime_call_role_context_from_row($row, $userId, $isSystemAdmin, $isOrganizationAdmin);
    }

    $query = $pdo->prepare(
        $selectSql . <<<'SQL'

ORDER BY
    CASE calls.status
        WHEN 'active' THEN 0
        ELSE 1
    END ASC,
    calls.starts_at ASC,
    calls.created_at ASC
SQL
    );
    $query->execute($params);
    foreach ($query->fetchAll() ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $isOrganizationAdmin = !$isSystemAdmin && videochat_user_is_organization_admin_for_call($pdo, $row, $userId, $tenantId);
        if (!videochat_realtime_call_role_context_row_allows_user($row, $userId, $isSystemAdmin, $isOrganizationAdmin)) {
            continue;
        }

        return videochat_realtime_call_role_context_from_row($row, $userId, $isSystemAdmin, $isOrganizationAdmin);
    }

    return $fallback;
}
