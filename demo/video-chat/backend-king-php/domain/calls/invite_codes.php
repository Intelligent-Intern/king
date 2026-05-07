<?php

declare(strict_types=1);

require_once __DIR__ . '/invite_code_contract.php';

function videochat_create_invite_code(
    PDO $pdo,
    int $authUserId,
    string $authRole,
    array $payload,
    ?int $nowUnix = null,
    ?int $tenantId = null
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
        $room = videochat_fetch_active_room_context($pdo, (string) ($data['room_id'] ?? ''), $tenantId);
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
        $call = videochat_fetch_call_for_update($pdo, (string) ($data['call_id'] ?? ''), $tenantId);
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

    $tenantColumn = is_int($tenantId) && $tenantId > 0 && videochat_tenant_table_has_column($pdo, 'invite_codes', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
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
    created_at{$tenantColumn}
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
    :created_at{$tenantValue}
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
            $params = [
                ':id' => $inviteId,
                ':code' => $inviteCode,
                ':scope' => $scope,
                ':room_id' => $roomId,
                ':call_id' => $callId,
                ':issued_by_user_id' => $authUserId,
                ':expires_at' => $expiresAt,
                ':max_redemptions' => $maxRedemptions,
                ':created_at' => $createdAt,
            ];
            if ($tenantColumn !== '') {
                $params[':tenant_id'] = $tenantId;
            }
            $insert->execute($params);
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

/**
 * @return array<string, mixed>
 */

function videochat_fetch_invite_code_by_code(PDO $pdo, string $code, ?int $tenantId = null): ?array
{
    $trimmedCode = strtolower(trim($code));
    if ($trimmedCode === '') {
        return null;
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'invite_codes', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND tenant_id = :tenant_id' : '';
    $statement = $pdo->prepare(
        <<<SQL
SELECT
    id,
    {$tenantSelect}
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
FROM invite_codes
WHERE lower(code) = :code
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':code' => $trimmedCode];
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
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'code' => strtolower((string) ($row['code'] ?? '')),
        'scope' => (string) ($row['scope'] ?? ''),
        'room_id' => is_string($row['room_id'] ?? null) ? (string) $row['room_id'] : null,
        'call_id' => is_string($row['call_id'] ?? null) ? (string) $row['call_id'] : null,
        'issued_by_user_id' => (int) ($row['issued_by_user_id'] ?? 0),
        'expires_at' => (string) ($row['expires_at'] ?? ''),
        'redeemed_at' => is_string($row['redeemed_at'] ?? null) ? (string) $row['redeemed_at'] : null,
        'redeemed_by_user_id' => is_numeric($row['redeemed_by_user_id'] ?? null)
            ? (int) $row['redeemed_by_user_id']
            : null,
        'max_redemptions' => (int) ($row['max_redemptions'] ?? 0),
        'redemption_count' => (int) ($row['redemption_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
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
function videochat_fetch_invite_code_by_id(PDO $pdo, string $inviteId, ?int $tenantId = null): ?array
{
    $trimmedInviteId = strtolower(trim($inviteId));
    if ($trimmedInviteId === '') {
        return null;
    }

    $hasTenantColumn = videochat_tenant_table_has_column($pdo, 'invite_codes', 'tenant_id');
    $tenantSelect = $hasTenantColumn ? 'tenant_id,' : 'NULL AS tenant_id,';
    $tenantWhere = $hasTenantColumn && is_int($tenantId) && $tenantId > 0 ? 'AND tenant_id = :tenant_id' : '';
    $statement = $pdo->prepare(
        <<<SQL
SELECT
    id,
    {$tenantSelect}
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
FROM invite_codes
WHERE lower(id) = :id
  {$tenantWhere}
LIMIT 1
SQL
    );
    $params = [':id' => $trimmedInviteId];
    if ($tenantWhere !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $statement->execute($params);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => strtolower((string) ($row['id'] ?? '')),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
        'code' => strtolower((string) ($row['code'] ?? '')),
        'scope' => (string) ($row['scope'] ?? ''),
        'room_id' => is_string($row['room_id'] ?? null) ? (string) $row['room_id'] : null,
        'call_id' => is_string($row['call_id'] ?? null) ? (string) $row['call_id'] : null,
        'issued_by_user_id' => (int) ($row['issued_by_user_id'] ?? 0),
        'expires_at' => (string) ($row['expires_at'] ?? ''),
        'redeemed_at' => is_string($row['redeemed_at'] ?? null) ? (string) $row['redeemed_at'] : null,
        'redeemed_by_user_id' => is_numeric($row['redeemed_by_user_id'] ?? null)
            ? (int) $row['redeemed_by_user_id']
            : null,
        'max_redemptions' => (int) ($row['max_redemptions'] ?? 0),
        'redemption_count' => (int) ($row['redemption_count'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function videochat_can_copy_invite_code(PDO $pdo, array $inviteCode, int $authUserId, string $authRole, ?int $tenantId = null): bool
{
    if ($authUserId <= 0) {
        return false;
    }

    $normalizedRole = videochat_normalize_role_slug($authRole);
    if ($normalizedRole === 'admin') {
        return true;
    }

    if ((int) ($inviteCode['issued_by_user_id'] ?? 0) === $authUserId) {
        return true;
    }

    if ((string) ($inviteCode['scope'] ?? '') !== 'call') {
        return false;
    }

    $callId = (string) ($inviteCode['call_id'] ?? '');
    if ($callId === '') {
        return false;
    }

    $call = videochat_fetch_call_for_update($pdo, $callId, $tenantId);
    if (!is_array($call)) {
        return false;
    }

    return videochat_can_edit_call($authRole, $authUserId, (int) ($call['owner_user_id'] ?? 0));
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   invite_code: ?array<string, mixed>,
 *   copy: ?array<string, mixed>
 * }
 */
function videochat_prepare_invite_code_copy(
    PDO $pdo,
    string $inviteId,
    int $authUserId,
    string $authRole,
    ?int $nowUnix = null,
    ?int $tenantId = null
): array {
    $trimmedInviteId = strtolower(trim($inviteId));
    if ($trimmedInviteId === '') {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['invite_code' => 'invite_code_not_found'],
            'invite_code' => null,
            'copy' => null,
        ];
    }

    $invite = videochat_fetch_invite_code_by_id($pdo, $trimmedInviteId, $tenantId);
    if (!is_array($invite)) {
        return [
            'ok' => false,
            'reason' => 'not_found',
            'errors' => ['invite_code' => 'invite_code_not_found'],
            'invite_code' => null,
            'copy' => null,
        ];
    }

    if (!videochat_can_copy_invite_code($pdo, $invite, $authUserId, $authRole, $tenantId)) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['invite_code' => 'not_allowed_to_copy_invite_code'],
            'invite_code' => null,
            'copy' => null,
        ];
    }

    $effectiveNowUnix = is_int($nowUnix) && $nowUnix > 0 ? $nowUnix : time();
    $expiresAtUnix = strtotime((string) ($invite['expires_at'] ?? ''));
    if (!is_int($expiresAtUnix) || $expiresAtUnix <= $effectiveNowUnix) {
        return [
            'ok' => false,
            'reason' => 'expired',
            'errors' => ['invite_code' => 'invite_code_expired'],
            'invite_code' => null,
            'copy' => null,
        ];
    }

    return [
        'ok' => true,
        'reason' => 'copy_ready',
        'errors' => [],
        'invite_code' => videochat_invite_code_preview($invite),
        'copy' => videochat_invite_code_copy_payload($invite),
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   reason: string,
 *   errors: array<string, string>,
 *   redemption: ?array<string, mixed>
 * }
 */
function videochat_redeem_invite_code(
    PDO $pdo,
    int $authUserId,
    string $authRole,
    array $payload,
    ?int $nowUnix = null,
    ?int $tenantId = null
): array {
    $validation = videochat_validate_redeem_invite_code_payload($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => is_array($validation['errors'] ?? null) ? $validation['errors'] : [],
            'redemption' => null,
        ];
    }

    if ($authUserId <= 0) {
        return [
            'ok' => false,
            'reason' => 'forbidden',
            'errors' => ['auth' => 'invalid_user_context'],
            'redemption' => null,
        ];
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $code = (string) ($data['code'] ?? '');
    $effectiveNowUnix = is_int($nowUnix) && $nowUnix > 0 ? $nowUnix : time();
    $redeemedAt = gmdate('c', $effectiveNowUnix);
    $joinContext = [];

    try {
        $pdo->beginTransaction();

        $invite = videochat_fetch_invite_code_by_code($pdo, $code, $tenantId);
        if (!is_array($invite)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'not_found',
                'errors' => ['code' => 'invite_code_not_found'],
                'redemption' => null,
            ];
        }

        $expiresAtUnix = strtotime((string) ($invite['expires_at'] ?? ''));
        if (!is_int($expiresAtUnix) || $expiresAtUnix <= $effectiveNowUnix) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'expired',
                'errors' => ['code' => 'invite_code_expired'],
                'redemption' => null,
            ];
        }

        $maxRedemptions = (int) ($invite['max_redemptions'] ?? 0);
        $currentRedemptionCount = (int) ($invite['redemption_count'] ?? 0);
        if ($maxRedemptions <= 0 || $currentRedemptionCount >= $maxRedemptions) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'exhausted',
                'errors' => ['code' => 'invite_code_redemption_limit_reached'],
                'redemption' => null,
            ];
        }

        $scope = (string) ($invite['scope'] ?? '');
        if ($scope === 'room') {
            $room = videochat_fetch_active_room_context($pdo, (string) ($invite['room_id'] ?? ''), $tenantId);
            if ($room === null) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'reason' => 'not_found',
                    'errors' => ['room_id' => 'room_not_found_or_inactive'],
                    'redemption' => null,
                ];
            }

            $joinContext = [
                'scope' => 'room',
                'room' => [
                    'id' => (string) $room['id'],
                    'name' => (string) $room['name'],
                ],
                'call' => null,
            ];
        } elseif ($scope === 'call') {
            $call = videochat_fetch_call_for_update($pdo, (string) ($invite['call_id'] ?? ''), $tenantId);
            if ($call === null) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'reason' => 'not_found',
                    'errors' => ['call_id' => 'call_not_found'],
                    'redemption' => null,
                ];
            }

            $callStatus = (string) ($call['status'] ?? 'scheduled');
            if (in_array($callStatus, ['cancelled', 'ended'], true)) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'reason' => 'conflict',
                    'errors' => ['call_id' => 'call_not_joinable_from_status'],
                    'redemption' => null,
                ];
            }

            $room = videochat_fetch_active_room_context($pdo, (string) ($call['room_id'] ?? ''), $tenantId);
            if ($room === null) {
                $pdo->rollBack();
                return [
                    'ok' => false,
                    'reason' => 'not_found',
                    'errors' => ['room_id' => 'room_not_found_or_inactive'],
                    'redemption' => null,
                ];
            }

            $joinContext = [
                'scope' => 'call',
                'room' => [
                    'id' => (string) $room['id'],
                    'name' => (string) $room['name'],
                ],
                'call' => [
                    'id' => (string) ($call['id'] ?? ''),
                    'room_id' => (string) ($call['room_id'] ?? ''),
                    'title' => (string) ($call['title'] ?? ''),
                    'status' => $callStatus,
                    'starts_at' => (string) ($call['starts_at'] ?? ''),
                    'ends_at' => (string) ($call['ends_at'] ?? ''),
                ],
            ];
        } else {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'validation_failed',
                'errors' => ['scope' => 'invalid_invite_scope'],
                'redemption' => null,
            ];
        }

        $update = $pdo->prepare(
            <<<'SQL'
UPDATE invite_codes
SET redemption_count = redemption_count + 1,
    redeemed_at = CASE
        WHEN redeemed_at IS NULL OR redeemed_at = '' THEN :redeemed_at
        ELSE redeemed_at
    END,
    redeemed_by_user_id = CASE
        WHEN redeemed_by_user_id IS NULL THEN :redeemed_by_user_id
        ELSE redeemed_by_user_id
    END
WHERE id = :id
  AND redemption_count < max_redemptions
SQL
        );
        $update->execute([
            ':id' => (string) ($invite['id'] ?? ''),
            ':redeemed_at' => $redeemedAt,
            ':redeemed_by_user_id' => $authUserId,
        ]);

        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'exhausted',
                'errors' => ['code' => 'invite_code_redemption_limit_reached'],
                'redemption' => null,
            ];
        }

        $updatedInvite = videochat_fetch_invite_code_by_code($pdo, $code);
        if (!is_array($updatedInvite)) {
            $pdo->rollBack();
            return [
                'ok' => false,
                'reason' => 'internal_error',
                'errors' => [],
                'redemption' => null,
            ];
        }

        $pdo->commit();

        $remaining = max(0, (int) ($updatedInvite['max_redemptions'] ?? 0) - (int) ($updatedInvite['redemption_count'] ?? 0));
        return [
            'ok' => true,
            'reason' => 'redeemed',
            'errors' => [],
            'redemption' => [
                'invite_code' => [
                    'id' => (string) ($updatedInvite['id'] ?? ''),
                    'code' => (string) ($updatedInvite['code'] ?? ''),
                    'scope' => (string) ($updatedInvite['scope'] ?? ''),
                    'room_id' => $updatedInvite['room_id'] ?? null,
                    'call_id' => $updatedInvite['call_id'] ?? null,
                    'issued_by_user_id' => (int) ($updatedInvite['issued_by_user_id'] ?? 0),
                    'expires_at' => (string) ($updatedInvite['expires_at'] ?? ''),
                    'max_redemptions' => (int) ($updatedInvite['max_redemptions'] ?? 0),
                    'redemption_count' => (int) ($updatedInvite['redemption_count'] ?? 0),
                    'remaining_redemptions' => $remaining,
                    'redeemed_at' => $updatedInvite['redeemed_at'] ?? null,
                    'redeemed_by_user_id' => $updatedInvite['redeemed_by_user_id'] ?? null,
                    'created_at' => (string) ($updatedInvite['created_at'] ?? ''),
                ],
                'join_context' => [
                    ...$joinContext,
                    'request_user' => [
                        'user_id' => $authUserId,
                        'role' => videochat_normalize_role_slug($authRole),
                    ],
                ],
                'redeemed_at' => $redeemedAt,
            ],
        ];
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'internal_error',
            'errors' => [],
            'redemption' => null,
        ];
    }
}
