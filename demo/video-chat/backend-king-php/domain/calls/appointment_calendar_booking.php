<?php

declare(strict_types=1);

function videochat_book_public_appointment(PDO $pdo, string $publicId, array $payload): array
{
    $calendar = videochat_get_appointment_settings_by_public_id($pdo, $publicId);
    if ($calendar === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found']];
    }
    $ownerUserId = (int) ($calendar['owner_user_id'] ?? 0);
    $tenantId = is_numeric($calendar['tenant_id'] ?? null) ? (int) $calendar['tenant_id'] : null;
    $owner = videochat_appointment_owner($pdo, $ownerUserId, $tenantId);
    if ($owner === null) {
        return ['ok' => false, 'reason' => 'not_found', 'errors' => ['owner' => 'owner_not_found']];
    }
    $calendarSettings = is_array($calendar['settings'] ?? null) ? (array) $calendar['settings'] : [];
    $slotSeconds = videochat_appointment_slot_seconds($calendarSettings);

    $validation = videochat_validate_public_appointment_booking_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $validation['errors'] ?? []];
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $slot = videochat_parse_appointment_slot_token((string) ($data['slot_id'] ?? ''));
    $blockId = (string) (($slot['block_id'] ?? null) ?: ($data['slot_id'] ?? ''));
    $now = gmdate('c');
    $bookingId = videochat_generate_call_id();
    $callId = videochat_generate_call_id();
    $accessId = videochat_generate_call_access_uuid();

    try {
        $pdo->beginTransaction();
        $blockTenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_blocks', 'tenant_id')
            ? '  AND tenant_id = :tenant_id'
            : '';
        $blockQuery = $pdo->prepare(
            <<<SQL
SELECT *
FROM appointment_blocks
WHERE id = :id
  AND owner_user_id = :owner_user_id
  AND status = 'open'
{$blockTenantWhere}
LIMIT 1
SQL
        );
        $blockParams = [':id' => $blockId, ':owner_user_id' => $ownerUserId];
        if ($blockTenantWhere !== '') {
            $blockParams[':tenant_id'] = $tenantId;
        }
        $blockQuery->execute($blockParams);
        $block = $blockQuery->fetch();
        if (!is_array($block)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'not_found', 'errors' => ['slot_id' => 'slot_not_found']];
        }

        $blockStartsAt = (string) ($block['starts_at'] ?? '');
        $blockEndsAt = (string) ($block['ends_at'] ?? '');
        $startsAt = (string) (($slot['starts_at'] ?? null) ?: $blockStartsAt);
        $endsAt = (string) (($slot['ends_at'] ?? null) ?: $blockEndsAt);
        $blockStartsAtUnix = strtotime($blockStartsAt);
        $blockEndsAtUnix = strtotime($blockEndsAt);
        $startsAtUnix = strtotime($startsAt);
        $endsAtUnix = strtotime($endsAt);
        if (!is_int($blockStartsAtUnix) || !is_int($blockEndsAtUnix) || !is_int($startsAtUnix) || !is_int($endsAtUnix)) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_unavailable']];
        }
        $isSlotInBlock = $startsAtUnix >= $blockStartsAtUnix && $endsAtUnix <= $blockEndsAtUnix;
        $isSlotDuration = ($endsAtUnix - $startsAtUnix) === $slotSeconds;
        $isSlotAligned = (($startsAtUnix - $blockStartsAtUnix) % $slotSeconds) === 0;
        if ($startsAtUnix <= time() || $endsAtUnix <= $startsAtUnix || !$isSlotInBlock || ($slot !== null && (!$isSlotDuration || !$isSlotAligned))) {
            $pdo->rollBack();
            return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_unavailable']];
        }

        foreach (videochat_appointment_booked_ranges($pdo, $ownerUserId, $tenantId) as $range) {
            if (videochat_appointment_ranges_overlap($startsAtUnix, $endsAtUnix, (int) $range['start_unix'], (int) $range['end_unix'])) {
                $pdo->rollBack();
                return ['ok' => false, 'reason' => 'conflict', 'errors' => ['slot_id' => 'slot_already_booked']];
            }
        }

        $bookingBlockId = (string) ($block['id'] ?? '');
        if ($startsAt !== $blockStartsAt || $endsAt !== $blockEndsAt) {
            $bookingBlockId = videochat_generate_call_id();
            $tenantColumn = $blockTenantWhere !== '' ? ', tenant_id' : '';
            $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
            $insertBookingBlock = $pdo->prepare(
                <<<SQL
INSERT INTO appointment_blocks(id, owner_user_id, starts_at, ends_at, timezone, status, created_at, updated_at{$tenantColumn})
VALUES(:id, :owner_user_id, :starts_at, :ends_at, :timezone, 'open', :created_at, :updated_at{$tenantValue})
SQL
            );
            $bookingBlockParams = [
                ':id' => $bookingBlockId,
                ':owner_user_id' => $ownerUserId,
                ':starts_at' => $startsAt,
                ':ends_at' => $endsAt,
                ':timezone' => videochat_normalize_call_schedule_timezone($block['timezone'] ?? 'UTC'),
                ':created_at' => $now,
                ':updated_at' => $now,
            ];
            if ($tenantColumn !== '') {
                $bookingBlockParams[':tenant_id'] = $tenantId;
            }
            $insertBookingBlock->execute($bookingBlockParams);
        }

        $displayName = trim((string) $data['first_name'] . ' ' . (string) $data['last_name']);
        $callTitle = 'Video call with ' . $displayName;
        $schedule = videochat_build_call_schedule_metadata($startsAt, $endsAt, $block['timezone'] ?? 'UTC', false);
        $status = $startsAtUnix <= time() && time() < $endsAtUnix ? 'active' : 'scheduled';

        $roomTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'rooms', 'tenant_id') ? ', tenant_id' : '';
        $roomTenantValue = $roomTenantColumn !== '' ? ', :tenant_id' : '';
        $insertRoom = $pdo->prepare(
            <<<SQL
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at{$roomTenantColumn})
VALUES(:id, :name, 'private', 'active', :owner_user_id, :created_at, :updated_at{$roomTenantValue})
SQL
        );
        $roomParams = [
            ':id' => $callId,
            ':name' => $callTitle,
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($roomTenantColumn !== '') {
            $roomParams[':tenant_id'] = $tenantId;
        }
        $insertRoom->execute($roomParams);

        $callTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id') ? ', tenant_id' : '';
        $callTenantValue = $callTenantColumn !== '' ? ', :tenant_id' : '';
        $insertCall = $pdo->prepare(
            <<<SQL
INSERT INTO calls(
    id, room_id, title, access_mode, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at{$callTenantColumn}
) VALUES(
    :id, :room_id, :title, 'invite_only', :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, 0,
    NULL, NULL, NULL, :created_at, :updated_at{$callTenantValue}
)
SQL
        );
        $callParams = [
            ':id' => $callId,
            ':room_id' => $callId,
            ':title' => $callTitle,
            ':owner_user_id' => $ownerUserId,
            ':status' => $status,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':schedule_timezone' => (string) ($schedule['timezone'] ?? 'UTC'),
            ':schedule_date' => (string) ($schedule['date'] ?? ''),
            ':schedule_duration_minutes' => (int) ($schedule['duration_minutes'] ?? 0),
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($callTenantColumn !== '') {
            $callParams[':tenant_id'] = $tenantId;
        }
        $insertCall->execute($callParams);

        $insertParticipant = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, NULL, NULL)
SQL
        );
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $ownerUserId,
            ':email' => (string) $owner['email'],
            ':display_name' => (string) $owner['display_name'],
            ':source' => 'internal',
            ':call_role' => 'owner',
            ':invite_state' => 'allowed',
        ]);
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => null,
            ':email' => (string) $data['email'],
            ':display_name' => $displayName,
            ':source' => 'external',
            ':call_role' => 'participant',
            ':invite_state' => 'invited',
        ]);

        $accessTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
        $accessTenantValue = $accessTenantColumn !== '' ? ', :tenant_id' : '';
        $insertAccess = $pdo->prepare(
            <<<SQL
INSERT INTO call_access_links(
    id, call_id, participant_user_id, participant_email, invite_code_id,
    created_by_user_id, created_at, expires_at, last_used_at, consumed_at{$accessTenantColumn}
) VALUES(
    :id, :call_id, NULL, :participant_email, NULL,
    :owner_user_id, :created_at, :expires_at, NULL, NULL{$accessTenantValue}
)
SQL
        );
        $accessParams = [
            ':id' => $accessId,
            ':call_id' => $callId,
            ':participant_email' => (string) $data['email'],
            ':owner_user_id' => $ownerUserId,
            ':created_at' => $now,
            ':expires_at' => $endsAt,
        ];
        if ($accessTenantColumn !== '') {
            $accessParams[':tenant_id'] = $tenantId;
        }
        $insertAccess->execute($accessParams);

        $bookingTenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'appointment_bookings', 'tenant_id') ? ', tenant_id' : '';
        $bookingTenantValue = $bookingTenantColumn !== '' ? ', :tenant_id' : '';
        $insertBooking = $pdo->prepare(
            <<<SQL
INSERT INTO appointment_bookings(
    id, block_id, call_id, access_id, owner_user_id, salutation, title,
    first_name, last_name, email, message, privacy_accepted, privacy_accepted_at,
    status, created_at, updated_at{$bookingTenantColumn}
) VALUES(
    :id, :block_id, :call_id, :access_id, :owner_user_id, :salutation, :title,
    :first_name, :last_name, :email, :message, 1, :privacy_accepted_at,
    'booked', :created_at, :updated_at{$bookingTenantValue}
)
SQL
        );
        $bookingParams = [
            ':id' => $bookingId,
            ':block_id' => $bookingBlockId,
            ':call_id' => $callId,
            ':access_id' => $accessId,
            ':owner_user_id' => $ownerUserId,
            ':salutation' => (string) $data['salutation'],
            ':title' => (string) $data['title'],
            ':first_name' => (string) $data['first_name'],
            ':last_name' => (string) $data['last_name'],
            ':email' => (string) $data['email'],
            ':message' => (string) $data['message'],
            ':privacy_accepted_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];
        if ($bookingTenantColumn !== '') {
            $bookingParams[':tenant_id'] = $tenantId;
        }
        $insertBooking->execute($bookingParams);

        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'reason' => 'internal_error', 'errors' => []];
    }

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    $publicCall = is_array($call) ? [
        'id' => (string) ($call['id'] ?? $callId),
        'room_id' => (string) ($call['room_id'] ?? $callId),
        'title' => (string) ($call['title'] ?? 'Video call'),
        'starts_at' => (string) ($call['starts_at'] ?? ''),
        'ends_at' => (string) ($call['ends_at'] ?? ''),
        'status' => (string) ($call['status'] ?? 'scheduled'),
    ] : null;
    $calendarMailSettings = is_array($calendar['settings'] ?? null) ? (array) $calendar['settings'] : [];
    $mailSettings = [
        ...videochat_workspace_mail_transport_settings($pdo, $tenantId),
        'mail_subject_template' => (string) ($calendarMailSettings['mail_subject_template'] ?? videochat_default_appointment_email_subject_template()),
        'mail_body_template' => (string) ($calendarMailSettings['mail_body_template'] ?? videochat_default_appointment_email_body_template()),
    ];
    $notifications = videochat_send_appointment_booking_notifications(
        $mailSettings,
        $owner,
        $data,
        [
            'call_id' => $callId,
            'access_id' => $accessId,
            'call_title' => $callTitle,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => (string) ($block['timezone'] ?? 'UTC'),
        ]
    );

    return [
        'ok' => true,
        'reason' => 'booked',
        'errors' => [],
        'booking' => [
            'id' => $bookingId,
            'slot_id' => (string) ($data['slot_id'] ?? ''),
            'call_id' => $callId,
            'access_id' => $accessId,
            'starts_at' => $call['starts_at'] ?? '',
            'ends_at' => $call['ends_at'] ?? '',
            'join_path' => '/join/' . $accessId,
        ],
        'call' => $publicCall,
        'join_path' => '/join/' . $accessId,
        'notifications' => $notifications,
    ];
}
