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
