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
    ];
}
