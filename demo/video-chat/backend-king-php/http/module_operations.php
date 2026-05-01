<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/operations/video_operations.php';

function videochat_handle_operations_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path !== '/api/admin/video-operations') {
        return null;
    }

    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/video-operations.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    try {
        $pdo = $openDatabase();
        return $jsonResponse(200, videochat_video_operations_snapshot($pdo));
    } catch (Throwable) {
        return $errorResponse(
            500,
            'video_operations_unavailable',
            'Could not load video operations metrics.'
        );
    }
}
