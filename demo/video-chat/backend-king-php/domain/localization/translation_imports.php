<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/localization.php';

function videochat_translation_clean_text(mixed $value, int $maxLength): string
{
    $text = trim((string) ($value ?? ''));
    if ($maxLength > 0 && strlen($text) > $maxLength) {
        return substr($text, 0, $maxLength);
    }

    return $text;
}

function videochat_translation_error(int $rowNumber, string $field, string $code, string $message): array
{
    return [
        'row' => $rowNumber,
        'field' => $field,
        'code' => $code,
        'message' => $message,
    ];
}

function videochat_translation_header_name(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? $normalized;
    return trim($normalized, '_');
}

/**
 * @return array<string, int>
 */
function videochat_translation_csv_header_map(array $header): array
{
    $aliases = [
        'locale' => ['locale', 'language', 'lang'],
        'namespace' => ['namespace', 'ns'],
        'resource_key' => ['resource_key', 'key', 'translation_key', 'message_key'],
        'value' => ['value', 'text', 'translation', 'message'],
        'tenant_id' => ['tenant_id', 'tenant'],
    ];

    $normalized = [];
    foreach ($header as $index => $name) {
        $normalized[videochat_translation_header_name((string) $name)] = (int) $index;
    }

    $map = [];
    foreach ($aliases as $canonical => $candidates) {
        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $normalized)) {
                $map[$canonical] = $normalized[$candidate];
                break;
            }
        }
    }

    return $map;
}

function videochat_translation_csv_cell(array $row, array $headerMap, string $field): string
{
    $index = $headerMap[$field] ?? null;
    if (!is_int($index) || !array_key_exists($index, $row)) {
        return '';
    }

    return (string) ($row[$index] ?? '');
}

function videochat_translation_tenant_exists(PDO $pdo, ?int $tenantId): bool
{
    if ($tenantId === null) {
        return true;
    }
    if ($tenantId <= 0 || !videochat_locale_table_exists($pdo, 'tenants')) {
        return false;
    }

    $statement = $pdo->prepare('SELECT 1 FROM tenants WHERE id = :id AND status = \'active\' LIMIT 1');
    $statement->execute([':id' => $tenantId]);
    return (bool) $statement->fetchColumn();
}

/**
 * @return array{
 *   ok: bool,
 *   total_rows: int,
 *   valid_rows: int,
 *   error_count: int,
 *   resources: array<int, array<string, mixed>>,
 *   errors: array<int, array<string, mixed>>,
 *   summary: array<string, mixed>
 * }
 */
function videochat_preview_translation_csv(PDO $pdo, string $csv, ?int $defaultTenantId = null): array
{
    $text = (string) $csv;
    $errors = [];
    $resources = [];
    $seen = [];

    if (trim($text) === '') {
        return [
            'ok' => false,
            'total_rows' => 0,
            'valid_rows' => 0,
            'error_count' => 1,
            'resources' => [],
            'errors' => [videochat_translation_error(1, 'csv', 'required_csv', 'CSV content is required.')],
            'summary' => ['locales' => [], 'namespaces' => [], 'tenant_ids' => []],
        ];
    }

    $handle = fopen('php://temp', 'r+');
    if (!is_resource($handle)) {
        throw new RuntimeException('csv_temp_stream_unavailable');
    }
    fwrite($handle, $text);
    rewind($handle);

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        return [
            'ok' => false,
            'total_rows' => 0,
            'valid_rows' => 0,
            'error_count' => 1,
            'resources' => [],
            'errors' => [videochat_translation_error(1, 'csv', 'invalid_csv', 'CSV header could not be parsed.')],
            'summary' => ['locales' => [], 'namespaces' => [], 'tenant_ids' => []],
        ];
    }

    $headerMap = videochat_translation_csv_header_map($header);
    foreach (['locale', 'namespace', 'resource_key', 'value'] as $requiredField) {
        if (!array_key_exists($requiredField, $headerMap)) {
            $errors[] = videochat_translation_error(1, $requiredField, 'missing_required_column', "CSV column {$requiredField} is required.");
        }
    }
    if ($errors !== []) {
        fclose($handle);
        return [
            'ok' => false,
            'total_rows' => 0,
            'valid_rows' => 0,
            'error_count' => count($errors),
            'resources' => [],
            'errors' => $errors,
            'summary' => ['locales' => [], 'namespaces' => [], 'tenant_ids' => []],
        ];
    }

    $rowNumber = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if ($row === [null] || trim(implode('', array_map('strval', $row))) === '') {
            continue;
        }

        $locale = videochat_normalize_locale_code(videochat_translation_csv_cell($row, $headerMap, 'locale'));
        $namespace = videochat_translation_clean_text(videochat_translation_csv_cell($row, $headerMap, 'namespace'), 120);
        $resourceKey = videochat_translation_clean_text(videochat_translation_csv_cell($row, $headerMap, 'resource_key'), 240);
        $value = (string) videochat_translation_csv_cell($row, $headerMap, 'value');
        $tenantRaw = trim(videochat_translation_csv_cell($row, $headerMap, 'tenant_id'));
        $tenantId = $tenantRaw !== '' ? (int) $tenantRaw : $defaultTenantId;
        if (!is_int($tenantId) || $tenantId <= 0) {
            $tenantId = null;
        }

        $rowErrors = [];
        if ($locale === '' || !videochat_locale_is_supported($pdo, $locale)) {
            $rowErrors[] = videochat_translation_error($rowNumber, 'locale', 'unsupported_locale', 'Locale is not supported.');
        }
        if ($namespace === '') {
            $rowErrors[] = videochat_translation_error($rowNumber, 'namespace', 'required_namespace', 'Namespace is required.');
        } elseif (preg_match('/^[a-z][a-z0-9_.-]{0,119}$/', $namespace) !== 1) {
            $rowErrors[] = videochat_translation_error($rowNumber, 'namespace', 'invalid_namespace', 'Namespace must be a stable lowercase key.');
        }
        if ($resourceKey === '') {
            $rowErrors[] = videochat_translation_error($rowNumber, 'resource_key', 'required_resource_key', 'Resource key is required.');
        } elseif (preg_match('/^[A-Za-z0-9_.-]{1,240}$/', $resourceKey) !== 1) {
            $rowErrors[] = videochat_translation_error($rowNumber, 'resource_key', 'invalid_resource_key', 'Resource key contains unsupported characters.');
        }
        if (!videochat_translation_tenant_exists($pdo, $tenantId)) {
            $rowErrors[] = videochat_translation_error($rowNumber, 'tenant_id', 'unknown_tenant', 'Tenant does not exist or is not active.');
        }

        $duplicateKey = (string) ($tenantId ?? 0) . '|' . $locale . '|' . $namespace . '|' . $resourceKey;
        if (isset($seen[$duplicateKey])) {
            $rowErrors[] = videochat_translation_error($rowNumber, 'resource_key', 'duplicate_key', 'Duplicate locale namespace key in CSV.');
        }
        $seen[$duplicateKey] = true;

        if ($rowErrors !== []) {
            array_push($errors, ...$rowErrors);
            continue;
        }

        $resources[] = [
            'row' => $rowNumber,
            'tenant_id' => $tenantId,
            'locale' => $locale,
            'namespace' => $namespace,
            'resource_key' => $resourceKey,
            'value' => $value,
        ];
    }
    fclose($handle);

    $locales = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['locale'], $resources)));
    sort($locales);
    $namespaces = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['namespace'], $resources)));
    sort($namespaces);
    $tenantIds = array_values(array_unique(array_filter(array_map(static fn (array $row): ?int => $row['tenant_id'], $resources))));
    sort($tenantIds);

    return [
        'ok' => $errors === [] && $resources !== [],
        'total_rows' => count($resources) + count($errors),
        'valid_rows' => count($resources),
        'error_count' => count($errors),
        'resources' => $resources,
        'errors' => $errors,
        'summary' => [
            'locales' => $locales,
            'namespaces' => $namespaces,
            'tenant_ids' => $tenantIds,
        ],
    ];
}

function videochat_issue_translation_import_id(PDO $pdo): string
{
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $candidate = 'trimp_' . bin2hex(random_bytes(12));
        $statement = $pdo->prepare('SELECT 1 FROM translation_imports WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $candidate]);
        if (!$statement->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException('translation_import_id_issue_failed');
}

function videochat_upsert_translation_resource(PDO $pdo, array $resource, int $actorUserId, string $source): void
{
    $tenantId = is_int($resource['tenant_id'] ?? null) && (int) $resource['tenant_id'] > 0 ? (int) $resource['tenant_id'] : null;
    $tenantWhere = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant_id';
    $select = $pdo->prepare(
        "SELECT id FROM translation_resources WHERE {$tenantWhere} AND locale = :locale AND namespace = :namespace AND resource_key = :resource_key LIMIT 1"
    );
    $params = [
        ':locale' => (string) $resource['locale'],
        ':namespace' => (string) $resource['namespace'],
        ':resource_key' => (string) $resource['resource_key'],
    ];
    if ($tenantId !== null) {
        $params[':tenant_id'] = $tenantId;
    }
    $select->execute($params);
    $existingId = (int) $select->fetchColumn();

    if ($existingId > 0) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE translation_resources
SET value = :value,
    source = :source,
    created_by_user_id = :actor_user_id,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $update->execute([
            ':value' => (string) $resource['value'],
            ':source' => $source,
            ':actor_user_id' => $actorUserId,
            ':updated_at' => gmdate('c'),
            ':id' => $existingId,
        ]);
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value, source, created_by_user_id, created_at, updated_at)
VALUES(:tenant_id, :locale, :namespace, :resource_key, :value, :source, :actor_user_id, :created_at, :updated_at)
SQL
    );
    $now = gmdate('c');
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':locale' => (string) $resource['locale'],
        ':namespace' => (string) $resource['namespace'],
        ':resource_key' => (string) $resource['resource_key'],
        ':value' => (string) $resource['value'],
        ':source' => $source,
        ':actor_user_id' => $actorUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_commit_translation_csv(PDO $pdo, int $actorUserId, string $csv, string $fileName = '', ?int $defaultTenantId = null): array
{
    $preview = videochat_preview_translation_csv($pdo, $csv, $defaultTenantId);
    if (!(bool) ($preview['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => 'validation_failed',
            'preview' => $preview,
            'import' => null,
        ];
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $importId = videochat_issue_translation_import_id($pdo);
        $source = 'csv:' . $importId;
        foreach ($preview['resources'] as $resource) {
            videochat_upsert_translation_resource($pdo, $resource, $actorUserId, $source);
        }

        $tenantIds = is_array($preview['summary']['tenant_ids'] ?? null) ? (array) $preview['summary']['tenant_ids'] : [];
        $tenantId = count($tenantIds) === 1 ? (int) $tenantIds[0] : null;
        $now = gmdate('c');
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO translation_imports(id, tenant_id, imported_by_user_id, file_name, status, row_count, error_count, summary_json, errors_json, created_at, committed_at)
VALUES(:id, :tenant_id, :imported_by_user_id, :file_name, 'committed', :row_count, 0, :summary_json, '[]', :created_at, :committed_at)
SQL
        );
        $insert->execute([
            ':id' => $importId,
            ':tenant_id' => $tenantId,
            ':imported_by_user_id' => $actorUserId,
            ':file_name' => videochat_translation_clean_text($fileName, 255),
            ':row_count' => (int) ($preview['valid_rows'] ?? 0),
            ':summary_json' => json_encode($preview['summary'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':created_at' => $now,
            ':committed_at' => $now,
        ]);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'reason' => 'committed',
            'preview' => $preview,
            'import' => videochat_fetch_translation_import($pdo, $importId),
        ];
    } catch (Throwable) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'reason' => 'commit_failed',
            'preview' => $preview,
            'import' => null,
        ];
    }
}

function videochat_decode_json_column(mixed $value, array $fallback): array
{
    $decoded = json_decode(is_string($value) ? $value : '', true);
    return is_array($decoded) ? $decoded : $fallback;
}

function videochat_normalize_translation_import_row(array $row): array
{
    return [
        'id' => (string) ($row['id'] ?? ''),
        'tenant_id' => isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
        'imported_by_user_id' => (int) ($row['imported_by_user_id'] ?? 0),
        'file_name' => (string) ($row['file_name'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'row_count' => (int) ($row['row_count'] ?? 0),
        'error_count' => (int) ($row['error_count'] ?? 0),
        'summary' => videochat_decode_json_column($row['summary_json'] ?? '', []),
        'errors' => videochat_decode_json_column($row['errors_json'] ?? '', []),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'committed_at' => is_string($row['committed_at'] ?? null) ? (string) $row['committed_at'] : null,
    ];
}

function videochat_list_translation_imports(PDO $pdo, int $page = 1, int $pageSize = 20): array
{
    $safePage = max(1, $page);
    $safePageSize = max(1, min(100, $pageSize));
    $total = (int) $pdo->query('SELECT COUNT(*) FROM translation_imports')->fetchColumn();
    $pageCount = max(1, (int) ceil($total / $safePageSize));
    $safePage = min($safePage, $pageCount);
    $offset = ($safePage - 1) * $safePageSize;

    $statement = $pdo->prepare('SELECT * FROM translation_imports ORDER BY created_at DESC LIMIT :limit OFFSET :offset');
    $statement->bindValue(':limit', $safePageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();

    $rows = [];
    foreach ($statement as $row) {
        $rows[] = videochat_normalize_translation_import_row($row);
    }

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $safePage,
        'page_size' => $safePageSize,
        'page_count' => $pageCount,
    ];
}

function videochat_fetch_translation_import(PDO $pdo, string $importId): ?array
{
    $id = trim($importId);
    if ($id === '') {
        return null;
    }
    $statement = $pdo->prepare('SELECT * FROM translation_imports WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $id]);
    $row = $statement->fetch();
    return is_array($row) ? videochat_normalize_translation_import_row($row) : null;
}

function videochat_list_translation_bundles(PDO $pdo): array
{
    $statement = $pdo->query(
        <<<'SQL'
SELECT tenant_id, locale, namespace, COUNT(*) AS resource_count, MAX(updated_at) AS updated_at
FROM translation_resources
GROUP BY tenant_id, locale, namespace
ORDER BY locale ASC, namespace ASC, tenant_id ASC
SQL
    );
    $rows = [];
    foreach ($statement as $row) {
        $rows[] = [
            'tenant_id' => isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
            'locale' => (string) ($row['locale'] ?? ''),
            'namespace' => (string) ($row['namespace'] ?? ''),
            'resource_count' => (int) ($row['resource_count'] ?? 0),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function videochat_fetch_translation_bundle(PDO $pdo, string $locale, string $namespace, ?int $tenantId = null): ?array
{
    $resolvedLocale = videochat_resolve_locale_code($pdo, $locale);
    $resolvedNamespace = videochat_translation_clean_text($namespace, 120);
    if ($resolvedNamespace === '') {
        return null;
    }

    $tenantWhere = $tenantId !== null && $tenantId > 0 ? 'tenant_id = :tenant_id' : 'tenant_id IS NULL';
    $statement = $pdo->prepare(
        "SELECT id, resource_key, value, source, updated_at FROM translation_resources WHERE {$tenantWhere} AND locale = :locale AND namespace = :namespace ORDER BY resource_key ASC"
    );
    $params = [
        ':locale' => $resolvedLocale,
        ':namespace' => $resolvedNamespace,
    ];
    if ($tenantId !== null && $tenantId > 0) {
        $params[':tenant_id'] = $tenantId;
    }
    $statement->execute($params);

    $resources = [];
    foreach ($statement as $row) {
        $resources[] = [
            'id' => (int) ($row['id'] ?? 0),
            'resource_key' => (string) ($row['resource_key'] ?? ''),
            'value' => (string) ($row['value'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return [
        'tenant_id' => $tenantId !== null && $tenantId > 0 ? $tenantId : null,
        'locale' => $resolvedLocale,
        'namespace' => $resolvedNamespace,
        'resources' => $resources,
    ];
}
