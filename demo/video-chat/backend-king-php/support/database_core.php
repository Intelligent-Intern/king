<?php

declare(strict_types=1);

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
    $pdo->exec('PRAGMA busy_timeout = 5000');
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
