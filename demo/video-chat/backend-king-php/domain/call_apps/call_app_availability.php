<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_marketplace_entitlements.php';
require_once __DIR__ . '/../calls/call_management.php';

/**
 * @return array{
 *   ok: bool,
 *   query: string,
 *   category: string,
 *   page: int,
 *   page_size: int,
 *   limit: int,
 *   offset: int,
 *   errors: array<string, string>
 * }
 */
function videochat_call_app_availability_filters(array $queryParams): array
{
    $errors = [];
    $queryRaw = $queryParams['query'] ?? ($queryParams['q'] ?? '');
    $query = is_string($queryRaw) ? trim($queryRaw) : '';
    if (strlen($query) > 160) {
        $query = substr($query, 0, 160);
    }

    $category = strtolower(trim((string) ($queryParams['category'] ?? 'all')));
    if ($category === '') {
        $category = 'all';
    }
    if (!preg_match('/^[a-z0-9._-]{1,80}$/', $category)) {
        $errors['category'] = 'must_be_all_or_known_category_slug';
        $category = 'all';
    }

    $page = filter_var($queryParams['page'] ?? '1', FILTER_VALIDATE_INT);
    if (!is_int($page) || $page < 1) {
        $errors['page'] = 'must_be_integer_greater_than_zero';
        $page = 1;
    }

    $pageSize = filter_var($queryParams['page_size'] ?? '12', FILTER_VALIDATE_INT);
    if (!is_int($pageSize) || $pageSize < 1 || $pageSize > 50) {
        $errors['page_size'] = 'must_be_integer_between_1_and_50';
        $pageSize = 12;
    }

    return [
        'ok' => $errors === [],
        'query' => $query,
        'category' => $category,
        'page' => $page,
        'page_size' => $pageSize,
        'limit' => $pageSize,
        'offset' => ($page - 1) * $pageSize,
        'errors' => $errors,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_available_row(array $row): array
{
    $catalog = videochat_call_app_catalog_row($row);

    return [
        ...$catalog,
        'availability' => [
            'installed' => true,
            'healthy' => true,
            'source' => 'organization_installation',
        ],
        'installation' => [
            'id' => (string) ($row['installation_public_id'] ?? ''),
            'status' => (string) ($row['installation_status'] ?? 'enabled'),
            'default_app_policy' => (string) ($row['default_app_policy'] ?? 'blocked_by_default'),
            'config' => videochat_call_app_marketplace_decode_json((string) ($row['config_json'] ?? '{}'), []),
            'installed_at' => (string) ($row['installed_at'] ?? ''),
            'updated_at' => (string) ($row['installation_updated_at'] ?? ''),
        ],
        'entitlement' => [
            'id' => (string) ($row['entitlement_public_id'] ?? ''),
            'status' => (string) ($row['entitlement_status'] ?? 'active'),
            'expires_at' => is_string($row['expires_at'] ?? null) ? (string) $row['expires_at'] : null,
        ],
    ];
}

/**
 * @param array{
 *   query: string,
 *   category: string,
 *   page: int,
 *   page_size: int,
 *   limit: int,
 *   offset: int
 * } $filters
 * @return array{
 *   apps: array<int, array<string, mixed>>,
 *   total: int,
 *   page_count: int
 * }
 */
function videochat_call_app_list_available_for_tenant(PDO $pdo, int $tenantId, array $filters): array
{
    if ($tenantId <= 0) {
        return ['apps' => [], 'total' => 0, 'page_count' => 0];
    }

    $where = [
        'installations.tenant_id = :tenant_id',
        'installations.status = \'enabled\'',
        'entitlements.tenant_id = :tenant_id',
        'entitlements.status = \'active\'',
        '(entitlements.expires_at IS NULL OR trim(entitlements.expires_at) = \'\' OR entitlements.expires_at > :now)',
        'catalog.health_status = \'healthy\'',
    ];
    $params = [
        ':tenant_id' => $tenantId,
        ':now' => gmdate('c'),
    ];

    $query = trim((string) ($filters['query'] ?? ''));
    if ($query !== '') {
        $where[] = '(lower(catalog.name) LIKE :search OR lower(catalog.app_key) LIKE :search OR lower(catalog.category) LIKE :search)';
        $params[':search'] = '%' . strtolower($query) . '%';
    }

    $category = (string) ($filters['category'] ?? 'all');
    if ($category !== '' && $category !== 'all') {
        $where[] = 'lower(catalog.category) = :category';
        $params[':category'] = strtolower($category);
    }

    $whereSql = 'WHERE ' . implode("\n  AND ", $where);
    $count = $pdo->prepare(
        <<<SQL
SELECT COUNT(*)
FROM organization_call_app_installations installations
INNER JOIN organization_call_app_entitlements entitlements ON entitlements.id = installations.entitlement_id
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = installations.app_key
   AND catalog.app_version = installations.app_version
{$whereSql}
SQL
    );
    foreach ($params as $name => $value) {
        $count->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $count->execute();
    $total = (int) $count->fetchColumn();

    $pageSize = max(1, min(50, (int) ($filters['page_size'] ?? 12)));
    $pageCount = $total === 0 ? 0 : (int) ceil($total / $pageSize);
    $limit = max(1, min(50, (int) ($filters['limit'] ?? $pageSize)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));

    $statement = $pdo->prepare(
        <<<SQL
SELECT
    catalog.*,
    installations.public_id AS installation_public_id,
    installations.status AS installation_status,
    installations.config_json,
    installations.default_app_policy,
    installations.installed_at,
    installations.updated_at AS installation_updated_at,
    entitlements.public_id AS entitlement_public_id,
    entitlements.status AS entitlement_status,
    entitlements.expires_at
FROM organization_call_app_installations installations
INNER JOIN organization_call_app_entitlements entitlements ON entitlements.id = installations.entitlement_id
INNER JOIN call_app_catalog_entries catalog
    ON catalog.app_key = installations.app_key
   AND catalog.app_version = installations.app_version
{$whereSql}
ORDER BY lower(catalog.name) ASC, catalog.app_key ASC, catalog.app_version DESC
LIMIT :limit OFFSET :offset
SQL
    );
    foreach ($params as $name => $value) {
        $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $apps = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        $apps[] = videochat_call_app_available_row($row);
    }

    return [
        'apps' => $apps,
        'total' => $total,
        'page_count' => $pageCount,
    ];
}
