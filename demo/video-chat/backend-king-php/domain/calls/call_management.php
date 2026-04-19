<?php

declare(strict_types=1);

function videochat_generate_call_id(): string
{
    try {
        $bytes = random_bytes(16);
    } catch (Throwable) {
        $bytes = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true), true);
        if (!is_string($bytes) || strlen($bytes) < 16) {
            $bytes = str_repeat("\0", 16);
        }
        $bytes = substr($bytes, 0, 16);
    }

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function videochat_normalize_call_access_mode(mixed $value, string $fallback = 'invite_only'): string
{
    $fallbackNormalized = strtolower(trim($fallback));
    if (!in_array($fallbackNormalized, ['invite_only', 'free_for_all'], true)) {
        $fallbackNormalized = 'invite_only';
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['invite_only', 'free_for_all'], true)) {
        return $normalized;
    }

    return $fallbackNormalized;
}

function videochat_normalize_call_invite_state(mixed $value, string $fallback = 'invited'): string
{
    $fallbackNormalized = strtolower(trim($fallback));
    if ($fallbackNormalized !== '' && !in_array($fallbackNormalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        $fallbackNormalized = 'invited';
    }

    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
        return $normalized;
    }

    return $fallbackNormalized;
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     room_id: string,
 *     title: string,
 *     access_mode: string,
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

    $accessModeInput = strtolower(trim((string) ($payload['access_mode'] ?? 'invite_only')));
    $accessMode = videochat_normalize_call_access_mode($accessModeInput, 'invite_only');
    if (!in_array($accessModeInput, ['invite_only', 'free_for_all'], true)) {
        $errors['access_mode'] = 'must_be_invite_only_or_free_for_all';
    }

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
            'access_mode' => $accessMode,
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
        'call_role' => 'owner',
        'invite_state' => 'allowed',
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
            'call_role' => 'participant',
            'invite_state' => 'invited',
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
            'invite_state' => 'invited',
        ];
    }

    $callId = videochat_generate_call_id();
    $callRoomId = $callId;
    $createdAt = gmdate('c');
    $startsAt = (string) $data['starts_at'];
    $endsAt = (string) $data['ends_at'];
    $initialStatus = 'scheduled';
    $startsAtUnix = strtotime($startsAt);
    $endsAtUnix = strtotime($endsAt);
    $nowUnix = time();
    if (is_int($startsAtUnix) && is_int($endsAtUnix) && $startsAtUnix <= $nowUnix && $nowUnix < $endsAtUnix) {
        $initialStatus = 'active';
    }

    $pdo->beginTransaction();
    try {
        $insertRoom = $pdo->prepare(
            <<<'SQL'
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :name, 'private', 'active', :created_by_user_id, :created_at, :updated_at)
SQL
        );
        $insertRoom->execute([
            ':id' => $callRoomId,
            ':name' => (string) $data['title'],
            ':created_by_user_id' => (int) $owner['id'],
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);

        $insertCall = $pdo->prepare(
            <<<'SQL'
INSERT INTO calls(
    id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at, cancelled_at, cancel_reason, cancel_message, created_at, updated_at
) VALUES(
    :id, :room_id, :title, :access_mode, :owner_user_id, :status, :starts_at, :ends_at, NULL, NULL, NULL, :created_at, :updated_at
)
SQL
        );
        $insertCall->execute([
            ':id' => $callId,
            ':room_id' => $callRoomId,
            ':title' => (string) $data['title'],
            ':access_mode' => (string) $data['access_mode'],
            ':owner_user_id' => (int) $owner['id'],
            ':status' => $initialStatus,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ]);

        $insertParticipant = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
        );

        foreach ($internalParticipants as $participant) {
            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => $participant['user_id'],
                ':email' => $participant['email'],
                ':display_name' => $participant['display_name'],
                ':source' => $participant['source'],
                ':call_role' => (string) ($participant['call_role'] ?? 'participant'),
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
                ':call_role' => 'participant',
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
            'room_id' => $callRoomId,
            'title' => (string) $data['title'],
            'access_mode' => (string) $data['access_mode'],
            'status' => $initialStatus,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
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
                        $callRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
                        if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                            $callRole = 'participant';
                        }
                        return [
                            'user_id' => (int) $participant['user_id'],
                            'email' => (string) $participant['email'],
                            'display_name' => (string) $participant['display_name'],
                            'call_role' => $callRole,
                            'invite_state' => (string) $participant['invite_state'],
                            'is_owner' => (bool) ($participant['is_owner'] ?? false),
                            'is_moderator' => $callRole === 'moderator',
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
 *   access_mode: string,
 *   owner_user_id: int,
 *   status: string,
 *   starts_at: string,
 *   ends_at: string,
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   cancel_message: ?string,
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
    calls.access_mode,
    calls.owner_user_id,
    calls.status,
    calls.starts_at,
    calls.ends_at,
    calls.cancelled_at,
    calls.cancel_reason,
    calls.cancel_message,
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
        'access_mode' => videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only'),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'status' => (string) ($row['status'] ?? 'scheduled'),
        'starts_at' => (string) ($row['starts_at'] ?? ''),
        'ends_at' => (string) ($row['ends_at'] ?? ''),
        'cancelled_at' => is_string($row['cancelled_at'] ?? null) ? (string) $row['cancelled_at'] : null,
        'cancel_reason' => is_string($row['cancel_reason'] ?? null) ? (string) $row['cancel_reason'] : null,
        'cancel_message' => is_string($row['cancel_message'] ?? null) ? (string) $row['cancel_message'] : null,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'owner_email' => strtolower((string) ($row['owner_email'] ?? '')),
        'owner_display_name' => (string) ($row['owner_display_name'] ?? ''),
    ];
}

function videochat_can_edit_call(string $authRole, int $authUserId, int $ownerUserId): bool
{
    $role = strtolower(trim($authRole));
    if ($role === 'admin') {
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
    $hasAccessMode = array_key_exists('access_mode', $payload);
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

    $accessMode = 'invite_only';
    if ($hasAccessMode) {
        $accessModeInput = strtolower(trim((string) ($payload['access_mode'] ?? '')));
        if (!in_array($accessModeInput, ['invite_only', 'free_for_all'], true)) {
            $errors['access_mode'] = 'must_be_invite_only_or_free_for_all';
        }
        $accessMode = videochat_normalize_call_access_mode($accessModeInput, 'invite_only');
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
        && !$hasAccessMode
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
            'has_access_mode' => $hasAccessMode,
            'access_mode' => $accessMode,
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
    $currentAccessMode = videochat_normalize_call_access_mode((string) ($existingCall['access_mode'] ?? 'invite_only'));
    $nextAccessMode = (bool) ($data['has_access_mode'] ?? false)
        ? videochat_normalize_call_access_mode((string) ($data['access_mode'] ?? 'invite_only'))
        : $currentAccessMode;
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
    $currentInternalRoleByUserId = [];
    $currentInternalInviteStateByUserId = [];
    foreach ((array) ($currentParticipants['internal'] ?? []) as $participant) {
        $participantUserId = (int) ($participant['user_id'] ?? 0);
        if ($participantUserId <= 0) {
            continue;
        }
        $participantRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
        if (!in_array($participantRole, ['owner', 'moderator', 'participant'], true)) {
            $participantRole = 'participant';
        }
        $currentInternalRoleByUserId[$participantUserId] = $participantRole;
        $currentInternalInviteStateByUserId[$participantUserId] = videochat_normalize_call_invite_state(
            $participant['invite_state'] ?? 'invited'
        );
    }

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
            'call_role' => 'owner',
            'invite_state' => 'allowed',
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
                'call_role' => (($currentInternalRoleByUserId[(int) $internalUser['id']] ?? 'participant') === 'moderator')
                    ? 'moderator'
                    : 'participant',
                'invite_state' => $currentInternalInviteStateByUserId[(int) $internalUser['id']] ?? 'invited',
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
                'call_role' => (string) ($participant['call_role'] ?? ($userId === (int) $existingCall['owner_user_id'] ? 'owner' : 'participant')),
                'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
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
                    'invite_state' => 'invited',
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
                'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
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
    access_mode = :access_mode,
    starts_at = :starts_at,
    ends_at = :ends_at,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $updateCall->execute([
            ':room_id' => $nextRoomId,
            ':title' => $nextTitle,
            ':access_mode' => $nextAccessMode,
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
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
            );

            foreach ($nextInternalParticipants as $participant) {
                $participantCallRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
                if (!in_array($participantCallRole, ['owner', 'moderator', 'participant'], true)) {
                    $participantCallRole = 'participant';
                }
                $insertParticipant->execute([
                    ':call_id' => (string) $existingCall['id'],
                    ':user_id' => (int) $participant['user_id'],
                    ':email' => (string) $participant['email'],
                    ':display_name' => (string) $participant['display_name'],
                    ':source' => 'internal',
                    ':call_role' => $participantCallRole,
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
                    ':call_role' => 'participant',
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
            'access_mode' => $nextAccessMode,
            'status' => (string) $existingCall['status'],
            'starts_at' => $nextStartsAt,
            'ends_at' => $nextEndsAt,
            'cancelled_at' => $existingCall['cancelled_at'],
            'cancel_reason' => $existingCall['cancel_reason'],
            'cancel_message' => $existingCall['cancel_message'],
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
                        $callRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
                        if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                            $callRole = 'participant';
                        }
                        return [
                            'user_id' => (int) ($participant['user_id'] ?? 0),
                            'email' => (string) ($participant['email'] ?? ''),
                            'display_name' => (string) ($participant['display_name'] ?? ''),
                            'call_role' => $callRole,
                            'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
                            'is_owner' => (bool) ($participant['is_owner'] ?? false),
                            'is_moderator' => $callRole === 'moderator',
                        ];
                    },
                    $nextInternalParticipants
                ),
                'external' => array_map(
                    static function (array $participant): array {
                        return [
                            'email' => (string) ($participant['email'] ?? ''),
                            'display_name' => (string) ($participant['display_name'] ?? ''),
                            'invite_state' => videochat_normalize_call_invite_state($participant['invite_state'] ?? 'invited'),
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

/**
 * @return array{
 *   ok: bool,
 *   data: array{cancel_reason: string, cancel_message: string},
 *   errors: array<string, string>
 * }
 */
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
        'room_id' => (string) ($callRecord['room_id'] ?? ''),
        'title' => (string) ($callRecord['title'] ?? ''),
        'access_mode' => videochat_normalize_call_access_mode((string) ($callRecord['access_mode'] ?? 'invite_only')),
        'status' => (string) ($callRecord['status'] ?? ''),
        'starts_at' => (string) ($callRecord['starts_at'] ?? ''),
        'ends_at' => (string) ($callRecord['ends_at'] ?? ''),
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
    string $authRole
): array {
    $existingCall = videochat_fetch_call_for_update($pdo, $callId);
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

    $isAdmin = videochat_normalize_role_slug($authRole) === 'admin';
    $isOwner = $authUserId > 0 && $authUserId === (int) ($existingCall['owner_user_id'] ?? 0);
    if (!$isAdmin && !$isOwner) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => [],
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

    $currentOwnerUserId = (int) ($existingCall['owner_user_id'] ?? 0);
    if ($normalizedTargetRole === 'owner') {
        if (!$isOwner) {
            return [
                'ok' => false,
                'reason' => 'forbidden',
                'errors' => ['role' => 'owner_transfer_requires_current_owner'],
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

    $normalizedCurrentRole = videochat_normalize_call_participant_role((string) ($targetParticipant['call_role'] ?? 'participant'));
    if ($normalizedTargetRole === $normalizedCurrentRole && !($normalizedTargetRole === 'owner' && $targetUserId !== $currentOwnerUserId)) {
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
                ':call_id' => (string) ($existingCall['id'] ?? ''),
                ':user_id' => $currentOwnerUserId,
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

    $updatedCall = videochat_fetch_call_for_update($pdo, (string) ($existingCall['id'] ?? ''));
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

/**
 * @return array{
 *   call_id: string,
 *   call_role: string,
 *   invite_state: string,
 *   joined_at: string,
 *   left_at: string,
 *   can_moderate: bool
 * }
 */
function videochat_call_role_context_for_room_user(PDO $pdo, string $roomId, int $userId): array
{
    $fallback = [
        'call_id' => '',
        'call_role' => 'participant',
        'invite_state' => 'invited',
        'joined_at' => '',
        'left_at' => '',
        'can_moderate' => false,
    ];
    if ($userId <= 0) {
        return $fallback;
    }

    $normalizedRoomId = strtolower(trim($roomId));
    if ($normalizedRoomId === '') {
        return $fallback;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT
    calls.id,
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
      OR cp.user_id IS NOT NULL
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
    if (!is_array($row)) {
        return $fallback;
    }

    $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
    if ((int) ($row['owner_user_id'] ?? 0) === $userId) {
        $callRole = 'owner';
    }

    return [
        'call_id' => (string) ($row['id'] ?? ''),
        'call_role' => $callRole,
        'invite_state' => videochat_normalize_call_invite_state($row['invite_state'] ?? 'invited'),
        'joined_at' => trim((string) ($row['joined_at'] ?? '')),
        'left_at' => trim((string) ($row['left_at'] ?? '')),
        'can_moderate' => in_array($callRole, ['owner', 'moderator'], true),
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
function videochat_get_call_for_user(PDO $pdo, string $callId, int $authUserId, string $authRole): array
{
    $call = videochat_fetch_call_for_update($pdo, $callId);
    if ($call === null) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => [],
            'call' => null,
        ];
    }

    $isAdmin = videochat_normalize_role_slug($authRole) === 'admin';
    if (!$isAdmin) {
        $isOwner = $authUserId > 0 && $authUserId === (int) ($call['owner_user_id'] ?? 0);
        $participantCheck = $pdo->prepare(
            <<<'SQL'
SELECT 1
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
        $isInternalParticipant = $participantCheck->fetchColumn() !== false;

        if (!$isOwner && !$isInternalParticipant) {
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
