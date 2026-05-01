<?php

declare(strict_types=1);

require_once __DIR__ . '/config_hardening.php';
require_once __DIR__ . '/database_core.php';
require_once __DIR__ . '/database_demo_seed.php';
require_once __DIR__ . '/database_migrations.php';

function videochat_bootstrap_sqlite(string $databasePath): array
{
    $trimmedPath = trim($databasePath);
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
                $pdo->exec($sql);
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

    $seededDemoUsers = [];
    $seededDemoCalls = [];
    // HTTP workers bootstrap the same SQLite file; serialize fixed demo IDs.
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $seededDemoUsers = videochat_seed_demo_users($pdo);
        $seededDemoCalls = videochat_seed_demo_calls($pdo);
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
}
