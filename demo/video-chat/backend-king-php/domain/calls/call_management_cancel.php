<?php

declare(strict_types=1);

function videochat_validate_cancel_call_payload(array $payload): array
{
    $errors = [];

    $cancelReasonRaw = $payload['cancel_reason'] ?? ($payload['reason'] ?? '');
    if (!(is_string($cancelReasonRaw) || is_numeric($cancelReasonRaw) || $cancelReasonRaw === null)) {
        $errors['cancel_reason'] = 'must_be_string';
        $cancelReasonRaw = '';
    }
    $cancelReason = trim((string) $cancelReasonRaw);
    if ($cancelReason === '') {
        $errors['cancel_reason'] = 'required_non_empty_string';
    } elseif (strlen($cancelReason) > 160) {
        $errors['cancel_reason'] = 'max_length_160';
    }

    $cancelMessageRaw = $payload['cancel_message'] ?? ($payload['message'] ?? '');
    if (!(is_string($cancelMessageRaw) || is_numeric($cancelMessageRaw) || $cancelMessageRaw === null)) {
        $errors['cancel_message'] = 'must_be_string';
        $cancelMessageRaw = '';
    }
    $cancelMessage = trim((string) $cancelMessageRaw);
    if ($cancelMessage === '') {
        $errors['cancel_message'] = 'required_non_empty_string';
    } elseif (strlen($cancelMessage) > 4000) {
        $errors['cancel_message'] = 'max_length_4000';
    }

    return [
        'ok' => $errors === [],
        'data' => [
            'cancel_reason' => $cancelReason,
            'cancel_message' => $cancelMessage,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_cancel_call(PDO $pdo, string $callId, int $authUserId, string $authRole, array $payload): array
{
    $existingCall = videochat_fetch_call_for_update($pdo, $callId);
    if ($existingCall === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }

    if (!videochat_can_edit_call($authRole, $authUserId, (int) $existingCall['owner_user_id'])) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'call' => null,
        ];
    }

    $currentStatus = (string) ($existingCall['status'] ?? '');
    if ($currentStatus === 'cancelled') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['status' => 'already_cancelled'],
            'call' => null,
        ];
    }
    if (!in_array($currentStatus, ['scheduled', 'active'], true)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['status' => 'transition_not_allowed'],
            'call' => null,
        ];
    }

    $validation = videochat_validate_cancel_call_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'call' => null,
        ];
    }

    $cancelReason = (string) ($validation['data']['cancel_reason'] ?? '');
    $cancelMessage = (string) ($validation['data']['cancel_message'] ?? '');
    $cancelledAt = gmdate('c');
    $updatedAt = $cancelledAt;

    $pdo->beginTransaction();
    try {
        $updateCall = $pdo->prepare(
            <<<'SQL'
UPDATE calls
SET status = :status,
    cancelled_at = :cancelled_at,
    cancel_reason = :cancel_reason,
    cancel_message = :cancel_message,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $updateCall->execute([
            ':status' => 'cancelled',
            ':cancelled_at' => $cancelledAt,
            ':cancel_reason' => $cancelReason,
            ':cancel_message' => $cancelMessage,
            ':updated_at' => $updatedAt,
            ':id' => (string) $existingCall['id'],
        ]);

        $updateParticipants = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled',
    left_at = CASE
        WHEN joined_at IS NOT NULL AND left_at IS NULL THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
SQL
        );
        $updateParticipants->execute([
            ':left_at' => $cancelledAt,
            ':call_id' => (string) $existingCall['id'],
        ]);

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

    $participants = videochat_fetch_call_participants($pdo, (string) $existingCall['id']);
    $internalParticipants = array_map(
        static function (array $participant) use ($existingCall): array {
            $userId = (int) ($participant['user_id'] ?? 0);
            $callRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
            if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                $callRole = 'participant';
            }
            return [
                'user_id' => $userId,
                'email' => (string) ($participant['email'] ?? ''),
                'display_name' => (string) ($participant['display_name'] ?? ''),
                'call_role' => $callRole,
                'invite_state' => (string) ($participant['invite_state'] ?? 'cancelled'),
                'is_owner' => $userId > 0 && $userId === (int) ($existingCall['owner_user_id'] ?? 0),
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
                'invite_state' => (string) ($participant['invite_state'] ?? 'cancelled'),
            ];
        },
        (array) ($participants['external'] ?? [])
    );

    return [
        'ok' => true,
        'reason' => 'cancelled',
        'errors' => [],
        'call' => [
            'id' => (string) $existingCall['id'],
            'room_id' => (string) $existingCall['room_id'],
            'title' => (string) $existingCall['title'],
            'access_mode' => videochat_normalize_call_access_mode((string) ($existingCall['access_mode'] ?? 'invite_only')),
            'status' => 'cancelled',
            'starts_at' => (string) $existingCall['starts_at'],
            'ends_at' => (string) $existingCall['ends_at'],
            'schedule' => videochat_call_schedule_from_row($existingCall),
            'cancelled_at' => $cancelledAt,
            'cancel_reason' => $cancelReason,
            'cancel_message' => $cancelMessage,
            'created_at' => (string) $existingCall['created_at'],
            'updated_at' => $updatedAt,
            'owner' => [
                'user_id' => (int) $existingCall['owner_user_id'],
                'email' => (string) $existingCall['owner_email'],
                'display_name' => (string) $existingCall['owner_display_name'],
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
            'my_participation' => false,
        ],
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array{
 *     id: string,
 *     room_id: string,
 *     title: string,
 *     owner_user_id: int,
 *     status: string
 *   }
 * }
 */
function videochat_delete_call(PDO $pdo, string $callId, int $authUserId, string $authRole): array
{
    $existingCall = videochat_fetch_call_for_update($pdo, $callId);
    if ($existingCall === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }

    if (!videochat_can_edit_call($authRole, $authUserId, (int) $existingCall['owner_user_id'])) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'call' => null,
        ];
    }

    $pdo->beginTransaction();
    try {
        $deleteCall = $pdo->prepare(
            <<<'SQL'
DELETE FROM calls
WHERE id = :id
SQL
        );
        $deleteCall->execute([
            ':id' => (string) $existingCall['id'],
        ]);

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

    return [
        'ok' => true,
        'reason' => 'deleted',
        'errors' => [],
        'call' => [
            'id' => (string) $existingCall['id'],
            'room_id' => (string) $existingCall['room_id'],
            'title' => (string) $existingCall['title'],
            'owner_user_id' => (int) $existingCall['owner_user_id'],
            'status' => (string) $existingCall['status'],
        ],
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   deleted_count: int
 * }
 */
function videochat_delete_all_calls(PDO $pdo, int $authUserId, string $authRole, array $payload): array
{
    if ($authUserId <= 0 || strtolower(trim($authRole)) !== 'admin') {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'deleted_count' => 0,
        ];
    }

    $confirm = $payload['confirm'] ?? '';
    if ($confirm !== 'delete_all_calls') {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => [
                'confirm' => 'must_equal_delete_all_calls',
            ],
            'deleted_count' => 0,
        ];
    }

    $pdo->beginTransaction();
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM calls')->fetchColumn();
        $pdo->exec('DELETE FROM calls');
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'deleted_count' => 0,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'deleted',
        'errors' => [],
        'deleted_count' => $count,
    ];
}
