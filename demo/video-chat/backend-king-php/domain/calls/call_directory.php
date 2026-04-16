<?php

declare(strict_types=1);

/**
 * @return array{
 *   ok: bool,
 *   query: string,
 *   status: string,
 *   requested_scope: string,
 *   effective_scope: string,
 *   page: int,
 *   page_size: int,
 *   limit: int,
 *   offset: int,
 *   errors: array<string, string>
 * }
 */
function videochat_calls_list_filters(array $queryParams, string $authRole): array
{
    $errors = [];

    $queryRaw = $queryParams['query'] ?? ($queryParams['q'] ?? '');
    $query = is_string($queryRaw) ? trim($queryRaw) : '';
    if (strlen($query) > 160) {
        $query = substr($query, 0, 160);
    }

    $status = strtolower(trim((string) ($queryParams['status'] ?? 'all')));
    $allowedStatuses = ['all', 'scheduled', 'active', 'ended', 'cancelled'];
    if (!in_array($status, $allowedStatuses, true)) {
        $errors['status'] = 'must_be_all_or_valid_call_status';
        $status = 'all';
    }

    $requestedScope = strtolower(trim((string) ($queryParams['scope'] ?? 'my')));
    if (!in_array($requestedScope, ['my', 'all'], true)) {
        $errors['scope'] = 'must_be_my_or_all';
        $requestedScope = 'my';
    }

    $normalizedRole = videochat_normalize_role_slug($authRole);
    $canReadAll = $normalizedRole === 'admin';
    $effectiveScope = $requestedScope === 'all' && !$canReadAll ? 'my' : $requestedScope;

    $pageRaw = $queryParams['page'] ?? '1';
    $pageSizeRaw = $queryParams['page_size'] ?? '10';

    $page = filter_var($pageRaw, FILTER_VALIDATE_INT);
    if (!is_int($page) || $page < 1) {
        $errors['page'] = 'must_be_integer_greater_than_zero';
        $page = 1;
    }

    $pageSize = filter_var($pageSizeRaw, FILTER_VALIDATE_INT);
    if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 100) {
        $errors['page_size'] = 'must_be_integer_between_1_and_100';
        $pageSize = 10;
    }

    return [
        'ok' => $errors === [],
        'query' => $query,
        'status' => $status,
        'requested_scope' => $requestedScope,
        'effective_scope' => $effectiveScope,
        'page' => $page,
        'page_size' => $pageSize,
        'limit' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
        'errors' => $errors,
    ];
}

/**
 * @param array{
 *   query: string,
 *   status: string,
 *   effective_scope: string,
 *   page: int,
 *   page_size: int,
 *   limit: int,
 *   offset: int
 * } $filters
 * @return array{
 *   rows: array<int, array{
 *     id: string,
 *     room_id: string,
 *     title: string,
 *     access_mode: string,
 *     status: string,
 *     starts_at: string,
 *     ends_at: string,
 *     cancelled_at: ?string,
 *     cancel_reason: ?string,
 *     cancel_message: ?string,
 *     created_at: string,
 *     updated_at: string,
 *     owner: array{
 *       user_id: int,
 *       email: string,
 *       display_name: string
 *     },
 *     participants: array{
 *       total: int,
 *       internal: int,
 *       external: int
 *     },
 *     my_participation: bool
 *   }>,
 *   total: int,
 *   page_count: int
 * }
 */
function videochat_list_calls(PDO $pdo, int $authUserId, array $filters): array
{
    $whereClauses = [];
    $whereParams = [];

    if ((string) ($filters['effective_scope'] ?? 'my') === 'my') {
        $whereParams[':auth_user_id'] = $authUserId;
        $whereClauses[] = <<<'SQL'
(
    calls.owner_user_id = :auth_user_id
    OR EXISTS (
        SELECT 1
        FROM call_participants cp_me
        WHERE cp_me.call_id = calls.id
          AND cp_me.user_id = :auth_user_id
          AND calls.status <> 'cancelled'
    )
)
SQL;
    }

    $status = (string) ($filters['status'] ?? 'all');
    if ($status !== 'all') {
        $whereClauses[] = 'calls.status = :status';
        $whereParams[':status'] = $status;
    }

    $query = trim((string) ($filters['query'] ?? ''));
    if ($query !== '') {
        $whereClauses[] = 'lower(calls.title) LIKE :title_query';
        $whereParams[':title_query'] = '%' . strtolower($query) . '%';
    }

    $whereSql = $whereClauses === [] ? '' : ('WHERE ' . implode(' AND ', $whereClauses));

    $countSql = <<<SQL
SELECT COUNT(*)
FROM calls
{$whereSql}
SQL;
    $countStatement = $pdo->prepare($countSql);
    foreach ($whereParams as $name => $value) {
        if (is_int($value)) {
            $countStatement->bindValue($name, $value, PDO::PARAM_INT);
        } else {
            $countStatement->bindValue($name, $value, PDO::PARAM_STR);
        }
    }
    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();

    $pageSize = (int) ($filters['page_size'] ?? 10);
    $pageSize = max(1, min(100, $pageSize));
    $pageCount = $total === 0 ? 0 : (int) ceil($total / $pageSize);

    $limit = (int) ($filters['limit'] ?? $pageSize);
    $offset = (int) ($filters['offset'] ?? 0);

    $listSql = <<<SQL
SELECT
    calls.id,
    calls.room_id,
    calls.title,
    calls.access_mode,
    calls.status,
    calls.starts_at,
    calls.ends_at,
    calls.cancelled_at,
    calls.cancel_reason,
    calls.cancel_message,
    calls.created_at,
    calls.updated_at,
    calls.owner_user_id,
    owners.email AS owner_email,
    owners.display_name AS owner_display_name,
    COALESCE(participants.total_count, 0) AS participants_total,
    COALESCE(participants.internal_count, 0) AS participants_internal,
    COALESCE(participants.external_count, 0) AS participants_external,
    CASE
        WHEN calls.status = 'cancelled' THEN 0
        WHEN calls.owner_user_id = :auth_user_id OR me.call_id IS NOT NULL THEN 1
        ELSE 0
    END AS my_participation
FROM calls
INNER JOIN users owners ON owners.id = calls.owner_user_id
LEFT JOIN (
    SELECT
        call_id,
        COUNT(*) AS total_count,
        SUM(CASE WHEN source = 'internal' THEN 1 ELSE 0 END) AS internal_count,
        SUM(CASE WHEN source = 'external' THEN 1 ELSE 0 END) AS external_count
    FROM call_participants
    GROUP BY call_id
) participants ON participants.call_id = calls.id
LEFT JOIN (
    SELECT DISTINCT call_id
    FROM call_participants
    WHERE user_id = :auth_user_id
) me ON me.call_id = calls.id
{$whereSql}
ORDER BY
    calls.starts_at ASC,
    calls.created_at ASC,
    calls.id ASC
LIMIT :limit OFFSET :offset
SQL;
    $listStatement = $pdo->prepare($listSql);
    $listParams = $whereParams;
    $listParams[':auth_user_id'] = $authUserId;
    foreach ($listParams as $name => $value) {
        if (is_int($value)) {
            $listStatement->bindValue($name, $value, PDO::PARAM_INT);
        } else {
            $listStatement->bindValue($name, $value, PDO::PARAM_STR);
        }
    }
    $listStatement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();

    $rows = [];
    $fetched = $listStatement->fetchAll();
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'id' => (string) ($row['id'] ?? ''),
            'room_id' => (string) ($row['room_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'access_mode' => videochat_normalize_call_access_mode($row['access_mode'] ?? 'invite_only'),
            'status' => (string) ($row['status'] ?? ''),
            'starts_at' => (string) ($row['starts_at'] ?? ''),
            'ends_at' => (string) ($row['ends_at'] ?? ''),
            'cancelled_at' => is_string($row['cancelled_at'] ?? null) ? (string) $row['cancelled_at'] : null,
            'cancel_reason' => is_string($row['cancel_reason'] ?? null) ? (string) $row['cancel_reason'] : null,
            'cancel_message' => is_string($row['cancel_message'] ?? null) ? (string) $row['cancel_message'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'owner' => [
                'user_id' => (int) ($row['owner_user_id'] ?? 0),
                'email' => (string) ($row['owner_email'] ?? ''),
                'display_name' => (string) ($row['owner_display_name'] ?? ''),
            ],
            'participants' => [
                'total' => (int) ($row['participants_total'] ?? 0),
                'internal' => (int) ($row['participants_internal'] ?? 0),
                'external' => (int) ($row['participants_external'] ?? 0),
            ],
            'my_participation' => ((int) ($row['my_participation'] ?? 0)) === 1,
        ];
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}
