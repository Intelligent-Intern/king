<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_fetch_call_for_update(PDO $pdo, string $callId, ?int $tenantId = null): ?array
{
    $trimmedCallId = trim($callId);
    if ($trimmedCallId === '') {
        return null;
    }
    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'calls.tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND calls.tenant_id = :tenant_id' : '';

    $statement = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    calls.room_id,
    calls.title,
    calls.access_mode,
    calls.owner_user_id,
    calls.status,
    calls.starts_at,
    calls.ends_at,
    calls.schedule_timezone,
    calls.schedule_date,
    calls.schedule_duration_minutes,
    calls.schedule_all_day,
    calls.cancelled_at,
    calls.cancel_reason,
    calls.cancel_message,
    calls.created_at,
    calls.updated_at,
    owners.email AS owner_email,
    owners.display_name AS owner_display_name,
    owners.status AS owner_status
FROM calls
INNER JOIN users owners ON owners.id = calls.owner_user_id
WHERE calls.id = :id
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $trimmedCallId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $statement->execute($params);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'room_id' => (string) ($row['room_id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'access_mode' => videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only'),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'scheduled'),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'schedule_timezone' => videochat_normalize_call_schedule_timezone($row['schedule_timezone'] ?? 'UTC'),
        'schedule_date' => (string) ($row['schedule_date'] ?? ''),
        'schedule_duration_minutes' => (int) ($row['schedule_duration_minutes'] ?? 0),
        'schedule_all_day' => (int) ($row['schedule_all_day'] ?? 0),
        'cancelled_at' => is_string($row['cancelled_at'] ?? null) ? (string) $row['cancelled_at'] : null,
        'cancel_reason' => is_string($row['cancel_reason'] ?? null) ? (string) $row['cancel_reason'] : null,
        'cancel_message' => is_string($row['cancel_message'] ?? null) ? (string) $row['cancel_message'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'owner_email' => strtolower((string) ($row['owner_email'] ?? '')),
        'owner_display_name' => (string) ($row['owner_display_name'] ?? ''),
        'owner_status' => strtolower(trim((string) ($row['owner_status'] ?? 'active'))),
    ];
}

function videochat_call_tenant_is_active(PDO $pdo, array $call): bool
{
    $tenantId = is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : null;
    return videochat_tenant_is_active($pdo, $tenantId);
}

function videochat_call_owner_is_active(array $call): bool
{
    return strtolower(trim((string) ($call['owner_status'] ?? 'active'))) === 'active';
}

function videochat_user_has_system_admin_call_rights(PDO $pdo, int $authUserId, string $authRole): bool
{
    if ($authUserId <= 0 || strtolower(trim($authRole)) !== 'admin') {
        return false;
    }

    try {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT users.email, users.password_hash, users.status, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = :user_id
LIMIT 1
SQL
        );
        $query->execute([':user_id' => $authUserId]);
        $row = $query->fetch();
    } catch (Throwable) {
        return false;
    }
    if (!is_array($row)) {
        return false;
    }

    $roleSlug = strtolower(trim((string) ($row['role_slug'] ?? '')));
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($roleSlug !== 'admin' || $status !== 'active') {
        return false;
    }

    $email = strtolower(trim((string) ($row['email'] ?? '')));
    $passwordHash = is_string($row['password_hash'] ?? null) ? trim((string) $row['password_hash']) : '';
    if ($passwordHash === '' && str_starts_with($email, 'guest+') && str_ends_with($email, '@videochat.local')) {
        return false;
    }

    return true;
}

function videochat_can_edit_call(string $authRole, int $authUserId, int $ownerUserId, ?PDO $pdo = null): bool
{
    $role = strtolower(trim($authRole));
    $isOwner = $authUserId > 0 && $ownerUserId > 0 && $authUserId === $ownerUserId;
    if ($isOwner) {
        return true;
    }
    if ($role !== 'admin') {
        return false;
    }
    if ($pdo instanceof PDO) {
        return videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole);
    }

    return true;
}

function videochat_user_is_call_moderator(PDO $pdo, string $callId, int $authUserId): bool
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || $authUserId <= 0) {
        return false;
    }

    $roleQuery = $pdo->prepare(
        <<<'SQL'
SELECT call_participants.call_role, call_participants.invite_state, calls.status
FROM call_participants
INNER JOIN calls ON calls.id = call_participants.call_id
WHERE call_participants.call_id = :call_id
  AND call_participants.user_id = :user_id
  AND call_participants.source = 'internal'
LIMIT 1
SQL
    );
    $roleQuery->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $authUserId,
    ]);

    $row = $roleQuery->fetch();
    if (!is_array($row)) {
        return false;
    }

    if (!in_array(strtolower(trim((string) ($row['status'] ?? ''))), ['active', 'scheduled'], true)) {
        return false;
    }
    if (!videochat_call_invite_state_allows_scoped_role($row['invite_state'] ?? 'invited')) {
        return false;
    }

    $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
    return $callRole === 'owner' || $callRole === 'moderator';
}

function videochat_user_is_organization_admin_for_call(PDO $pdo, array|string $callOrId, int $authUserId, ?int $tenantId = null): bool
{
    if ($authUserId <= 0) {
        return false;
    }
    if (
        !videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
        || !videochat_tenant_table_has_column($pdo, 'organizations', 'tenant_id')
        || !videochat_tenant_table_has_column($pdo, 'organization_memberships', 'membership_role')
        || !videochat_tenant_table_has_column($pdo, 'organization_memberships', 'tenant_id')
    ) {
        return false;
    }

    $call = is_array($callOrId) ? $callOrId : videochat_fetch_call_for_update($pdo, (string) $callOrId, $tenantId);
    if (!is_array($call)) {
        return false;
    }

    $callTenantId = is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : 0;
    $ownerUserId = is_numeric($call['owner_user_id'] ?? null) ? (int) $call['owner_user_id'] : 0;
    if ($callTenantId <= 0 || $ownerUserId <= 0) {
        return false;
    }
    if (is_int($tenantId) && $tenantId > 0 && $tenantId !== $callTenantId) {
        return false;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT 1
FROM organization_memberships admin_membership
INNER JOIN organizations
    ON organizations.id = admin_membership.organization_id
   AND organizations.tenant_id = admin_membership.tenant_id
   AND organizations.status = 'active'
INNER JOIN organization_memberships owner_membership
    ON owner_membership.organization_id = admin_membership.organization_id
   AND owner_membership.tenant_id = admin_membership.tenant_id
   AND owner_membership.status = 'active'
WHERE admin_membership.tenant_id = :tenant_id
  AND admin_membership.user_id = :admin_user_id
  AND admin_membership.membership_role = 'admin'
  AND admin_membership.status = 'active'
  AND owner_membership.user_id = :owner_user_id
LIMIT 1
SQL
    );
    $query->execute([
        ':tenant_id' => $callTenantId,
        ':admin_user_id' => $authUserId,
        ':owner_user_id' => $ownerUserId,
    ]);

    return $query->fetchColumn() !== false;
}

function videochat_can_administer_call(
    PDO $pdo,
    string $callId,
    string $authRole,
    int $authUserId,
    int $ownerUserId,
    ?int $tenantId = null
): bool
{
    if (videochat_can_edit_call($authRole, $authUserId, $ownerUserId, $pdo)) {
        return true;
    }

    if (videochat_user_is_call_moderator($pdo, $callId, $authUserId)) {
        return true;
    }

    return videochat_user_is_organization_admin_for_call($pdo, $callId, $authUserId, $tenantId);
}

function videochat_can_manage_call_guest_list(
    PDO $pdo,
    array $call,
    string $authRole,
    int $authUserId,
    ?int $tenantId = null
): bool {
    if (videochat_can_edit_call($authRole, $authUserId, (int) ($call['owner_user_id'] ?? 0), $pdo)) {
        return true;
    }

    return videochat_user_is_organization_admin_for_call($pdo, $call, $authUserId, $tenantId);
}

/**
 * @return array{
 *   internal: array<int, array{
 *     user_id: int,
 *     email: string,
 *     display_name: string,
 *     call_role: string,
 *     invite_state: string
 *   }>,
 *   external: array<int, array{
 *     email: string,
 *     display_name: string,
 *     invite_state: string
 *   }>
 * }
 */
function videochat_fetch_call_participants(PDO $pdo, string $callId): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT call_id, user_id, email, display_name, source, call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
ORDER BY source ASC, email ASC
SQL
    );
    $statement->execute([':call_id' => $callId]);
    $rows = $statement->fetchAll();

    $internal = [];
    $external = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $source = strtolower(trim((string) ($row['source'] ?? '')));
        $inviteState = videochat_normalize_call_invite_state($row['invite_state'] ?? 'invited');
        if ($source === 'internal') {
            $callRole = strtolower(trim((string) ($row['call_role'] ?? 'participant')));
            if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                $callRole = 'participant';
            }
            $internal[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'email' => strtolower((string) ($row['email'] ?? '')),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'call_role' => $callRole,
                'invite_state' => $inviteState,
            ];
            continue;
        }
        if ($source === 'external') {
            $external[] = [
                'email' => strtolower((string) ($row['email'] ?? '')),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'invite_state' => $inviteState,
            ];
        }
    }

    return [
        'internal' => $internal,
        'external' => $external,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     has_room_id: bool,
 *     room_id: string,
 *     has_title: bool,
 *     title: string,
 *     has_access_mode: bool,
 *     access_mode: string,
 *     has_starts_at: bool,
 *     starts_at_unix: int,
 *     has_ends_at: bool,
 *     ends_at_unix: int,
 *     has_schedule_timezone: bool,
 *     schedule_timezone: string,
 *     has_schedule_all_day: bool,
 *     schedule_all_day: bool,
 *     has_internal_participants: bool,
 *     internal_participant_user_ids: array<int, int>,
 *     has_external_participants: bool,
 *     external_participants: array<int, array{email: string, display_name: string}>
 *   },
 *   errors: array<string, string>
 * }
 */

function videochat_normalize_call_participant_role(string $role, string $fallback = 'participant'): string
{
    $normalized = strtolower(trim($role));
    if (in_array($normalized, ['owner', 'moderator', 'participant'], true)) {
        return $normalized;
    }

    $normalizedFallback = strtolower(trim($fallback));
    if (in_array($normalizedFallback, ['owner', 'moderator', 'participant'], true)) {
        return $normalizedFallback;
    }

    if (trim($fallback) === '') {
        return '';
    }

    return 'participant';
}

/**
 * @return array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   access_mode: string,
 *   status: string,
 *   starts_at: string,
 *   ends_at: string,
 *   schedule: array{
 *     timezone: string,
 *     date: string,
 *     starts_at_local: string,
 *     ends_at_local: string,
 *     duration_minutes: int,
 *     all_day: bool
 *   },
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   cancel_message: ?string,
 *   created_at: string,
 *   updated_at: string,
 *   owner: array{user_id: int, email: string, display_name: string},
 *   participants: array{
 *     internal: array<int, array{
 *       user_id: int,
 *       email: string,
 *       display_name: string,
 *       call_role: string,
 *       invite_state: string,
 *       is_owner: bool,
 *       is_moderator: bool
 *     }>,
 *     external: array<int, array{
 *       email: string,
 *       display_name: string,
 *       invite_state: string
 *     }>,
 *     totals: array{total: int, internal: int, external: int}
 *   },
 *   my_participation: bool
 * }
 */
function videochat_build_call_payload(PDO $pdo, array $callRecord, int $authUserId): array
{
    $participants = videochat_fetch_call_participants($pdo, (string) ($callRecord['id'] ?? ''));
    $internalParticipants = array_map(
        static function (array $participant): array {
            $callRole = videochat_normalize_call_participant_role((string) ($participant['call_role'] ?? 'participant'));
            return [
                'user_id' => (int) ($participant['user_id'] ?? 0),
                'email' => (string) ($participant['email'] ?? ''),
                'display_name' => (string) ($participant['display_name'] ?? ''),
                'call_role' => $callRole,
                'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
                'is_owner' => $callRole === 'owner',
                'is_moderator' => $callRole === 'moderator',
            ];
        },
        (array) ($participants['internal'] ?? [])
    );
    $externalParticipants = array_map(
        static function (array $participant): array {
            return [
                'email' => (string) ($participant['email'] ?? ''),
                'display_name' => (string) ($participant['display_name'] ?? ''),
                'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
            ];
        },
        (array) ($participants['external'] ?? [])
    );

    $myParticipation = $authUserId > 0 && $authUserId === (int) ($callRecord['owner_user_id'] ?? 0);
    if (!$myParticipation && $authUserId > 0) {
        foreach ($internalParticipants as $participant) {
            if ((int) ($participant['user_id'] ?? 0) === $authUserId) {
                $myParticipation = true;
                break;
            }
        }
    }

    return [
        'id' => (string) ($callRecord['id'] ?? ''),
        'tenant_id' => is_numeric($callRecord['tenant_id'] ?? null) ? (int) $callRecord['tenant_id'] : null,
        'room_id' => (string) ($callRecord['room_id'] ?? ''),
        'title' => (string) ($callRecord['title'] ?? ''),
        'access_mode' => videochat_normalize_call_access_mode((string) ($callRecord['access_mode'] ?? 'invite_only')),
        'status' => (string) ($callRecord['status'] ?? ''),
        'starts_at' => (string) ($callRecord['starts_at'] ?? ''),
        'ends_at' => (string) ($callRecord['ends_at'] ?? ''),
        'schedule' => videochat_call_schedule_from_row($callRecord),
        'cancelled_at' => is_string($callRecord['cancelled_at'] ?? null) ? (string) $callRecord['cancelled_at'] : null,
        'cancel_reason' => is_string($callRecord['cancel_reason'] ?? null) ? (string) $callRecord['cancel_reason'] : null,
        'cancel_message' => is_string($callRecord['cancel_message'] ?? null) ? (string) $callRecord['cancel_message'] : null,
        'created_at' => (string) ($callRecord['created_at'] ?? ''),
        'updated_at' => (string) ($callRecord['updated_at'] ?? ''),
        'owner' => [
            'user_id' => (int) ($callRecord['owner_user_id'] ?? 0),
            'email' => (string) ($callRecord['owner_email'] ?? ''),
            'display_name' => (string) ($callRecord['owner_display_name'] ?? ''),
        ],
        'participants' => [
            'internal' => $internalParticipants,
            'external' => $externalParticipants,
            'totals' => [
                'total' => count($internalParticipants) + count($externalParticipants),
                'internal' => count($internalParticipants),
                'external' => count($externalParticipants),
            ],
        ],
        'my_participation' => $myParticipation,
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
function videochat_call_role_context_for_room_user(PDO $pdo, string $roomId, int $userId): array
{
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
    if ($userId <= 0) {
        return $fallback;
    }

    $normalizedRoomId = strtolower(trim($roomId));
    if ($normalizedRoomId === '') {
        return $fallback;
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'calls.tenant_id,' : 'NULL AS tenant_id,';
    $contextFromRow = static function (array $row, bool $isOrganizationAdmin) use ($userId): array {
        $isFreeForAll = videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only') === 'free_for_all';
        $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
        $isCallOwner = (int) ($row['owner_user_id'] ?? 0) === $userId;
        if ($isCallOwner) {
            $callRole = 'owner';
        }
        $effectiveCallRole = $isOrganizationAdmin && $callRole !== 'owner' ? 'moderator' : $callRole;
        $inviteState = $isOrganizationAdmin
            ? 'allowed'
            : videochat_normalize_call_invite_state($row['invite_state'] ?? ($isFreeForAll ? 'allowed' : 'invited'));
        $scopedRoleActive = $isCallOwner || videochat_call_invite_state_allows_scoped_role($inviteState);

        return [
            'call_id' => (string) ($row['id'] ?? ''),
            'call_role' => $callRole,
            'effective_call_role' => $effectiveCallRole,
            'invite_state' => $inviteState,
            'joined_at' => trim((string) ($row['joined_at'] ?? '')),
            'left_at' => trim((string) ($row['left_at'] ?? '')),
            'can_moderate' => $isOrganizationAdmin || ($scopedRoleActive && in_array($callRole, ['owner', 'moderator'], true)),
            'can_manage_owner' => $scopedRoleActive && $callRole === 'owner',
        ];
    };

    $query = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    calls.access_mode,
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
  AND calls.status IN ('active', 'scheduled')
  AND (
      calls.owner_user_id = :user_id
      OR (cp.user_id IS NOT NULL AND cp.invite_state NOT IN ('declined', 'cancelled'))
      OR calls.access_mode = 'free_for_all'
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
    $query->execute([
        ':room_id' => $normalizedRoomId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch();
    if (is_array($row)) {
        return $contextFromRow($row, videochat_user_is_organization_admin_for_call($pdo, $row, $userId));
    }

    $organizationAdminQuery = $pdo->prepare(
        <<<SQL
SELECT
    calls.id,
    {$tenantSelect}
    calls.access_mode,
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
    $organizationAdminQuery->execute([
        ':room_id' => $normalizedRoomId,
        ':user_id' => $userId,
    ]);
    $candidateRows = $organizationAdminQuery->fetchAll();
    foreach ($candidateRows as $candidateRow) {
        if (!is_array($candidateRow)) {
            continue;
        }
        if (videochat_user_is_organization_admin_for_call($pdo, $candidateRow, $userId)) {
            return $contextFromRow($candidateRow, true);
        }
    }

    return $fallback;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_get_call_for_user(PDO $pdo, string $callId, int $authUserId, string $authRole, ?int $tenantId = null): array
{
    $isSystemAdmin = videochat_user_has_system_admin_call_rights($pdo, $authUserId, $authRole);
    $call = videochat_fetch_call_for_update($pdo, $callId, $isSystemAdmin ? null : $tenantId);
    if ($call === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }
    if (!videochat_call_tenant_is_active($pdo, $call) || !videochat_call_owner_is_active($call)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }

    if (!$isSystemAdmin) {
        $isOwner = $authUserId > 0 && $authUserId === (int) ($call['owner_user_id'] ?? 0);
        $participantCheck = $pdo->prepare(
            <<<'SQL'
SELECT invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
        );
        $participantCheck->execute([
            ':call_id' => (string) ($call['id'] ?? ''),
            ':user_id' => $authUserId,
        ]);
        $participantInviteState = $participantCheck->fetchColumn();
        $isInternalParticipant = is_string($participantInviteState) || is_numeric($participantInviteState)
            ? !in_array(videochat_normalize_call_invite_state($participantInviteState), ['pending', 'declined', 'cancelled'], true)
            : false;

        $isFreeForAll = videochat_normalize_call_access_mode($call['access_mode'] ?? 'invite_only') === 'free_for_all';
        $isOrganizationAdmin = videochat_user_is_organization_admin_for_call($pdo, $call, $authUserId, $tenantId);
        if (!$isOwner && !$isInternalParticipant && !$isFreeForAll && !$isOrganizationAdmin) {
            return [
                'ok' => false,
                'reason' => 'forbidden',
                'errors' => [],
                'call' => null,
            ];
        }
    }

    return [
        'ok' => true,
        'reason' => 'ok',
        'errors' => [],
        'call' => videochat_build_call_payload($pdo, $call, $authUserId),
    ];
}
