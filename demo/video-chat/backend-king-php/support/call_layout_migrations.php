<?php

declare(strict_types=1);

function videochat_call_layout_call_app_workspace_migration_statements(): array
{
    return [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_layout_state_0050 (
    call_id TEXT PRIMARY KEY REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    mode TEXT NOT NULL DEFAULT 'main_mini' CHECK (mode IN ('grid', 'main_mini', 'main_only', 'call_app_workspace')),
    strategy TEXT NOT NULL DEFAULT 'manual_pinned' CHECK (strategy IN ('manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active')),
    automation_paused INTEGER NOT NULL DEFAULT 0 CHECK (automation_paused IN (0, 1)),
    pinned_user_ids_json TEXT NOT NULL DEFAULT '[]',
    main_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    selected_user_ids_json TEXT NOT NULL DEFAULT '[]',
    updated_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
        <<<'SQL'
INSERT OR REPLACE INTO call_layout_state_0050 (
    call_id,
    room_id,
    mode,
    strategy,
    automation_paused,
    pinned_user_ids_json,
    main_user_id,
    selected_user_ids_json,
    updated_by_user_id,
    updated_at
)
SELECT
    call_id,
    room_id,
    CASE
        WHEN mode IN ('grid', 'main_mini', 'main_only', 'call_app_workspace') THEN mode
        ELSE 'main_mini'
    END,
    CASE
        WHEN strategy IN ('manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active') THEN strategy
        ELSE 'manual_pinned'
    END,
    CASE WHEN automation_paused = 1 THEN 1 ELSE 0 END,
    pinned_user_ids_json,
    main_user_id,
    selected_user_ids_json,
    updated_by_user_id,
    updated_at
FROM call_layout_state
SQL,
        'DROP TABLE call_layout_state',
        'ALTER TABLE call_layout_state_0050 RENAME TO call_layout_state',
        'CREATE INDEX IF NOT EXISTS idx_call_layout_state_room_id ON call_layout_state(room_id)',
    ];
}
