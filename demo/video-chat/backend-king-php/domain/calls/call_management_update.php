<?php

declare(strict_types=1);

function videochat_validate_update_call_payload(array $payload): array
{
    $errors = [];

    $hasRoomId = array_key_exists('room_id', $payload);
    $hasTitle = array_key_exists('title', $payload);
    $hasAccessMode = array_key_exists('access_mode', $payload);
    $hasStartsAt = array_key_exists('starts_at', $payload);
    $hasEndsAt = array_key_exists('ends_at', $payload);
    $hasScheduleTimezone = array_key_exists('schedule_timezone', $payload) || array_key_exists('timezone', $payload);
    $scheduleTimezoneField = array_key_exists('schedule_timezone', $payload) ? 'schedule_timezone' : 'timezone';
    $hasScheduleAllDay = array_key_exists('schedule_all_day', $payload);
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

    if ($hasRoomId) {
        $errors['room_id'] = 'immutable_for_call';
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

    $scheduleTimezone = 'UTC';
    if ($hasScheduleTimezone) {
        $timezoneValidation = videochat_validate_call_schedule_timezone($payload[$scheduleTimezoneField] ?? '');
        if (!(bool) $timezoneValidation['ok']) {
            $errors[$scheduleTimezoneField] = (string) $timezoneValidation['error'];
        }
        $scheduleTimezone = (string) $timezoneValidation['timezone'];
    }

    $scheduleAllDay = false;
    if ($hasScheduleAllDay) {
        $allDayValidation = videochat_validate_call_schedule_all_day($payload['schedule_all_day']);
        if (!(bool) $allDayValidation['ok']) {
            $errors['schedule_all_day'] = (string) $allDayValidation['error'];
        }
        $scheduleAllDay = (bool) $allDayValidation['all_day'];
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
        !$hasTitle
        && !$hasAccessMode
        && !$hasStartsAt
        && !$hasEndsAt
        && !$hasScheduleTimezone
        && !$hasScheduleAllDay
        && !$hasInternalParticipants
        && !$hasExternalParticipants
        && !$resendRequested
        && !$hasRoomId
    ) {
        $errors['payload'] = 'at_least_one_supported_field_required';
    }

    return [
        'ok' => $errors === [],
        'data' => [
            'has_room_id' => false,
            'room_id' => '',
            'has_title' => $hasTitle,
            'title' => $title,
            'has_access_mode' => $hasAccessMode,
            'access_mode' => $accessMode,
            'has_starts_at' => $hasStartsAt,
            'starts_at_unix' => $startsAtUnix,
            'has_ends_at' => $hasEndsAt,
            'ends_at_unix' => $endsAtUnix,
            'has_schedule_timezone' => $hasScheduleTimezone,
            'schedule_timezone' => $scheduleTimezone,
            'has_schedule_all_day' => $hasScheduleAllDay,
            'schedule_all_day' => $scheduleAllDay,
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
function videochat_update_call(PDO $pdo, string $callId, int $authUserId, string $authRole, array $payload, ?int $tenantId = null): array
{
    $existingCall = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
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

    $nextRoomId = (string) $existingCall['room_id'];
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
        $activeInternalUsers = videochat_active_internal_users($pdo, $requestedInternalIds, $tenantId);
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
    $nextScheduleTimezone = (bool) ($data['has_schedule_timezone'] ?? false)
        ? (string) ($data['schedule_timezone'] ?? 'UTC')
        : videochat_normalize_call_schedule_timezone($existingCall['schedule_timezone'] ?? 'UTC');
    $nextScheduleAllDay = (bool) ($data['has_schedule_all_day'] ?? false)
        ? (bool) ($data['schedule_all_day'] ?? false)
        : ((int) ($existingCall['schedule_all_day'] ?? 0) === 1);
    $nextSchedule = videochat_build_call_schedule_metadata(
        $nextStartsAt,
        $nextEndsAt,
        $nextScheduleTimezone,
        $nextScheduleAllDay
    );

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
    schedule_timezone = :schedule_timezone,
    schedule_date = :schedule_date,
    schedule_duration_minutes = :schedule_duration_minutes,
    schedule_all_day = :schedule_all_day,
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
            ':schedule_timezone' => (string) ($nextSchedule['timezone'] ?? 'UTC'),
            ':schedule_date' => (string) ($nextSchedule['date'] ?? ''),
            ':schedule_duration_minutes' => (int) ($nextSchedule['duration_minutes'] ?? 0),
            ':schedule_all_day' => (bool) ($nextSchedule['all_day'] ?? false) ? 1 : 0,
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
            'schedule' => $nextSchedule,
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
