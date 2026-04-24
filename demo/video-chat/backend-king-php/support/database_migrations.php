<?php

declare(strict_types=1);

function videochat_sqlite_migrations(): array
{
    return [
        1 => [
            'name' => '0001_identity_and_sessions',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    is_system INTEGER NOT NULL DEFAULT 1 CHECK (is_system IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL,
    password_hash TEXT,
    role_id INTEGER NOT NULL REFERENCES roles(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    avatar_path TEXT,
    time_format TEXT NOT NULL DEFAULT '24h' CHECK (time_format IN ('24h', '12h')),
    theme TEXT NOT NULL DEFAULT 'dark',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS sessions (
    id TEXT PRIMARY KEY,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    issued_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT,
    client_ip TEXT,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at)",
                "CREATE INDEX IF NOT EXISTS idx_sessions_revoked_at ON sessions(revoked_at)",
                <<<'SQL'
INSERT OR IGNORE INTO roles (slug, name, description, is_system) VALUES
  ('admin', 'Admin', 'Platform administrator', 1),
  ('user', 'User', 'Standard workspace user', 1)
SQL,
            ],
        ],
        2 => [
            'name' => '0002_rooms_and_memberships',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS rooms (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'public')),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'archived')),
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS room_memberships (
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    membership_role TEXT NOT NULL DEFAULT 'member' CHECK (membership_role IN ('owner', 'moderator', 'member')),
    joined_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    left_at TEXT,
    PRIMARY KEY (room_id, user_id)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_rooms_status ON rooms(status)",
                "CREATE INDEX IF NOT EXISTS idx_room_memberships_user_id ON room_memberships(user_id)",
                <<<'SQL'
INSERT OR IGNORE INTO rooms (id, name, visibility, status) VALUES
  ('lobby', 'Lobby', 'public', 'active')
SQL,
            ],
        ],
        3 => [
            'name' => '0003_calls_invites_and_participants',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS calls (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    title TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    status TEXT NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'active', 'ended', 'cancelled')),
    starts_at TEXT NOT NULL,
    ends_at TEXT NOT NULL,
    cancelled_at TEXT,
    cancel_reason TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_participants (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('internal', 'external')),
    invite_state TEXT NOT NULL DEFAULT 'invited' CHECK (invite_state IN ('invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled')),
    joined_at TEXT,
    left_at TEXT,
    PRIMARY KEY (call_id, email)
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS invite_codes (
    id TEXT PRIMARY KEY,
    code TEXT NOT NULL UNIQUE,
    scope TEXT NOT NULL CHECK (scope IN ('room', 'call')),
    room_id TEXT REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    call_id TEXT REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    issued_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    expires_at TEXT NOT NULL,
    redeemed_at TEXT,
    redeemed_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    max_redemptions INTEGER NOT NULL DEFAULT 1,
    redemption_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    CHECK (
        (scope = 'room' AND room_id IS NOT NULL AND call_id IS NULL)
        OR (scope = 'call' AND call_id IS NOT NULL AND room_id IS NULL)
    )
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_calls_room_id ON calls(room_id)",
                "CREATE INDEX IF NOT EXISTS idx_calls_owner_user_id ON calls(owner_user_id)",
                "CREATE INDEX IF NOT EXISTS idx_calls_status ON calls(status)",
                "CREATE INDEX IF NOT EXISTS idx_call_participants_user_id ON call_participants(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_invite_codes_code ON invite_codes(code)",
                "CREATE INDEX IF NOT EXISTS idx_invite_codes_expires_at ON invite_codes(expires_at)",
            ],
        ],
        4 => [
            'name' => '0004_calls_cancel_message',
            'statements' => [
                "ALTER TABLE calls ADD COLUMN cancel_message TEXT",
            ],
        ],
        5 => [
            'name' => '0005_remove_global_moderator_role',
            'statements' => [
                <<<'SQL'
UPDATE users
SET role_id = (SELECT id FROM roles WHERE slug = 'user' LIMIT 1)
WHERE role_id = (SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1)
  AND EXISTS (SELECT 1 FROM roles WHERE slug = 'user')
SQL,
                "DELETE FROM roles WHERE slug = 'moderator'",
            ],
        ],
        6 => [
            'name' => '0006_call_participant_roles',
            'statements' => [
                "ALTER TABLE call_participants ADD COLUMN call_role TEXT NOT NULL DEFAULT 'participant' CHECK (call_role IN ('owner', 'moderator', 'participant'))",
                <<<'SQL'
UPDATE call_participants
SET call_role = 'owner'
WHERE source = 'internal'
  AND user_id IS NOT NULL
  AND EXISTS (
      SELECT 1
      FROM calls
      WHERE calls.id = call_participants.call_id
        AND calls.owner_user_id = call_participants.user_id
  )
SQL,
                <<<'SQL'
UPDATE call_participants
SET call_role = 'participant'
WHERE call_role IS NULL OR trim(call_role) = ''
SQL,
            ],
        ],
        7 => [
            'name' => '0007_call_access_links',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_access_links (
    id TEXT PRIMARY KEY,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    participant_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    participant_email TEXT,
    invite_code_id TEXT REFERENCES invite_codes(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    expires_at TEXT,
    last_used_at TEXT,
    consumed_at TEXT
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_call_id ON call_access_links(call_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_participant_user_id ON call_access_links(participant_user_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_links_invite_code_id ON call_access_links(invite_code_id)",
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_access_links_call_user
ON call_access_links(call_id, participant_user_id)
WHERE participant_user_id IS NOT NULL
SQL,
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_access_links_call_email
ON call_access_links(call_id, participant_email)
WHERE participant_user_id IS NULL
  AND participant_email IS NOT NULL
  AND trim(participant_email) <> ''
SQL,
            ],
        ],
        8 => [
            'name' => '0008_calls_access_mode',
            'statements' => [
                "ALTER TABLE calls ADD COLUMN access_mode TEXT NOT NULL DEFAULT 'invite_only' CHECK (access_mode IN ('invite_only', 'free_for_all'))",
                <<<'SQL'
UPDATE calls
SET access_mode = 'invite_only'
WHERE access_mode IS NULL
   OR trim(access_mode) = ''
   OR lower(access_mode) NOT IN ('invite_only', 'free_for_all')
SQL,
            ],
        ],
        9 => [
            'name' => '0009_calls_dedicated_room_ids',
            'statements' => [
                <<<'SQL'
INSERT OR IGNORE INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
SELECT
    calls.id,
    CASE
        WHEN trim(coalesce(calls.title, '')) = '' THEN 'Call Room'
        ELSE calls.title
    END,
    'private',
    'active',
    calls.owner_user_id,
    coalesce(nullif(calls.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(calls.updated_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
FROM calls
WHERE lower(trim(coalesce(calls.room_id, ''))) = 'lobby'
SQL,
                <<<'SQL'
UPDATE calls
SET room_id = id
WHERE lower(trim(coalesce(room_id, ''))) = 'lobby'
SQL,
            ],
        ],
        10 => [
            'name' => '0010_user_email_identities',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS user_emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    email TEXT NOT NULL,
    is_verified INTEGER NOT NULL DEFAULT 0 CHECK (is_verified IN (0, 1)),
    is_primary INTEGER NOT NULL DEFAULT 0 CHECK (is_primary IN (0, 1)),
    verified_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS user_email_change_tokens (
    id TEXT PRIMARY KEY,
    user_email_id INTEGER NOT NULL REFERENCES user_emails(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    created_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_user_emails_email_nocase ON user_emails(lower(email))",
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_emails_primary_per_user
ON user_emails(user_id)
WHERE is_primary = 1
SQL,
                "CREATE INDEX IF NOT EXISTS idx_user_emails_user_id ON user_emails(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_email_change_tokens_user_id ON user_email_change_tokens(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_user_email_change_tokens_expires_at ON user_email_change_tokens(expires_at)",
                <<<'SQL'
INSERT INTO user_emails(user_id, email, is_verified, is_primary, verified_at, created_at, updated_at)
SELECT
    users.id,
    lower(trim(users.email)),
    1,
    1,
    coalesce(nullif(users.updated_at, ''), nullif(users.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(users.created_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    coalesce(nullif(users.updated_at, ''), strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
FROM users
WHERE trim(coalesce(users.email, '')) <> ''
  AND NOT EXISTS (
      SELECT 1
      FROM user_emails
      WHERE user_emails.user_id = users.id
  )
SQL,
            ],
        ],
        11 => [
            'name' => '0011_users_date_format',
            'statements' => [
                "ALTER TABLE users ADD COLUMN date_format TEXT NOT NULL DEFAULT 'dmy_dot' CHECK (date_format IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot'))",
                <<<'SQL'
UPDATE users
SET date_format = 'dmy_dot'
WHERE date_format IS NULL
   OR trim(date_format) = ''
   OR lower(date_format) NOT IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot')
SQL,
            ],
        ],
        12 => [
            'name' => '0012_call_participant_admission_states',
            'statements' => [
                <<<'SQL'
ALTER TABLE call_participants RENAME TO call_participants_0012_old
SQL,
                <<<'SQL'
CREATE TABLE call_participants (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('internal', 'external')),
    invite_state TEXT NOT NULL DEFAULT 'invited' CHECK (invite_state IN ('invited', 'pending', 'allowed', 'accepted', 'declined', 'cancelled')),
    joined_at TEXT,
    left_at TEXT,
    call_role TEXT NOT NULL DEFAULT 'participant' CHECK (call_role IN ('owner', 'moderator', 'participant')),
    PRIMARY KEY (call_id, email)
)
SQL,
                <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at, call_role)
SELECT
    call_id,
    user_id,
    email,
    display_name,
    CASE
        WHEN lower(trim(coalesce(source, ''))) IN ('internal', 'external') THEN lower(trim(source))
        ELSE 'external'
    END,
    CASE
        WHEN lower(trim(coalesce(invite_state, ''))) = 'accepted' THEN 'allowed'
        WHEN lower(trim(coalesce(invite_state, ''))) = 'pending' THEN 'invited'
        WHEN lower(trim(coalesce(invite_state, ''))) IN ('invited', 'allowed', 'declined', 'cancelled') THEN lower(trim(invite_state))
        ELSE 'invited'
    END,
    joined_at,
    left_at,
    CASE
        WHEN lower(trim(coalesce(call_role, ''))) IN ('owner', 'moderator', 'participant') THEN lower(trim(call_role))
        ELSE 'participant'
    END
FROM call_participants_0012_old
SQL,
                <<<'SQL'
DROP TABLE call_participants_0012_old
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_participants_user_id ON call_participants(user_id)",
            ],
        ],
        13 => [
            'name' => '0013_call_chat_attachments',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_attachments (
    id TEXT PRIMARY KEY,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    uploaded_by_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    content_type TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('image', 'text', 'pdf', 'document')),
    extension TEXT NOT NULL,
    object_key TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'attached', 'deleted')),
    attached_message_id TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    attached_at TEXT
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_call_id ON call_chat_attachments(call_id, status)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_room_id ON call_chat_attachments(room_id, status)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_attachments_uploaded_by ON call_chat_attachments(uploaded_by_user_id, status)",
            ],
        ],
        14 => [
            'name' => '0014_call_chat_archive',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_messages (
    seq INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id TEXT NOT NULL UNIQUE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    sender_display_name TEXT NOT NULL,
    sender_role TEXT NOT NULL DEFAULT 'user',
    text TEXT NOT NULL,
    message_json TEXT NOT NULL,
    transcript_object_key TEXT NOT NULL UNIQUE,
    server_unix_ms INTEGER NOT NULL,
    server_time TEXT NOT NULL,
    snapshot_version INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_chat_acl (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    access_role TEXT NOT NULL CHECK (access_role IN ('owner', 'moderator', 'participant', 'admin')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (call_id, user_id)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_call_seq ON call_chat_messages(call_id, seq)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_room_time ON call_chat_messages(room_id, server_unix_ms)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_messages_sender ON call_chat_messages(sender_user_id, seq)",
                "CREATE INDEX IF NOT EXISTS idx_call_chat_acl_user_id ON call_chat_acl(user_id)",
            ],
        ],
        15 => [
            'name' => '0015_call_activity_layout_state',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_layout_state (
    call_id TEXT PRIMARY KEY REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    mode TEXT NOT NULL DEFAULT 'main_mini' CHECK (mode IN ('grid', 'main_mini', 'main_only')),
    strategy TEXT NOT NULL DEFAULT 'manual_pinned' CHECK (strategy IN ('manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active')),
    automation_paused INTEGER NOT NULL DEFAULT 0 CHECK (automation_paused IN (0, 1)),
    pinned_user_ids_json TEXT NOT NULL DEFAULT '[]',
    main_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    selected_user_ids_json TEXT NOT NULL DEFAULT '[]',
    updated_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_layout_state_room_id ON call_layout_state(room_id)",
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_participant_activity (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    raw_score REAL NOT NULL DEFAULT 0,
    audio_level REAL NOT NULL DEFAULT 0,
    motion_score REAL NOT NULL DEFAULT 0,
    gesture_score REAL NOT NULL DEFAULT 0,
    is_speaking INTEGER NOT NULL DEFAULT 0 CHECK (is_speaking IN (0, 1)),
    source TEXT NOT NULL DEFAULT 'client_observed',
    updated_at_ms INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (call_id, user_id)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_participant_activity_room_score ON call_participant_activity(room_id, updated_at_ms)",
            ],
        ],
        16 => [
            'name' => '0016_call_access_sessions',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_access_sessions (
    session_id TEXT PRIMARY KEY REFERENCES sessions(id) ON UPDATE CASCADE ON DELETE CASCADE,
    access_id TEXT NOT NULL REFERENCES call_access_links(id) ON UPDATE CASCADE ON DELETE CASCADE,
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    link_kind TEXT NOT NULL CHECK (link_kind IN ('personal', 'open')),
    issued_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_access_sessions_access_id ON call_access_sessions(access_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_sessions_call_user ON call_access_sessions(call_id, user_id)",
                "CREATE INDEX IF NOT EXISTS idx_call_access_sessions_room_id ON call_access_sessions(room_id)",
            ],
        ],
        17 => [
            'name' => '0017_call_schedule_metadata',
            'statements' => [
                "ALTER TABLE calls ADD COLUMN schedule_timezone TEXT NOT NULL DEFAULT 'UTC'",
                "ALTER TABLE calls ADD COLUMN schedule_date TEXT",
                "ALTER TABLE calls ADD COLUMN schedule_duration_minutes INTEGER NOT NULL DEFAULT 0",
                "ALTER TABLE calls ADD COLUMN schedule_all_day INTEGER NOT NULL DEFAULT 0 CHECK (schedule_all_day IN (0, 1))",
                <<<'SQL'
UPDATE calls
SET schedule_timezone = 'UTC'
WHERE schedule_timezone IS NULL
   OR trim(schedule_timezone) = ''
SQL,
                <<<'SQL'
UPDATE calls
SET schedule_date = substr(starts_at, 1, 10)
WHERE schedule_date IS NULL
   OR trim(schedule_date) = ''
SQL,
                <<<'SQL'
UPDATE calls
SET schedule_duration_minutes = CASE
    WHEN strftime('%s', ends_at) > strftime('%s', starts_at)
    THEN CAST((strftime('%s', ends_at) - strftime('%s', starts_at)) / 60 AS INTEGER)
    ELSE 0
END
WHERE schedule_duration_minutes IS NULL
   OR schedule_duration_minutes <= 0
SQL,
                <<<'SQL'
UPDATE calls
SET schedule_all_day = 0
WHERE schedule_all_day IS NULL
   OR schedule_all_day NOT IN (0, 1)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_calls_schedule_date ON calls(schedule_date, starts_at)",
            ],
        ],
        18 => [
            'name' => '0018_client_diagnostics',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS client_diagnostics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    session_id TEXT NOT NULL DEFAULT '',
    call_id TEXT NOT NULL DEFAULT '',
    room_id TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL,
    level TEXT NOT NULL CHECK (level IN ('debug', 'info', 'warning', 'error')),
    event_type TEXT NOT NULL,
    code TEXT NOT NULL DEFAULT '',
    message TEXT NOT NULL DEFAULT '',
    payload_json TEXT NOT NULL DEFAULT '{}',
    repeat_count INTEGER NOT NULL DEFAULT 1,
    client_time TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_client_diagnostics_user_time ON client_diagnostics(user_id, created_at DESC)",
                "CREATE INDEX IF NOT EXISTS idx_client_diagnostics_call_time ON client_diagnostics(call_id, created_at DESC)",
                "CREATE INDEX IF NOT EXISTS idx_client_diagnostics_room_time ON client_diagnostics(room_id, created_at DESC)",
                "CREATE INDEX IF NOT EXISTS idx_client_diagnostics_event_time ON client_diagnostics(event_type, created_at DESC)",
            ],
        ],
        19 => [
            'name' => '0019_call_app_marketplace',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS call_apps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    manufacturer TEXT NOT NULL,
    website TEXT NOT NULL DEFAULT '',
    category TEXT NOT NULL DEFAULT 'other' CHECK (category IN ('whiteboard', 'avatar', 'assistant', 'collaboration', 'utility', 'other')),
    description TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_call_apps_category_name ON call_apps(category, name)",
                "CREATE INDEX IF NOT EXISTS idx_call_apps_updated_at ON call_apps(updated_at DESC)",
                <<<'SQL'
CREATE UNIQUE INDEX IF NOT EXISTS idx_call_apps_name_manufacturer_nocase
ON call_apps(lower(name), lower(manufacturer))
SQL,
            ],
        ],
    ];
}

/**
 * @return array{
 *   path: string,
 *   schema_version: int,
 *   migrations_total: int,
 *   migrations_applied: int,
 *   migrations_newly_applied: int,
 *   migrations_pending: int,
 *   applied_versions: array<int, int>,
 *   table_count: int,
 *   table_names: array<int, string>,
 *   journal_mode: string,
 *   demo_users: array<int, array{email: string, display_name: string, role: string}>,
 *   demo_calls: array<int, array{id: string, room_id: string, title: string, status: string, owner_email: string, starts_at: string, ends_at: string}>
 * }
 */
