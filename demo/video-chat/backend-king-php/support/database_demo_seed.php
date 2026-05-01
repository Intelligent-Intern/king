<?php

declare(strict_types=1);

function videochat_demo_user_blueprint(): array
{
    $adminEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_ADMIN_EMAIL') ?: 'admin@intelligent-intern.com')));
    $userEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_USER_EMAIL') ?: 'user@intelligent-intern.com')));
    $adminPassword = videochat_config_secret_value('VIDEOCHAT_DEMO_ADMIN_PASSWORD', 'admin123');
    $userPassword = videochat_config_secret_value('VIDEOCHAT_DEMO_USER_PASSWORD', 'user123');

    if ($adminEmail === '' || filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_ADMIN_EMAIL must be a valid email address.');
    }
    if ($userEmail === '' || filter_var($userEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_USER_EMAIL must be a valid email address.');
    }
    if ($adminPassword === '') {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_ADMIN_PASSWORD must not be empty.');
    }
    if ($userPassword === '') {
        throw new InvalidArgumentException('VIDEOCHAT_DEMO_USER_PASSWORD must not be empty.');
    }
    $users = [
        [
            'email' => $adminEmail,
            'display_name' => 'Platform Admin',
            'role' => 'admin',
            'password' => $adminPassword,
            'time_format' => '24h',
            'date_format' => 'dmy_dot',
            'theme' => 'dark',
        ],
        [
            'email' => $userEmail,
            'display_name' => 'Call User',
            'role' => 'user',
            'password' => $userPassword,
            'time_format' => '24h',
            'date_format' => 'dmy_dot',
            'theme' => 'dark',
        ],
    ];

    $deduplicated = [];
    $seenByEmail = [];
    foreach ($users as $user) {
        $email = strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '' || isset($seenByEmail[$email])) {
            continue;
        }
        $seenByEmail[$email] = true;
        $deduplicated[] = $user;
    }

    return $deduplicated;
}

/**
 * @return array<int, array{email: string, display_name: string, role: string}>
 */
function videochat_seed_demo_users(PDO $pdo): array
{
    $roles = [];
    $roleRows = $pdo->query('SELECT id, slug FROM roles');
    foreach ($roleRows as $row) {
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : '';
        if ($slug === '') {
            continue;
        }
        $roles[$slug] = (int) ($row['id'] ?? 0);
    }

    $selectUser = $pdo->prepare(
        <<<'SQL'
SELECT id, role_id, display_name, password_hash, status, time_format, date_format, theme
FROM users
WHERE lower(email) = lower(:email)
LIMIT 1
SQL
    );
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', :time_format, :date_format, :theme, :updated_at)
SQL
    );
    $updateUser = $pdo->prepare(
        <<<'SQL'
UPDATE users
SET display_name = :display_name,
    password_hash = :password_hash,
    role_id = :role_id,
    status = 'active',
    time_format = :time_format,
    date_format = :date_format,
    theme = :theme,
    updated_at = :updated_at
WHERE id = :id
SQL
    );

    $seeded = [];
    foreach (videochat_demo_user_blueprint() as $demoUser) {
        $roleId = (int) ($roles[$demoUser['role']] ?? 0);
        if ($roleId <= 0) {
            throw new RuntimeException(sprintf('Missing role slug in roles table: %s', $demoUser['role']));
        }

        $selectUser->execute([':email' => $demoUser['email']]);
        $existing = $selectUser->fetch();

        $passwordHash = null;
        $needsUpdate = false;
        if (is_array($existing)) {
            $existingHash = is_string($existing['password_hash'] ?? null) ? trim((string) $existing['password_hash']) : '';
            $hashValid = $existingHash !== '' && password_verify($demoUser['password'], $existingHash);
            $hashNeedsRehash = $hashValid && password_needs_rehash($existingHash, PASSWORD_DEFAULT);

            if (!$hashValid || $hashNeedsRehash) {
                $passwordHash = password_hash($demoUser['password'], PASSWORD_DEFAULT);
                if (!is_string($passwordHash) || $passwordHash === '') {
                    throw new RuntimeException('Failed to hash demo user password.');
                }
                $needsUpdate = true;
            } else {
                $passwordHash = $existingHash;
            }

            if ((int) ($existing['role_id'] ?? 0) !== $roleId) {
                $needsUpdate = true;
            }
            if ((string) ($existing['display_name'] ?? '') !== $demoUser['display_name']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['status'] ?? '') !== 'active') {
                $needsUpdate = true;
            }
            if ((string) ($existing['time_format'] ?? '') !== $demoUser['time_format']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['date_format'] ?? '') !== $demoUser['date_format']) {
                $needsUpdate = true;
            }
            if ((string) ($existing['theme'] ?? '') !== $demoUser['theme']) {
                $needsUpdate = true;
            }

            if ($needsUpdate) {
                $updateUser->execute([
                    ':id' => (int) $existing['id'],
                    ':display_name' => $demoUser['display_name'],
                    ':password_hash' => $passwordHash,
                    ':role_id' => $roleId,
                    ':time_format' => $demoUser['time_format'],
                    ':date_format' => $demoUser['date_format'],
                    ':theme' => $demoUser['theme'],
                    ':updated_at' => gmdate('c'),
                ]);
            }
        } else {
            $passwordHash = password_hash($demoUser['password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Failed to hash demo user password.');
            }

            $insertParams = [
                ':email' => $demoUser['email'],
                ':display_name' => $demoUser['display_name'],
                ':password_hash' => $passwordHash,
                ':role_id' => $roleId,
                ':time_format' => $demoUser['time_format'],
                ':date_format' => $demoUser['date_format'],
                ':theme' => $demoUser['theme'],
                ':updated_at' => gmdate('c'),
            ];

            try {
                $insertUser->execute($insertParams);
            } catch (Throwable $error) {
                $message = strtolower($error->getMessage());
                $isEmailRace = str_contains($message, 'unique constraint failed')
                    && str_contains($message, 'users.email');
                if (!$isEmailRace) {
                    throw $error;
                }
                // Another bootstrap process inserted the same demo user between SELECT and INSERT.
                // Treat as successful seed and continue.
            }
        }

        $seeded[] = [
            'email' => $demoUser['email'],
            'display_name' => $demoUser['display_name'],
            'role' => $demoUser['role'],
        ];
    }

    return $seeded;
}

function videochat_demo_seed_calls_enabled(): bool
{
    $raw = getenv('VIDEOCHAT_DEMO_SEED_CALLS');
    if ($raw === false) {
        return false;
    }

    $normalized = strtolower(trim((string) $raw));
    if ($normalized === '') {
        return false;
    }

    return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
}

/**
 * @param array<string, array{id: int, email: string, display_name: string, role: string}> $usersByEmail
 * @return array<int, array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   status: string,
 *   owner_email: string,
 *   starts_at: string,
 *   ends_at: string,
 *   cancelled_at: ?string,
 *   cancel_reason: ?string,
 *   cancel_message: ?string,
 *   participants: array<int, array{
 *     source: string,
 *     email: string,
 *     display_name: string,
 *     call_role: string,
 *     invite_state: string,
 *     joined_at: ?string,
 *     left_at: ?string
 *   }>
 * }>
 */
function videochat_demo_call_blueprint(array $usersByEmail, ?int $nowUnix = null): array
{
    if ($usersByEmail === []) {
        return [];
    }

    $effectiveNow = $nowUnix ?? time();
    $adminEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_ADMIN_EMAIL') ?: 'admin@intelligent-intern.com')));
    $userEmail = strtolower(trim((string) (getenv('VIDEOCHAT_DEMO_USER_EMAIL') ?: 'user@intelligent-intern.com')));

    if (!isset($usersByEmail[$adminEmail])) {
        return [];
    }

    $internalEmails = [$adminEmail];
    if ($userEmail !== $adminEmail && isset($usersByEmail[$userEmail])) {
        $internalEmails[] = $userEmail;
    }

    $baseInternalParticipants = [];
    foreach ($internalEmails as $index => $email) {
        $user = $usersByEmail[$email] ?? null;
        if (!is_array($user)) {
            continue;
        }

        $baseInternalParticipants[] = [
            'source' => 'internal',
            'email' => strtolower(trim((string) ($user['email'] ?? ''))),
            'display_name' => (string) ($user['display_name'] ?? 'User'),
            'call_role' => $index === 0 ? 'owner' : ($index === 1 ? 'moderator' : 'participant'),
            'invite_state' => $index === 0 ? 'allowed' : 'invited',
            'joined_at' => null,
            'left_at' => null,
        ];
    }

    $activeParticipants = [];
    foreach ($baseInternalParticipants as $participant) {
        $participant['joined_at'] = gmdate('c', $effectiveNow - 600);
        $participant['invite_state'] = 'allowed';
        $activeParticipants[] = $participant;
    }

    $architectureCallId = 'demo-call-architecture-sync';
    $platformCallId = 'demo-call-platform-standup';
    $retroCallId = 'demo-call-retro-weekly';

    return [
        [
            'id' => $architectureCallId,
            'room_id' => $architectureCallId,
            'title' => 'Architecture Sync',
            'status' => 'scheduled',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow + 3600),
            'ends_at' => gmdate('c', $effectiveNow + 7200),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => [
                ...$baseInternalParticipants,
                [
                    'source' => 'external',
                    'email' => 'guest.architecture@example.com',
                    'display_name' => 'Guest Architect',
                    'call_role' => 'participant',
                    'invite_state' => 'invited',
                    'joined_at' => null,
                    'left_at' => null,
                ],
            ],
        ],
        [
            'id' => $platformCallId,
            'room_id' => $platformCallId,
            'title' => 'Platform Standup',
            'status' => 'active',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow - 900),
            'ends_at' => gmdate('c', $effectiveNow + 2700),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => $activeParticipants,
        ],
        [
            'id' => $retroCallId,
            'room_id' => $retroCallId,
            'title' => 'Weekly Retrospective',
            'status' => 'ended',
            'owner_email' => $adminEmail,
            'starts_at' => gmdate('c', $effectiveNow - 7200),
            'ends_at' => gmdate('c', $effectiveNow - 3600),
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
            'participants' => $baseInternalParticipants,
        ],
    ];
}

/**
 * @return array{
 *   schedule_timezone: string,
 *   schedule_date: string,
 *   schedule_duration_minutes: int,
 *   schedule_all_day: int
 * }
 */
function videochat_demo_call_schedule_columns(string $startsAt, string $endsAt): array
{
    $startsAtUnix = strtotime($startsAt);
    $endsAtUnix = strtotime($endsAt);
    if (!is_int($startsAtUnix)) {
        $startsAtUnix = 0;
    }
    if (!is_int($endsAtUnix)) {
        $endsAtUnix = $startsAtUnix;
    }

    return [
        'schedule_timezone' => 'UTC',
        'schedule_date' => gmdate('Y-m-d', $startsAtUnix),
        'schedule_duration_minutes' => intdiv(max(0, $endsAtUnix - $startsAtUnix), 60),
        'schedule_all_day' => 0,
    ];
}

/**
 * @return array<int, array{
 *   id: string,
 *   room_id: string,
 *   title: string,
 *   status: string,
 *   owner_email: string,
 *   starts_at: string,
 *   ends_at: string
 * }>
 */
function videochat_seed_demo_calls(PDO $pdo): array
{
    if (!videochat_demo_seed_calls_enabled()) {
        return [];
    }

    $userRows = $pdo->query(
        <<<'SQL'
SELECT users.id, users.email, users.display_name, roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.status = 'active'
SQL
    )->fetchAll();

    $usersByEmail = [];
    if (is_array($userRows)) {
        foreach ($userRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $usersByEmail[$email] = [
                'id' => (int) ($row['id'] ?? 0),
                'email' => $email,
                'display_name' => (string) ($row['display_name'] ?? $email),
                'role' => (string) ($row['role_slug'] ?? 'user'),
            ];
        }
    }

    $blueprint = videochat_demo_call_blueprint($usersByEmail);
    if ($blueprint === []) {
        return [];
    }

    $selectCall = $pdo->prepare('SELECT id FROM calls WHERE id = :id LIMIT 1');
    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, room_id, title, owner_user_id, status, starts_at, ends_at,
    schedule_timezone, schedule_date, schedule_duration_minutes, schedule_all_day,
    cancelled_at, cancel_reason, cancel_message, created_at, updated_at
)
VALUES(
    :id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at,
    :schedule_timezone, :schedule_date, :schedule_duration_minutes, :schedule_all_day,
    :cancelled_at, :cancel_reason, :cancel_message, :created_at, :updated_at
)
SQL
    );
    $updateCall = $pdo->prepare(
        <<<'SQL'
UPDATE calls
SET room_id = :room_id,
    title = :title,
    owner_user_id = :owner_user_id,
    status = :status,
    starts_at = :starts_at,
    ends_at = :ends_at,
    schedule_timezone = :schedule_timezone,
    schedule_date = :schedule_date,
    schedule_duration_minutes = :schedule_duration_minutes,
    schedule_all_day = :schedule_all_day,
    cancelled_at = :cancelled_at,
    cancel_reason = :cancel_reason,
    cancel_message = :cancel_message,
    updated_at = :updated_at
WHERE id = :id
SQL
    );
    $insertRoom = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, :name, 'private', 'active', :created_by_user_id, :created_at, :updated_at)
SQL
    );
    $deleteParticipants = $pdo->prepare('DELETE FROM call_participants WHERE call_id = :call_id');
    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
    );

    $seeded = [];
    foreach ($blueprint as $call) {
        $callId = trim((string) ($call['id'] ?? ''));
        $ownerEmail = strtolower(trim((string) ($call['owner_email'] ?? '')));
        $owner = $usersByEmail[$ownerEmail] ?? null;
        if ($callId === '' || !is_array($owner) || (int) ($owner['id'] ?? 0) <= 0) {
            continue;
        }

        $startsAt = (string) ($call['starts_at'] ?? gmdate('c'));
        $endsAt = (string) ($call['ends_at'] ?? gmdate('c'));
        $scheduleColumns = videochat_demo_call_schedule_columns($startsAt, $endsAt);

        $callPayload = [
            ':id' => $callId,
            ':room_id' => (string) ($call['room_id'] ?? $callId),
            ':title' => (string) ($call['title'] ?? 'Demo Call'),
            ':owner_user_id' => (int) ($owner['id'] ?? 0),
            ':status' => (string) ($call['status'] ?? 'scheduled'),
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':schedule_timezone' => $scheduleColumns['schedule_timezone'],
            ':schedule_date' => $scheduleColumns['schedule_date'],
            ':schedule_duration_minutes' => $scheduleColumns['schedule_duration_minutes'],
            ':schedule_all_day' => $scheduleColumns['schedule_all_day'],
            ':cancelled_at' => is_string($call['cancelled_at'] ?? null) ? (string) $call['cancelled_at'] : null,
            ':cancel_reason' => is_string($call['cancel_reason'] ?? null) ? (string) $call['cancel_reason'] : null,
            ':cancel_message' => is_string($call['cancel_message'] ?? null) ? (string) $call['cancel_message'] : null,
            ':created_at' => gmdate('c'),
            ':updated_at' => gmdate('c'),
        ];
        $updateCallPayload = $callPayload;
        unset($updateCallPayload[':created_at']);

        $insertRoom->execute([
            ':id' => (string) $callPayload[':room_id'],
            ':name' => (string) $callPayload[':title'],
            ':created_by_user_id' => (int) ($owner['id'] ?? 0),
            ':created_at' => (string) $callPayload[':created_at'],
            ':updated_at' => (string) $callPayload[':updated_at'],
        ]);

        $selectCall->execute([':id' => $callId]);
        $existing = $selectCall->fetch();
        if (is_array($existing)) {
            $updateCall->execute($updateCallPayload);
        } else {
            $insertCall->execute($callPayload);
        }

        $deleteParticipants->execute([':call_id' => $callId]);
        $participants = is_array($call['participants'] ?? null) ? $call['participants'] : [];

        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $source = strtolower(trim((string) ($participant['source'] ?? '')));
            $email = strtolower(trim((string) ($participant['email'] ?? '')));
            if ($email === '' || !in_array($source, ['internal', 'external'], true)) {
                continue;
            }

            $internalUser = $usersByEmail[$email] ?? null;
            $userId = null;
            $displayName = trim((string) ($participant['display_name'] ?? ''));

            if ($source === 'internal') {
                if (!is_array($internalUser) || (int) ($internalUser['id'] ?? 0) <= 0) {
                    continue;
                }
                $userId = (int) ($internalUser['id'] ?? 0);
                if ($displayName === '') {
                    $displayName = (string) ($internalUser['display_name'] ?? $email);
                }
            } elseif ($displayName === '') {
                $displayName = $email;
            }

            $inviteState = strtolower(trim((string) ($participant['invite_state'] ?? 'invited')));
            if (!in_array($inviteState, ['invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled'], true)) {
                $inviteState = 'invited';
            }

            $callRole = strtolower(trim((string) ($participant['call_role'] ?? 'participant')));
            if (!in_array($callRole, ['owner', 'moderator', 'participant'], true)) {
                $callRole = 'participant';
            }
            if ($source !== 'internal') {
                $callRole = 'participant';
            } elseif ($email === $ownerEmail) {
                $callRole = 'owner';
            }

            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => $userId,
                ':email' => $email,
                ':display_name' => $displayName,
                ':source' => $source,
                ':call_role' => $callRole,
                ':invite_state' => $inviteState,
                ':joined_at' => is_string($participant['joined_at'] ?? null) ? (string) $participant['joined_at'] : null,
                ':left_at' => is_string($participant['left_at'] ?? null) ? (string) $participant['left_at'] : null,
            ]);
        }

        $seeded[] = [
            'id' => $callId,
            'room_id' => (string) ($call['room_id'] ?? $callId),
            'title' => (string) ($call['title'] ?? 'Demo Call'),
            'status' => (string) ($call['status'] ?? 'scheduled'),
            'owner_email' => $ownerEmail,
            'starts_at' => (string) ($call['starts_at'] ?? gmdate('c')),
            'ends_at' => (string) ($call['ends_at'] ?? gmdate('c')),
        ];
    }

    return $seeded;
}

/**
 * @return array<int, array{name: string, statements: array<int, string>}>
 */
