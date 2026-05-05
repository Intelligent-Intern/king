<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_migrations.php';

function videochat_create_call(PDO $pdo, int $ownerUserId, array $payload, ?int $tenantId = null): array
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
    $activeInternalUsers = videochat_active_internal_users($pdo, $requestedInternalIds, $tenantId);
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
    $schedule = is_array($data['schedule'] ?? null)
        ? $data['schedule']
        : videochat_build_call_schedule_metadata($startsAt, $endsAt);
    $initialStatus = 'scheduled';
    $startsAtUnix = strtotime($startsAt);
    $endsAtUnix = strtotime($endsAt);
    $nowUnix = time();
    if (is_int($startsAtUnix) && is_int($endsAtUnix) && $startsAtUnix <= $nowUnix && $nowUnix < $endsAtUnix) {
        $initialStatus = 'active';
    }

    $pdo->beginTransaction();
    try {
        $effectiveTenantId = is_int($tenantId) && $tenantId > 0 ? $tenantId : null;
        $roomTenantColumn = $effectiveTenantId !== null && videochat_tenant_table_has_column($pdo, 'rooms', 'tenant_id')
            ? ', tenant_id'
            : '';
        $roomTenantValue = $roomTenantColumn !== '' ? ', :tenant_id' : '';
        $insertRoom = $pdo->prepare(
            <<<SQL
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at{$roomTenantColumn})
VALUES(:id, :name, 'private', 'active', :created_by_user_id, :created_at, :updated_at{$roomTenantValue})
SQL
        );
        $roomParams = [
            ':id' => $callRoomId,
            ':name' => (string) $data['title'],
            ':created_by_user_id' => (int) $owner['id'],
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ];
        if ($roomTenantColumn !== '') {
            $roomParams[':tenant_id'] = $effectiveTenantId;
        }
        $insertRoom->execute($roomParams);

        $callTenantColumn = $effectiveTenantId !== null && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
            ? ', tenant_id'
            : '';
        $callTenantValue = $callTenantColumn !== '' ? ', :tenant_id' : '';
        $insertCall = $pdo->prepare(
            <<<SQL
INSERT INTO calls(
    id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at{$callTenantColumn}
) VALUES(
    :id, :room_id, :title, :access_mode, :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, :schedule_all_day,
    NULL, NULL, NULL, :created_at, :updated_at{$callTenantValue}
)
SQL
        );
        $callParams = [
            ':id' => $callId,
            ':room_id' => $callRoomId,
            ':title' => (string) $data['title'],
            ':access_mode' => (string) $data['access_mode'],
            ':owner_user_id' => (int) $owner['id'],
            ':status' => $initialStatus,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':schedule_timezone' => (string) ($schedule['timezone'] ?? 'UTC'),
            ':schedule_date' => (string) ($schedule['date'] ?? ''),
            ':schedule_duration_minutes' => (int) ($schedule['duration_minutes'] ?? 0),
            ':schedule_all_day' => (bool) ($schedule['all_day'] ?? false) ? 1 : 0,
            ':created_at' => $createdAt,
            ':updated_at' => $createdAt,
        ];
        if ($callTenantColumn !== '') {
            $callParams[':tenant_id'] = $effectiveTenantId;
        }
        $insertCall->execute($callParams);

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
            'tenant_id' => $effectiveTenantId,
            'room_id' => $callRoomId,
            'title' => (string) $data['title'],
            'access_mode' => (string) $data['access_mode'],
            'status' => $initialStatus,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'schedule' => $schedule,
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
 *   schedule_timezone: string,
 *   schedule_date: string,
 *   schedule_duration_minutes: int,
 *   schedule_all_day: int,
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   cancel_message: ?string,
 *   created_at: string,
 *   updated_at: string,
 *   owner_email: string,
 *   owner_display_name: string
 * }|null
 */
