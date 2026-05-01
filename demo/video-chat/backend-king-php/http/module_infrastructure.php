<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/infrastructure/infrastructure_inventory.php';

function videochat_handle_infrastructure_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse
): ?array {
    if ($path !== '/api/admin/infrastructure') {
        return null;
    }

    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/infrastructure.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    return $jsonResponse(200, videochat_infra_inventory_snapshot());
}
