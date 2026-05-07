<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

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
function videochat_fetch_active_room_context(PDO $pdo, string $roomId, ?int $tenantId = null): ?array
{
    $trimmedRoomId = trim($roomId);
    if ($trimmedRoomId === '') {
        return null;
    }

    $tenantWhere = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'rooms', 'tenant_id')
        ? '  AND tenant_id = :tenant_id'
        : '';
    $statement = $pdo->prepare(
        <<<SQL
SELECT id, name
FROM rooms
WHERE id = :id
  AND status = 'active'
{$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $trimmedRoomId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $statement->execute($params);
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

function videochat_invite_code_preview(array $inviteCode): array
{
    $scope = (string) ($inviteCode['scope'] ?? '');
    $maxRedemptions = (int) ($inviteCode['max_redemptions'] ?? 0);
    $redemptionCount = (int) ($inviteCode['redemption_count'] ?? 0);
    $remainingRedemptions = max(0, $maxRedemptions - $redemptionCount);
    $ttlSeconds = (int) ($inviteCode['expires_in_seconds'] ?? 0);
    if ($ttlSeconds <= 0 && in_array($scope, ['room', 'call'], true)) {
        $ttlSeconds = videochat_invite_scope_ttl_seconds($scope);
    }

    $preview = [
        'id' => (string) ($inviteCode['id'] ?? ''),
        'scope' => $scope,
        'room_id' => $inviteCode['room_id'] ?? null,
        'call_id' => $inviteCode['call_id'] ?? null,
        'issued_by_user_id' => (int) ($inviteCode['issued_by_user_id'] ?? 0),
        'expires_at' => (string) ($inviteCode['expires_at'] ?? ''),
        'expires_in_seconds' => $ttlSeconds,
        'max_redemptions' => $maxRedemptions,
        'redemption_count' => $redemptionCount,
        'remaining_redemptions' => $remainingRedemptions,
        'redeemed_at' => $inviteCode['redeemed_at'] ?? null,
        'redeemed_by_user_id' => $inviteCode['redeemed_by_user_id'] ?? null,
        'created_at' => (string) ($inviteCode['created_at'] ?? ''),
        'secret_available' => false,
        'expiry_policy' => [
            'managed_by' => 'server_scope_ttl',
            'scope_ttl_seconds' => $ttlSeconds,
        ],
    ];

    if (is_array($inviteCode['context'] ?? null)) {
        $preview['context'] = $inviteCode['context'];
    }

    return $preview;
}

/**
 * @return array<string, mixed>
 */
function videochat_invite_code_copy_payload(array $inviteCode): array
{
    $code = strtolower((string) ($inviteCode['code'] ?? ''));

    return [
        'code' => $code,
        'copy_text' => $code,
        'redeem_api_path' => '/api/invite-codes/redeem',
        'redeem_payload' => ['code' => $code],
        'expires_at' => (string) ($inviteCode['expires_at'] ?? ''),
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   data: array{code: string},
 *   errors: array<string, string>
 * }
 */
function videochat_validate_redeem_invite_code_payload(array $payload): array
{
    $errors = [];
    $code = trim((string) ($payload['code'] ?? ''));

    if ($code === '') {
        $errors['code'] = 'required_code';
    } elseif (strlen($code) > 120) {
        $errors['code'] = 'code_too_long';
    } elseif (preg_match('/^[A-Fa-f0-9-]{36}$/', $code) !== 1) {
        $errors['code'] = 'invalid_code_format';
    }

    return [
        'ok' => $errors === [],
        'data' => ['code' => strtolower($code)],
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   id: string,
 *   code: string,
 *   scope: string,
 *   room_id: ?string,
 *   call_id: ?string,
 *   issued_by_user_id: int,
 *   expires_at: string,
 *   redeemed_at: ?string,
 *   redeemed_by_user_id: ?int,
 *   max_redemptions: int,
 *   redemption_count: int,
 *   created_at: string
 * }|null
 */
