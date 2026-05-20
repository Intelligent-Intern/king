<?php

declare(strict_types=1);

const VIDEOCHAT_SQLITE_BUSY_TIMEOUT_MS = 15000;

function videochat_sqlite_is_transient_lock(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    return str_contains($message, 'database is locked')
        || str_contains($message, 'database schema is locked')
        || str_contains($message, 'database table is locked');
}

function videochat_sqlite_retry_delay_us(int $attempt, int $baseDelayUs = 100_000, int $maxDelayUs = 750_000): int
{
    $boundedAttempt = max(1, min($attempt, 10));
    $delay = min($maxDelayUs, $baseDelayUs * $boundedAttempt);
    return $delay + random_int(0, (int) max(10_000, floor($delay / 3)));
}

function videochat_sqlite_pdo_main_path(PDO $pdo): string
{
    try {
        $statement = $pdo->query('PRAGMA database_list');
        foreach ($statement ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['name'] ?? '') !== 'main') {
                continue;
            }
            $path = trim((string) ($row['file'] ?? ''));
            if ($path !== '') {
                return $path;
            }
        }
    } catch (Throwable) {
        return '';
    }

    return '';
}

function videochat_sqlite_ingest(PDO $pdo, string $name, callable $writer): mixed
{
    $ingestDepth = (int) ($GLOBALS['videochat_sqlite_ingest_depth'] ?? 0);

    if ($ingestDepth > 0) {
        return $writer();
    }

    $GLOBALS['videochat_sqlite_ingest_depth'] = $ingestDepth + 1;
    try {
        if (!function_exists('king_db_ingest')) {
            return $writer();
        }

        $databasePath = videochat_sqlite_pdo_main_path($pdo);
        $lockPath = $databasePath !== ''
            ? $databasePath . '.king-ingestor.lock'
            : sys_get_temp_dir() . '/videochat-sqlite.king-ingestor.lock';

        return king_db_ingest('videochat.sqlite.' . trim($name), $writer, [
            'lock_path' => $lockPath,
            'timeout_ms' => VIDEOCHAT_SQLITE_BUSY_TIMEOUT_MS,
            'poll_us' => 5000,
        ]);
    } finally {
        $GLOBALS['videochat_sqlite_ingest_depth'] = max(0, (int) ($GLOBALS['videochat_sqlite_ingest_depth'] ?? 1) - 1);
    }
}

function videochat_sqlite_ingest_active(): bool
{
    return (int) ($GLOBALS['videochat_sqlite_ingest_depth'] ?? 0) > 0;
}

function videochat_open_sqlite_pdo(string $databasePath): PDO
{
    $trimmedPath = trim($databasePath);
    if ($trimmedPath === '') {
        throw new InvalidArgumentException('VIDEOCHAT_KING_DB_PATH must not be empty.');
    }

    $directory = dirname($trimmedPath);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Could not create sqlite directory: %s', $directory));
    }

    $pdo = new PDO('sqlite:' . $trimmedPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = ' . VIDEOCHAT_SQLITE_BUSY_TIMEOUT_MS);
    $pdo->exec('PRAGMA synchronous = NORMAL');

    return $pdo;
}

/**
 * @return array<int, array{
 *   email: string,
 *   display_name: string,
 *   role: string,
 *   password: string,
 *   time_format: string,
 *   date_format: string,
 *   theme: string
 * }>
 */
