<?php

declare(strict_types=1);

/**
 * @return array{
 *   ok: bool,
 *   query: string,
 *   status: string,
 *   order: string,
 *   page: int,
 *   page_size: int,
 *   limit: int,
 *   offset: int,
 *   errors: array<string, string>
 * }
 */
function videochat_admin_user_list_filters(array $queryParams): array
{
    $queryRaw = $queryParams['query'] ?? ($queryParams['q'] ?? '');
    $query = is_string($queryRaw) ? trim($queryRaw) : '';
    if (strlen($query) > 120) {
        $query = substr($query, 0, 120);
    }
    $statusRaw = $queryParams['status'] ?? 'all';
    $status = is_string($statusRaw) ? strtolower(trim($statusRaw)) : 'all';
    $orderRaw = $queryParams['order'] ?? 'role_then_name_asc';
    $order = is_string($orderRaw) ? strtolower(trim($orderRaw)) : 'role_then_name_asc';

    $pageRaw = $queryParams['page'] ?? '1';
    $pageSizeRaw = $queryParams['page_size'] ?? '10';

    $errors = [];

    $allowedStatusValues = ['all', 'active', 'disabled'];
    if (!in_array($status, $allowedStatusValues, true)) {
        $errors['status'] = 'must_be_all_active_or_disabled';
        $status = 'all';
    }

    $allowedOrderValues = ['role_then_name_asc', 'role_then_name_desc'];
    if (!in_array($order, $allowedOrderValues, true)) {
        $errors['order'] = 'must_be_one_of_role_then_name_asc_or_role_then_name_desc';
        $order = 'role_then_name_asc';
    }

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

    $limit = $pageSize;
    $offset = ($page - 1) * $pageSize;

    return [
        'ok' => $errors === [],
        'query' => $query,
        'status' => $status,
        'order' => $order,
        'page' => $page,
        'page_size' => $pageSize,
        'limit' => $limit,
        'offset' => $offset,
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   rows: array<int, array{
 *     id: int,
 *     email: string,
 *     display_name: string,
 *     role: string,
 *     status: string,
 *     time_format: string,
 *     theme: string,
 *     avatar_path: ?string,
 *     created_at: string,
 *     updated_at: string
 *   }>,
 *   total: int,
 *   page_count: int
 * }
 */
function videochat_admin_list_users(
    PDO $pdo,
    string $query,
    int $page,
    int $pageSize,
    string $order = 'role_then_name_asc',
    string $status = 'all'
): array
{
    $effectivePage = max(1, $page);
    $effectivePageSize = max(1, min(100, $pageSize));
    $offset = ($effectivePage - 1) * $effectivePageSize;
    $effectiveOrder = in_array($order, ['role_then_name_asc', 'role_then_name_desc'], true)
        ? $order
        : 'role_then_name_asc';
    $effectiveStatus = in_array($status, ['all', 'active', 'disabled'], true)
        ? $status
        : 'all';
    $displayNameDirection = $effectiveOrder === 'role_then_name_desc' ? 'DESC' : 'ASC';

    $search = trim($query);
    $whereParts = [];
    $params = [];
    if ($search !== '') {
        $whereParts[] = <<<'SQL'
(
    lower(users.email) LIKE :search
    OR lower(users.display_name) LIKE :search
    OR lower(roles.slug) LIKE :search
)
SQL;
        $params[':search'] = '%' . strtolower($search) . '%';
    }

    if ($effectiveStatus !== 'all') {
        $whereParts[] = 'users.status = :status';
        $params[':status'] = $effectiveStatus;
    }

    $where = '';
    if ($whereParts !== []) {
        $where = 'WHERE ' . implode("\n  AND ", $whereParts);
    }

    $countSql = <<<SQL
SELECT COUNT(*)
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$where}
SQL;
    $countStatement = $pdo->prepare($countSql);
    $countStatement->execute($params);
    $total = (int) $countStatement->fetchColumn();
    $pageCount = $total === 0 ? 0 : (int) ceil($total / $effectivePageSize);

    $listSql = <<<SQL
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.time_format,
    users.theme,
    users.avatar_path,
    users.created_at,
    users.updated_at,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$where}
ORDER BY
    CASE roles.slug
        WHEN 'admin' THEN 0
        ELSE 1
    END ASC,
    lower(users.display_name) {$displayNameDirection},
    users.id ASC
LIMIT :limit OFFSET :offset
SQL;
    $listStatement = $pdo->prepare($listSql);
    foreach ($params as $name => $value) {
        $listStatement->bindValue($name, $value, PDO::PARAM_STR);
    }
    $listStatement->bindValue(':limit', $effectivePageSize, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();

    $rows = [];
    $fetched = $listStatement->fetchAll();
    foreach ($fetched as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'role' => (string) ($row['role_slug'] ?? 'user'),
            'status' => (string) ($row['status'] ?? 'disabled'),
            'time_format' => (string) ($row['time_format'] ?? '24h'),
            'theme' => (string) ($row['theme'] ?? 'dark'),
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   query: string,
 *   order: string,
 *   page: int,
 *   page_size: int,
 *   errors: array<string, string>
 * }
 */
function videochat_user_directory_filters(array $queryParams): array
{
    $queryRaw = $queryParams['query'] ?? ($queryParams['q'] ?? '');
    $query = is_string($queryRaw) ? trim($queryRaw) : '';
    if (strlen($query) > 120) {
        $query = substr($query, 0, 120);
    }

    $orderRaw = $queryParams['order'] ?? 'name_asc';
    $order = is_string($orderRaw) ? strtolower(trim($orderRaw)) : 'name_asc';
    $pageRaw = $queryParams['page'] ?? '1';
    $pageSizeRaw = $queryParams['page_size'] ?? '10';
    $errors = [];

    if (!in_array($order, ['name_asc', 'name_desc'], true)) {
        $errors['order'] = 'must_be_one_of_name_asc_or_name_desc';
        $order = 'name_asc';
    }

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
        'order' => $order,
        'page' => $page,
        'page_size' => $pageSize,
        'errors' => $errors,
    ];
}

/**
 * @return array{
 *   rows: array<int, array{
 *     id: int,
 *     email: string,
 *     display_name: string,
 *     role: string,
 *     avatar_path: ?string
 *   }>,
 *   total: int,
 *   page_count: int
 * }
 */
function videochat_user_directory_list(
    PDO $pdo,
    string $query,
    int $page,
    int $pageSize,
    string $order = 'name_asc',
    int $excludeUserId = 0
): array
{
    $effectivePage = max(1, $page);
    $effectivePageSize = max(1, min(100, $pageSize));
    $offset = ($effectivePage - 1) * $effectivePageSize;
    $displayNameDirection = $order === 'name_desc' ? 'DESC' : 'ASC';

    $search = trim($query);
    $whereParts = ['users.status = :status'];
    $params = [':status' => 'active'];
    if ($search !== '') {
        $whereParts[] = <<<'SQL'
(
    lower(users.email) LIKE :search
    OR lower(users.display_name) LIKE :search
    OR lower(roles.slug) LIKE :search
)
SQL;
        $params[':search'] = '%' . strtolower($search) . '%';
    }

    if ($excludeUserId > 0) {
        $whereParts[] = 'users.id <> :exclude_user_id';
        $params[':exclude_user_id'] = $excludeUserId;
    }

    $where = 'WHERE ' . implode("\n  AND ", $whereParts);

    $countSql = <<<SQL
SELECT COUNT(*)
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$where}
SQL;
    $countStatement = $pdo->prepare($countSql);
    foreach ($params as $name => $value) {
        $countStatement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStatement->execute();
    $total = (int) $countStatement->fetchColumn();
    $pageCount = $total === 0 ? 0 : (int) ceil($total / $effectivePageSize);

    $listSql = <<<SQL
SELECT
    users.id,
    users.email,
    users.display_name,
    users.avatar_path,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$where}
ORDER BY
    lower(users.display_name) {$displayNameDirection},
    lower(users.email) {$displayNameDirection},
    users.id ASC
LIMIT :limit OFFSET :offset
SQL;
    $listStatement = $pdo->prepare($listSql);
    foreach ($params as $name => $value) {
        $listStatement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $listStatement->bindValue(':limit', $effectivePageSize, PDO::PARAM_INT);
    $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $listStatement->execute();

    $rows = [];
    foreach ($listStatement->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'email' => (string) ($row['email'] ?? ''),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'role' => (string) ($row['role_slug'] ?? 'user'),
            'avatar_path' => is_string($row['avatar_path'] ?? null) ? (string) $row['avatar_path'] : null,
        ];
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}
