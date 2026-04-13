<?php

declare(strict_types=1);

/**
 * @return array{
 *   ok: bool,
 *   query: string,
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

    $pageRaw = $queryParams['page'] ?? '1';
    $pageSizeRaw = $queryParams['page_size'] ?? '10';

    $errors = [];

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
function videochat_admin_list_users(PDO $pdo, string $query, int $page, int $pageSize): array
{
    $effectivePage = max(1, $page);
    $effectivePageSize = max(1, min(100, $pageSize));
    $offset = ($effectivePage - 1) * $effectivePageSize;

    $search = trim($query);
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = <<<'SQL'
WHERE (
    lower(users.email) LIKE :search
    OR lower(users.display_name) LIKE :search
    OR lower(roles.slug) LIKE :search
)
SQL;
        $params[':search'] = '%' . strtolower($search) . '%';
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
        WHEN 'moderator' THEN 1
        ELSE 2
    END ASC,
    lower(users.display_name) ASC,
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
