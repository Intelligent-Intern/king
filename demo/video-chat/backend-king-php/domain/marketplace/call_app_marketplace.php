<?php

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function videochat_call_app_marketplace_categories(): array
{
    return ['whiteboard', 'avatar', 'assistant', 'collaboration', 'utility', 'other'];
}

function videochat_normalize_call_app_category(mixed $value, string $fallback = 'other'): string
{
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, videochat_call_app_marketplace_categories(), true)) {
        return $normalized;
    }

    return $fallback;
}

/**
 * @return array{ok: bool, value: string, error?: string}
 */
function videochat_validate_call_app_website(mixed $value): array
{
    $website = trim((string) $value);
    if ($website === '') {
        return ['ok' => true, 'value' => ''];
    }

    if (filter_var($website, FILTER_VALIDATE_URL) === false) {
        return ['ok' => false, 'value' => '', 'error' => 'must_be_valid_url'];
    }

    $scheme = strtolower((string) parse_url($website, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'value' => '', 'error' => 'must_use_http_or_https'];
    }

    return ['ok' => true, 'value' => $website];
}

/**
 * @return array{ok: bool, query?: string, category?: string, page?: int, page_size?: int, errors?: array<string, string>}
 */
function videochat_call_app_marketplace_filters(array $queryParams): array
{
    $errors = [];
    $query = trim((string) ($queryParams['query'] ?? ''));
    $categoryRaw = strtolower(trim((string) ($queryParams['category'] ?? 'all')));
    $category = $categoryRaw === '' ? 'all' : $categoryRaw;
    $page = (int) ($queryParams['page'] ?? 1);
    $pageSize = (int) ($queryParams['page_size'] ?? 10);

    if ($page < 1) {
        $errors['page'] = 'must_be_integer_greater_than_zero';
    }
    if ($pageSize < 1 || $pageSize > 100) {
        $errors['page_size'] = 'must_be_integer_between_1_and_100';
    }
    if ($category !== 'all' && !in_array($category, videochat_call_app_marketplace_categories(), true)) {
        $errors['category'] = 'must_be_all_or_known_category';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    return [
        'ok' => true,
        'query' => $query,
        'category' => $category,
        'page' => $page,
        'page_size' => $pageSize,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_marketplace_row(array $row): array
{
    return [
        'id' => (int) ($row['id'] ?? 0),
        'name' => trim((string) ($row['name'] ?? '')),
        'manufacturer' => trim((string) ($row['manufacturer'] ?? '')),
        'website' => trim((string) ($row['website'] ?? '')),
        'category' => videochat_normalize_call_app_category($row['category'] ?? 'other'),
        'description' => trim((string) ($row['description'] ?? '')),
        'created_at' => trim((string) ($row['created_at'] ?? '')),
        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
    ];
}

function videochat_admin_call_app_catalog_match_value(mixed $value): string
{
    return strtolower(trim((string) $value));
}

function videochat_admin_call_app_catalog_signature(array $row, bool $includeManufacturer = true): string
{
    $parts = [
        videochat_admin_call_app_catalog_match_value($row['name'] ?? ''),
        videochat_admin_call_app_catalog_match_value($row['category'] ?? ''),
    ];
    if ($includeManufacturer) {
        $parts[] = videochat_admin_call_app_catalog_match_value($row['manufacturer'] ?? '');
    }

    return implode("\n", $parts);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_call_app_catalog_summary(array $catalogApp): array
{
    return [
        'app_key' => (string) ($catalogApp['app_key'] ?? ''),
        'version' => (string) ($catalogApp['version'] ?? ''),
        'name' => (string) ($catalogApp['name'] ?? ''),
        'category' => (string) ($catalogApp['category'] ?? 'other'),
        'manufacturer' => (string) ($catalogApp['manufacturer'] ?? ''),
        'health_status' => (string) ($catalogApp['health_status'] ?? 'unknown'),
        'metadata_hash' => (string) ($catalogApp['metadata_hash'] ?? ''),
        'organization' => is_array($catalogApp['organization'] ?? null) ? $catalogApp['organization'] : null,
        'organization_actions' => is_array($catalogApp['organization_actions'] ?? null) ? $catalogApp['organization_actions'] : null,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_call_app_catalog_marketplace_row(array $catalogApp): array
{
    $listing = is_array($catalogApp['listing'] ?? null) ? $catalogApp['listing'] : [];
    $appKey = trim((string) ($catalogApp['app_key'] ?? ''));
    $name = trim((string) ($catalogApp['name'] ?? ($listing['name'] ?? $appKey)));

    return [
        'id' => 0,
        'catalog_key' => $appKey,
        'source' => 'call_app_catalog',
        'catalog_only' => true,
        'name' => $name === '' ? $appKey : $name,
        'manufacturer' => trim((string) ($catalogApp['manufacturer'] ?? '')),
        'website' => trim((string) ($listing['website'] ?? '')),
        'category' => videochat_normalize_call_app_category($catalogApp['category'] ?? ($listing['category'] ?? 'other')),
        'description' => trim((string) ($catalogApp['description'] ?? ($listing['summary'] ?? ''))),
        'created_at' => trim((string) ($catalogApp['verified_at'] ?? '')),
        'updated_at' => trim((string) ($catalogApp['updated_at'] ?? '')),
        'call_app_catalog' => videochat_admin_call_app_catalog_summary($catalogApp),
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, array<string, mixed>> $catalogApps
 * @return array<int, array<string, mixed>>
 */
function videochat_admin_call_app_attach_catalog_entries(array $rows, array $catalogApps): array
{
    $exact = [];
    $nameCategory = [];
    foreach ($catalogApps as $catalogApp) {
        if (!is_array($catalogApp) || trim((string) ($catalogApp['app_key'] ?? '')) === '') {
            continue;
        }
        $exact[videochat_admin_call_app_catalog_signature($catalogApp, true)] = $catalogApp;
        $nameCategory[videochat_admin_call_app_catalog_signature($catalogApp, false)] = $catalogApp;
    }

    $matchedCatalogKeys = [];
    $attachedRows = array_map(static function (array $row) use ($exact, $nameCategory, &$matchedCatalogKeys): array {
        $match = $exact[videochat_admin_call_app_catalog_signature($row, true)]
            ?? $nameCategory[videochat_admin_call_app_catalog_signature($row, false)]
            ?? null;
        if (is_array($match)) {
            $matchedCatalogKeys[(string) ($match['app_key'] ?? '') . ':' . (string) ($match['version'] ?? '')] = true;
        }
        $row['call_app_catalog'] = is_array($match) ? videochat_admin_call_app_catalog_summary($match) : null;
        return $row;
    }, $rows);

    foreach ($catalogApps as $catalogApp) {
        if (!is_array($catalogApp) || trim((string) ($catalogApp['app_key'] ?? '')) === '') {
            continue;
        }
        $catalogKey = (string) ($catalogApp['app_key'] ?? '') . ':' . (string) ($catalogApp['version'] ?? '');
        if (isset($matchedCatalogKeys[$catalogKey])) {
            continue;
        }
        $attachedRows[] = videochat_admin_call_app_catalog_marketplace_row($catalogApp);
    }

    return $attachedRows;
}

/**
 * @return array{rows: array<int, array<string, mixed>>, total: int, page_count: int}
 */
function videochat_admin_list_call_apps(PDO $pdo, string $query, int $page, int $pageSize, string $category, array $catalogApps = []): array
{
    $where = [];
    $params = [];

    if ($category !== 'all') {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }

    $search = trim($query);
    if ($search !== '') {
        $where[] = '(
            lower(name) LIKE :search
            OR lower(manufacturer) LIKE :search
            OR lower(website) LIKE :search
            OR lower(description) LIKE :search
        )';
        $params[':search'] = '%' . strtolower($search) . '%';
    }

    $boundedPageSize = max(1, min(100, $pageSize));
    $whereSql = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

    $listQuery = $pdo->prepare(
        'SELECT id, name, manufacturer, website, category, description, created_at, updated_at
         FROM call_apps '
        . $whereSql
        . ' ORDER BY lower(name) ASC, lower(manufacturer) ASC, id ASC'
    );
    foreach ($params as $key => $value) {
        $listQuery->bindValue($key, $value, PDO::PARAM_STR);
    }
    $listQuery->execute();

    $rows = $listQuery->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }
    $combinedRows = videochat_admin_call_app_attach_catalog_entries(
        array_map(static fn (array $row): array => videochat_call_app_marketplace_row($row), $rows),
        $catalogApps
    );
    usort($combinedRows, static function (array $left, array $right): int {
        $leftName = videochat_admin_call_app_catalog_match_value($left['name'] ?? '');
        $rightName = videochat_admin_call_app_catalog_match_value($right['name'] ?? '');
        if ($leftName !== $rightName) {
            return $leftName <=> $rightName;
        }
        $leftManufacturer = videochat_admin_call_app_catalog_match_value($left['manufacturer'] ?? '');
        $rightManufacturer = videochat_admin_call_app_catalog_match_value($right['manufacturer'] ?? '');
        if ($leftManufacturer !== $rightManufacturer) {
            return $leftManufacturer <=> $rightManufacturer;
        }
        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    $total = count($combinedRows);
    $pageCount = max((int) ceil($total / $boundedPageSize), 1);
    $currentPage = min(max($page, 1), $pageCount);
    $offset = ($currentPage - 1) * $boundedPageSize;

    return [
        'rows' => array_slice($combinedRows, $offset, $boundedPageSize),
        'total' => $total,
        'page_count' => $pageCount,
    ];
}

function videochat_admin_fetch_call_app(PDO $pdo, int $appId): ?array
{
    if ($appId <= 0) {
        return null;
    }

    $query = $pdo->prepare(
        'SELECT id, name, manufacturer, website, category, description, created_at, updated_at
         FROM call_apps WHERE id = :id LIMIT 1'
    );
    $query->execute([':id' => $appId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return videochat_call_app_marketplace_row($row);
}

/**
 * @return array{ok: bool, data?: array<string, mixed>, errors?: array<string, string>, reason?: string}
 */
function videochat_validate_call_app_payload(array $payload, bool $isUpdate = false): array
{
    $errors = [];
    $data = [];
    $supportedFields = ['name', 'manufacturer', 'website', 'category', 'description'];
    $providedFields = array_values(array_intersect(array_keys($payload), $supportedFields));

    if ($isUpdate && $providedFields === []) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'errors' => ['payload' => 'at_least_one_supported_field_required'],
        ];
    }

    $nameProvided = array_key_exists('name', $payload);
    $name = trim((string) ($payload['name'] ?? ''));
    if (!$isUpdate || $nameProvided) {
        if ($name === '') {
            $errors['name'] = 'required';
        } elseif (mb_strlen($name) > 140) {
            $errors['name'] = 'must_be_140_chars_or_less';
        } else {
            $data['name'] = $name;
        }
    }

    $manufacturerProvided = array_key_exists('manufacturer', $payload);
    $manufacturer = trim((string) ($payload['manufacturer'] ?? ''));
    if (!$isUpdate || $manufacturerProvided) {
        if ($manufacturer === '') {
            $errors['manufacturer'] = 'required';
        } elseif (mb_strlen($manufacturer) > 140) {
            $errors['manufacturer'] = 'must_be_140_chars_or_less';
        } else {
            $data['manufacturer'] = $manufacturer;
        }
    }

    if (!$isUpdate || array_key_exists('website', $payload)) {
        $websiteValidation = videochat_validate_call_app_website($payload['website'] ?? '');
        if (!(bool) ($websiteValidation['ok'] ?? false)) {
            $errors['website'] = (string) ($websiteValidation['error'] ?? 'must_be_valid_url');
        } else {
            $data['website'] = (string) ($websiteValidation['value'] ?? '');
        }
    }

    if (!$isUpdate || array_key_exists('category', $payload)) {
        $category = strtolower(trim((string) ($payload['category'] ?? '')));
        if ($category === '' || !in_array($category, videochat_call_app_marketplace_categories(), true)) {
            $errors['category'] = 'must_be_known_category';
        } else {
            $data['category'] = $category;
        }
    }

    if (!$isUpdate || array_key_exists('description', $payload)) {
        $description = trim((string) ($payload['description'] ?? ''));
        if (mb_strlen($description) > 2000) {
            $errors['description'] = 'must_be_2000_chars_or_less';
        } else {
            $data['description'] = $description;
        }
    }

    if ($errors !== []) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => $errors];
    }

    return ['ok' => true, 'data' => $data];
}

function videochat_call_app_conflicts(PDO $pdo, string $name, string $manufacturer, int $ignoreId = 0): bool
{
    $query = $pdo->prepare(
        'SELECT id FROM call_apps WHERE lower(name) = lower(:name) AND lower(manufacturer) = lower(:manufacturer) LIMIT 1'
    );
    $query->execute([
        ':name' => $name,
        ':manufacturer' => $manufacturer,
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }

    $existingId = (int) ($row['id'] ?? 0);
    return $existingId > 0 && $existingId !== $ignoreId;
}

function videochat_admin_create_call_app(PDO $pdo, array $payload): array
{
    $validation = videochat_validate_call_app_payload($payload, false);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $name = (string) ($data['name'] ?? '');
    $manufacturer = (string) ($data['manufacturer'] ?? '');
    if (videochat_call_app_conflicts($pdo, $name, $manufacturer)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['name' => 'already_exists'],
        ];
    }

    $timestamp = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_apps(name, manufacturer, website, category, description, created_at, updated_at)
VALUES(:name, :manufacturer, :website, :category, :description, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':name' => $name,
        ':manufacturer' => $manufacturer,
        ':website' => (string) ($data['website'] ?? ''),
        ':category' => (string) ($data['category'] ?? 'other'),
        ':description' => (string) ($data['description'] ?? ''),
        ':created_at' => $timestamp,
        ':updated_at' => $timestamp,
    ]);

    $appId = (int) $pdo->lastInsertId();
    $app = videochat_admin_fetch_call_app($pdo, $appId);

    return [
        'ok' => true,
        'reason' => 'created',
        'app' => $app,
    ];
}

function videochat_admin_update_call_app(PDO $pdo, int $appId, array $payload): array
{
    $existing = videochat_admin_fetch_call_app($pdo, $appId);
    if ($existing === null) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $validation = videochat_validate_call_app_payload($payload, true);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $data = is_array($validation['data'] ?? null) ? $validation['data'] : [];
    $name = (string) ($data['name'] ?? ($existing['name'] ?? ''));
    $manufacturer = (string) ($data['manufacturer'] ?? ($existing['manufacturer'] ?? ''));
    if (videochat_call_app_conflicts($pdo, $name, $manufacturer, $appId)) {
        return [
            'ok' => false,
            'reason' => 'conflict',
            'errors' => ['name' => 'already_exists'],
        ];
    }

    $assignments = [];
    $params = [':id' => $appId];
    foreach ($data as $field => $value) {
        $assignments[] = $field . ' = :' . $field;
        $params[':' . $field] = $value;
    }
    $assignments[] = 'updated_at = :updated_at';
    $params[':updated_at'] = gmdate('c');

    $update = $pdo->prepare('UPDATE call_apps SET ' . implode(', ', $assignments) . ' WHERE id = :id');
    $update->execute($params);

    return [
        'ok' => true,
        'reason' => 'updated',
        'app' => videochat_admin_fetch_call_app($pdo, $appId),
    ];
}

function videochat_admin_delete_call_app(PDO $pdo, int $appId): array
{
    $existing = videochat_admin_fetch_call_app($pdo, $appId);
    if ($existing === null) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $delete = $pdo->prepare('DELETE FROM call_apps WHERE id = :id');
    $delete->execute([':id' => $appId]);

    return [
        'ok' => true,
        'reason' => 'deleted',
        'app' => $existing,
    ];
}
