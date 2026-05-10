<?php

declare(strict_types=1);

function videochat_call_app_session_guest_id(string $email): string
{
    return 'guest_' . substr(hash('sha256', strtolower(trim($email))), 0, 32);
}

function videochat_call_app_active_participant_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return $prefix . "invite_state IN ('allowed', 'accepted')"
        . ' AND (' . $prefix . 'left_at IS NULL OR trim(' . $prefix . "left_at) = '')";
}

function videochat_call_app_joinable_call_sql(string $alias): string
{
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return $prefix . "status IN ('scheduled', 'active')";
}

function videochat_call_app_grant_subject_in_call(PDO $pdo, string $callId, string $subjectType, ?int $userId, string $guestId): bool
{
    $normalizedCallId = trim($callId);
    $normalizedSubjectType = strtolower(trim($subjectType));
    if ($normalizedCallId === '') {
        return false;
    }

    $joinableCallWhere = videochat_call_app_joinable_call_sql('calls');
    $activeParticipantWhere = videochat_call_app_active_participant_sql('cp');
    if ($normalizedSubjectType === 'user') {
        $normalizedUserId = (int) ($userId ?? 0);
        if ($normalizedUserId <= 0) {
            return false;
        }
        $statement = $pdo->prepare(
            <<<SQL
SELECT 1
FROM calls
WHERE calls.id = :call_id
  AND calls.owner_user_id = :user_id
  AND {$joinableCallWhere}
UNION
SELECT 1
FROM call_participants cp
INNER JOIN calls ON calls.id = cp.call_id
WHERE cp.call_id = :call_id
  AND cp.user_id = :user_id
  AND cp.source = 'internal'
  AND {$activeParticipantWhere}
  AND {$joinableCallWhere}
LIMIT 1
SQL
        );
        $statement->execute([':call_id' => $normalizedCallId, ':user_id' => $normalizedUserId]);
        return (bool) $statement->fetchColumn();
    }

    $normalizedGuestId = trim($guestId);
    if ($normalizedSubjectType !== 'guest' || $normalizedGuestId === '') {
        return false;
    }
    $statement = $pdo->prepare(
        <<<SQL
SELECT cp.email
FROM call_participants cp
INNER JOIN calls ON calls.id = cp.call_id
WHERE cp.call_id = :call_id
  AND cp.user_id IS NULL
  AND {$activeParticipantWhere}
  AND {$joinableCallWhere}
SQL
    );
    $statement->execute([':call_id' => $normalizedCallId]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
        if (videochat_call_app_session_guest_id((string) $email) === $normalizedGuestId) {
            return true;
        }
    }
    return false;
}

function videochat_call_app_active_call_subjects(PDO $pdo, string $callId): array
{
    $normalizedCallId = trim($callId);
    if ($normalizedCallId === '') {
        return [];
    }

    $joinableCallWhere = videochat_call_app_joinable_call_sql('calls');
    $activeParticipantWhere = videochat_call_app_active_participant_sql('cp');
    $participants = $pdo->prepare(
        <<<SQL
SELECT DISTINCT cp.user_id, cp.email
FROM call_participants cp
INNER JOIN calls ON calls.id = cp.call_id
WHERE cp.call_id = :call_id
  AND ((cp.user_id IS NOT NULL AND cp.source = 'internal') OR cp.user_id IS NULL)
  AND {$activeParticipantWhere}
  AND {$joinableCallWhere}
SQL
    );
    $participants->execute([':call_id' => $normalizedCallId]);

    $subjects = [];
    foreach ($participants->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $userId = is_numeric($row['user_id'] ?? null) ? (int) $row['user_id'] : 0;
        if ($userId > 0) {
            $subjects['user:' . $userId] = ['subject_type' => 'user', 'user_id' => $userId, 'guest_id' => ''];
            continue;
        }
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $guestId = videochat_call_app_session_guest_id($email);
            $subjects['guest:' . $guestId] = ['subject_type' => 'guest', 'user_id' => null, 'guest_id' => $guestId];
        }
    }

    $owner = $pdo->prepare(
        <<<SQL
SELECT owner_user_id
FROM calls
WHERE id = :call_id
  AND {$joinableCallWhere}
LIMIT 1
SQL
    );
    $owner->execute([':call_id' => $normalizedCallId]);
    $ownerUserId = (int) $owner->fetchColumn();
    if ($ownerUserId > 0) {
        $subjects['user:' . $ownerUserId] = ['subject_type' => 'user', 'user_id' => $ownerUserId, 'guest_id' => ''];
    }

    return $subjects;
}
