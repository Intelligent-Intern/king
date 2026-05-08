<?php

declare(strict_types=1);

/**
 * @return array<int, string>
 */
function videochat_workspace_calendar_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_calendars (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#1582BF',
    sync_calendar_ids TEXT NOT NULL DEFAULT '[]',
    calendar_type TEXT NOT NULL DEFAULT 'shared' CHECK (calendar_type IN ('personal', 'shared')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_workspace_calendars_personal_owner
ON workspace_calendars(tenant_id, owner_user_id)
WHERE calendar_type = 'personal'
SQL,
        'CREATE INDEX IF NOT EXISTS idx_workspace_calendars_tenant_status ON workspace_calendars(tenant_id, status, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_workspace_calendars_owner ON workspace_calendars(owner_user_id, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_calendar_members (
    calendar_id TEXT NOT NULL REFERENCES workspace_calendars(id) ON UPDATE CASCADE ON DELETE CASCADE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    access_role TEXT NOT NULL DEFAULT 'viewer' CHECK (access_role IN ('owner', 'editor', 'viewer')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (calendar_id, user_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_workspace_calendar_members_user ON workspace_calendar_members(tenant_id, user_id, status)',
        'CREATE INDEX IF NOT EXISTS idx_workspace_calendar_members_calendar ON workspace_calendar_members(calendar_id, status)',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_workspace_calendar_additive_migration_statements(): array
{
    return [
        "ALTER TABLE workspace_calendars ADD COLUMN color TEXT NOT NULL DEFAULT '#1582BF'",
        "ALTER TABLE workspace_calendars ADD COLUMN sync_calendar_ids TEXT NOT NULL DEFAULT '[]'",
    ];
}
