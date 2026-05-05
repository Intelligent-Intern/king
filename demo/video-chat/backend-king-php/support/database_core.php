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
