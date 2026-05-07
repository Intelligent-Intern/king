<?php

declare(strict_types=1);

/**
 * @return array<int, array{name: string, statements: array<int, string>}>
 */
function videochat_user_profile_migration_entries(): array
{
    return [
        32 => [
            'name' => '0032_user_profile_social_fields',
            'statements' => [
                "ALTER TABLE users ADD COLUMN about_me TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN linkedin_url TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN x_url TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN youtube_url TEXT NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN messenger_contacts_json TEXT NOT NULL DEFAULT '[]'",
            ],
        ],
        33 => [
            'name' => '0033_user_onboarding_progress',
            'statements' => [
                "ALTER TABLE users ADD COLUMN onboarding_progress_json TEXT NOT NULL DEFAULT '{}'",
            ],
        ],
        34 => [
            'name' => '0034_tenant_scoped_user_onboarding_progress',
            'statements' => [
                <<<'SQL'
CREATE TABLE IF NOT EXISTS user_onboarding_progress (
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON UPDATE CASCADE ON DELETE CASCADE,
    tour_key TEXT NOT NULL,
    completed_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    PRIMARY KEY (user_id, tenant_id, tour_key)
)
SQL,
                "CREATE INDEX IF NOT EXISTS idx_user_onboarding_progress_tenant ON user_onboarding_progress(tenant_id, tour_key)",
            ],
        ],
        46 => [
            'name' => '0046_remove_legacy_profile_settings_columns',
            'statements' => [
                'ALTER TABLE users DROP COLUMN messenger_contacts_json',
                'ALTER TABLE users DROP COLUMN onboarding_progress_json',
            ],
        ],
        53 => [
            'name' => '0053_user_web_app_notification_settings',
            'statements' => [
                'ALTER TABLE users ADD COLUMN web_app_notifications_enabled INTEGER NOT NULL DEFAULT 0 CHECK (web_app_notifications_enabled IN (0, 1))',
                'ALTER TABLE users ADD COLUMN web_app_notification_sound_enabled INTEGER NOT NULL DEFAULT 1 CHECK (web_app_notification_sound_enabled IN (0, 1))',
                'ALTER TABLE users ADD COLUMN web_app_notification_call_invites_enabled INTEGER NOT NULL DEFAULT 1 CHECK (web_app_notification_call_invites_enabled IN (0, 1))',
                'ALTER TABLE users ADD COLUMN web_app_notification_call_reminders_enabled INTEGER NOT NULL DEFAULT 1 CHECK (web_app_notification_call_reminders_enabled IN (0, 1))',
                'ALTER TABLE users ADD COLUMN web_app_notification_chat_mentions_enabled INTEGER NOT NULL DEFAULT 1 CHECK (web_app_notification_chat_mentions_enabled IN (0, 1))',
            ],
        ],
    ];
}
