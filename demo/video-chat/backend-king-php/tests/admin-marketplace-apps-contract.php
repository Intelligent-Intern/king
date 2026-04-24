<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_marketplace.php';

function videochat_admin_marketplace_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-marketplace-apps-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_admin_marketplace_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-marketplace-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $apiAuthContext = [
        'ok' => true,
        'token' => 'sess_admin_contract',
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ],
        'session' => ['id' => 'sess_admin_contract'],
    ];

    $emptyList = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'GET',
        ['method' => 'GET', 'uri' => '/api/admin/marketplace/apps'],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert(is_array($emptyList), 'empty list response must be an array');
    videochat_admin_marketplace_assert((int) ($emptyList['status'] ?? 0) === 200, 'empty list status should be 200');
    $emptyPayload = videochat_admin_marketplace_decode($emptyList);
    videochat_admin_marketplace_assert((string) ($emptyPayload['status'] ?? '') === 'ok', 'empty list payload status mismatch');
    videochat_admin_marketplace_assert((int) (($emptyPayload['pagination'] ?? [])['total'] ?? -1) === 0, 'empty list total should be 0');

    $invalidCreate = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'POST',
        ['method' => 'POST', 'uri' => '/api/admin/marketplace/apps', 'body' => 'not-json'],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($invalidCreate['status'] ?? 0) === 400, 'invalid create status should be 400');

    $invalidPayload = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/marketplace/apps',
            'body' => json_encode([
                'name' => '',
                'manufacturer' => '',
                'website' => 'notaurl',
                'category' => 'broken',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid payload status should be 422');
    $invalidPayloadBody = videochat_admin_marketplace_decode($invalidPayload);
    videochat_admin_marketplace_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['category'] ?? '') === 'must_be_known_category',
        'invalid payload category error mismatch'
    );

    $createdResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/marketplace/apps',
            'body' => json_encode([
                'name' => 'Shared Whiteboard',
                'manufacturer' => 'Intelligent Intern',
                'website' => 'https://intelligent-intern.com/apps/whiteboard',
                'category' => 'whiteboard',
                'description' => 'Collaborative drawing board for calls.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($createdResponse['status'] ?? 0) === 201, 'create status should be 201');
    $createdPayload = videochat_admin_marketplace_decode($createdResponse);
    videochat_admin_marketplace_assert(
        (string) (($createdPayload['result'] ?? [])['state'] ?? '') === 'created',
        'create state mismatch'
    );
    $createdApp = ($createdPayload['result'] ?? [])['app'] ?? null;
    videochat_admin_marketplace_assert(is_array($createdApp), 'created app payload should be an array');
    $appId = (int) ($createdApp['id'] ?? 0);
    videochat_admin_marketplace_assert($appId > 0, 'created app id should be positive');

    $duplicateResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/admin/marketplace/apps',
            'body' => json_encode([
                'name' => 'Shared Whiteboard',
                'manufacturer' => 'Intelligent Intern',
                'website' => 'https://intelligent-intern.com/apps/whiteboard-v2',
                'category' => 'whiteboard',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($duplicateResponse['status'] ?? 0) === 409, 'duplicate create status should be 409');

    $filteredList = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/admin/marketplace/apps?query=whiteboard&category=whiteboard&page=1&page_size=10',
            'query' => 'query=whiteboard&category=whiteboard&page=1&page_size=10',
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($filteredList['status'] ?? 0) === 200, 'filtered list status should be 200');
    $filteredPayload = videochat_admin_marketplace_decode($filteredList);
    videochat_admin_marketplace_assert((int) (($filteredPayload['pagination'] ?? [])['total'] ?? 0) === 1, 'filtered list total should be 1');
    videochat_admin_marketplace_assert((string) (($filteredPayload['apps'][0] ?? [])['category'] ?? '') === 'whiteboard', 'filtered list category mismatch');

    $updatedResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps/' . $appId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/admin/marketplace/apps/' . $appId,
            'body' => json_encode([
                'category' => 'collaboration',
                'website' => 'https://intelligent-intern.com/apps/shared-whiteboard',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($updatedResponse['status'] ?? 0) === 200, 'update status should be 200');
    $updatedPayload = videochat_admin_marketplace_decode($updatedResponse);
    videochat_admin_marketplace_assert((string) (($updatedPayload['result']['app'] ?? [])['category'] ?? '') === 'collaboration', 'updated category mismatch');

    $fetchResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps/' . $appId,
        'GET',
        ['method' => 'GET', 'uri' => '/api/admin/marketplace/apps/' . $appId],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($fetchResponse['status'] ?? 0) === 200, 'fetch status should be 200');
    $fetchPayload = videochat_admin_marketplace_decode($fetchResponse);
    videochat_admin_marketplace_assert(
        (string) (($fetchPayload['result']['app'] ?? [])['website'] ?? '') === 'https://intelligent-intern.com/apps/shared-whiteboard',
        'fetch website mismatch'
    );

    $deleteResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps/' . $appId,
        'DELETE',
        ['method' => 'DELETE', 'uri' => '/api/admin/marketplace/apps/' . $appId],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($deleteResponse['status'] ?? 0) === 200, 'delete status should be 200');

    $missingResponse = videochat_handle_marketplace_routes(
        '/api/admin/marketplace/apps/' . $appId,
        'GET',
        ['method' => 'GET', 'uri' => '/api/admin/marketplace/apps/' . $appId],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_admin_marketplace_assert((int) ($missingResponse['status'] ?? 0) === 404, 'deleted app fetch status should be 404');

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-marketplace-apps-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-marketplace-apps-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
