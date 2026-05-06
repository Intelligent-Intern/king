<?php

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function videochat_workspace_app_configuration_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_email_texts (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    template_key TEXT NOT NULL,
    label TEXT NOT NULL,
    subject_template TEXT NOT NULL,
    body_template TEXT NOT NULL,
    is_system INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0, 1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, template_key)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_workspace_email_texts_tenant_updated ON workspace_email_texts(tenant_id, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_workspace_email_texts_tenant_label ON workspace_email_texts(tenant_id, label COLLATE NOCASE)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_background_images (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    label TEXT NOT NULL,
    file_path TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    file_size INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, file_path)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_workspace_background_images_tenant_updated ON workspace_background_images(tenant_id, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_workspace_background_images_tenant_label ON workspace_background_images(tenant_id, label COLLATE NOCASE)',
    ];
}
