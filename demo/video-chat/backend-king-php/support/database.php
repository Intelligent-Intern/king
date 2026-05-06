<?php

declare(strict_types=1);

require_once __DIR__ . '/config_hardening.php';
require_once __DIR__ . '/database_core.php';
require_once __DIR__ . '/database_demo_seed.php';
require_once __DIR__ . '/database_migrations.php';

function videochat_bootstrap_exec_schema_statement(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $error) {
        if (str_contains(strtolower($error->getMessage()), 'duplicate column name')) {
            return;
        }

        throw $error;
    }
}

function videochat_bootstrap_sqlite_table_exists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
    );
    $statement->execute([':name' => $tableName]);

    return $statement->fetchColumn() !== false;
}

function videochat_bootstrap_sqlite_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $safeTable = preg_replace('/[^A-Za-z0-9_]/', '', $tableName);
    if (!is_string($safeTable) || $safeTable === '') {
        return false;
    }

    $columns = $pdo->query('PRAGMA table_info(' . $safeTable . ')');
    foreach ($columns ?: [] as $column) {
        if (strcasecmp((string) ($column['name'] ?? ''), $columnName) === 0) {
            return true;
        }
    }

    return false;
}

function videochat_bootstrap_add_column_if_missing(
    PDO $pdo,
    string $tableName,
    string $columnName,
    string $sql
): void {
    if (!videochat_bootstrap_sqlite_table_exists($pdo, $tableName)) {
        return;
    }
    if (videochat_bootstrap_sqlite_column_exists($pdo, $tableName, $columnName)) {
        return;
    }

    videochat_bootstrap_exec_schema_statement($pdo, $sql);
}

function videochat_bootstrap_repair_additive_schema(PDO $pdo): void
{
    foreach (videochat_localization_migration_statements() as $sql) {
        videochat_bootstrap_exec_schema_statement($pdo, $sql);
    }
    foreach (videochat_translation_import_history_migration_statements() as $sql) {
        videochat_bootstrap_exec_schema_statement($pdo, $sql);
    }
    foreach (videochat_workspace_app_configuration_migration_statements() as $sql) {
        videochat_bootstrap_exec_schema_statement($pdo, $sql);
    }
    foreach (videochat_user_profile_migration_entries() as $migration) {
        foreach ($migration['statements'] as $sql) {
            videochat_bootstrap_exec_schema_statement($pdo, $sql);
        }
    }

    $additiveColumns = [
        [
            'users',
            'date_format',
            "ALTER TABLE users ADD COLUMN date_format TEXT NOT NULL DEFAULT 'dmy_dot' CHECK (date_format IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot'))",
        ],
        [
            'users',
            'post_logout_landing_url',
            "ALTER TABLE users ADD COLUMN post_logout_landing_url TEXT NOT NULL DEFAULT ''",
        ],
        [
            'sessions',
            'post_logout_landing_url',
            "ALTER TABLE sessions ADD COLUMN post_logout_landing_url TEXT NOT NULL DEFAULT ''",
        ],
        [
            'users',
            'theme_editor_enabled',
            'ALTER TABLE users ADD COLUMN theme_editor_enabled INTEGER NOT NULL DEFAULT 0 CHECK (theme_editor_enabled IN (0, 1))',
        ],
        [
            'appointment_calendar_settings',
            'slot_mode',
            "ALTER TABLE appointment_calendar_settings ADD COLUMN slot_mode TEXT NOT NULL DEFAULT 'selected_dates' CHECK (slot_mode IN ('selected_dates', 'recurring_weekly'))",
        ],
        [
            'call_participant_activity',
            'sample_history_json',
            "ALTER TABLE call_participant_activity ADD COLUMN sample_history_json TEXT NOT NULL DEFAULT '[]'",
        ],
    ];

    foreach ($additiveColumns as [$tableName, $columnName, $sql]) {
        videochat_bootstrap_add_column_if_missing($pdo, $tableName, $columnName, $sql);
    }

    if (videochat_bootstrap_sqlite_column_exists($pdo, 'users', 'date_format')) {
        videochat_bootstrap_exec_schema_statement(
            $pdo,
            <<<'SQL'
UPDATE users
SET date_format = 'dmy_dot'
WHERE date_format IS NULL
   OR trim(date_format) = ''
   OR lower(date_format) NOT IN ('dmy_dot', 'dmy_slash', 'dmy_dash', 'ymd_dash', 'ymd_slash', 'ymd_dot', 'ymd_compact', 'mdy_slash', 'mdy_dash', 'mdy_dot')
SQL
        );
    }
    if (
        videochat_bootstrap_sqlite_column_exists($pdo, 'users', 'locale')
        && videochat_bootstrap_sqlite_table_exists($pdo, 'supported_locales')
    ) {
        videochat_bootstrap_exec_schema_statement(
            $pdo,
            <<<'SQL'
UPDATE users
SET locale = 'en'
WHERE locale IS NULL
   OR trim(locale) = ''
   OR lower(locale) NOT IN (SELECT code FROM supported_locales WHERE is_enabled = 1)
SQL
        );
    }
}

function videochat_bootstrap_sqlite(string $databasePath): array
{
    $trimmedPath = trim($databasePath);
    $lockPath = $trimmedPath . '.bootstrap.lock';
    $lockDirectory = dirname($lockPath);
    if (!is_dir($lockDirectory) && !mkdir($lockDirectory, 0775, true) && !is_dir($lockDirectory)) {
        throw new RuntimeException(sprintf('Could not create sqlite bootstrap lock directory: %s', $lockDirectory));
    }
    $lockHandle = fopen($lockPath, 'c');
    if (!is_resource($lockHandle)) {
        throw new RuntimeException(sprintf('Could not open sqlite bootstrap lock: %s', $lockPath));
    }
    $locked = flock($lockHandle, LOCK_EX);
    if (!$locked) {
        fclose($lockHandle);
        throw new RuntimeException(sprintf('Could not acquire sqlite bootstrap lock: %s', $lockPath));
    }

    try {
        $pdo = videochat_open_sqlite_pdo($trimmedPath);
        $journalMode = (string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn();

        $pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    version INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    applied_at TEXT NOT NULL
)
SQL
        );

        $appliedVersions = [];
        $appliedRows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC');
        foreach ($appliedRows as $row) {
            $appliedVersions[] = (int) ($row['version'] ?? 0);
        }

        $migrationMap = videochat_sqlite_migrations();
        ksort($migrationMap);

        $newlyApplied = 0;
        foreach ($migrationMap as $version => $migration) {
            if (in_array($version, $appliedVersions, true)) {
                continue;
            }

            $pdo->beginTransaction();
            try {
                foreach ($migration['statements'] as $sql) {
                    videochat_bootstrap_exec_schema_statement($pdo, $sql);
                }

                $insert = $pdo->prepare(
                    'INSERT INTO schema_migrations(version, name, applied_at) VALUES(:version, :name, :applied_at)'
                );
                $insert->execute([
                    ':version' => $version,
                    ':name' => $migration['name'],
                    ':applied_at' => gmdate('c'),
                ]);
                $pdo->commit();
            } catch (Throwable $error) {
                $pdo->rollBack();
                $message = strtolower($error->getMessage());
                $isMigrationRace = str_contains($message, 'unique constraint failed')
                    && str_contains($message, 'schema_migrations.version');
                if ($isMigrationRace) {
                    if (!in_array($version, $appliedVersions, true)) {
                        $appliedVersions[] = $version;
                        sort($appliedVersions);
                    }
                    continue;
                }
                throw $error;
            }

            $appliedVersions[] = $version;
            sort($appliedVersions);
            $newlyApplied++;
        }

        videochat_bootstrap_repair_additive_schema($pdo);

        $seededDemoUsers = [];
        $seededDemoCalls = [];
        // HTTP workers bootstrap the same SQLite file; serialize fixed demo IDs.
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $seededDemoUsers = videochat_seed_demo_users($pdo);
            if (function_exists('videochat_tenant_backfill_default_memberships')) {
                videochat_tenant_backfill_default_memberships($pdo);
            }
            $seededDemoCalls = videochat_seed_demo_calls($pdo);
            if (function_exists('videochat_tenant_backfill_default_owned_records')) {
                videochat_tenant_backfill_default_owned_records($pdo);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw $error;
        }

        $tableNames = [];
        $tableRows = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
        );
        foreach ($tableRows as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name !== '') {
                $tableNames[] = $name;
            }
        }

        $schemaVersion = empty($appliedVersions) ? 0 : max($appliedVersions);
        $migrationTotal = count($migrationMap);
        $migrationApplied = count($appliedVersions);

        return [
            'path' => $trimmedPath,
            'schema_version' => $schemaVersion,
            'migrations_total' => $migrationTotal,
            'migrations_applied' => $migrationApplied,
            'migrations_newly_applied' => $newlyApplied,
            'migrations_pending' => max($migrationTotal - $migrationApplied, 0),
            'applied_versions' => $appliedVersions,
            'table_count' => count($tableNames),
            'table_names' => $tableNames,
            'journal_mode' => strtoupper($journalMode),
            'demo_users' => $seededDemoUsers,
            'demo_calls' => $seededDemoCalls,
        ];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function videochat_sqlite_runtime_snapshot(string $databasePath): array
{
    $trimmedPath = trim($databasePath);
    $pdo = videochat_open_sqlite_pdo($trimmedPath);

    $appliedVersions = [];
    $schemaVersion = 0;
    if (videochat_bootstrap_sqlite_table_exists($pdo, 'schema_migrations')) {
        $appliedRows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version ASC');
        foreach ($appliedRows as $row) {
            $version = (int) ($row['version'] ?? 0);
            if ($version > 0) {
                $appliedVersions[] = $version;
            }
        }
        $schemaVersion = empty($appliedVersions) ? 0 : max($appliedVersions);
    }

    $tableNames = [];
    $tableRows = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name ASC"
    );
    foreach ($tableRows as $row) {
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $tableNames[] = $name;
        }
    }

    $demoUsers = [];
    if (
        videochat_bootstrap_sqlite_table_exists($pdo, 'users')
        && videochat_bootstrap_sqlite_table_exists($pdo, 'roles')
    ) {
        $demoEmails = array_map(
            static fn (array $user): string => strtolower(trim((string) ($user['email'] ?? ''))),
            videochat_demo_user_blueprint()
        );
        $demoEmails = array_values(array_filter(array_unique($demoEmails), static fn (string $email): bool => $email !== ''));
        if ($demoEmails !== []) {
            $placeholders = implode(',', array_fill(0, count($demoEmails), '?'));
            $userRows = $pdo->prepare(
                <<<SQL
SELECT users.email, users.display_name, roles.slug AS role
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) IN ({$placeholders})
ORDER BY users.id ASC
SQL
            );
            $userRows->execute($demoEmails);
            foreach ($userRows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                $demoUsers[] = [
                    'email' => $email,
                    'display_name' => (string) ($row['display_name'] ?? ''),
                    'role' => (string) ($row['role'] ?? 'user'),
                ];
            }
        }
    }

    $migrationMap = videochat_sqlite_migrations();
    $journalMode = (string) $pdo->query('PRAGMA journal_mode')->fetchColumn();

    return [
        'path' => $trimmedPath,
        'schema_version' => $schemaVersion,
        'migrations_total' => count($migrationMap),
        'migrations_applied' => count($appliedVersions),
        'migrations_newly_applied' => 0,
        'migrations_pending' => max(count($migrationMap) - count($appliedVersions), 0),
        'applied_versions' => $appliedVersions,
        'table_count' => count($tableNames),
        'table_names' => $tableNames,
        'journal_mode' => strtoupper($journalMode),
        'demo_users' => $demoUsers,
        'demo_calls' => [],
    ];
}
