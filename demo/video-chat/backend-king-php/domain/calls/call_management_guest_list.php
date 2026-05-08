<?php

declare(strict_types=1);

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   call_id: string,
 *   room_id: string,
 *   guest_list_entry: ?array{user_id: int, invite_state: string, call_role: string}
 * }
 */
function videochat_user_can_direct_join_call(
    PDO $pdo,
    string $callId,
    int $authUserId,
    string $authRole = 'user',
    ?int $tenantId = null
): array {
    $fallback = [
        'ok' => false,
        'reason' => 'not_on_guest_list',
        'call_id' => trim($callId),
        'room_id' => '',
        'guest_list_entry' => null,
    ];

    if (trim($callId) === '') {
        $fallback['reason'] = 'invalid_call_id';
        return $fallback;
    }
    if ($authUserId <= 0) {
        $fallback['reason'] = 'invalid_user_context';
        return $fallback;
    }

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    if (!is_array($call)) {
        $fallback['reason'] = 'not_found';
        return $fallback;
    }

    $callId = (string) ($call['id'] ?? $callId);
    $roomId = (string) ($call['room_id'] ?? '');
    $status = strtolower(trim((string) ($call['status'] ?? '')));
    if (!in_array($status, ['scheduled', 'active'], true)) {
        return [
            'ok' => false,
            'reason' => 'call_not_joinable_from_status',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if (videochat_normalize_role_slug($authRole) === 'admin') {
        return [
            'ok' => true,
            'reason' => 'system_admin',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if ((int) ($call['owner_user_id'] ?? 0) === $authUserId) {
        return [
            'ok' => true,
            'reason' => 'owner',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    if (videochat_normalize_call_access_mode($call['access_mode'] ?? 'invite_only') === 'free_for_all') {
        return [
            'ok' => true,
            'reason' => 'free_for_all',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    $guestListEntry = $pdo->prepare(
        <<<'SQL'
SELECT user_id, invite_state, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $guestListEntry->execute([
        ':call_id' => $callId,
        ':user_id' => $authUserId,
    ]);
    $entry = $guestListEntry->fetch();
    if (!is_array($entry)) {
        return [
            'ok' => false,
            'reason' => 'not_on_guest_list',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => null,
        ];
    }

    $inviteState = videochat_normalize_call_invite_state($entry['invite_state'] ?? 'invited');
    $normalizedEntry = [
        'user_id' => (int) ($entry['user_id'] ?? 0),
        'invite_state' => $inviteState,
        'call_role' => videochat_normalize_call_participant_role((string) ($entry['call_role'] ?? 'participant')),
    ];
    if (in_array($inviteState, ['declined', 'cancelled'], true)) {
        return [
            'ok' => false,
            'reason' => 'guest_list_entry_inactive',
            'call_id' => $callId,
            'room_id' => $roomId,
            'guest_list_entry' => $normalizedEntry,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'guest_list',
        'call_id' => $callId,
        'room_id' => $roomId,
        'guest_list_entry' => $normalizedEntry,
    ];
}
