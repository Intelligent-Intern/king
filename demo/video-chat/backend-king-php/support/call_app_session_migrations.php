<?php

declare(strict_types=1);

function videochat_call_app_session_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    installation_id INTEGER NOT NULL REFERENCES organization_call_app_installations(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    app_key TEXT NOT NULL,
    app_version TEXT NOT NULL,
    document_id TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'removed')),
    default_app_policy TEXT NOT NULL DEFAULT 'blocked_by_default' CHECK (default_app_policy IN ('allowed_by_default', 'blocked_by_default')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    activated_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    removed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    activated_at TEXT,
    removed_at TEXT,
    updated_at TEXT NOT NULL
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_sessions_call_status ON call_app_sessions(tenant_id, call_id, status, updated_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_sessions_app ON call_app_sessions(tenant_id, app_key, app_version, status)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_participant_grants (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_session_id INTEGER NOT NULL REFERENCES call_app_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    subject_type TEXT NOT NULL CHECK (subject_type IN ('user', 'guest')),
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    guest_id TEXT NOT NULL DEFAULT '',
    grant_state TEXT NOT NULL DEFAULT 'denied' CHECK (grant_state IN ('allowed', 'denied')),
    source TEXT NOT NULL DEFAULT 'default' CHECK (source IN ('default', 'explicit')),
    changed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    changed_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK (
        (subject_type = 'user' AND user_id IS NOT NULL AND guest_id = '')
        OR (subject_type = 'guest' AND user_id IS NULL AND guest_id <> '')
    ),
    UNIQUE(app_session_id, subject_type, user_id, guest_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_participant_grants_session ON call_app_participant_grants(tenant_id, app_session_id, grant_state)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_participant_grants_user ON call_app_participant_grants(tenant_id, user_id, grant_state)',
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_call_app_participant_grants_user_unique ON call_app_participant_grants(app_session_id, user_id) WHERE subject_type = 'user' AND user_id IS NOT NULL",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_call_app_participant_grants_guest_unique ON call_app_participant_grants(app_session_id, guest_id) WHERE subject_type = 'guest' AND guest_id <> ''",
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_launch_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_session_id INTEGER NOT NULL REFERENCES call_app_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    token_hash TEXT NOT NULL,
    issued_to_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    issued_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_launch_tokens_session ON call_app_launch_tokens(tenant_id, app_session_id, revoked_at, expires_at)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_launch_tokens_hash ON call_app_launch_tokens(token_hash)',
    ];
}

function videochat_call_app_grant_audit_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_session_id INTEGER NOT NULL REFERENCES call_app_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    event_type TEXT NOT NULL,
    subject_type TEXT NOT NULL CHECK (subject_type IN ('user', 'guest')),
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    guest_id TEXT NOT NULL DEFAULT '',
    grant_state TEXT NOT NULL DEFAULT '',
    actor_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    payload_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_audit_events_session ON call_app_audit_events(tenant_id, app_session_id, created_at DESC)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_audit_events_call ON call_app_audit_events(tenant_id, call_id, created_at DESC)',
    ];
}

function videochat_call_app_crdt_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_crdt_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_session_id INTEGER NOT NULL REFERENCES call_app_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_key TEXT NOT NULL,
    app_version TEXT NOT NULL,
    schema_version TEXT NOT NULL,
    snapshot_json TEXT NOT NULL DEFAULT '{}',
    snapshot_clock INTEGER NOT NULL DEFAULT 0,
    compacted_through_clock INTEGER NOT NULL DEFAULT 0,
    op_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_crdt_documents_session ON call_app_crdt_documents(tenant_id, app_session_id)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_crdt_documents_app ON call_app_crdt_documents(tenant_id, app_key, app_version)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_app_crdt_ops (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    document_row_id INTEGER NOT NULL REFERENCES call_app_crdt_documents(id) ON UPDATE CASCADE ON DELETE CASCADE,
    app_session_id INTEGER NOT NULL REFERENCES call_app_sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    operation_id TEXT NOT NULL,
    actor_id TEXT NOT NULL,
    logical_clock INTEGER NOT NULL,
    causal_dependencies_json TEXT NOT NULL DEFAULT '[]',
    payload_type TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    payload_hash TEXT NOT NULL,
    server_admission_stamp_json TEXT NOT NULL,
    admitted_at TEXT NOT NULL,
    UNIQUE(document_row_id, operation_id)
)
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_app_crdt_ops_replay ON call_app_crdt_ops(tenant_id, document_row_id, logical_clock, id)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_crdt_ops_session ON call_app_crdt_ops(tenant_id, app_session_id, logical_clock)',
        'CREATE INDEX IF NOT EXISTS idx_call_app_crdt_ops_actor ON call_app_crdt_ops(tenant_id, actor_id, logical_clock)',
    ];
}
