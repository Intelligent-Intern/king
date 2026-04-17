<?php

declare(strict_types=1);

function model_inference_open_sqlite_pdo(string $dbPath): PDO
{
    $directory = dirname($dbPath);
    if ($directory !== '' && !is_dir($directory)) {
        if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('unable to create sqlite directory: ' . $directory);
        }
    }

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA synchronous = NORMAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

/**
 * Bootstrap the SQLite backing store for the inference backend.
 *
 * At M-1 this only creates a schema-version registry so /api/runtime can
 * report a truthful database envelope. Later leaves extend the schema with
 * model-registry (M-5) and transcript-index (M-16) tables.
 *
 * @return array<string, mixed>
 */
function model_inference_bootstrap_sqlite(string $dbPath): array
{
    $pdo = model_inference_open_sqlite_pdo($dbPath);

    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
        id INTEGER PRIMARY KEY,
        applied_at TEXT NOT NULL,
        description TEXT NOT NULL
    )');

    $migrations = [
        1 => [
            'description' => 'initial schema-version registry',
            'sql' => [],
        ],
    ];

    $applied = (int) ($pdo->query('SELECT COUNT(1) AS c FROM schema_migrations')->fetch()['c'] ?? 0);
    foreach ($migrations as $id => $migration) {
        $row = $pdo->prepare('SELECT id FROM schema_migrations WHERE id = :id');
        $row->execute([':id' => $id]);
        if ($row->fetch() !== false) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            foreach ($migration['sql'] as $statement) {
                $pdo->exec($statement);
            }
            $insert = $pdo->prepare('INSERT INTO schema_migrations (id, applied_at, description) VALUES (:id, :applied_at, :description)');
            $insert->execute([
                ':id' => $id,
                ':applied_at' => gmdate('c'),
                ':description' => $migration['description'],
            ]);
            $pdo->commit();
            $applied += 1;
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    return [
        'status' => 'ready',
        'schema_version' => max(array_keys($migrations)),
        'migrations_applied' => $applied,
        'migrations_total' => count($migrations),
        'path' => $dbPath,
    ];
}
