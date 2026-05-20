<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

const VIDEOCHAT_PERMANENT_CALL_DEFAULT_IDS = [
    '39c5b3ea-855b-40fd-b030-c8af1d512605',
];
const VIDEOCHAT_PERMANENT_CALL_GUARD_ENDS_AT = '2099-12-31T23:59:59+00:00';

function videochat_permanent_call_normalize_id(mixed $callId): string
{
    return strtolower(trim((string) $callId));
}

/**
 * @return array<int, string>
 */
function videochat_permanent_call_ids(): array
{
    $ids = VIDEOCHAT_PERMANENT_CALL_DEFAULT_IDS;
    $envValue = getenv('VIDEOCHAT_PERMANENT_CALL_IDS');
    if (is_string($envValue) && trim($envValue) !== '') {
        foreach (preg_split('/[\s,;]+/', $envValue) ?: [] as $candidate) {
            $ids[] = (string) $candidate;
        }
    }

    $normalized = [];
    foreach ($ids as $id) {
        $callId = videochat_permanent_call_normalize_id($id);
        if ($callId !== '') {
            $normalized[$callId] = $callId;
        }
    }

    return array_values($normalized);
}

function videochat_is_permanent_call(string $callId): bool
{
    $normalizedCallId = videochat_permanent_call_normalize_id($callId);
    return $normalizedCallId !== '' && in_array($normalizedCallId, videochat_permanent_call_ids(), true);
}

function videochat_permanent_call_guard_ends_at(): string
{
    return VIDEOCHAT_PERMANENT_CALL_GUARD_ENDS_AT;
}

function videochat_permanent_call_table_available(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
    } catch (Throwable) {
        return false;
    }

    return true;
}

function videochat_permanent_call_table_has_column(PDO $pdo, string $table, string $column): bool
{
    return function_exists('videochat_tenant_table_has_column')
        && videochat_tenant_table_has_column($pdo, $table, $column);
}

function videochat_permanent_call_primary_admin(PDO $pdo): ?array
{
    try {
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT users.id, users.email, users.display_name
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = 1
  AND roles.slug = 'admin'
  AND users.status = 'active'
LIMIT 1
SQL
        );
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable) {
        return null;
    }

    if (!is_array($row) || (int) ($row['id'] ?? 0) !== 1) {
        return null;
    }

    return [
        'id' => 1,
        'email' => strtolower(trim((string) ($row['email'] ?? ''))),
        'display_name' => trim((string) ($row['display_name'] ?? 'Admin')),
    ];
}

function videochat_permanent_call_fetch_basic(PDO $pdo, string $callId): ?array
{
    if (!videochat_permanent_call_table_available($pdo, 'calls')) {
        return null;
    }

    $select = [
        'id',
        'room_id',
        'owner_user_id',
        'status',
        'starts_at',
        'ends_at',
    ];
    foreach (['tenant_id', 'cancelled_at', 'cancel_reason', 'cancel_message'] as $column) {
        if (videochat_permanent_call_table_has_column($pdo, 'calls', $column)) {
            $select[] = $column;
        }
    }

    $statement = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM calls WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $callId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function videochat_permanent_call_restore_if_missing(PDO $pdo, string $callId, string $now): bool
{
    if (!videochat_is_permanent_call($callId) || videochat_permanent_call_fetch_basic($pdo, $callId) !== null) {
        return false;
    }

    $owner = videochat_permanent_call_primary_admin($pdo);
    if (!is_array($owner) || (string) ($owner['email'] ?? '') === '') {
        return false;
    }

    $tenantId = videochat_tenant_default_id($pdo);
    $roomColumns = ['id', 'name', 'visibility', 'status', 'created_by_user_id', 'created_at', 'updated_at'];
    $roomValues = [':id', ':name', "'private'", "'active'", ':created_by_user_id', ':created_at', ':updated_at'];
    $roomParams = [
        ':id' => $callId,
        ':name' => 'Permanent KingRT Video Call',
        ':created_by_user_id' => 1,
        ':created_at' => $now,
        ':updated_at' => $now,
    ];
    if ($tenantId > 0 && videochat_permanent_call_table_has_column($pdo, 'rooms', 'tenant_id')) {
        $roomColumns[] = 'tenant_id';
        $roomValues[] = ':tenant_id';
        $roomParams[':tenant_id'] = $tenantId;
    }

    $callColumns = [
        'id',
        'room_id',
        'title',
        'owner_user_id',
        'status',
        'starts_at',
        'ends_at',
        'created_at',
        'updated_at',
    ];
    $callValues = [
        ':id',
        ':room_id',
        ':title',
        ':owner_user_id',
        "'active'",
        ':starts_at',
        ':ends_at',
        ':created_at',
        ':updated_at',
    ];
    $callParams = [
        ':id' => $callId,
        ':room_id' => $callId,
        ':title' => 'Permanent KingRT Video Call',
        ':owner_user_id' => 1,
        ':starts_at' => $now,
        ':ends_at' => videochat_permanent_call_guard_ends_at(),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];
    if (videochat_permanent_call_table_has_column($pdo, 'calls', 'access_mode')) {
        $callColumns[] = 'access_mode';
        $callValues[] = "'invite_only'";
    }
    if (videochat_permanent_call_table_has_column($pdo, 'calls', 'schedule_timezone')) {
        $callColumns[] = 'schedule_timezone';
        $callValues[] = "'UTC'";
    }
    if (videochat_permanent_call_table_has_column($pdo, 'calls', 'schedule_date')) {
        $callColumns[] = 'schedule_date';
        $callValues[] = ':schedule_date';
        $callParams[':schedule_date'] = gmdate('Y-m-d');
    }
    if (videochat_permanent_call_table_has_column($pdo, 'calls', 'schedule_duration_minutes')) {
        $callColumns[] = 'schedule_duration_minutes';
        $callValues[] = '0';
    }
    if (videochat_permanent_call_table_has_column($pdo, 'calls', 'schedule_all_day')) {
        $callColumns[] = 'schedule_all_day';
        $callValues[] = '0';
    }
    if ($tenantId > 0 && videochat_permanent_call_table_has_column($pdo, 'calls', 'tenant_id')) {
        $callColumns[] = 'tenant_id';
        $callValues[] = ':tenant_id';
        $callParams[':tenant_id'] = $tenantId;
    }

    try {
        $insertRoom = $pdo->prepare(
            'INSERT OR IGNORE INTO rooms(' . implode(', ', $roomColumns) . ') VALUES(' . implode(', ', $roomValues) . ')'
        );
        $insertRoom->execute($roomParams);

        $insertCall = $pdo->prepare(
            'INSERT OR IGNORE INTO calls(' . implode(', ', $callColumns) . ') VALUES(' . implode(', ', $callValues) . ')'
        );
        $insertCall->execute($callParams);

        if (videochat_permanent_call_table_available($pdo, 'call_participants')) {
            $insertParticipant = $pdo->prepare(
                <<<'SQL'
INSERT OR IGNORE INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'owner', 'allowed', NULL, NULL)
SQL
            );
            $insertParticipant->execute([
                ':call_id' => $callId,
                ':user_id' => 1,
                ':email' => (string) $owner['email'],
                ':display_name' => (string) (($owner['display_name'] ?? '') ?: 'Admin'),
            ]);
        }
    } catch (Throwable) {
        return false;
    }

    return videochat_permanent_call_fetch_basic($pdo, $callId) !== null;
}

function videochat_permanent_call_row_needs_repair(array $call, string $now): bool
{
    if (strtolower(trim((string) ($call['status'] ?? ''))) !== 'active') {
        return true;
    }
    foreach (['cancelled_at', 'cancel_reason', 'cancel_message'] as $column) {
        if (trim((string) ($call[$column] ?? '')) !== '') {
            return true;
        }
    }

    $endsAt = trim((string) ($call['ends_at'] ?? ''));
    $endsAtUnix = $endsAt === '' ? false : strtotime($endsAt);
    $guardUnix = strtotime(videochat_permanent_call_guard_ends_at());
    $nowUnix = strtotime($now);
    if ($endsAtUnix === false || $guardUnix === false || $nowUnix === false) {
        return true;
    }

    return $endsAtUnix < $guardUnix || $endsAtUnix <= $nowUnix;
}

function videochat_permanent_call_repair_owner_participant(PDO $pdo, string $callId, int $ownerUserId): int
{
    if ($callId === '' || $ownerUserId <= 0 || !videochat_permanent_call_table_available($pdo, 'call_participants')) {
        return 0;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed',
    left_at = NULL
WHERE call_id = :call_id
  AND source = 'internal'
  AND (
      user_id = :owner_user_id
      OR call_role = 'owner'
  )
  AND (
      invite_state <> 'allowed'
      OR left_at IS NOT NULL
  )
SQL
    );
    $statement->execute([
        ':call_id' => $callId,
        ':owner_user_id' => $ownerUserId,
    ]);

    return max(0, $statement->rowCount());
}

function videochat_permanent_call_extend_join_artifacts(PDO $pdo, string $callId): array
{
    $extendedLinks = 0;
    $extendedInviteCodes = 0;
    $guardEndsAt = videochat_permanent_call_guard_ends_at();

    if (
        videochat_permanent_call_table_available($pdo, 'call_access_links')
        && videochat_permanent_call_table_has_column($pdo, 'call_access_links', 'expires_at')
    ) {
        $updateLinks = $pdo->prepare(
            <<<'SQL'
UPDATE call_access_links
SET expires_at = :expires_at
WHERE call_id = :call_id
  AND (
      expires_at IS NULL
      OR trim(expires_at) = ''
      OR datetime(expires_at) < datetime(:expires_at)
  )
SQL
        );
        $updateLinks->execute([
            ':expires_at' => $guardEndsAt,
            ':call_id' => $callId,
        ]);
        $extendedLinks = max(0, $updateLinks->rowCount());
    }

    if (videochat_permanent_call_table_available($pdo, 'invite_codes')) {
        $updateInvites = $pdo->prepare(
            <<<'SQL'
UPDATE invite_codes
SET expires_at = :expires_at
WHERE scope = 'call'
  AND call_id = :call_id
  AND datetime(expires_at) < datetime(:expires_at)
SQL
        );
        $updateInvites->execute([
            ':expires_at' => $guardEndsAt,
            ':call_id' => $callId,
        ]);
        $extendedInviteCodes = max(0, $updateInvites->rowCount());
    }

    return [
        'extended_access_links' => $extendedLinks,
        'extended_invite_codes' => $extendedInviteCodes,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   permanent: bool,
 *   reason: string,
 *   changed: bool,
 *   restored: bool,
 *   call_id: string,
 *   owner_rows: int,
 *   extended_access_links: int,
 *   extended_invite_codes: int
 * }
 */
function videochat_permanent_call_ensure_active(PDO $pdo, string $callId, string $reason = 'permanent_call_guard'): array
{
    $normalizedCallId = videochat_permanent_call_normalize_id($callId);
    if (!videochat_is_permanent_call($normalizedCallId)) {
        return [
            'ok' => true,
            'permanent' => false,
            'reason' => 'not_permanent',
            'changed' => false,
            'restored' => false,
            'call_id' => $normalizedCallId,
            'owner_rows' => 0,
            'extended_access_links' => 0,
            'extended_invite_codes' => 0,
        ];
    }

    $now = gmdate('c');
    $restored = videochat_permanent_call_restore_if_missing($pdo, $normalizedCallId, $now);
    $call = videochat_permanent_call_fetch_basic($pdo, $normalizedCallId);
    if (!is_array($call)) {
        return [
            'ok' => false,
            'permanent' => true,
            'reason' => 'missing_permanent_call',
            'changed' => false,
            'restored' => $restored,
            'call_id' => $normalizedCallId,
            'owner_rows' => 0,
            'extended_access_links' => 0,
            'extended_invite_codes' => 0,
        ];
    }

    $changed = $restored;
    if (videochat_permanent_call_row_needs_repair($call, $now)) {
        $sets = [
            "status = 'active'",
            'ends_at = :ends_at',
            'updated_at = :updated_at',
        ];
        foreach (['cancelled_at', 'cancel_reason', 'cancel_message'] as $column) {
            if (videochat_permanent_call_table_has_column($pdo, 'calls', $column)) {
                $sets[] = "{$column} = NULL";
            }
        }

        $updateCall = $pdo->prepare(
            'UPDATE calls SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $updateCall->execute([
            ':ends_at' => videochat_permanent_call_guard_ends_at(),
            ':updated_at' => $now,
            ':id' => $normalizedCallId,
        ]);
        $changed = true;
    }

    $ownerRows = videochat_permanent_call_repair_owner_participant(
        $pdo,
        $normalizedCallId,
        (int) ($call['owner_user_id'] ?? 0)
    );
    $joinArtifacts = videochat_permanent_call_extend_join_artifacts($pdo, $normalizedCallId);
    if ($ownerRows > 0 || (int) ($joinArtifacts['extended_access_links'] ?? 0) > 0 || (int) ($joinArtifacts['extended_invite_codes'] ?? 0) > 0) {
        $changed = true;
    }

    return [
        'ok' => true,
        'permanent' => true,
        'reason' => $reason,
        'changed' => $changed,
        'restored' => $restored,
        'call_id' => $normalizedCallId,
        'owner_rows' => $ownerRows,
        'extended_access_links' => (int) ($joinArtifacts['extended_access_links'] ?? 0),
        'extended_invite_codes' => (int) ($joinArtifacts['extended_invite_codes'] ?? 0),
    ];
}

/**
 * @return array<int, string>
 */
function videochat_permanent_call_sql_placeholders(array &$params, string $prefix = ':permanent_call_'): array
{
    $placeholders = [];
    foreach (videochat_permanent_call_ids() as $index => $callId) {
        $placeholder = $prefix . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $callId;
    }

    return $placeholders;
}
