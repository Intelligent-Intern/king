<?php

declare(strict_types=1);

require_once __DIR__ . '/workspace_theme_migrations.php';

function videochat_tenant_default_public_id(): string
{
    return '00000000-0000-4000-8000-000000000001';
}

function videochat_sqlite_tenant_migrations(): array
{
    return [
        28 => [
            'name' => '0028_tenant_foundation_default_backfill',
            'statements' => videochat_tenant_foundation_statements(),
        ],
        29 => [
            'name' => '0029_tenant_scoped_singletons',
            'statements' => videochat_tenant_scoped_singleton_statements(),
        ],
        35 => [
            'name' => '0035_permission_grant_public_metadata',
            'statements' => videochat_permission_grant_public_metadata_statements(),
        ],
        36 => [
            'name' => '0036_permission_grant_source',
            'statements' => [
                'ALTER TABLE permission_grants ADD COLUMN source TEXT NOT NULL DEFAULT \'manual\'',
                'CREATE INDEX IF NOT EXISTS idx_permission_grants_subject_source ON permission_grants(tenant_id, subject_type, group_id, organization_id, user_id, source)',
            ],
        ],
        37 => [
            'name' => '0037_governance_policies',
            'statements' => videochat_governance_policy_statements(),
        ],
        38 => [
            'name' => '0038_governance_roles',
            'statements' => videochat_governance_role_statements(),
        ],
        39 => [
            'name' => '0039_governance_group_roles',
            'statements' => videochat_governance_group_role_statements(),
        ],
        40 => [
            'name' => '0040_governance_organization_roles',
            'statements' => videochat_governance_organization_role_statements(),
        ],
        41 => [
            'name' => '0041_governance_user_roles',
            'statements' => videochat_governance_user_role_statements(),
        ],
        42 => [
            'name' => '0042_workspace_theme_palette_refresh',
            'statements' => videochat_workspace_theme_refresh_statements(),
        ],
        43 => [
            'name' => '0043_workspace_theme_styleguide_palette',
            'statements' => videochat_workspace_theme_refresh_statements(),
        ],
    ];
}

function videochat_governance_group_role_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_group_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    group_id INTEGER NOT NULL REFERENCES "groups"(id) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES governance_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(group_id, role_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_group_roles_tenant_group ON governance_group_roles(tenant_id, group_id)',
        'CREATE INDEX IF NOT EXISTS idx_governance_group_roles_tenant_role ON governance_group_roles(tenant_id, role_id)',
    ];
}

function videochat_governance_organization_role_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_organization_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES governance_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(organization_id, role_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_organization_roles_tenant_organization ON governance_organization_roles(tenant_id, organization_id)',
        'CREATE INDEX IF NOT EXISTS idx_governance_organization_roles_tenant_role ON governance_organization_roles(tenant_id, role_id)',
    ];
}

function videochat_governance_user_role_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_user_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES governance_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(user_id, role_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_user_roles_tenant_user ON governance_user_roles(tenant_id, user_id)',
        'CREATE INDEX IF NOT EXISTS idx_governance_user_roles_tenant_role ON governance_user_roles(tenant_id, role_id)',
    ];
}

function videochat_governance_role_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    public_id TEXT NOT NULL UNIQUE,
    key TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_governance_roles_tenant_key ON governance_roles(tenant_id, lower(key)) WHERE key <> \'\'',
        'CREATE INDEX IF NOT EXISTS idx_governance_roles_tenant_status ON governance_roles(tenant_id, status, updated_at DESC)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_role_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES governance_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    permission_key TEXT NOT NULL,
    resource_type TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('create', 'read', 'update', 'delete', 'share', 'manage')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(role_id, permission_key)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_role_permissions_tenant_role ON governance_role_permissions(tenant_id, role_id)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_role_modules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    role_id INTEGER NOT NULL REFERENCES governance_roles(id) ON UPDATE CASCADE ON DELETE CASCADE,
    module_key TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(role_id, module_key)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_role_modules_tenant_role ON governance_role_modules(tenant_id, role_id)',
    ];
}

function videochat_governance_policy_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    public_id TEXT NOT NULL UNIQUE,
    key TEXT NOT NULL DEFAULT '',
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_governance_policies_tenant_key ON governance_policies(tenant_id, lower(key)) WHERE key <> \'\'',
        'CREATE INDEX IF NOT EXISTS idx_governance_policies_tenant_status ON governance_policies(tenant_id, status, updated_at DESC)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_policy_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    policy_id INTEGER NOT NULL REFERENCES governance_policies(id) ON UPDATE CASCADE ON DELETE CASCADE,
    group_id INTEGER NOT NULL REFERENCES "groups"(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(policy_id, group_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_policy_groups_tenant_group ON governance_policy_groups(tenant_id, group_id)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_policy_organizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    policy_id INTEGER NOT NULL REFERENCES governance_policies(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(policy_id, organization_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_policy_orgs_tenant_org ON governance_policy_organizations(tenant_id, organization_id)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS governance_policy_permissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    policy_id INTEGER NOT NULL REFERENCES governance_policies(id) ON UPDATE CASCADE ON DELETE CASCADE,
    permission_key TEXT NOT NULL,
    resource_type TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('create', 'read', 'update', 'delete', 'share', 'manage')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(policy_id, permission_key)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_governance_policy_permissions_tenant_policy ON governance_policy_permissions(tenant_id, policy_id)',
    ];
}

function videochat_permission_grant_public_metadata_statements(): array
{
    return [
        'ALTER TABLE permission_grants ADD COLUMN public_id TEXT',
        'ALTER TABLE permission_grants ADD COLUMN label TEXT NOT NULL DEFAULT \'\'',
        'ALTER TABLE permission_grants ADD COLUMN description TEXT NOT NULL DEFAULT \'\'',
        'ALTER TABLE permission_grants ADD COLUMN permission_key TEXT NOT NULL DEFAULT \'\'',
        <<<'SQL'
UPDATE permission_grants
SET public_id = lower(
    hex(randomblob(4)) || '-' ||
    hex(randomblob(2)) || '-' ||
    '4' || substr(hex(randomblob(2)), 2) || '-' ||
    substr('89ab', abs(random()) % 4 + 1, 1) || substr(hex(randomblob(2)), 2) || '-' ||
    hex(randomblob(6))
)
WHERE public_id IS NULL OR public_id = ''
SQL,
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_permission_grants_public_id ON permission_grants(public_id)',
    ];
}

function videochat_tenant_scoped_singleton_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS appointment_calendar_settings_tenant_rebuild (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    public_id TEXT NOT NULL UNIQUE,
    slot_minutes INTEGER NOT NULL DEFAULT 15 CHECK (slot_minutes IN (5, 10, 15, 20, 30, 45, 60)),
    slot_mode TEXT NOT NULL DEFAULT 'selected_dates' CHECK (slot_mode IN ('selected_dates', 'recurring_weekly')),
    invitation_text TEXT NOT NULL DEFAULT '',
    mail_from_email TEXT NOT NULL DEFAULT '',
    mail_from_name TEXT NOT NULL DEFAULT '',
    mail_smtp_host TEXT NOT NULL DEFAULT '',
    mail_smtp_port INTEGER NOT NULL DEFAULT 587,
    mail_smtp_encryption TEXT NOT NULL DEFAULT 'starttls',
    mail_smtp_username TEXT NOT NULL DEFAULT '',
    mail_smtp_password TEXT NOT NULL DEFAULT '',
    mail_subject_template TEXT NOT NULL DEFAULT 'Video call scheduled: {call_title}',
    mail_body_template TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, owner_user_id)
)
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO appointment_calendar_settings_tenant_rebuild(
    tenant_id, owner_user_id, public_id, slot_minutes, slot_mode, invitation_text,
    mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, mail_subject_template, mail_body_template,
    created_at, updated_at
)
SELECT
    COALESCE(tenant_id, (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1)),
    owner_user_id, public_id, slot_minutes, slot_mode, invitation_text,
    mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, mail_subject_template, mail_body_template,
    created_at, updated_at
FROM appointment_calendar_settings
SQL,
        'DROP TABLE appointment_calendar_settings',
        'ALTER TABLE appointment_calendar_settings_tenant_rebuild RENAME TO appointment_calendar_settings',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_appointment_calendar_settings_public_id ON appointment_calendar_settings(public_id)',
        'CREATE INDEX IF NOT EXISTS idx_appointment_calendar_settings_tenant_owner ON appointment_calendar_settings(tenant_id, owner_user_id)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_administration_settings_tenant_rebuild (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER UNIQUE,
    mail_from_email TEXT NOT NULL DEFAULT '',
    mail_from_name TEXT NOT NULL DEFAULT '',
    mail_smtp_host TEXT NOT NULL DEFAULT '',
    mail_smtp_port INTEGER NOT NULL DEFAULT 587,
    mail_smtp_encryption TEXT NOT NULL DEFAULT 'starttls',
    mail_smtp_username TEXT NOT NULL DEFAULT '',
    mail_smtp_password TEXT NOT NULL DEFAULT '',
    lead_recipients TEXT NOT NULL DEFAULT '[]',
    lead_subject_template TEXT NOT NULL DEFAULT 'New website lead: {name}',
    lead_body_template TEXT NOT NULL DEFAULT '',
    sidebar_logo_path TEXT NOT NULL DEFAULT '/assets/orgas/kingrt/logo.svg',
    modal_logo_path TEXT NOT NULL DEFAULT '/assets/orgas/kingrt/logo.svg',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO workspace_administration_settings_tenant_rebuild(
    tenant_id, mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, lead_recipients, lead_subject_template, lead_body_template,
    sidebar_logo_path, modal_logo_path, created_at, updated_at
)
SELECT
    COALESCE(tenant_id, (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1)),
    mail_from_email, mail_from_name, mail_smtp_host, mail_smtp_port, mail_smtp_encryption,
    mail_smtp_username, mail_smtp_password, lead_recipients, lead_subject_template, lead_body_template,
    sidebar_logo_path, modal_logo_path, created_at, updated_at
FROM workspace_administration_settings
SQL,
        'DROP TABLE workspace_administration_settings',
        'ALTER TABLE workspace_administration_settings_tenant_rebuild RENAME TO workspace_administration_settings',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_workspace_administration_settings_tenant ON workspace_administration_settings(tenant_id)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS workspace_theme_presets_tenant_rebuild (
    row_id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER,
    id TEXT NOT NULL,
    label TEXT NOT NULL,
    colors_json TEXT NOT NULL,
    is_system INTEGER NOT NULL DEFAULT 0 CHECK (is_system IN (0, 1)),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, id)
)
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO workspace_theme_presets_tenant_rebuild(tenant_id, id, label, colors_json, is_system, created_by_user_id, created_at, updated_at)
SELECT COALESCE(tenant_id, (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1)), id, label, colors_json, is_system, created_by_user_id, created_at, updated_at
FROM workspace_theme_presets
SQL,
        'DROP TABLE workspace_theme_presets',
        'ALTER TABLE workspace_theme_presets_tenant_rebuild RENAME TO workspace_theme_presets',
        'CREATE INDEX IF NOT EXISTS idx_workspace_theme_presets_tenant_label ON workspace_theme_presets(tenant_id, label)',
    ];
}

function videochat_tenant_foundation_statements(): array
{
    $defaultTenantUuid = videochat_tenant_default_public_id();

    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tenants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tenant_memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    membership_role TEXT NOT NULL DEFAULT 'member' CHECK (membership_role IN ('owner', 'admin', 'member')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    permissions_json TEXT NOT NULL DEFAULT '{}',
    default_membership INTEGER NOT NULL DEFAULT 0 CHECK (default_membership IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(tenant_id, user_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_tenant_memberships_user_default ON tenant_memberships(user_id, default_membership, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS organizations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    parent_organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    public_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (parent_organization_id IS NULL OR parent_organization_id <> id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_organizations_tenant_parent ON organizations(tenant_id, parent_organization_id, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS organization_memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER NOT NULL REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    membership_role TEXT NOT NULL DEFAULT 'member' CHECK (membership_role IN ('admin', 'member')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    UNIQUE(organization_id, user_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_organization_memberships_tenant_user ON organization_memberships(tenant_id, user_id, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS "groups" (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE SET NULL,
    public_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_groups_tenant_org ON "groups"(tenant_id, organization_id, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS group_memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    group_id INTEGER NOT NULL REFERENCES "groups"(id) ON UPDATE CASCADE ON DELETE CASCADE,
    subject_type TEXT NOT NULL CHECK (subject_type IN ('user', 'organization')),
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (
        (subject_type = 'user' AND user_id IS NOT NULL AND organization_id IS NULL)
        OR (subject_type = 'organization' AND organization_id IS NOT NULL AND user_id IS NULL)
    )
)
SQL,
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_group_memberships_user_unique ON group_memberships(group_id, user_id) WHERE subject_type = \'user\'',
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_group_memberships_org_unique ON group_memberships(group_id, organization_id) WHERE subject_type = \'organization\'',
        'CREATE INDEX IF NOT EXISTS idx_group_memberships_tenant_user ON group_memberships(tenant_id, user_id, status)',
        ...videochat_governance_policy_statements(),
        <<<'SQL'
CREATE TABLE IF NOT EXISTS permission_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    resource_type TEXT NOT NULL,
    resource_id TEXT NOT NULL,
    action TEXT NOT NULL CHECK (action IN ('create', 'read', 'update', 'delete', 'share', 'manage')),
    subject_type TEXT NOT NULL CHECK (subject_type IN ('user', 'group', 'organization')),
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    group_id INTEGER REFERENCES "groups"(id) ON UPDATE CASCADE ON DELETE CASCADE,
    organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE CASCADE,
    valid_from TEXT,
    valid_until TEXT,
    revoked_at TEXT,
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (
        (subject_type = 'user' AND user_id IS NOT NULL AND group_id IS NULL AND organization_id IS NULL)
        OR (subject_type = 'group' AND group_id IS NOT NULL AND user_id IS NULL AND organization_id IS NULL)
        OR (subject_type = 'organization' AND organization_id IS NOT NULL AND user_id IS NULL AND group_id IS NULL)
    )
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_permission_grants_resource ON permission_grants(tenant_id, resource_type, resource_id, action)',
        'CREATE INDEX IF NOT EXISTS idx_permission_grants_user ON permission_grants(tenant_id, user_id, revoked_at)',
        'CREATE INDEX IF NOT EXISTS idx_permission_grants_group ON permission_grants(tenant_id, group_id, revoked_at)',
        'CREATE INDEX IF NOT EXISTS idx_permission_grants_org ON permission_grants(tenant_id, organization_id, revoked_at)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tenant_export_jobs (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    scope_type TEXT NOT NULL CHECK (scope_type IN ('user', 'organization')),
    scope_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    scope_organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE SET NULL,
    schema_version TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    result_json TEXT NOT NULL DEFAULT '{}',
    failure_reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    completed_at TEXT
)
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS tenant_import_jobs (
    id TEXT PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    actor_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    scope_type TEXT NOT NULL CHECK (scope_type IN ('user', 'organization')),
    scope_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    scope_organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE SET NULL,
    schema_version TEXT NOT NULL,
    dry_run INTEGER NOT NULL DEFAULT 1 CHECK (dry_run IN (0, 1)),
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    result_json TEXT NOT NULL DEFAULT '{}',
    failure_reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    completed_at TEXT
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_tenant_export_jobs_scope ON tenant_export_jobs(tenant_id, scope_type, scope_user_id, scope_organization_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_tenant_import_jobs_scope ON tenant_import_jobs(tenant_id, scope_type, scope_user_id, scope_organization_id, created_at DESC)',
        'INSERT OR IGNORE INTO tenants(public_id, slug, label, status) VALUES(\'' . $defaultTenantUuid . '\', \'default\', \'Default Workspace\', \'active\')',
        "INSERT OR IGNORE INTO organizations(tenant_id, parent_organization_id, public_id, name, status)
SELECT id, NULL, '00000000-0000-4000-8000-000000000101', 'Default Organization', 'active'
FROM tenants WHERE slug = 'default'",
        "INSERT OR IGNORE INTO \"groups\"(tenant_id, organization_id, public_id, name, status)
SELECT tenants.id, organizations.id, '00000000-0000-4000-8000-000000000201', 'Default Members', 'active'
FROM tenants
INNER JOIN organizations ON organizations.tenant_id = tenants.id
WHERE tenants.slug = 'default' AND organizations.parent_organization_id IS NULL",
        ...videochat_tenant_column_backfill_statements(),
        ...videochat_tenant_membership_backfill_statements(),
    ];
}

function videochat_tenant_column_backfill_statements(): array
{
    $statements = [];
    foreach (videochat_tenant_legacy_owned_table_names() as $table) {
        $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN tenant_id INTEGER';
        $statements[] = 'UPDATE ' . $table . " SET tenant_id = (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1) WHERE tenant_id IS NULL";
        $statements[] = 'CREATE INDEX IF NOT EXISTS idx_' . $table . '_tenant_id ON ' . $table . '(tenant_id)';
    }

    $statements[] = 'ALTER TABLE sessions ADD COLUMN active_tenant_id INTEGER';
    $statements[] = "UPDATE sessions SET active_tenant_id = (SELECT id FROM tenants WHERE slug = 'default' LIMIT 1) WHERE active_tenant_id IS NULL";
    $statements[] = 'CREATE INDEX IF NOT EXISTS idx_sessions_active_tenant ON sessions(active_tenant_id, user_id)';

    return $statements;
}

function videochat_tenant_legacy_owned_table_names(): array
{
    return [
        'sessions',
        'rooms',
        'calls',
        'invite_codes',
        'call_access_links',
        'call_access_sessions',
        'call_chat_attachments',
        'call_chat_messages',
        'call_chat_acl',
        'call_layout_state',
        'call_participant_activity',
        'client_diagnostics',
        'appointment_blocks',
        'appointment_bookings',
        'appointment_calendar_settings',
        'workspace_administration_settings',
        'workspace_theme_presets',
        'website_leads',
    ];
}

function videochat_tenant_owned_table_names(): array
{
    return [
        ...videochat_tenant_legacy_owned_table_names(),
        'workspace_email_texts',
        'workspace_background_images',
        'governance_roles',
        'governance_role_permissions',
        'governance_role_modules',
        'governance_group_roles',
        'governance_organization_roles',
        'governance_user_roles',
        'governance_policies',
        'governance_policy_groups',
        'governance_policy_organizations',
        'governance_policy_permissions',
    ];
}

function videochat_tenant_membership_backfill_statements(): array
{
    return [
        <<<'SQL'
INSERT OR IGNORE INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
SELECT
    tenants.id,
    users.id,
    CASE WHEN roles.slug = 'admin' THEN 'owner' ELSE 'member' END,
    'active',
    CASE WHEN roles.slug = 'admin' THEN '{"platform_admin":true}' ELSE '{}' END,
    1,
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
FROM users
INNER JOIN roles ON roles.id = users.role_id
INNER JOIN tenants ON tenants.slug = 'default'
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
SELECT
    tenants.id,
    organizations.id,
    users.id,
    CASE WHEN roles.slug = 'admin' THEN 'admin' ELSE 'member' END,
    'active',
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
FROM users
INNER JOIN roles ON roles.id = users.role_id
INNER JOIN tenants ON tenants.slug = 'default'
INNER JOIN organizations ON organizations.tenant_id = tenants.id AND organizations.parent_organization_id IS NULL
SQL,
        <<<'SQL'
INSERT OR IGNORE INTO group_memberships(tenant_id, group_id, subject_type, user_id, organization_id, status, created_at, updated_at)
SELECT
    tenants.id,
    "groups".id,
    'user',
    users.id,
    NULL,
    'active',
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now'),
    strftime('%Y-%m-%dT%H:%M:%fZ', 'now')
FROM users
INNER JOIN tenants ON tenants.slug = 'default'
INNER JOIN "groups" ON "groups".tenant_id = tenants.id
WHERE "groups".name = 'Default Members'
SQL,
    ];
}

function videochat_tenant_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if (!is_string($safeTable) || $safeTable === '') {
        return false;
    }

    try {
        $columns = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
        foreach ($columns ?: [] as $column) {
            if (strcasecmp((string) ($column['name'] ?? ''), $columnName) === 0) {
                return true;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return false;
}

function videochat_tenant_backfill_default_memberships(PDO $pdo): void
{
    foreach (videochat_tenant_membership_backfill_statements() as $statement) {
        $pdo->exec($statement);
    }
}

function videochat_tenant_backfill_default_owned_records(PDO $pdo): void
{
    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    if ($defaultTenantId <= 0) {
        return;
    }

    foreach (videochat_tenant_owned_table_names() as $table) {
        if (videochat_tenant_table_has_column($pdo, $table, 'tenant_id')) {
            $pdo->exec('UPDATE ' . $table . ' SET tenant_id = ' . $defaultTenantId . ' WHERE tenant_id IS NULL');
        }
    }
    if (videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id')) {
        $pdo->exec('UPDATE sessions SET active_tenant_id = ' . $defaultTenantId . ' WHERE active_tenant_id IS NULL');
    }
}
