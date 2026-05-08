<?php

declare(strict_types=1);

/**
 * @return array{
 *   allowed: bool,
 *   reason: string,
 *   source: string,
 *   scope: string,
 *   call_id: string,
 *   room_id: string,
 *   tenant_id: ?int,
 *   access_mode: string,
 *   call_role: string,
 *   effective_call_role: string,
 *   invite_state: string,
 *   can_administer: bool,
 *   can_moderate: bool,
 *   can_manage_owner: bool
 * }
 */
function videochat_decide_call_access_for_user(
    PDO $pdo,
    string $callId,
    int $authUserId,
    string $authRole,
    ?int $tenantId = null
): array {
    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    if (!is_array($call)) {
        return videochat_call_access_decision_result(false, 'not_found');
    }

    if (!videochat_is_call_joinable_status((string) ($call['status'] ?? ''))) {
        return videochat_call_access_decision_result(
            false,
            'call_not_joinable_from_status',
            'none',
            'none',
            $call
        );
    }

    if ($authUserId <= 0 || !is_array(videochat_fetch_active_user_for_call_access($pdo, $authUserId, null, null, false))) {
        return videochat_call_access_decision_result(
            false,
            'invalid_user',
            'none',
            'none',
            $call
        );
    }

    $normalizedCallId = (string) ($call['id'] ?? '');
    $accessMode = videochat_normalize_call_access_mode($call['access_mode'] ?? 'invite_only');
    $ownerUserId = (int) ($call['owner_user_id'] ?? 0);
    $normalizedRole = videochat_normalize_role_slug($authRole);
    $isAdmin = $normalizedRole === 'admin';

    if (!videochat_is_call_joinable_status((string) ($call['status'] ?? ''))) {
        return videochat_call_access_decision_result(
            false,
            'call_not_joinable',
            'none',
            'none',
            $call
        );
    }

    $participant = $authUserId > 0
        ? videochat_fetch_call_access_participant_for_decision($pdo, $normalizedCallId, $authUserId)
        : null;
    $callRole = is_array($participant)
        ? videochat_normalize_call_participant_role((string) ($participant['call_role'] ?? 'participant'))
        : 'participant';
    if ($authUserId > 0 && $authUserId === $ownerUserId) {
        $callRole = 'owner';
    }

    $inviteState = is_array($participant)
        ? videochat_normalize_call_invite_state($participant['invite_state'] ?? ($accessMode === 'free_for_all' ? 'allowed' : 'invited'))
        : videochat_normalize_call_invite_state($accessMode === 'free_for_all' ? 'allowed' : 'invited');

    if ($isAdmin) {
        return videochat_call_access_decision_result(
            true,
            'allowed',
            'system_admin',
            'system',
            $call,
            $callRole,
            'owner',
            $inviteState
        );
    }

    if ($authUserId > 0 && $authUserId === $ownerUserId) {
        return videochat_call_access_decision_result(
            true,
            'allowed',
            'owner',
            'call',
            $call,
            'owner',
            'owner',
            $inviteState
        );
    }

    if (is_array($participant)) {
        return videochat_call_access_decision_result(
            true,
            'allowed',
            'internal_participant',
            'call',
            $call,
            $callRole,
            $callRole,
            $inviteState
        );
    }

    if ($accessMode === 'free_for_all') {
        return videochat_call_access_decision_result(
            true,
            'allowed',
            'free_for_all',
            'call',
            $call,
            'participant',
            'participant',
            'allowed'
        );
    }

    return videochat_call_access_decision_result(
        false,
        'forbidden',
        'none',
        'none',
        $call,
        'participant',
        'participant',
        'invited'
    );
}

/**
 * @return array<string, mixed>|null
 */
function videochat_fetch_call_access_participant_for_decision(PDO $pdo, string $callId, int $userId): ?array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '' || $userId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    call_id,
    user_id,
    email,
    display_name,
    source,
    call_role,
    invite_state,
    joined_at,
    left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'call_id' => (string) ($row['call_id'] ?? ''),
        'user_id' => is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0,
        'email' => strtolower((string) ($row['email'] ?? '')),
        'display_name' => (string) ($row['display_name'] ?? ''),
        'source' => (string) ($row['source'] ?? 'internal'),
        'call_role' => videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant')),
        'invite_state' => videochat_normalize_call_invite_state($row['invite_state'] ?? 'invited'),
        'joined_at' => trim((string) ($row['joined_at'] ?? '')),
        'left_at' => trim((string) ($row['left_at'] ?? '')),
    ];
}

/**
 * @return array{
 *   allowed: bool,
 *   reason: string,
 *   source: string,
 *   scope: string,
 *   call_id: string,
 *   room_id: string,
 *   tenant_id: ?int,
 *   access_mode: string,
 *   call_role: string,
 *   effective_call_role: string,
 *   invite_state: string,
 *   can_administer: bool,
 *   can_moderate: bool,
 *   can_manage_owner: bool
 * }
 */
function videochat_call_access_decision_result(
    bool $allowed,
    string $reason,
    string $source = 'none',
    string $scope = 'none',
    ?array $call = null,
    string $callRole = 'participant',
    string $effectiveCallRole = 'participant',
    string $inviteState = 'invited'
): array {
    $normalizedCallRole = videochat_normalize_call_participant_role($callRole);
    $normalizedEffectiveRole = videochat_normalize_call_participant_role($effectiveCallRole);
    $normalizedInviteState = videochat_normalize_call_invite_state($inviteState);
    $canAdminister = $allowed && ($source === 'system_admin' || in_array($normalizedEffectiveRole, ['owner', 'moderator'], true));
    $canManageOwner = $allowed && ($source === 'system_admin' || $normalizedEffectiveRole === 'owner');

    return [
        'allowed' => $allowed,
        'reason' => $reason,
        'source' => $allowed ? $source : 'none',
        'scope' => $allowed ? $scope : 'none',
        'call_id' => is_array($call) ? (string) ($call['id'] ?? '') : '',
        'room_id' => is_array($call) ? (string) ($call['room_id'] ?? '') : '',
        'tenant_id' => is_array($call) && is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : null,
        'access_mode' => is_array($call) ? videochat_normalize_call_access_mode($call['access_mode'] ?? 'invite_only') : 'invite_only',
        'call_role' => $normalizedCallRole,
        'effective_call_role' => $normalizedEffectiveRole,
        'invite_state' => $normalizedInviteState,
        'can_administer' => $canAdminister,
        'can_moderate' => $canAdminister,
        'can_manage_owner' => $canManageOwner,
    ];
}
