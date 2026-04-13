<?php

declare(strict_types=1);

function videochat_generate_uuid_v4(): string
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

function videochat_invite_scope_ttl_seconds(string $scope): int
{
    $normalizedScope = strtolower(trim($scope));
    $envKey = $normalizedScope === 'room'
        ? 'VIDEOCHAT_INVITE_ROOM_TTL_SECONDS'
        : 'VIDEOCHAT_INVITE_CALL_TTL_SECONDS';
    $defaultTtl = $normalizedScope === 'room' ? 86_400 : 21_600;

    $raw = getenv($envKey);
    $ttl = filter_var($raw, FILTER_VALIDATE_INT);
    if (!is_int($ttl)) {
        $ttl = $defaultTtl;
    }

    if ($ttl < 300) {
        return 300;
    }
    if ($ttl > 2_592_000) {
        return 2_592_000;
    }

    return $ttl;
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{
 *     scope: string,
 *     room_id: string,
 *     call_id: string
 *   },
 *   errors: array<string, string>
 * }
 */
function videochat_validate_create_invite_code_payload(array $payload): array
{
    $errors = [];

    $scope = strtolower(trim((string) ($payload['scope'] ?? '')));
    if (!in_array($scope, ['room', 'call'], true)) {
        $errors['scope'] = 'must_be_room_or_call';
    }

    $roomId = trim((string) ($payload['room_id'] ?? ''));
    $callId = trim((string) ($payload['call_id'] ?? ''));

    if (array_key_exists('expires_at', $payload)) {
        $errors['expires_at'] = 'server_managed_expiry_policy';
    }
    if (array_key_exists('expires_in_seconds', $payload)) {
        $errors['expires_in_seconds'] = 'server_managed_expiry_policy';
    }

    if ($scope === 'room') {
        if ($roomId === '') {
            $errors['room_id'] = 'required_for_room_scope';
        } elseif (strlen($roomId) > 120 || preg_match('/^[A-Za-z0-9._-]+$/', $roomId) !== 1) {
            $errors['room_id'] = 'invalid_room_id';
        }

        if ($callId !== '') {
            $errors['call_id'] = 'not_allowed_for_room_scope';
        }
    }

    if ($scope === 'call') {
        if ($callId === '') {
            $errors['call_id'] = 'required_for_call_scope';
        } elseif (strlen($callId) > 200 || preg_match('/^[A-Za-z0-9._-]+$/', $callId) !== 1) {
            $errors['call_id'] = 'invalid_call_id';
        }

        if ($roomId !== '') {
            $errors['room_id'] = 'not_allowed_for_call_scope';
        }
    }

    return [
        'ok' => $errors === [],
        'data' => [
            'scope' => $scope,
            'room_id' => $roomId,
            'call_id' => $callId,
        ],
        'errors' => $errors,
    ];
}

/**
 * @return array{id: string, name: string}|null
 */
function videochat_fetch_active_room_context(PDO $pdo, string $roomId): ?array
{
    $trimmedRoomId = trim($roomId);
    if ($trimmedRoomId === '') {
        return null;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, name
FROM rooms
WHERE id = :id
  AND status = 'active'
LIMIT 1
SQL
    );
    $statement->execute([':id' => $trimmedRoomId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
    ];
}

function videochat_is_sqlite_unique_constraint_error(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }

    $sqlState = '';
    $driverCode = 0;
    if (is_array($error->errorInfo ?? null)) {
        $sqlState = is_string($error->errorInfo[0] ?? null) ? (string) $error->errorInfo[0] : '';
        $driverCode = (int) ($error->errorInfo[1] ?? 0);
    }

    if ($sqlState === '23000' || $driverCode === 19) {
        return true;
    }

    return str_contains(strtolower($error->getMessage()), 'unique constraint');
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   invite_code: ?array<string, mixed>
 * }
 */
function videochat_create_invite_code(
    PDO $pdo,
    int $authUserId,
    string $authRole,
    array $payload,
    ?int $nowUnix = null
): array {
    $validation = videochat_validate_create_invite_code_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => is_array($validation['errors'] ?? null) ? $validation['errors'] : [],
            'invite_code' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'invite_code' => null,
        ];
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $scope = (string) ($data['scope'] ?? '');
    $roomId = null;
    $callId = null;
    $scopeContext = [];

    if ($scope === 'room') {
        $room = videochat_fetch_active_room_context($pdo, (string) ($data['room_id'] ?? ''));
        if ($room === null) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'errors' => ['room_id' => 'room_not_found_or_inactive'],
                'invite_code' => null,
            ];
        }

        $roomId = (string) $room['id'];
        $scopeContext = [
            'room' => [
                'id' => (string) $room['id'],
                'name' => (string) $room['name'],
            ],
        ];
    } elseif ($scope === 'call') {
        $call = videochat_fetch_call_for_update($pdo, (string) ($data['call_id'] ?? ''));
        if (!is_array($call)) {
            return [
                'ok' => false,
                'reason' => 'not_found',
                'errors' => ['call_id' => 'call_not_found'],
                'invite_code' => null,
            ];
        }

        if (!videochat_can_edit_call($authRole, $authUserId, (int) ($call['owner_user_id'] ?? 0))) {
            return [
                'ok' => false,
                'reason' => 'forbidden',
                'errors' => ['call_id' => 'not_allowed_for_call'],
                'invite_code' => null,
            ];
        }

        $callStatus = (string) ($call['status'] ?? 'scheduled');
        if (in_array($callStatus, ['cancelled', 'ended'], true)) {
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ['call_id' => 'call_not_invitable_from_status'],
                'invite_code' => null,
            ];
        }

        $callId = (string) ($call['id'] ?? '');
        $scopeContext = [
            'call' => [
                'id' => (string) ($call['id'] ?? ''),
                'room_id' => (string) ($call['room_id'] ?? ''),
                'title' => (string) ($call['title'] ?? ''),
                'status' => $callStatus,
            ],
        ];
    } else {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['scope' => 'must_be_room_or_call'],
            'invite_code' => null,
        ];
    }

    $ttlSeconds = videochat_invite_scope_ttl_seconds($scope);
    $effectiveNowUnix = is_int($nowUnix) && $nowUnix > 0 ? $nowUnix : time();
    $createdAt = gmdate('c', $effectiveNowUnix);
    $expiresAt = gmdate('c', $effectiveNowUnix + $ttlSeconds);
    $maxRedemptions = 1;

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO invite_codes(
    id,
    code,
    scope,
    room_id,
    call_id,
    issued_by_user_id,
    expires_at,
    redeemed_at,
    redeemed_by_user_id,
    max_redemptions,
    redemption_count,
    created_at
) VALUES(
    :id,
    :code,
    :scope,
    :room_id,
    :call_id,
    :issued_by_user_id,
    :expires_at,
    NULL,
    NULL,
    :max_redemptions,
    0,
    :created_at
)
SQL
    );

    $inviteId = '';
    $inviteCode = '';
    $created = false;
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $inviteId = videochat_generate_uuid_v4();
        $inviteCode = videochat_generate_uuid_v4();
        try {
            $insert->execute([
                ':id' => $inviteId,
                ':code' => $inviteCode,
                ':scope' => $scope,
                ':room_id' => $roomId,
                ':call_id' => $callId,
                ':issued_by_user_id' => $authUserId,
                ':expires_at' => $expiresAt,
                ':max_redemptions' => $maxRedemptions,
                ':created_at' => $createdAt,
            ]);
            $created = true;
            break;
        } catch (Throwable $error) {
            if (videochat_is_sqlite_unique_constraint_error($error)) {
                continue;
            }

            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'invite_code' => null,
            ];
        }
    }

    if (!$created) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['code' => 'could_not_allocate_unique_code'],
            'invite_code' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'created',
        'errors' => [],
        'invite_code' => [
            'id' => $inviteId,
            'code' => $inviteCode,
            'scope' => $scope,
            'room_id' => $roomId,
            'call_id' => $callId,
            'issued_by_user_id' => $authUserId,
            'expires_at' => $expiresAt,
            'expires_in_seconds' => $ttlSeconds,
            'max_redemptions' => $maxRedemptions,
            'redemption_count' => 0,
            'created_at' => $createdAt,
            'expiry_policy' => [
                'managed_by' => 'server_scope_ttl',
                'scope_ttl_seconds' => $ttlSeconds,
            ],
            'context' => $scopeContext,
        ],
    ];
}
