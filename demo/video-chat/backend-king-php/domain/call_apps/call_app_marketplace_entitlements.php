<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_mcp_metadata.php';

function videochat_call_app_marketplace_generate_public_id(string $prefix): string
{
    try {
        $uuid = sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            substr(bin2hex(random_bytes(2)), 1),
            substr('89ab', random_int(0, 3), 1),
            substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6))
        );
    } catch (Throwable) {
        $uuid = hash('sha256', uniqid($prefix, true) . microtime(true));
    }

    return $prefix . '_' . strtolower($uuid);
}

function videochat_call_app_marketplace_json(mixed $value, string $fallback): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : $fallback;
}

function videochat_call_app_marketplace_decode_json(string $value, mixed $fallback): mixed
{
    $decoded = json_decode($value, true);
    return $decoded === null && strtolower(trim($value)) !== 'null' ? $fallback : $decoded;
}

/**
 * @return array{ok: bool, entry?: array<string, mixed>, errors?: array<string, string>}
 */
function videochat_call_app_catalog_entry_from_package(array $package, ?string $packageRoot = null): array
{
    $validation = videochat_call_app_mcp_validate_package($package);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => is_array($validation['errors'] ?? null) ? $validation['errors'] : []];
    }

    $appKey = (string) ($package['app_key'] ?? '');
    $methodResults = [];
    foreach (videochat_call_app_mcp_supported_methods() as $method) {
        $response = videochat_call_app_mcp_handle_request([
            'method' => $method,
            'params' => ['app_key' => $appKey],
        ], $packageRoot);
        if (!(bool) ($response['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => ['mcp.' . $method => (string) ($response['reason'] ?? 'failed')],
            ];
        }
        $methodResults[$method] = is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    $service = videochat_call_app_semantic_dns_service_payload($package);
    $describe = $methodResults['call_app.describe'] ?? [];
    $listing = $methodResults['call_app.marketplace_listing'] ?? [];
    $capabilities = $methodResults['call_app.capabilities'] ?? [];
    $launch = $methodResults['call_app.launch_contract'] ?? [];
    $crdt = $methodResults['call_app.crdt_schema'] ?? [];
    $health = $methodResults['call_app.health'] ?? [];
    $exports = $methodResults['call_app.export_formats']['formats'] ?? [];

    return [
        'ok' => true,
        'entry' => [
            'app_key' => $appKey,
            'app_version' => (string) ($package['version'] ?? ''),
            'name' => (string) ($listing['name'] ?? ($describe['name'] ?? '')),
            'description' => (string) ($describe['description'] ?? ''),
            'category' => (string) ($listing['category'] ?? ($describe['category'] ?? 'other')),
            'manufacturer' => (string) ($describe['manufacturer'] ?? ''),
            'service_id' => (string) ($service['service_id'] ?? ''),
            'service_name' => (string) ($service['service_name'] ?? ''),
            'mcp_endpoint' => (string) (($service['attributes'] ?? [])['mcp_endpoint'] ?? ''),
            'iframe_entrypoint' => (string) ($launch['iframe_entrypoint'] ?? ''),
            'crdt_protocol' => (string) ($crdt['protocol'] ?? ''),
            'health_status' => (string) ($health['status'] ?? ($package['health_status'] ?? 'unknown')),
            'metadata_hash' => (string) ($package['metadata_hash'] ?? ''),
            'listing' => $listing,
            'capabilities' => is_array($capabilities['capabilities'] ?? null) ? array_values($capabilities['capabilities']) : [],
            'export_formats' => is_array($exports) ? array_values($exports) : [],
        ],
    ];
}

function videochat_call_app_catalog_upsert(PDO $pdo, array $entry): void
{
    $now = gmdate('c');
    $existing = $pdo->prepare('SELECT id FROM call_app_catalog_entries WHERE app_key = :app_key AND app_version = :app_version LIMIT 1');
    $existing->execute([
        ':app_key' => (string) ($entry['app_key'] ?? ''),
        ':app_version' => (string) ($entry['app_version'] ?? ''),
    ]);
    $existingId = (int) $existing->fetchColumn();
    $params = [
        ':app_key' => (string) ($entry['app_key'] ?? ''),
        ':app_version' => (string) ($entry['app_version'] ?? ''),
        ':name' => (string) ($entry['name'] ?? ''),
        ':description' => (string) ($entry['description'] ?? ''),
        ':category' => (string) ($entry['category'] ?? 'other'),
        ':manufacturer' => (string) ($entry['manufacturer'] ?? ''),
        ':service_id' => (string) ($entry['service_id'] ?? ''),
        ':service_name' => (string) ($entry['service_name'] ?? ''),
        ':mcp_endpoint' => (string) ($entry['mcp_endpoint'] ?? ''),
        ':iframe_entrypoint' => (string) ($entry['iframe_entrypoint'] ?? ''),
        ':crdt_protocol' => (string) ($entry['crdt_protocol'] ?? ''),
        ':health_status' => (string) ($entry['health_status'] ?? 'unknown'),
        ':metadata_hash' => (string) ($entry['metadata_hash'] ?? ''),
        ':listing_json' => videochat_call_app_marketplace_json($entry['listing'] ?? [], '{}'),
        ':capabilities_json' => videochat_call_app_marketplace_json($entry['capabilities'] ?? [], '[]'),
        ':export_formats_json' => videochat_call_app_marketplace_json($entry['export_formats'] ?? [], '[]'),
        ':verified_at' => $now,
        ':updated_at' => $now,
    ];

    if ($existingId > 0) {
        $updateParams = $params;
        unset($updateParams[':app_key'], $updateParams[':app_version']);
        $updateParams[':id'] = $existingId;
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE call_app_catalog_entries
SET name = :name,
    description = :description,
    category = :category,
    manufacturer = :manufacturer,
    service_id = :service_id,
    service_name = :service_name,
    mcp_endpoint = :mcp_endpoint,
    iframe_entrypoint = :iframe_entrypoint,
    crdt_protocol = :crdt_protocol,
    health_status = :health_status,
    metadata_hash = :metadata_hash,
    listing_json = :listing_json,
    capabilities_json = :capabilities_json,
    export_formats_json = :export_formats_json,
    verified_at = :verified_at,
    updated_at = :updated_at
WHERE id = :id
SQL
        );
        $update->execute($updateParams);
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_app_catalog_entries(
    app_key, app_version, name, description, category, manufacturer,
    service_id, service_name, mcp_endpoint, iframe_entrypoint, crdt_protocol,
    health_status, metadata_hash, listing_json, capabilities_json,
    export_formats_json, verified_at, created_at, updated_at
) VALUES(
    :app_key, :app_version, :name, :description, :category, :manufacturer,
    :service_id, :service_name, :mcp_endpoint, :iframe_entrypoint, :crdt_protocol,
    :health_status, :metadata_hash, :listing_json, :capabilities_json,
    :export_formats_json, :verified_at, :updated_at, :updated_at
)
SQL
    );
    $insert->execute($params);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_catalog_row(array $row): array
{
    return [
        'app_key' => (string) ($row['app_key'] ?? ''),
        'version' => (string) ($row['app_version'] ?? ''),
        'name' => (string) ($row['name'] ?? ''),
        'description' => (string) ($row['description'] ?? ''),
        'category' => (string) ($row['category'] ?? 'other'),
        'manufacturer' => (string) ($row['manufacturer'] ?? ''),
        'service_id' => (string) ($row['service_id'] ?? ''),
        'service_name' => (string) ($row['service_name'] ?? ''),
        'mcp_endpoint' => (string) ($row['mcp_endpoint'] ?? ''),
        'iframe_entrypoint' => (string) ($row['iframe_entrypoint'] ?? ''),
        'crdt_protocol' => (string) ($row['crdt_protocol'] ?? ''),
        'health_status' => (string) ($row['health_status'] ?? 'unknown'),
        'metadata_hash' => (string) ($row['metadata_hash'] ?? ''),
        'listing' => videochat_call_app_marketplace_decode_json((string) ($row['listing_json'] ?? '{}'), []),
        'capabilities' => videochat_call_app_marketplace_decode_json((string) ($row['capabilities_json'] ?? '[]'), []),
        'export_formats' => videochat_call_app_marketplace_decode_json((string) ($row['export_formats_json'] ?? '[]'), []),
        'verified_at' => (string) ($row['verified_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_refresh_catalog(PDO $pdo, ?string $packageRoot = null): array
{
    $entries = [];
    $invalid = [];
    foreach (videochat_call_app_scan_packages($packageRoot) as $package) {
        $entryResult = videochat_call_app_catalog_entry_from_package($package, $packageRoot);
        if (!(bool) ($entryResult['ok'] ?? false)) {
            $invalid[] = [
                'app_key' => (string) ($package['app_key'] ?? ''),
                'errors' => is_array($entryResult['errors'] ?? null) ? $entryResult['errors'] : [],
            ];
            continue;
        }
        $entry = is_array($entryResult['entry'] ?? null) ? $entryResult['entry'] : [];
        videochat_call_app_catalog_upsert($pdo, $entry);
        $entries[] = $entry;
    }

    return [
        'ok' => $invalid === [],
        'entries' => $entries,
        'invalid' => $invalid,
        'refreshed_at' => gmdate('c'),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_call_app_list_catalog(PDO $pdo, string $query = '', string $category = 'all'): array
{
    $where = [];
    $params = [];
    if ($category !== '' && $category !== 'all') {
        $where[] = 'category = :category';
        $params[':category'] = $category;
    }
    if (trim($query) !== '') {
        $where[] = '(lower(name) LIKE :search OR lower(app_key) LIKE :search OR lower(description) LIKE :search)';
        $params[':search'] = '%' . strtolower(trim($query)) . '%';
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
    $statement = $pdo->prepare(
        'SELECT * FROM call_app_catalog_entries ' . $whereSql . ' ORDER BY lower(name) ASC, app_key ASC, app_version DESC'
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $row): array => videochat_call_app_catalog_row($row), is_array($rows) ? $rows : []);
}

function videochat_call_app_fetch_catalog_entry(PDO $pdo, string $appKey, string $version = ''): ?array
{
    $params = [':app_key' => strtolower(trim($appKey))];
    $versionWhere = '';
    if (trim($version) !== '') {
        $versionWhere = 'AND app_version = :app_version';
        $params[':app_version'] = trim($version);
    }
    $statement = $pdo->prepare(
        <<<SQL
SELECT *
FROM call_app_catalog_entries
WHERE lower(app_key) = :app_key
  {$versionWhere}
ORDER BY verified_at DESC, app_version DESC
LIMIT 1
SQL
    );
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? videochat_call_app_catalog_row($row) : null;
}

/**
 * @return array<string, mixed>|null
 */
function videochat_call_app_fetch_entitlement(PDO $pdo, int $tenantId, string $appKey, string $version): ?array
{
    $statement = $pdo->prepare(
        'SELECT * FROM organization_call_app_entitlements WHERE tenant_id = :tenant_id AND app_key = :app_key AND app_version = :app_version LIMIT 1'
    );
    $statement->execute([':tenant_id' => $tenantId, ':app_key' => $appKey, ':app_version' => $version]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? videochat_call_app_entitlement_row($row) : null;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_entitlement_row(array $row): array
{
    return [
        'id' => (string) ($row['public_id'] ?? ''),
        'tenant_id' => (int) ($row['tenant_id'] ?? 0),
        'app_key' => (string) ($row['app_key'] ?? ''),
        'version' => (string) ($row['app_version'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'plan_license' => (string) ($row['plan_license'] ?? 'organization'),
        'ordered_by_user_id' => (int) ($row['ordered_by_user_id'] ?? 0),
        'ordered_at' => (string) ($row['ordered_at'] ?? ''),
        'expires_at' => is_string($row['expires_at'] ?? null) ? (string) $row['expires_at'] : null,
        'marketplace_order_reference' => (string) ($row['marketplace_order_reference'] ?? ''),
        'metadata_hash' => (string) ($row['metadata_hash'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_create_organization_order(PDO $pdo, int $tenantId, int $actorUserId, string $appKey, array $payload = []): array
{
    if ($tenantId <= 0 || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_tenant_context'];
    }
    foreach (['tenant_id', 'organization_id', 'user_id', 'owner_user_id'] as $forbiddenField) {
        if (array_key_exists($forbiddenField, $payload)) {
            return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [$forbiddenField => 'not_client_selectable']];
        }
    }

    $catalogEntry = videochat_call_app_fetch_catalog_entry($pdo, $appKey);
    if (!is_array($catalogEntry)) {
        return ['ok' => false, 'reason' => 'app_not_found'];
    }

    $now = gmdate('c');
    $existing = videochat_call_app_fetch_entitlement($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']);
    if (is_array($existing)) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE organization_call_app_entitlements
SET status = 'active',
    ordered_by_user_id = :ordered_by_user_id,
    ordered_at = :ordered_at,
    metadata_hash = :metadata_hash,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_key = :app_key
  AND app_version = :app_version
SQL
        );
        $update->execute([
            ':ordered_by_user_id' => $actorUserId,
            ':ordered_at' => $now,
            ':metadata_hash' => (string) ($catalogEntry['metadata_hash'] ?? ''),
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
            ':app_key' => (string) $catalogEntry['app_key'],
            ':app_version' => (string) $catalogEntry['version'],
        ]);
        return [
            'ok' => true,
            'state' => $existing['status'] === 'active' ? 'existing' : 'reactivated',
            'entitlement' => videochat_call_app_fetch_entitlement($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']),
            'catalog_entry' => $catalogEntry,
        ];
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_call_app_entitlements(
    public_id, tenant_id, app_key, app_version, status, plan_license,
    ordered_by_user_id, ordered_at, expires_at, marketplace_order_reference,
    metadata_hash, created_at, updated_at
) VALUES(
    :public_id, :tenant_id, :app_key, :app_version, 'active', :plan_license,
    :ordered_by_user_id, :ordered_at, NULL, :marketplace_order_reference,
    :metadata_hash, :created_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_call_app_marketplace_generate_public_id('ent'),
        ':tenant_id' => $tenantId,
        ':app_key' => (string) $catalogEntry['app_key'],
        ':app_version' => (string) $catalogEntry['version'],
        ':plan_license' => (string) (($catalogEntry['listing'] ?? [])['default_license'] ?? 'organization'),
        ':ordered_by_user_id' => $actorUserId,
        ':ordered_at' => $now,
        ':marketplace_order_reference' => 'order_' . bin2hex(random_bytes(8)),
        ':metadata_hash' => (string) ($catalogEntry['metadata_hash'] ?? ''),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return [
        'ok' => true,
        'state' => 'created',
        'entitlement' => videochat_call_app_fetch_entitlement($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']),
        'catalog_entry' => $catalogEntry,
    ];
}

function videochat_call_app_fetch_installation(PDO $pdo, int $tenantId, string $appKey, string $version, string $identifier = ''): ?array
{
    $params = [':tenant_id' => $tenantId, ':app_key' => $appKey, ':app_version' => $version];
    $identifierWhere = '';
    if (trim($identifier) !== '') {
        $identifierWhere = 'AND public_id = :public_id';
        $params[':public_id'] = trim($identifier);
    }
    $statement = $pdo->prepare(
        <<<SQL
SELECT *
FROM organization_call_app_installations
WHERE tenant_id = :tenant_id
  AND app_key = :app_key
  AND app_version = :app_version
  {$identifierWhere}
LIMIT 1
SQL
    );
    $statement->execute($params);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? videochat_call_app_installation_row($row) : null;
}

function videochat_call_app_installation_row(array $row): array
{
    return [
        'id' => (string) ($row['public_id'] ?? ''),
        'tenant_id' => (int) ($row['tenant_id'] ?? 0),
        'entitlement_id' => (int) ($row['entitlement_id'] ?? 0),
        'app_key' => (string) ($row['app_key'] ?? ''),
        'version' => (string) ($row['app_version'] ?? ''),
        'status' => (string) ($row['status'] ?? 'disabled'),
        'config' => videochat_call_app_marketplace_decode_json((string) ($row['config_json'] ?? '{}'), []),
        'default_app_policy' => (string) ($row['default_app_policy'] ?? 'blocked_by_default'),
        'installed_by_user_id' => (int) ($row['installed_by_user_id'] ?? 0),
        'installed_at' => (string) ($row['installed_at'] ?? ''),
        'disabled_at' => is_string($row['disabled_at'] ?? null) ? (string) $row['disabled_at'] : null,
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function videochat_call_app_entitlement_is_active(?array $entitlement, ?int $nowEpoch = null): bool
{
    if (!is_array($entitlement) || (string) ($entitlement['status'] ?? '') !== 'active') {
        return false;
    }

    $expiresAt = trim((string) ($entitlement['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return true;
    }

    $expiresEpoch = strtotime($expiresAt);
    if ($expiresEpoch === false) {
        return false;
    }

    return $expiresEpoch > ($nowEpoch ?? time());
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_organization_state(PDO $pdo, int $tenantId, string $appKey, string $version): array
{
    $entitlement = $tenantId > 0 ? videochat_call_app_fetch_entitlement($pdo, $tenantId, $appKey, $version) : null;
    $installation = $tenantId > 0 ? videochat_call_app_fetch_installation($pdo, $tenantId, $appKey, $version) : null;
    $ordered = videochat_call_app_entitlement_is_active($entitlement);
    $installed = $ordered && is_array($installation) && (string) ($installation['status'] ?? '') === 'enabled';
    $status = 'not_installed';
    if ($installed) {
        $status = 'installed';
    } elseif ($ordered && is_array($installation) && (string) ($installation['status'] ?? '') === 'disabled') {
        $status = 'disabled';
    } elseif ($ordered) {
        $status = 'ordered';
    }

    return [
        'tenant_id' => $tenantId,
        'status' => $status,
        'ordered' => $ordered,
        'installed' => $installed,
        'entitlement' => $entitlement,
        'installation' => $installation,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_app_organization_actions(string $appKey, array $organization): array
{
    $encodedAppKey = rawurlencode(trim($appKey));
    $installed = (bool) ($organization['installed'] ?? false);
    $ordered = (bool) ($organization['ordered'] ?? false);

    return [
        'add_to_organization' => [
            'available' => !$installed,
            'method' => 'POST_SEQUENCE',
            'steps' => [
                [
                    'name' => 'order',
                    'method' => 'POST',
                    'path' => '/api/marketplace/call-apps/' . $encodedAppKey . '/orders',
                    'required' => !$ordered,
                    'idempotent' => true,
                ],
                [
                    'name' => 'install',
                    'method' => 'POST',
                    'path' => '/api/marketplace/call-apps/' . $encodedAppKey . '/installations',
                    'required' => !$installed,
                    'idempotent' => true,
                ],
            ],
        ],
        'verify_installation' => [
            'available' => $installed,
            'method' => 'POST',
            'path' => '/api/marketplace/call-apps/' . $encodedAppKey . '/installations',
            'idempotent' => true,
        ],
    ];
}

/**
 * @param array<int, array<string, mixed>> $catalogApps
 * @return array<int, array<string, mixed>>
 */
function videochat_call_app_attach_organization_state(PDO $pdo, int $tenantId, array $catalogApps): array
{
    return array_map(static function (array $app) use ($pdo, $tenantId): array {
        $appKey = (string) ($app['app_key'] ?? '');
        $version = (string) ($app['version'] ?? '');
        $app['organization'] = videochat_call_app_organization_state($pdo, $tenantId, $appKey, $version);
        $app['organization_actions'] = videochat_call_app_organization_actions($appKey, $app['organization']);
        return $app;
    }, $catalogApps);
}

function videochat_call_app_create_organization_installation(PDO $pdo, int $tenantId, int $actorUserId, string $appKey, array $payload = []): array
{
    if ($tenantId <= 0 || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_tenant_context'];
    }
    foreach (['tenant_id', 'organization_id', 'user_id', 'owner_user_id'] as $forbiddenField) {
        if (array_key_exists($forbiddenField, $payload)) {
            return ['ok' => false, 'reason' => 'validation_failed', 'errors' => [$forbiddenField => 'not_client_selectable']];
        }
    }
    $catalogEntry = videochat_call_app_fetch_catalog_entry($pdo, $appKey);
    if (!is_array($catalogEntry)) {
        return ['ok' => false, 'reason' => 'app_not_found'];
    }
    $entitlement = videochat_call_app_fetch_entitlement($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']);
    if (!is_array($entitlement) || (string) ($entitlement['status'] ?? '') !== 'active') {
        return ['ok' => false, 'reason' => 'entitlement_required'];
    }

    $policy = (string) ($payload['default_app_policy'] ?? 'blocked_by_default');
    if (!in_array($policy, ['allowed_by_default', 'blocked_by_default'], true)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['default_app_policy' => 'must_be_known_policy']];
    }
    $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
    $now = gmdate('c');
    $existing = videochat_call_app_fetch_installation($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']);
    if (is_array($existing)) {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE organization_call_app_installations
SET status = 'enabled',
    config_json = :config_json,
    default_app_policy = :default_app_policy,
    installed_by_user_id = :installed_by_user_id,
    installed_at = :installed_at,
    disabled_at = NULL,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND app_key = :app_key
  AND app_version = :app_version
SQL
        );
        $update->execute([
            ':config_json' => videochat_call_app_marketplace_json($config, '{}'),
            ':default_app_policy' => $policy,
            ':installed_by_user_id' => $actorUserId,
            ':installed_at' => $now,
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
            ':app_key' => (string) $catalogEntry['app_key'],
            ':app_version' => (string) $catalogEntry['version'],
        ]);
        return [
            'ok' => true,
            'state' => $existing['status'] === 'enabled' ? 'existing' : 'enabled',
            'installation' => videochat_call_app_fetch_installation($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']),
            'entitlement' => $entitlement,
        ];
    }

    $entitlementId = (int) $pdo->query(
        "SELECT id FROM organization_call_app_entitlements WHERE public_id = " . $pdo->quote((string) ($entitlement['id'] ?? '')) . " LIMIT 1"
    )->fetchColumn();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_call_app_installations(
    public_id, tenant_id, entitlement_id, app_key, app_version, status,
    config_json, default_app_policy, installed_by_user_id, installed_at,
    disabled_at, created_at, updated_at
) VALUES(
    :public_id, :tenant_id, :entitlement_id, :app_key, :app_version, 'enabled',
    :config_json, :default_app_policy, :installed_by_user_id, :installed_at,
    NULL, :created_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_call_app_marketplace_generate_public_id('inst'),
        ':tenant_id' => $tenantId,
        ':entitlement_id' => $entitlementId,
        ':app_key' => (string) $catalogEntry['app_key'],
        ':app_version' => (string) $catalogEntry['version'],
        ':config_json' => videochat_call_app_marketplace_json($config, '{}'),
        ':default_app_policy' => $policy,
        ':installed_by_user_id' => $actorUserId,
        ':installed_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return [
        'ok' => true,
        'state' => 'created',
        'installation' => videochat_call_app_fetch_installation($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version']),
        'entitlement' => $entitlement,
    ];
}

function videochat_call_app_update_organization_installation(PDO $pdo, int $tenantId, string $appKey, string $installationId, array $payload = []): array
{
    $catalogEntry = videochat_call_app_fetch_catalog_entry($pdo, $appKey);
    if (!is_array($catalogEntry)) {
        return ['ok' => false, 'reason' => 'app_not_found'];
    }
    $installation = videochat_call_app_fetch_installation($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version'], $installationId);
    if (!is_array($installation)) {
        return ['ok' => false, 'reason' => 'installation_not_found'];
    }
    $status = (string) ($payload['status'] ?? '');
    if (!in_array($status, ['enabled', 'disabled'], true)) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['status' => 'must_be_enabled_or_disabled']];
    }
    $now = gmdate('c');
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE organization_call_app_installations
SET status = :status,
    disabled_at = :disabled_at,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND public_id = :public_id
SQL
    );
    $update->execute([
        ':status' => $status,
        ':disabled_at' => $status === 'disabled' ? $now : null,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':public_id' => $installationId,
    ]);

    return [
        'ok' => true,
        'state' => $status,
        'installation' => videochat_call_app_fetch_installation($pdo, $tenantId, (string) $catalogEntry['app_key'], (string) $catalogEntry['version'], $installationId),
    ];
}
