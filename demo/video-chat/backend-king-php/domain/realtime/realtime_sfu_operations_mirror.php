<?php

declare(strict_types=1);

function videochat_sfu_main_mirror_room_id(string $roomId): string
{
    $normalized = trim($roomId);
    $marker = ':room:';
    $position = strpos($normalized, $marker);
    if (str_starts_with($normalized, 'tenant:') && $position !== false) {
        return trim(substr($normalized, $position + strlen($marker)));
    }
    return $normalized;
}

function videochat_sfu_main_mirror_database(): ?PDO
{
    static $mirrorPdo = false;

    if ($mirrorPdo instanceof PDO) {
        return $mirrorPdo;
    }
    if ($mirrorPdo === null) {
        return null;
    }

    $mainPath = trim((string) (getenv('VIDEOCHAT_KING_DB_PATH') ?: ''));
    $brokerPath = function_exists('videochat_sfu_broker_database_path')
        ? videochat_sfu_broker_database_path()
        : trim((string) (getenv('VIDEOCHAT_KING_SFU_BROKER_DB_PATH') ?: ''));
    if ($mainPath === '' || $brokerPath === '' || $mainPath === $brokerPath) {
        $mirrorPdo = null;
        return null;
    }

    try {
        $pdo = new PDO('sqlite:' . $mainPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        videochat_sfu_bootstrap($pdo);
        $mirrorPdo = $pdo;
        return $mirrorPdo;
    } catch (Throwable) {
        $mirrorPdo = null;
        return null;
    }
}

function videochat_sfu_mirror_upsert_publisher(string $roomId, string $publisherId, string $userId, string $userName, int $updatedAtMs): void
{
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            <<<'SQL'
INSERT INTO sfu_publishers(room_id, publisher_id, user_id, user_name, updated_at_ms)
VALUES(:room_id, :publisher_id, :user_id, :user_name, :updated_at_ms)
ON CONFLICT(room_id, publisher_id) DO UPDATE SET
    user_id = excluded.user_id,
    user_name = excluded.user_name,
    updated_at_ms = excluded.updated_at_ms
SQL
        );
        $statement->execute([
            ':room_id' => videochat_sfu_main_mirror_room_id($roomId),
            ':publisher_id' => $publisherId,
            ':user_id' => $userId,
            ':user_name' => $userName,
            ':updated_at_ms' => $updatedAtMs,
        ]);
    } catch (Throwable) {
        // The broker remains authoritative for media if the operations mirror is locked.
    }
}

function videochat_sfu_mirror_remove_publisher(string $roomId, string $publisherId): void
{
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $mirrorRoomId = videochat_sfu_main_mirror_room_id($roomId);
        $deleteTracks = $pdo->prepare('DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id');
        $deleteTracks->execute([':room_id' => $mirrorRoomId, ':publisher_id' => $publisherId]);
        $deletePublisher = $pdo->prepare('DELETE FROM sfu_publishers WHERE room_id = :room_id AND publisher_id = :publisher_id');
        $deletePublisher->execute([':room_id' => $mirrorRoomId, ':publisher_id' => $publisherId]);
    } catch (Throwable) {
        // Best effort operations mirror cleanup.
    }
}

function videochat_sfu_mirror_upsert_track(
    string $roomId,
    string $publisherId,
    string $trackId,
    string $kind,
    string $label,
    int $updatedAtMs
): void {
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            <<<'SQL'
INSERT INTO sfu_tracks(room_id, publisher_id, track_id, kind, label, updated_at_ms)
VALUES(:room_id, :publisher_id, :track_id, :kind, :label, :updated_at_ms)
ON CONFLICT(room_id, publisher_id, track_id) DO UPDATE SET
    kind = excluded.kind,
    label = excluded.label,
    updated_at_ms = excluded.updated_at_ms
SQL
        );
        $statement->execute([
            ':room_id' => videochat_sfu_main_mirror_room_id($roomId),
            ':publisher_id' => $publisherId,
            ':track_id' => $trackId,
            ':kind' => $kind,
            ':label' => $label,
            ':updated_at_ms' => $updatedAtMs,
        ]);
    } catch (Throwable) {
        // Best effort operations mirror update.
    }
}

function videochat_sfu_mirror_touch_publisher(string $roomId, string $publisherId, int $updatedAtMs): void
{
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id'
        );
        $statement->execute([
            ':room_id' => videochat_sfu_main_mirror_room_id($roomId),
            ':publisher_id' => $publisherId,
            ':updated_at_ms' => $updatedAtMs,
        ]);
    } catch (Throwable) {
        // Best effort operations mirror touch.
    }
}

function videochat_sfu_mirror_touch_track(string $roomId, string $publisherId, string $trackId, int $updatedAtMs): void
{
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'UPDATE sfu_tracks SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id AND track_id = :track_id'
        );
        $statement->execute([
            ':room_id' => videochat_sfu_main_mirror_room_id($roomId),
            ':publisher_id' => $publisherId,
            ':track_id' => $trackId,
            ':updated_at_ms' => $updatedAtMs,
        ]);
    } catch (Throwable) {
        // Best effort operations mirror touch.
    }
}

function videochat_sfu_mirror_remove_track(string $roomId, string $publisherId, string $trackId): void
{
    $pdo = videochat_sfu_main_mirror_database();
    if (!$pdo instanceof PDO) {
        return;
    }

    try {
        $statement = $pdo->prepare(
            'DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id AND track_id = :track_id'
        );
        $statement->execute([
            ':room_id' => videochat_sfu_main_mirror_room_id($roomId),
            ':publisher_id' => $publisherId,
            ':track_id' => $trackId,
        ]);
    } catch (Throwable) {
        // Best effort operations mirror cleanup.
    }
}
