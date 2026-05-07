<?php

declare(strict_types=1);

function videochat_call_app_marketplace_entitlement_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_catalog_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_key TEXT NOT NULL,
    app_version TEXT NOT NULL,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'other',
    manufacturer TEXT NOT NULL DEFAULT '',
    service_id TEXT NOT NULL,
    service_name TEXT NOT NULL,
    mcp_endpoint TEXT NOT NULL,
    iframe_entrypoint TEXT NOT NULL,
    crdt_protocol TEXT NOT NULL,
    health_status TEXT NOT NULL DEFAULT 'unknown',
    metadata_hash TEXT NOT NULL,
    listing_json TEXT NOT NULL DEFAULT '{}',
    capabilities_json TEXT NOT NULL DEFAULT '[]',
    export_formats_json TEXT NOT NULL DEFAULT '[]',
    verified_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(app_key, app_version)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_catalog_entries_category ON call_app_catalog_entries(category, name)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_catalog_entries_health ON call_app_catalog_entries(health_status, verified_at DESC)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS organization_call_app_entitlements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_key TEXT NOT NULL,
    app_version TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled', 'revoked')),
    plan_license TEXT NOT NULL DEFAULT 'organization',
    ordered_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    ordered_at TEXT NOT NULL,
    expires_at TEXT,
    marketplace_order_reference TEXT NOT NULL DEFAULT '',
    metadata_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, app_key, app_version)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_org_call_app_entitlements_tenant_status ON organization_call_app_entitlements(tenant_id, status, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_org_call_app_entitlements_app ON organization_call_app_entitlements(app_key, app_version)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS organization_call_app_installations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    entitlement_id INTEGER NOT NULL REFERENCES organization_call_app_entitlements(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_key TEXT NOT NULL,
    app_version TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'enabled' CHECK (status IN ('enabled', 'disabled')),
    config_json TEXT NOT NULL DEFAULT '{}',
    default_app_policy TEXT NOT NULL DEFAULT 'blocked_by_default' CHECK (default_app_policy IN ('allowed_by_default', 'blocked_by_default')),
    installed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    installed_at TEXT NOT NULL,
    disabled_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, app_key, app_version)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_org_call_app_installations_tenant_status ON organization_call_app_installations(tenant_id, status, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_org_call_app_installations_entitlement ON organization_call_app_installations(entitlement_id)',
    ];
}
