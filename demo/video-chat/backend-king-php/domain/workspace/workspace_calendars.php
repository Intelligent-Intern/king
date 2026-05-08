<?php

declare(strict_types=1);

require_once __DIR__ . '/../calls/call_access_contract.php';
require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_workspace_calendar_now(): string
{
    return gmdate('c');
}

function videochat_workspace_calendar_id(): string
{
    return strtolower(videochat_generate_call_access_uuid());
}

function videochat_workspace_calendar_tenant_id(PDO $pdo, int $tenantId): int
{
    if ($tenantId > 0) {
        return $tenantId;
    }

    return videochat_tenant_default_id($pdo);
}

function videochat_workspace_calendar_normalize_id(string $id): string
{
    $normalized = strtolower(trim($id));
    return preg_match('/^[a-f0-9-]{36}$/', $normalized) === 1 ? $normalized : '';
}

function videochat_workspace_calendar_text(mixed $value, int $maxLength): string
{
    $text = trim((string) $value);
    if (strlen($text) > $maxLength) {
        return substr($text, 0, $maxLength);
    }

    return $text;
}

function videochat_workspace_calendar_max_count(): int
{
    return 5;
}

/** @return array<int, string> */
function videochat_workspace_calendar_allowed_colors(): array
{
    return [
        '#000010',
        '#00052D',
        '#1582BF',
        '#59C7F2',
        '#EFEFE7',
        '#FFFFFF',
        '#03275A',
        '#00652F',
        '#F47221',
        '#EF4423',
    ];
}

function videochat_workspace_calendar_color(mixed $value): string
{
    $candidate = strtoupper(trim((string) $value));
    if ($candidate === '') {
        return '#1582BF';
    }
    if (preg_match('/^#[0-9A-F]{6}$/', $candidate) !== 1) {
        return '#1582BF';
    }

    return in_array($candidate, videochat_workspace_calendar_allowed_colors(), true) ? $candidate : '#1582BF';
}

function videochat_workspace_calendar_member_access_role(mixed $value): string
{
    $role = strtolower(trim((string) $value));
    return in_array($role, ['editor', 'viewer'], true) ? $role : 'viewer';
}

/** @return array<int, int> */
function videochat_workspace_calendar_member_ids_from_payload(array $payload, int $ownerUserId): array
{
    $source = $payload['member_user_ids'] ?? ($payload['members'] ?? []);
    if (!is_array($source)) {
        return [];
    }

    $ids = [];
    foreach ($source as $item) {
        $candidate = is_array($item) ? ($item['id'] ?? $item['user_id'] ?? 0) : $item;
        $id = filter_var($candidate, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id <= 0 || $id === $ownerUserId || in_array($id, $ids, true)) {
            continue;
        }
        $ids[] = $id;
    }

    sort($ids);
    return $ids;
}

/** @return array<int, string> */
function videochat_workspace_calendar_members_from_payload(array $payload, int $ownerUserId): array
{
    $source = $payload['members'] ?? ($payload['member_user_ids'] ?? []);
    if (!is_array($source)) {
        return [];
    }

    $members = [];
    foreach ($source as $item) {
        $candidate = is_array($item) ? ($item['user_id'] ?? $item['id'] ?? 0) : $item;
        $id = filter_var($candidate, FILTER_VALIDATE_INT);
        if (!is_int($id) || $id <= 0 || $id === $ownerUserId) {
            continue;
        }
        $members[$id] = is_array($item)
            ? videochat_workspace_calendar_member_access_role($item['access_role'] ?? $item['role'] ?? 'viewer')
            : 'viewer';
    }

    ksort($members);
    return $members;
}

/** @return array<int, string> */
function videochat_workspace_calendar_sync_ids_from_value(mixed $value, string $ownCalendarId = ''): array
{
    $source = $value;
    if (is_string($source)) {
        $decoded = json_decode($source, true);
        $source = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($source)) {
        return [];
    }

    $ids = [];
    foreach ($source as $item) {
        $id = videochat_workspace_calendar_normalize_id(is_array($item) ? (string) ($item['id'] ?? '') : (string) $item);
        if ($id === '' || $id === $ownCalendarId || in_array($id, $ids, true)) {
            continue;
        }
        $ids[] = $id;
    }
    sort($ids);
    return $ids;
}

/** @return array<int, string> */
function videochat_workspace_calendar_sync_ids_from_payload(array $payload, string $ownCalendarId = ''): array
{
    return videochat_workspace_calendar_sync_ids_from_value($payload['sync_calendar_ids'] ?? ($payload['sync_calendars'] ?? []), $ownCalendarId);
}

/** @return array<int, int> */
function videochat_workspace_calendar_active_user_ids(PDO $pdo, int $tenantId, array $userIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $tenantJoin = '';
    $tenantWhere = '';
    $params = $ids;
    if ($tenantId > 0 && videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id')) {
        $tenantJoin = 'INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id';
        $tenantWhere = " AND tenant_memberships.tenant_id = ? AND tenant_memberships.status = 'active'";
        $params[] = $tenantId;
    }

    $query = $pdo->prepare(
        <<<SQL
SELECT DISTINCT users.id
FROM users
{$tenantJoin}
WHERE users.status = 'active'
  AND users.id IN ({$placeholders})
  {$tenantWhere}
SQL
    );
    $query->execute($params);

    $active = [];
    foreach ($query->fetchAll() ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $active[] = $id;
        }
    }

    sort($active);
    return $active;
}

function videochat_workspace_calendar_owned_count(PDO $pdo, int $tenantId, int $ownerUserId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM workspace_calendars
WHERE tenant_id = :tenant_id
  AND owner_user_id = :owner_user_id
  AND status = 'active'
SQL
    );
    $query->execute([
        ':tenant_id' => $tenantId,
        ':owner_user_id' => $ownerUserId,
    ]);

    return (int) $query->fetchColumn();
}

/** @return array<int, string> */
function videochat_workspace_calendar_visible_sync_ids(PDO $pdo, int $userId, int $tenantId, array $syncCalendarIds): array
{
    $visible = [];
    foreach ($syncCalendarIds as $calendarId) {
        $normalized = videochat_workspace_calendar_normalize_id((string) $calendarId);
        if ($normalized === '') {
            continue;
        }
        if (videochat_workspace_calendar_fetch_visible($pdo, $normalized, $userId, $tenantId) !== null) {
            $visible[] = $normalized;
        }
    }
    sort($visible);
    return $visible;
}

function videochat_workspace_calendar_get_or_create_personal(PDO $pdo, int $userId, int $tenantId): ?array
{
    if ($userId <= 0 || $tenantId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM workspace_calendars
WHERE tenant_id = :tenant_id
  AND owner_user_id = :owner_user_id
  AND calendar_type = 'personal'
  AND status = 'active'
LIMIT 1
SQL
    );
    $query->execute([
        ':tenant_id' => $tenantId,
        ':owner_user_id' => $userId,
    ]);
    $existing = $query->fetch();
    if (is_array($existing)) {
        videochat_workspace_calendar_sync_members($pdo, (string) $existing['id'], $tenantId, $userId, [], false);
        return $existing;
    }

    $now = videochat_workspace_calendar_now();
    $calendarId = videochat_workspace_calendar_id();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_calendars(id, tenant_id, owner_user_id, name, description, calendar_type, status, created_at, updated_at)
VALUES(:id, :tenant_id, :owner_user_id, :name, '', 'personal', 'active', :created_at, :updated_at)
SQL
    );
    try {
        $insert->execute([
            ':id' => $calendarId,
            ':tenant_id' => $tenantId,
            ':owner_user_id' => $userId,
            ':name' => 'Own calendar',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        videochat_workspace_calendar_sync_members($pdo, $calendarId, $tenantId, $userId, [], false);
    } catch (Throwable $error) {
        if (!str_contains(strtolower($error->getMessage()), 'unique')) {
            throw $error;
        }
    }

    $query->execute([
        ':tenant_id' => $tenantId,
        ':owner_user_id' => $userId,
    ]);
    $created = $query->fetch();
    if (is_array($created)) {
        videochat_workspace_calendar_sync_members($pdo, (string) $created['id'], $tenantId, $userId, [], false);
    }
    return is_array($created) ? $created : null;
}

function videochat_workspace_calendar_sync_members(
    PDO $pdo,
    string $calendarId,
    int $tenantId,
    int $ownerUserId,
    array $memberUserRoles,
    bool $replaceMembers = true
): void {
    $now = videochat_workspace_calendar_now();
    $ownerInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_calendar_members(calendar_id, tenant_id, user_id, access_role, status, created_at, updated_at)
VALUES(:calendar_id, :tenant_id, :user_id, 'owner', 'active', :created_at, :updated_at)
ON CONFLICT(calendar_id, user_id) DO UPDATE SET
    access_role = 'owner',
    status = 'active',
    updated_at = excluded.updated_at
SQL
    );
    $ownerInsert->execute([
        ':calendar_id' => $calendarId,
        ':tenant_id' => $tenantId,
        ':user_id' => $ownerUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ($replaceMembers) {
        $delete = $pdo->prepare('DELETE FROM workspace_calendar_members WHERE calendar_id = :calendar_id AND user_id <> :owner_user_id');
        $delete->execute([
            ':calendar_id' => $calendarId,
            ':owner_user_id' => $ownerUserId,
        ]);
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO workspace_calendar_members(calendar_id, tenant_id, user_id, access_role, status, created_at, updated_at)
VALUES(:calendar_id, :tenant_id, :user_id, :access_role, 'active', :created_at, :updated_at)
ON CONFLICT(calendar_id, user_id) DO UPDATE SET
    access_role = excluded.access_role,
    status = 'active',
    updated_at = excluded.updated_at
SQL
    );
    foreach ($memberUserRoles as $memberUserId => $accessRole) {
        $normalizedMemberId = is_int($memberUserId) && $memberUserId > 0 ? $memberUserId : (int) $accessRole;
        $normalizedAccessRole = is_int($memberUserId) && $memberUserId > 0
            ? videochat_workspace_calendar_member_access_role($accessRole)
            : 'viewer';
        if ($normalizedMemberId <= 0 || $normalizedMemberId === $ownerUserId) {
            continue;
        }
        $insert->execute([
            ':calendar_id' => $calendarId,
            ':tenant_id' => $tenantId,
            ':user_id' => $normalizedMemberId,
            ':access_role' => $normalizedAccessRole,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
}

/** @return array<string, array<int, array<string, mixed>>> */
function videochat_workspace_calendar_members_by_calendar(PDO $pdo, array $calendarIds): array
{
    $ids = array_values(array_unique(array_filter(array_map(
        static fn ($id): string => videochat_workspace_calendar_normalize_id((string) $id),
        $calendarIds
    ), static fn (string $id): bool => $id !== '')));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $pdo->prepare(
        <<<SQL
SELECT
    workspace_calendar_members.calendar_id,
    workspace_calendar_members.user_id,
    workspace_calendar_members.access_role,
    users.email,
    users.display_name
FROM workspace_calendar_members
INNER JOIN users ON users.id = workspace_calendar_members.user_id
WHERE workspace_calendar_members.status = 'active'
  AND workspace_calendar_members.calendar_id IN ({$placeholders})
ORDER BY
    CASE workspace_calendar_members.access_role WHEN 'owner' THEN 0 WHEN 'editor' THEN 1 ELSE 2 END ASC,
    lower(users.display_name) ASC,
    users.id ASC
SQL
    );
    $query->execute($ids);

    $members = array_fill_keys($ids, []);
    foreach ($query->fetchAll() ?: [] as $row) {
        $calendarId = (string) ($row['calendar_id'] ?? '');
        if (!isset($members[$calendarId])) {
            continue;
        }
        $members[$calendarId][] = [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'access_role' => (string) ($row['access_role'] ?? 'viewer'),
        ];
    }

    return $members;
}

/** @return array<string, mixed> */
function videochat_workspace_calendar_row(array $row, array $members): array
{
    $calendarId = (string) ($row['id'] ?? '');
    $calendarMembers = $members[$calendarId] ?? [];
    return [
        'id' => $calendarId,
        'tenant_id' => (int) ($row['tenant_id'] ?? 0),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'owner_name' => (string) ($row['owner_name'] ?? ''),
        'owner_email' => (string) ($row['owner_email'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'color' => videochat_workspace_calendar_color($row['color'] ?? '#1582BF'),
        'sync_calendar_ids' => videochat_workspace_calendar_sync_ids_from_value($row['sync_calendar_ids'] ?? []),
        'calendar_type' => (string) ($row['calendar_type'] ?? 'shared'),
        'is_personal' => (string) ($row['calendar_type'] ?? '') === 'personal',
        'access_role' => (string) ($row['access_role'] ?? 'viewer'),
        'members' => $calendarMembers,
        'member_count' => count($calendarMembers),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/** @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, page_count: int} */
function videochat_workspace_calendar_list(PDO $pdo, int $userId, int $tenantId, string $query, int $page, int $pageSize): array
{
    $effectiveTenantId = videochat_workspace_calendar_tenant_id($pdo, $tenantId);
    videochat_workspace_calendar_get_or_create_personal($pdo, $userId, $effectiveTenantId);

    $effectivePage = max(1, $page);
    $effectivePageSize = max(1, min(100, $pageSize));
    $offset = ($effectivePage - 1) * $effectivePageSize;
    $search = videochat_workspace_calendar_text($query, 120);
    $searchSql = $search === '' ? '' : " AND (lower(c.name) LIKE :search OR lower(c.description) LIKE :search)";
    $params = [
        ':tenant_id' => $effectiveTenantId,
        ':user_id' => $userId,
    ];
    if ($search !== '') {
        $params[':search'] = '%' . strtolower($search) . '%';
    }

    $visibilitySql = <<<'SQL'
c.tenant_id = :tenant_id
AND c.status = 'active'
AND (
    c.owner_user_id = :user_id
    OR EXISTS (
        SELECT 1
        FROM workspace_calendar_members visible_members
        WHERE visible_members.calendar_id = c.id
          AND visible_members.user_id = :user_id
          AND visible_members.status = 'active'
    )
)
SQL;
    $count = $pdo->prepare("SELECT COUNT(*) FROM workspace_calendars c WHERE {$visibilitySql}{$searchSql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pageCount = $total === 0 ? 0 : (int) ceil($total / $effectivePageSize);

    $list = $pdo->prepare(
        <<<SQL
SELECT
    c.*,
    owners.display_name AS owner_name,
    owners.email AS owner_email,
    CASE
      WHEN c.owner_user_id = :user_id THEN 'owner'
      ELSE COALESCE((
          SELECT access_role
          FROM workspace_calendar_members role_members
          WHERE role_members.calendar_id = c.id
            AND role_members.user_id = :user_id
            AND role_members.status = 'active'
          LIMIT 1
      ), 'viewer')
    END AS access_role
FROM workspace_calendars c
INNER JOIN users owners ON owners.id = c.owner_user_id
WHERE {$visibilitySql}{$searchSql}
ORDER BY
    CASE c.calendar_type WHEN 'personal' THEN 0 ELSE 1 END ASC,
    lower(c.name) ASC,
    c.updated_at DESC
LIMIT :limit OFFSET :offset
SQL
    );
    foreach ($params as $name => $value) {
        $list->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $list->bindValue(':limit', $effectivePageSize, PDO::PARAM_INT);
    $list->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list->execute();

    $rawRows = array_values(array_filter($list->fetchAll() ?: [], 'is_array'));
    $members = videochat_workspace_calendar_members_by_calendar($pdo, array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $rawRows));
    return [
        'rows' => array_map(static fn (array $row): array => videochat_workspace_calendar_row($row, $members), $rawRows),
        'total' => $total,
        'page' => $effectivePage,
        'page_size' => $effectivePageSize,
        'page_count' => $pageCount,
    ];
}

function videochat_workspace_calendar_fetch_visible(PDO $pdo, string $calendarId, int $userId, int $tenantId): ?array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT c.*, owners.display_name AS owner_name, owners.email AS owner_email
FROM workspace_calendars c
INNER JOIN users owners ON owners.id = c.owner_user_id
WHERE c.id = :id
  AND c.tenant_id = :tenant_id
  AND c.status = 'active'
  AND (
    c.owner_user_id = :user_id
    OR EXISTS (
      SELECT 1 FROM workspace_calendar_members m
      WHERE m.calendar_id = c.id AND m.user_id = :user_id AND m.status = 'active'
    )
  )
LIMIT 1
SQL
    );
    $query->execute([
        ':id' => $calendarId,
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch();
    return is_array($row) ? $row : null;
}

/** @return array{ok: bool, errors?: array<string, string>, calendar?: array<string, mixed>} */
function videochat_workspace_calendar_save(PDO $pdo, int $userId, int $tenantId, array $payload, ?string $calendarId = null): array
{
    $effectiveTenantId = videochat_workspace_calendar_tenant_id($pdo, $tenantId);
    $name = videochat_workspace_calendar_text($payload['name'] ?? '', 120);
    $description = videochat_workspace_calendar_text($payload['description'] ?? '', 600);
    $color = videochat_workspace_calendar_color($payload['color'] ?? '#1582BF');
    $memberRoles = videochat_workspace_calendar_members_from_payload($payload, $userId);
    $memberIds = array_keys($memberRoles);
    $activeMemberIds = videochat_workspace_calendar_active_user_ids($pdo, $effectiveTenantId, $memberIds);
    $syncCalendarIds = videochat_workspace_calendar_sync_ids_from_payload($payload, (string) ($calendarId ?? ''));
    $visibleSyncCalendarIds = videochat_workspace_calendar_visible_sync_ids($pdo, $userId, $effectiveTenantId, $syncCalendarIds);
    $errors = [];
    if ($name === '') {
        $errors['name'] = 'required';
    }
    if ($activeMemberIds !== $memberIds) {
        $errors['members'] = 'contains_unknown_or_inactive_user';
    }
    if ($visibleSyncCalendarIds !== $syncCalendarIds) {
        $errors['sync_calendar_ids'] = 'contains_unknown_or_inaccessible_calendar';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo->beginTransaction();
    try {
        $now = videochat_workspace_calendar_now();
        if ($calendarId === null) {
            videochat_workspace_calendar_get_or_create_personal($pdo, $userId, $effectiveTenantId);
            if (videochat_workspace_calendar_owned_count($pdo, $effectiveTenantId, $userId) >= videochat_workspace_calendar_max_count()) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['calendar' => 'calendar_limit_reached']];
            }

            $calendarId = videochat_workspace_calendar_id();
            $insert = $pdo->prepare(
                <<<'SQL'
INSERT INTO workspace_calendars(id, tenant_id, owner_user_id, name, description, color, sync_calendar_ids, calendar_type, status, created_at, updated_at)
VALUES(:id, :tenant_id, :owner_user_id, :name, :description, :color, :sync_calendar_ids, 'shared', 'active', :created_at, :updated_at)
SQL
            );
            $insert->execute([
                ':id' => $calendarId,
                ':tenant_id' => $effectiveTenantId,
                ':owner_user_id' => $userId,
                ':name' => $name,
                ':description' => $description,
                ':color' => $color,
                ':sync_calendar_ids' => json_encode($visibleSyncCalendarIds, JSON_THROW_ON_ERROR),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        } else {
            $existing = videochat_workspace_calendar_fetch_visible($pdo, $calendarId, $userId, $effectiveTenantId);
            if (!is_array($existing)) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['calendar' => 'not_found']];
            }
            if ((int) ($existing['owner_user_id'] ?? 0) !== $userId) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['calendar' => 'owner_required']];
            }
            $visibleSyncCalendarIds = videochat_workspace_calendar_visible_sync_ids(
                $pdo,
                $userId,
                $effectiveTenantId,
                videochat_workspace_calendar_sync_ids_from_payload($payload, $calendarId)
            );
            $update = $pdo->prepare(
                <<<'SQL'
UPDATE workspace_calendars
SET name = :name,
    description = :description,
    color = :color,
    sync_calendar_ids = :sync_calendar_ids,
    updated_at = :updated_at
WHERE id = :id
SQL
            );
            $update->execute([
                ':name' => $name,
                ':description' => $description,
                ':color' => $color,
                ':sync_calendar_ids' => json_encode($visibleSyncCalendarIds, JSON_THROW_ON_ERROR),
                ':updated_at' => $now,
                ':id' => $calendarId,
            ]);
        }

        $activeMemberRoles = [];
        foreach ($activeMemberIds as $activeMemberId) {
            $activeMemberRoles[$activeMemberId] = $memberRoles[$activeMemberId] ?? 'viewer';
        }
        videochat_workspace_calendar_sync_members($pdo, $calendarId, $effectiveTenantId, $userId, $activeMemberRoles);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $row = videochat_workspace_calendar_fetch_visible($pdo, $calendarId, $userId, $effectiveTenantId);
    if (!is_array($row)) {
        return ['ok' => false, 'errors' => ['calendar' => 'not_found']];
    }
    $members = videochat_workspace_calendar_members_by_calendar($pdo, [$calendarId]);
    return ['ok' => true, 'calendar' => videochat_workspace_calendar_row($row, $members)];
}

/** @return array{ok: bool, errors?: array<string, string>} */
function videochat_workspace_calendar_archive(PDO $pdo, int $userId, int $tenantId, string $calendarId): array
{
    $effectiveTenantId = videochat_workspace_calendar_tenant_id($pdo, $tenantId);
    $existing = videochat_workspace_calendar_fetch_visible($pdo, $calendarId, $userId, $effectiveTenantId);
    if (!is_array($existing)) {
        return ['ok' => false, 'errors' => ['calendar' => 'not_found']];
    }
    if ((int) ($existing['owner_user_id'] ?? 0) !== $userId) {
        return ['ok' => false, 'errors' => ['calendar' => 'owner_required']];
    }
    if ((string) ($existing['calendar_type'] ?? '') === 'personal') {
        return ['ok' => false, 'errors' => ['calendar' => 'personal_calendar_cannot_be_deleted']];
    }

    $update = $pdo->prepare("UPDATE workspace_calendars SET status = 'archived', updated_at = :updated_at WHERE id = :id");
    $update->execute([
        ':updated_at' => videochat_workspace_calendar_now(),
        ':id' => $calendarId,
    ]);

    return ['ok' => true];
}
