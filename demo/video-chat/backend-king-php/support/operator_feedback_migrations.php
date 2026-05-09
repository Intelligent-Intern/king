<?php

declare(strict_types=1);

function videochat_operator_feedback_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_operator_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    tenant_id INTEGER REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE SET NULL,
    organization_id INTEGER REFERENCES organizations(id) ON UPDATE CASCADE ON DELETE SET NULL,
    organization_public_id TEXT NOT NULL DEFAULT '',
    organization_name TEXT NOT NULL DEFAULT '',
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    session_id TEXT NOT NULL DEFAULT '',
    client_message_id TEXT NOT NULL DEFAULT '',
    chat_message_id TEXT NOT NULL,
    message_text TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'deployed')),
    triage_notes TEXT NOT NULL DEFAULT '',
    sprint_ticket_ref TEXT NOT NULL DEFAULT '',
    toast_feature_label TEXT NOT NULL DEFAULT '',
    deployed_at TEXT,
    deployed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    toast_delivered_at TEXT,
    toast_delivered_to_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    metadata_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        'CREATE UNIQUE INDEX IF NOT EXISTS idx_call_operator_feedback_chat_message ON call_operator_feedback(chat_message_id)',
        <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_operator_feedback_client_message
ON call_operator_feedback(call_id, sender_user_id, client_message_id)
WHERE client_message_id <> ''
SQL,
        'CREATE INDEX IF NOT EXISTS idx_call_operator_feedback_tenant_status ON call_operator_feedback(tenant_id, status, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS idx_call_operator_feedback_call_status ON call_operator_feedback(call_id, status, created_at DESC, id DESC)',
        'CREATE INDEX IF NOT EXISTS idx_call_operator_feedback_sender_toast ON call_operator_feedback(sender_user_id, call_id, status, toast_delivered_at)',
    ];
}
