<?php

declare(strict_types=1);

function videochat_generate_call_id(): string
{
    try {
        return 'call_' . bin2hex(random_bytes(12));
    } catch (Throwable) {
        return 'call_' . hash('sha256', uniqid((string) mt_rand(), true) . microtime(true));
    }
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     room_id: string,
 *     title: string,
 *     starts_at: string,
 *     ends_at: string,
 *     internal_participant_user_ids: array<int, int>,
 *     external_participants: array<int, array{email: string, display_name: string}>
 *   },
 *   errors: array<string, string>
 * }
 */
function videochat_validate_create_call_payload(array $payload): array
{
    $errors = [];

    $roomId = trim((string) ($payload['room_id'] ?? 'lobby'));
    if ($roomId === '') {
        $errors['room_id'] = 'required_room_id';
    } elseif (strlen($roomId) > 120) {
        $errors['room_id'] = 'room_id_too_long';
    }

    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $errors['title'] = 'required_title';
    } elseif (strlen($title) > 200) {
        $errors['title'] = 'title_too_long';
    }

    $startsAtRaw = trim((string) ($payload['starts_at'] ?? ''));
    $endsAtRaw = trim((string) ($payload['ends_at'] ?? ''));
    $startsAtUnix = strtotime($startsAtRaw);
    $endsAtUnix = strtotime($endsAtRaw);
    if ($startsAtRaw === '' || !is_int($startsAtUnix)) {
        $errors['starts_at'] = 'required_valid_datetime';
    }
    if ($endsAtRaw === '' || !is_int($endsAtUnix)) {
        $errors['ends_at'] = 'required_valid_datetime';
    }
    if (!isset($errors['starts_at']) && !isset($errors['ends_at']) && $endsAtUnix <= $startsAtUnix) {
        $errors['ends_at'] = 'must_be_after_starts_at';
    }

    $internalIdsRaw = $payload['internal_participant_user_ids'] ?? [];
    if (!is_array($internalIdsRaw)) {
        $errors['internal_participant_user_ids'] = 'must_be_array';
        $internalIdsRaw = [];
    }
    $internalIds = [];
    foreach ($internalIdsRaw as $item) {
        $id = filter_var($item, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id <= 0) {
            $errors['internal_participant_user_ids'] = 'must_contain_positive_int_ids';
            break;
        }
        $internalIds[$id] = $id;
    }
    $internalIds = array_values($internalIds);

    $externalRaw = $payload['external_participants'] ?? [];
    if (!is_array($externalRaw)) {
        $errors['external_participants'] = 'must_be_array';
        $externalRaw = [];
    }

    $externalParticipants = [];
    $externalEmailMap = [];
    foreach ($externalRaw as $index => $row) {
        if (!is_array($row)) {
            $errors["external_participants.{$index}"] = 'must_be_object';
            continue;
        }

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $displayName = trim((string) ($row['display_name'] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors["external_participants.{$index}.email"] = 'required_valid_email';
            continue;
        }
        if ($displayName === '') {
            $errors["external_participants.{$index}.display_name"] = 'required_display_name';
            continue;
        }
        if (strlen($displayName) > 120) {
            $errors["external_participants.{$index}.display_name"] = 'display_name_too_long';
            continue;
        }
        if (isset($externalEmailMap[$email])) {
            $errors["external_participants.{$index}.email"] = 'duplicate_email';
            continue;
        }

        $externalEmailMap[$email] = true;
        $externalParticipants[] = [
            'email' => $email,
            'display_name' => $displayName,
        ];
    }

    $startsAt = is_int($startsAtUnix) ? gmdate('c', $startsAtUnix) : '';
    $endsAt = is_int($endsAtUnix) ? gmdate('c', $endsAtUnix) : '';

    return [
        'ok' => $errors === [],
        'data' => [
            'room_id' => $roomId,
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'internal_participant_user_ids' => $internalIds,
            'external_participants' => $externalParticipants,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{id: int, email: string, display_name: string}|null
 */
function videochat_active_user_identity(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, email, display_name
FROM users
WHERE id = :id AND status = 'active'
LIMIT 1
SQL
    );
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'email' => strtolower((string) ($row['email'] ?? '')),
        'display_name' => (string) ($row['display_name'] ?? ''),
    ];
}

/**
 * @param array<int, int> $userIds
 * @return array<int, array{id: int, email: string, display_name: string}>
 */
function videochat_active_internal_users(PDO $pdo, array $userIds): array
{
    $result = [];
    foreach ($userIds as $userId) {
        $identity = videochat_active_user_identity($pdo, (int) $userId);
        if ($identity === null) {
            continue;
        }
        $result[$identity['id']] = $identity;
    }

    return $result;
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>
 * }
 */
function videochat_create_call(PDO $pdo, int $ownerUserId, array $payload): array
{
    $validation = videochat_validate_create_call_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'call' => null,
        ];
    }

    $data = $validation['data'];
    $owner = videochat_active_user_identity($pdo, $ownerUserId);
    if ($owner === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['owner' => 'active_owner_not_found'],
            'call' => null,
        ];
    }

    $roomQuery = $pdo->prepare('SELECT id FROM rooms WHERE id = :id AND status = :status LIMIT 1');
    $roomQuery->execute([
        ':id' => (string) $data['room_id'],
        ':status' => 'active',
    ]);
    if ($roomQuery->fetch() === false) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['room_id' => 'room_not_found_or_inactive'],
            'call' => null,
        ];
    }

    $requestedInternalIds = array_values(array_filter(
        array_map('intval', $data['internal_participant_user_ids']),
        static fn (int $id): bool => $id > 0
    ));
    $requestedInternalIds = array_values(array_unique($requestedInternalIds));
    $activeInternalUsers = videochat_active_internal_users($pdo, $requestedInternalIds);
    if (count($activeInternalUsers) !== count($requestedInternalIds)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['internal_participant_user_ids' => 'contains_unknown_or_inactive_user'],
            'call' => null,
        ];
    }

    $participantEmailMap = [];
    $internalParticipants = [];

    $ownerEmail = strtolower((string) $owner['email']);
    $participantEmailMap[$ownerEmail] = true;
    $internalParticipants[] = [
        'user_id' => (int) $owner['id'],
        'email' => $ownerEmail,
        'display_name' => (string) $owner['display_name'],
        'source' => 'internal',
        'invite_state' => 'accepted',
        'is_owner' => true,
    ];

    foreach ($activeInternalUsers as $internalUser) {
        $email = strtolower((string) $internalUser['email']);
        if (isset($participantEmailMap[$email])) {
            continue;
        }
        $participantEmailMap[$email] = true;
        $internalParticipants[] = [
            'user_id' => (int) $internalUser['id'],
            'email' => $email,
            'display_name' => (string) $internalUser['display_name'],
            'source' => 'internal',
            'invite_state' => 'pending',
            'is_owner' => false,
        ];
    }

    $externalParticipants = [];
    foreach ($data['external_participants'] as $index => $external) {
        $email = strtolower((string) ($external['email'] ?? ''));
        if (isset($participantEmailMap[$email])) {
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ["external_participants.{$index}.email" => 'duplicates_internal_participant'],
                'call' => null,
            ];
        }

        $participantEmailMap[$email] = true;
        $externalParticipants[] = [
            'user_id' => null,
            'email' => $email,
            'display_name' => (string) ($external['display_name'] ?? ''),
            'source' => 'external',
            'invite_state' => 'pending',
        ];
    }

    $callId = videochat_generate_call_id();
    $createdAt = gmdate('c');
    $startsAt = (string) $data['starts_at'];
    $endsAt = (string) $data['ends_at'];

    $pdo->beginTransaction();
    try {
        $insertCall = $pdo->prepare(
            <<<'SQL'
INSERT INTO calls(
    id, room_id, title, owner_user_id, status, starts_at, ends_at, cancelled_at, cancel_reason, created_at, updated_at
) VALUES(
    :id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at, NULL, NULL, :created_at, :updated_at
)
SQL
        );
        $insertCall->execute([
            ':id' => $callId,
            ':room_id' => (string) $data['room_id'],
            ':title' => (string) $data['title'],
            ':owner_user_id' => (int) $owner['id'],
            ':status' => 'scheduled',
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);

        $insertParticipant = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :invite_state, :joined_at, :left_at)
SQL
        );

        foreach ($internalParticipants as $participant) {
            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => $participant['user_id'],
                ':email' => $participant['email'],
                ':display_name' => $participant['display_name'],
                ':source' => $participant['source'],
                ':invite_state' => $participant['invite_state'],
                ':joined_at' => null,
                ':left_at' => null,
            ]);
        }
        foreach ($externalParticipants as $participant) {
            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => null,
                ':email' => $participant['email'],
                ':display_name' => $participant['display_name'],
                ':source' => $participant['source'],
                ':invite_state' => $participant['invite_state'],
                ':joined_at' => null,
                ':left_at' => null,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
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
        'reason' => 'created',
        'errors' => [],
        'call' => [
            'id' => $callId,
            'room_id' => (string) $data['room_id'],
            'title' => (string) $data['title'],
            'status' => 'scheduled',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'owner' => [
                'user_id' => (int) $owner['id'],
                'email' => $owner['email'],
                'display_name' => $owner['display_name'],
            ],
            'participants' => [
                'internal' => array_map(
                    static function (array $participant): array {
                        return [
                            'user_id' => (int) $participant['user_id'],
                            'email' => (string) $participant['email'],
                            'display_name' => (string) $participant['display_name'],
                            'invite_state' => (string) $participant['invite_state'],
                            'is_owner' => (bool) ($participant['is_owner'] ?? false),
                        ];
                    },
                    $internalParticipants
                ),
                'external' => array_map(
                    static function (array $participant): array {
                        return [
                            'email' => (string) $participant['email'],
                            'display_name' => (string) $participant['display_name'],
                            'invite_state' => (string) $participant['invite_state'],
                        ];
                    },
                    $externalParticipants
                ),
                'totals' => [
                    'total' => count($internalParticipants) + count($externalParticipants),
                    'internal' => count($internalParticipants),
                    'external' => count($externalParticipants),
                ],
            ],
            'my_participation' => true,
        ],
    ];
}

/**
 * @return array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   owner_user_id: int,
 *   status: string,
 *   starts_at: string,
 *   ends_at: string,
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   created_at: string,
 *   updated_at: string,
 *   owner_email: string,
 *   owner_display_name: string
 * }|null
 */
function videochat_fetch_call_for_update(PDO $pdo, string $callId): ?array
{
    $trimmedCallId = trim($callId);
    if ($trimmedCallId === '') {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    calls.id,
    calls.room_id,
    calls.title,
    calls.owner_user_id,
    calls.status,
    calls.starts_at,
    calls.ends_at,
    calls.cancelled_at,
    calls.cancel_reason,
    calls.created_at,
    calls.updated_at,
    owners.email AS owner_email,
    owners.display_name AS owner_display_name
FROM calls
INNER JOIN users owners ON owners.id = calls.owner_user_id
WHERE calls.id = :id
LIMIT 1
SQL
    );
    $statement->execute([':id' => $trimmedCallId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'room_id' => (string) ($row['room_id'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'scheduled'),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'cancelled_at' => is_string($row['cancelled_at'] ?? null) ? (string) $row['cancelled_at'] : null,
        'cancel_reason' => is_string($row['cancel_reason'] ?? null) ? (string) $row['cancel_reason'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'owner_email' => strtolower((string) ($row['owner_email'] ?? '')),
        'owner_display_name' => (string) ($row['owner_display_name'] ?? ''),
    ];
}

function videochat_can_edit_call(string $authRole, int $authUserId, int $ownerUserId): bool
{
    $role = strtolower(trim($authRole));
    if (in_array($role, ['admin', 'moderator'], true)) {
        return true;
    }

    return $authUserId > 0 && $ownerUserId > 0 && $authUserId === $ownerUserId;
}

/**
 * @return array{
 *   internal: array<int, array{
 *     user_id: int,
 *     email: string,
 *     display_name: string,
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
SELECT call_id, user_id, email, display_name, source, invite_state
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
        $inviteState = (string) ($row['invite_state'] ?? 'pending');
        if ($source === 'internal') {
            $internal[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'email' => strtolower((string) ($row['email'] ?? '')),
                'display_name' => (string) ($row['display_name'] ?? ''),
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
 *     has_starts_at: bool,
 *     starts_at_unix: int,
 *     has_ends_at: bool,
 *     ends_at_unix: int,
 *     has_internal_participants: bool,
 *     internal_participant_user_ids: array<int, int>,
 *     has_external_participants: bool,
 *     external_participants: array<int, array{email: string, display_name: string}>
 *   },
 *   errors: array<string, string>
 * }
 */
function videochat_validate_update_call_payload(array $payload): array
{
    $errors = [];

    $hasRoomId = array_key_exists('room_id', $payload);
    $hasTitle = array_key_exists('title', $payload);
    $hasStartsAt = array_key_exists('starts_at', $payload);
    $hasEndsAt = array_key_exists('ends_at', $payload);
    $hasInternalParticipants = array_key_exists('internal_participant_user_ids', $payload);
    $hasExternalParticipants = array_key_exists('external_participants', $payload);

    $resendRequested = false;
    if (array_key_exists('resend_invites', $payload)) {
        $resendRequested = (bool) $payload['resend_invites'];
    } elseif (array_key_exists('global_invite_resend', $payload)) {
        $resendRequested = (bool) $payload['global_invite_resend'];
    }
    if ($resendRequested) {
        $errors['resend_invites'] = 'global_invite_resend_not_supported_use_explicit_action';
    }

    $roomId = '';
    if ($hasRoomId) {
        $roomId = trim((string) ($payload['room_id'] ?? ''));
        if ($roomId === '') {
            $errors['room_id'] = 'required_room_id';
        } elseif (strlen($roomId) > 120) {
            $errors['room_id'] = 'room_id_too_long';
        }
    }

    $title = '';
    if ($hasTitle) {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'required_title';
        } elseif (strlen($title) > 200) {
            $errors['title'] = 'title_too_long';
        }
    }

    $startsAtUnix = 0;
    if ($hasStartsAt) {
        $startsAtRaw = trim((string) ($payload['starts_at'] ?? ''));
        $startsAtUnixRaw = strtotime($startsAtRaw);
        if ($startsAtRaw === '' || !is_int($startsAtUnixRaw)) {
            $errors['starts_at'] = 'required_valid_datetime';
        } else {
            $startsAtUnix = $startsAtUnixRaw;
        }
    }

    $endsAtUnix = 0;
    if ($hasEndsAt) {
        $endsAtRaw = trim((string) ($payload['ends_at'] ?? ''));
        $endsAtUnixRaw = strtotime($endsAtRaw);
        if ($endsAtRaw === '' || !is_int($endsAtUnixRaw)) {
            $errors['ends_at'] = 'required_valid_datetime';
        } else {
            $endsAtUnix = $endsAtUnixRaw;
        }
    }

    $internalIds = [];
    if ($hasInternalParticipants) {
        $internalRaw = $payload['internal_participant_user_ids'];
        if (!is_array($internalRaw)) {
            $errors['internal_participant_user_ids'] = 'must_be_array';
        } else {
            foreach ($internalRaw as $item) {
                $id = filter_var($item, FILTER_VALIDATE_INT);
                if (!is_int($id) || $id <= 0) {
                    $errors['internal_participant_user_ids'] = 'must_contain_positive_int_ids';
                    break;
                }
                $internalIds[$id] = $id;
            }
            $internalIds = array_values($internalIds);
        }
    }

    $externalParticipants = [];
    if ($hasExternalParticipants) {
        $externalRaw = $payload['external_participants'];
        if (!is_array($externalRaw)) {
            $errors['external_participants'] = 'must_be_array';
        } else {
            $externalEmailMap = [];
            foreach ($externalRaw as $index => $row) {
                if (!is_array($row)) {
                    $errors["external_participants.{$index}"] = 'must_be_object';
                    continue;
                }

                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $displayName = trim((string) ($row['display_name'] ?? ''));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors["external_participants.{$index}.email"] = 'required_valid_email';
                    continue;
                }
                if ($displayName === '') {
                    $errors["external_participants.{$index}.display_name"] = 'required_display_name';
                    continue;
                }
                if (strlen($displayName) > 120) {
                    $errors["external_participants.{$index}.display_name"] = 'display_name_too_long';
                    continue;
                }
                if (isset($externalEmailMap[$email])) {
                    $errors["external_participants.{$index}.email"] = 'duplicate_email';
                    continue;
                }

                $externalEmailMap[$email] = true;
                $externalParticipants[] = [
                    'email' => $email,
                    'display_name' => $displayName,
                ];
            }
        }
    }

    if (
        !$hasRoomId
        && !$hasTitle
        && !$hasStartsAt
        && !$hasEndsAt
        && !$hasInternalParticipants
        && !$hasExternalParticipants
        && !$resendRequested
    ) {
        $errors['payload'] = 'at_least_one_supported_field_required';
    }

    return [
        'ok' => $errors === [],
        'data' => [
            'has_room_id' => $hasRoomId,
            'room_id' => $roomId,
            'has_title' => $hasTitle,
            'title' => $title,
            'has_starts_at' => $hasStartsAt,
            'starts_at_unix' => $startsAtUnix,
            'has_ends_at' => $hasEndsAt,
            'ends_at_unix' => $endsAtUnix,
            'has_internal_participants' => $hasInternalParticipants,
            'internal_participant_user_ids' => $internalIds,
            'has_external_participants' => $hasExternalParticipants,
            'external_participants' => $externalParticipants,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   call: ?array<string, mixed>,
 *   invite_dispatch: array{global_resend_triggered: bool, explicit_action_required: bool}
 * }
 */
function videochat_update_call(PDO $pdo, string $callId, int $authUserId, string $authRole, array $payload): array
{
    $existingCall = videochat_fetch_call_for_update($pdo, $callId);
    if ($existingCall === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    if (!videochat_can_edit_call($authRole, $authUserId, (int) $existingCall['owner_user_id'])) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    if (in_array((string) $existingCall['status'], ['cancelled', 'ended'], true)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['status' => 'immutable_for_edit'],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $validation = videochat_validate_update_call_payload($payload);
    if (!(bool) $validation['ok']) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => $validation['errors'],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $data = $validation['data'];

    $nextRoomId = (bool) $data['has_room_id'] ? (string) $data['room_id'] : (string) $existingCall['room_id'];
    $nextTitle = (bool) $data['has_title'] ? (string) $data['title'] : (string) $existingCall['title'];
    $currentStartsUnix = strtotime((string) $existingCall['starts_at']);
    $currentEndsUnix = strtotime((string) $existingCall['ends_at']);
    if (!is_int($currentStartsUnix) || !is_int($currentEndsUnix)) {
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $nextStartsUnix = (bool) $data['has_starts_at'] ? (int) $data['starts_at_unix'] : $currentStartsUnix;
    $nextEndsUnix = (bool) $data['has_ends_at'] ? (int) $data['ends_at_unix'] : $currentEndsUnix;
    if ($nextEndsUnix <= $nextStartsUnix) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['ends_at' => 'must_be_after_starts_at'],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $roomQuery = $pdo->prepare('SELECT id FROM rooms WHERE id = :id AND status = :status LIMIT 1');
    $roomQuery->execute([
        ':id' => $nextRoomId,
        ':status' => 'active',
    ]);
    if ($roomQuery->fetch() === false) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['room_id' => 'room_not_found_or_inactive'],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $participantsNeedUpdate = (bool) $data['has_internal_participants'] || (bool) $data['has_external_participants'];
    $currentParticipants = videochat_fetch_call_participants($pdo, (string) $existingCall['id']);

    if ((bool) $data['has_internal_participants']) {
        $requestedInternalIds = array_values(array_unique(array_filter(
            array_map('intval', (array) $data['internal_participant_user_ids']),
            static fn (int $id): bool => $id > 0
        )));
        $activeInternalUsers = videochat_active_internal_users($pdo, $requestedInternalIds);
        if (count($activeInternalUsers) !== count($requestedInternalIds)) {
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ['internal_participant_user_ids' => 'contains_unknown_or_inactive_user'],
                'call' => null,
                'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
            ];
        }

        $nextInternalParticipants = [];
        $ownerEmail = strtolower((string) $existingCall['owner_email']);
        $nextInternalParticipants[] = [
            'user_id' => (int) $existingCall['owner_user_id'],
            'email' => $ownerEmail,
            'display_name' => (string) $existingCall['owner_display_name'],
            'invite_state' => 'accepted',
            'is_owner' => true,
        ];

        foreach ($activeInternalUsers as $internalUser) {
            if ((int) $internalUser['id'] === (int) $existingCall['owner_user_id']) {
                continue;
            }
            $nextInternalParticipants[] = [
                'user_id' => (int) $internalUser['id'],
                'email' => strtolower((string) $internalUser['email']),
                'display_name' => (string) $internalUser['display_name'],
                'invite_state' => 'pending',
                'is_owner' => false,
            ];
        }
    } else {
        $nextInternalParticipants = [];
        foreach ($currentParticipants['internal'] as $participant) {
            $userId = (int) ($participant['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $nextInternalParticipants[] = [
                'user_id' => $userId,
                'email' => strtolower((string) ($participant['email'] ?? '')),
                'display_name' => (string) ($participant['display_name'] ?? ''),
                'invite_state' => (string) ($participant['invite_state'] ?? 'pending'),
                'is_owner' => $userId === (int) $existingCall['owner_user_id'],
            ];
        }
    }

    if ((bool) $data['has_external_participants']) {
        $nextExternalParticipants = array_map(
            static function (array $participant): array {
                return [
                    'email' => strtolower((string) ($participant['email'] ?? '')),
                    'display_name' => (string) ($participant['display_name'] ?? ''),
                    'invite_state' => 'pending',
                ];
            },
            (array) $data['external_participants']
        );
    } else {
        $nextExternalParticipants = [];
        foreach ($currentParticipants['external'] as $participant) {
            $nextExternalParticipants[] = [
                'email' => strtolower((string) ($participant['email'] ?? '')),
                'display_name' => (string) ($participant['display_name'] ?? ''),
                'invite_state' => (string) ($participant['invite_state'] ?? 'pending'),
            ];
        }
    }

    if ($participantsNeedUpdate) {
        $participantEmailMap = [];
        foreach ($nextInternalParticipants as $index => $participant) {
            $email = strtolower((string) ($participant['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return [
                    'ok' => false,
                    'reason' => 'validation_failed',
                    'errors' => ["internal_participants.{$index}.email" => 'required_valid_email'],
                    'call' => null,
                    'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
                ];
            }
            $participantEmailMap[$email] = true;
        }
        foreach ($nextExternalParticipants as $index => $participant) {
            $email = strtolower((string) ($participant['email'] ?? ''));
            if (isset($participantEmailMap[$email])) {
                return [
                    'ok' => false,
                    'reason' => 'validation_failed',
                    'errors' => ["external_participants.{$index}.email" => 'duplicates_internal_participant'],
                    'call' => null,
                    'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
                ];
            }
            $participantEmailMap[$email] = true;
        }
    }

    $updatedAt = gmdate('c');
    $nextStartsAt = gmdate('c', $nextStartsUnix);
    $nextEndsAt = gmdate('c', $nextEndsUnix);

    $pdo->beginTransaction();
    try {
        $updateCall = $pdo->prepare(
            <<<'SQL'
UPDATE calls
SET room_id = :room_id,
    title = :title,
    starts_at = :starts_at,
    ends_at = :ends_at,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $updateCall->execute([
            ':room_id' => $nextRoomId,
            ':title' => $nextTitle,
            ':starts_at' => $nextStartsAt,
            ':ends_at' => $nextEndsAt,
            ':updated_at' => $updatedAt,
            ':id' => (string) $existingCall['id'],
        ]);

        if ($participantsNeedUpdate) {
            $deleteParticipants = $pdo->prepare('DELETE FROM call_participants WHERE call_id = :call_id');
            $deleteParticipants->execute([':call_id' => (string) $existingCall['id']]);

            $insertParticipant = $pdo->prepare(
                <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :invite_state, :joined_at, :left_at)
SQL
            );

            foreach ($nextInternalParticipants as $participant) {
                $insertParticipant->execute([
                    ':call_id' => (string) $existingCall['id'],
                    ':user_id' => (int) $participant['user_id'],
                    ':email' => (string) $participant['email'],
                    ':display_name' => (string) $participant['display_name'],
                    ':source' => 'internal',
                    ':invite_state' => (string) $participant['invite_state'],
                    ':joined_at' => null,
                    ':left_at' => null,
                ]);
            }
            foreach ($nextExternalParticipants as $participant) {
                $insertParticipant->execute([
                    ':call_id' => (string) $existingCall['id'],
                    ':user_id' => null,
                    ':email' => (string) $participant['email'],
                    ':display_name' => (string) $participant['display_name'],
                    ':source' => 'external',
                    ':invite_state' => (string) $participant['invite_state'],
                    ':joined_at' => null,
                    ':left_at' => null,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'call' => null,
            'invite_dispatch' => ['global_resend_triggered' => false, 'explicit_action_required' => true],
        ];
    }

    $myParticipation = $authUserId === (int) $existingCall['owner_user_id'];
    if (!$myParticipation) {
        foreach ($nextInternalParticipants as $participant) {
            if ((int) ($participant['user_id'] ?? 0) === $authUserId) {
                $myParticipation = true;
                break;
            }
        }
    }

    return [
        'ok' => true,
        'reason' => 'updated',
        'errors' => [],
        'call' => [
            'id' => (string) $existingCall['id'],
            'room_id' => $nextRoomId,
            'title' => $nextTitle,
            'status' => (string) $existingCall['status'],
            'starts_at' => $nextStartsAt,
            'ends_at' => $nextEndsAt,
            'cancelled_at' => $existingCall['cancelled_at'],
            'cancel_reason' => $existingCall['cancel_reason'],
            'created_at' => (string) $existingCall['created_at'],
            'updated_at' => $updatedAt,
            'owner' => [
                'user_id' => (int) $existingCall['owner_user_id'],
                'email' => (string) $existingCall['owner_email'],
                'display_name' => (string) $existingCall['owner_display_name'],
            ],
            'participants' => [
                'internal' => array_map(
                    static function (array $participant): array {
                        return [
                            'user_id' => (int) ($participant['user_id'] ?? 0),
                            'email' => (string) ($participant['email'] ?? ''),
                            'display_name' => (string) ($participant['display_name'] ?? ''),
                            'invite_state' => (string) ($participant['invite_state'] ?? 'pending'),
                            'is_owner' => (bool) ($participant['is_owner'] ?? false),
                        ];
                    },
                    $nextInternalParticipants
                ),
                'external' => array_map(
                    static function (array $participant): array {
                        return [
                            'email' => (string) ($participant['email'] ?? ''),
                            'display_name' => (string) ($participant['display_name'] ?? ''),
                            'invite_state' => (string) ($participant['invite_state'] ?? 'pending'),
                        ];
                    },
                    $nextExternalParticipants
                ),
                'totals' => [
                    'total' => count($nextInternalParticipants) + count($nextExternalParticipants),
                    'internal' => count($nextInternalParticipants),
                    'external' => count($nextExternalParticipants),
                ],
            ],
            'my_participation' => $myParticipation,
        ],
        'invite_dispatch' => [
            'global_resend_triggered' => false,
            'explicit_action_required' => true,
        ],
    ];
}
