<?php

declare(strict_types=1);

require_once __DIR__ . '/../calls/call_management_contract.php';
require_once __DIR__ . '/realtime_connection_contract.php';
require_once __DIR__ . '/realtime_call_presence_db.php';

function videochat_realtime_upsert_pending_lobby_participant(
    callable $openDatabase,
    array $connection
): bool {
    $callId = videochat_realtime_connection_call_id($connection);
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($callId === '' || $userId <= 0) {
        return false;
    }

    try {
        $pdo = $openDatabase();
        $statement = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending',
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
  AND invite_state IN ('invited', 'declined', 'cancelled')
SQL
        );
        $statement->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
        ]);
        if ($statement->rowCount() > 0) {
            return true;
        }

        $existing = $pdo->prepare(
            <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
        );
        $existing->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
        ]);
        if ((int) ($existing->fetchColumn() ?: 0) > 0) {
            return false;
        }

        $identity = $pdo->prepare(
            <<<'SQL'
SELECT email, display_name
FROM users
WHERE id = :user_id
LIMIT 1
SQL
        );
        $identity->execute([':user_id' => $userId]);
        $user = $identity->fetch();
        if (!is_array($user)) {
            return false;
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '') {
            return false;
        }
        $displayName = trim((string) ($user['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $email;
        }

        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'participant', 'pending', NULL, NULL)
ON CONFLICT(call_id, email) DO UPDATE SET
    user_id = excluded.user_id,
    display_name = excluded.display_name,
    source = 'internal',
    invite_state = CASE
        WHEN call_participants.invite_state IN ('allowed', 'accepted') THEN call_participants.invite_state
        ELSE 'pending'
    END,
    left_at = NULL
SQL
        );
        $insert->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
        ]);

        return $insert->rowCount() > 0;
    } catch (Throwable) {
        return false;
    }
}
