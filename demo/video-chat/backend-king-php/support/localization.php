<?php

declare(strict_types=1);

/**
 * @return array<string, array{label: string, direction: string, is_default?: bool}>
 */
function videochat_supported_locale_definitions(): array
{
    return [
        'am' => ['label' => 'Amharic', 'direction' => 'ltr'],
        'ar' => ['label' => 'Arabic', 'direction' => 'rtl'],
        'bn' => ['label' => 'Bengali', 'direction' => 'ltr'],
        'de' => ['label' => 'Deutsch', 'direction' => 'ltr'],
        'en' => ['label' => 'English', 'direction' => 'ltr', 'is_default' => true],
        'es' => ['label' => 'Espanol', 'direction' => 'ltr'],
        'fa' => ['label' => 'Persian', 'direction' => 'rtl'],
        'fr' => ['label' => 'Francais', 'direction' => 'ltr'],
        'ha' => ['label' => 'Hausa', 'direction' => 'ltr'],
        'hi' => ['label' => 'Hindi', 'direction' => 'ltr'],
        'it' => ['label' => 'Italian', 'direction' => 'ltr'],
        'ja' => ['label' => 'Japanese', 'direction' => 'ltr'],
        'jv' => ['label' => 'Javanese', 'direction' => 'ltr'],
        'ko' => ['label' => 'Korean', 'direction' => 'ltr'],
        'my' => ['label' => 'Burmese', 'direction' => 'ltr'],
        'pa' => ['label' => 'Punjabi', 'direction' => 'ltr'],
        'ps' => ['label' => 'Pashto', 'direction' => 'rtl'],
        'pt' => ['label' => 'Portuguese', 'direction' => 'ltr'],
        'ru' => ['label' => 'Russian', 'direction' => 'ltr'],
        'sgd' => ['label' => 'Surigaonon', 'direction' => 'rtl'],
        'so' => ['label' => 'Somali', 'direction' => 'ltr'],
        'th' => ['label' => 'Thai', 'direction' => 'ltr'],
        'tl' => ['label' => 'Tagalog', 'direction' => 'ltr'],
        'tr' => ['label' => 'Turkish', 'direction' => 'ltr'],
        'uk' => ['label' => 'Ukrainian', 'direction' => 'ltr'],
        'uz' => ['label' => 'Uzbek', 'direction' => 'ltr'],
        'vi' => ['label' => 'Vietnamese', 'direction' => 'ltr'],
        'zh' => ['label' => 'Chinese', 'direction' => 'ltr'],
    ];
}

function videochat_default_locale_code(): string
{
    return 'en';
}

function videochat_normalize_locale_code(mixed $value): string
{
    $normalized = strtolower(trim(str_replace('_', '-', (string) ($value ?? ''))));
    if ($normalized === '' || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $normalized) !== 1) {
        return '';
    }

    return $normalized;
}

function videochat_locale_static_definition(string $code): ?array
{
    $definitions = videochat_supported_locale_definitions();
    return $definitions[$code] ?? null;
}

function videochat_locale_table_exists(PDO $pdo, string $tableName): bool
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableName) !== 1) {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :table_name LIMIT 1"
    );
    $statement->execute([':table_name' => $tableName]);
    return (bool) $statement->fetchColumn();
}

function videochat_locale_is_supported(PDO $pdo, mixed $value): bool
{
    $code = videochat_normalize_locale_code($value);
    if ($code === '') {
        return false;
    }

    if (videochat_locale_table_exists($pdo, 'supported_locales')) {
        $statement = $pdo->prepare(
            'SELECT 1 FROM supported_locales WHERE code = :code AND is_enabled = 1 LIMIT 1'
        );
        $statement->execute([':code' => $code]);
        return (bool) $statement->fetchColumn();
    }

    return videochat_locale_static_definition($code) !== null;
}

function videochat_resolve_locale_code(PDO $pdo, mixed $value): string
{
    $code = videochat_normalize_locale_code($value);
    if ($code !== '' && videochat_locale_is_supported($pdo, $code)) {
        return $code;
    }

    return videochat_default_locale_code();
}

/**
 * @return array<int, array{code: string, label: string, direction: string, is_default: bool}>
 */
function videochat_supported_locale_payload(PDO $pdo): array
{
    if (videochat_locale_table_exists($pdo, 'supported_locales')) {
        $rows = $pdo->query(
            <<<'SQL'
SELECT code, label, direction, is_default
FROM supported_locales
WHERE is_enabled = 1
ORDER BY label COLLATE NOCASE ASC, code ASC
SQL
        );
        $locales = [];
        foreach ($rows as $row) {
            $code = videochat_normalize_locale_code($row['code'] ?? '');
            $direction = strtolower(trim((string) ($row['direction'] ?? 'ltr')));
            if ($code === '' || !in_array($direction, ['ltr', 'rtl'], true)) {
                continue;
            }
            $locales[] = [
                'code' => $code,
                'label' => (string) ($row['label'] ?? strtoupper($code)),
                'direction' => $direction,
                'is_default' => ((int) ($row['is_default'] ?? 0)) === 1,
            ];
        }
        if ($locales !== []) {
            return $locales;
        }
    }

    $locales = [];
    foreach (videochat_supported_locale_definitions() as $code => $definition) {
        $locales[] = [
            'code' => $code,
            'label' => (string) ($definition['label'] ?? strtoupper($code)),
            'direction' => (string) ($definition['direction'] ?? 'ltr'),
            'is_default' => (bool) ($definition['is_default'] ?? false),
        ];
    }
    usort(
        $locales,
        static fn (array $left, array $right): int => strcasecmp((string) $left['label'], (string) $right['label'])
            ?: strcmp((string) $left['code'], (string) $right['code'])
    );

    return $locales;
}

function videochat_locale_direction_for_code(PDO $pdo, mixed $value): string
{
    $code = videochat_resolve_locale_code($pdo, $value);
    if (videochat_locale_table_exists($pdo, 'supported_locales')) {
        $statement = $pdo->prepare('SELECT direction FROM supported_locales WHERE code = :code LIMIT 1');
        $statement->execute([':code' => $code]);
        $direction = strtolower(trim((string) $statement->fetchColumn()));
        if (in_array($direction, ['ltr', 'rtl'], true)) {
            return $direction;
        }
    }

    $definition = videochat_locale_static_definition($code);
    return (string) (($definition['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr');
}

/**
 * @return array{locale: string, direction: string, supported_locales: array<int, array{code: string, label: string, direction: string, is_default: bool}>}
 */
function videochat_localization_payload(PDO $pdo, mixed $locale): array
{
    $resolvedLocale = videochat_resolve_locale_code($pdo, $locale);
    return [
        'locale' => $resolvedLocale,
        'direction' => videochat_locale_direction_for_code($pdo, $resolvedLocale),
        'supported_locales' => videochat_supported_locale_payload($pdo),
    ];
}

function videochat_locale_sql_quote(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * @return array<int, string>
 */
function videochat_localization_migration_statements(): array
{
    $localeRows = [];
    foreach (videochat_supported_locale_definitions() as $code => $definition) {
        $localeRows[] = '('
            . videochat_locale_sql_quote($code) . ', '
            . videochat_locale_sql_quote((string) ($definition['label'] ?? strtoupper($code))) . ', '
            . videochat_locale_sql_quote((string) ($definition['direction'] ?? 'ltr')) . ', '
            . '1, '
            . ((bool) ($definition['is_default'] ?? false) ? '1' : '0')
            . ", strftime('%Y-%m-%dT%H:%M:%fZ', 'now'), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))";
    }

    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS supported_locales (
    code TEXT PRIMARY KEY,
    label TEXT NOT NULL,
    direction TEXT NOT NULL CHECK (direction IN ('ltr', 'rtl')),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    is_default INTEGER NOT NULL DEFAULT 0 CHECK (is_default IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS translation_resources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    locale TEXT NOT NULL REFERENCES supported_locales(code) ON UPDATE CASCADE ON DELETE CASCADE,
    namespace TEXT NOT NULL,
    resource_key TEXT NOT NULL,
    value TEXT NOT NULL,
    source TEXT NOT NULL DEFAULT 'seed',
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (trim(namespace) <> ''),
    CHECK (trim(resource_key) <> '')
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_translation_resources_lookup ON translation_resources(locale, namespace, resource_key)',
        'CREATE INDEX IF NOT EXISTS idx_translation_resources_tenant_locale ON translation_resources(tenant_id, locale, namespace)',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_translation_resources_scope_key ON translation_resources(COALESCE(tenant_id, 0), locale, namespace, resource_key)',
        'INSERT OR REPLACE INTO supported_locales(code, label, direction, is_enabled, is_default, created_at, updated_at) VALUES ' . implode(",\n", $localeRows),
        "ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'en'",
        <<<'SQL'
UPDATE users
SET locale = 'en'
WHERE locale IS NULL
   OR trim(locale) = ''
   OR lower(locale) NOT IN (SELECT code FROM supported_locales WHERE is_enabled = 1)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_users_locale ON users(locale)',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_translation_import_history_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS translation_imports (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE SET NULL,
    imported_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    file_name TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL CHECK (status IN ('committed', 'failed')),
    row_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    summary_json TEXT NOT NULL DEFAULT '{}',
    errors_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    committed_at TEXT
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_translation_imports_created_at ON translation_imports(created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_translation_imports_actor ON translation_imports(imported_by_user_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_translation_imports_tenant ON translation_imports(tenant_id, created_at DESC)',
    ];
}

/**
 * @return array<string, string>
 */
function videochat_fetch_translation_resources(PDO $pdo, string $locale, ?int $tenantId = null, array $namespaces = []): array
{
    if (!videochat_locale_table_exists($pdo, 'translation_resources')) {
        return [];
    }

    $resolvedLocale = videochat_resolve_locale_code($pdo, $locale);
    $params = [':locale' => $resolvedLocale];
    $tenantClause = 'translation_resources.tenant_id IS NULL';
    $tenantOrder = '0';
    if (is_int($tenantId) && $tenantId > 0) {
        $tenantClause = '(translation_resources.tenant_id IS NULL OR translation_resources.tenant_id = :tenant_id)';
        $tenantOrder = 'CASE WHEN translation_resources.tenant_id = :tenant_id THEN 0 ELSE 1 END';
        $params[':tenant_id'] = $tenantId;
    }

    $namespaceClause = '';
    $normalizedNamespaces = [];
    foreach ($namespaces as $index => $namespace) {
        $normalized = trim((string) $namespace);
        if ($normalized === '') {
            continue;
        }
        $placeholder = ':namespace_' . count($normalizedNamespaces);
        $params[$placeholder] = $normalized;
        $normalizedNamespaces[] = $placeholder;
    }
    if ($normalizedNamespaces !== []) {
        $namespaceClause = 'AND translation_resources.namespace IN (' . implode(', ', $normalizedNamespaces) . ')';
    }

    $statement = $pdo->prepare(
        <<<SQL
SELECT namespace, resource_key, value
FROM translation_resources
WHERE translation_resources.locale = :locale
  AND {$tenantClause}
  {$namespaceClause}
ORDER BY {$tenantOrder} ASC, translation_resources.namespace ASC, translation_resources.resource_key ASC
SQL
    );
    $statement->execute($params);

    $resources = [];
    foreach ($statement as $row) {
        $key = trim((string) ($row['namespace'] ?? '')) . '.' . trim((string) ($row['resource_key'] ?? ''));
        if ($key === '.' || isset($resources[$key])) {
            continue;
        }
        $resources[$key] = (string) ($row['value'] ?? '');
    }

    return $resources;
}
