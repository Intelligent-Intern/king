<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_localization.php';

function videochat_localization_resources_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[localization-resources-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_localization_resources_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-localization-resources-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    videochat_localization_resources_assert($tenantId > 0, 'default tenant should exist');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO translation_resources(tenant_id, locale, namespace, resource_key, value, source)
VALUES(:tenant_id, :locale, :namespace, :resource_key, :value, 'contract')
SQL
    );
    $insert->execute([':tenant_id' => null, ':locale' => 'en', ':namespace' => 'common', ':resource_key' => 'save', ':value' => 'Save']);
    $insert->execute([':tenant_id' => null, ':locale' => 'de', ':namespace' => 'common', ':resource_key' => 'save', ':value' => 'Speichern']);
    $insert->execute([':tenant_id' => $tenantId, ':locale' => 'de', ':namespace' => 'common', ':resource_key' => 'save', ':value' => 'Mandant Speichern']);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };
    $decodeJsonBody = static fn (array $request): array => [null, 'unused'];
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $authContext = [
        'user' => ['id' => 2, 'role' => 'user', 'locale' => 'de'],
        'tenant' => ['id' => $tenantId, 'tenant_id' => $tenantId],
    ];

    $response = videochat_handle_localization_routes(
        '/api/localization/resources',
        'GET',
        ['method' => 'GET', 'uri' => '/api/localization/resources?locale=de&namespaces=common'],
        $authContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_resources_assert(is_array($response), 'resource route should return a response');
    videochat_localization_resources_assert((int) ($response['status'] ?? 0) === 200, 'resource route status mismatch');
    $payload = videochat_localization_resources_decode($response);
    videochat_localization_resources_assert((string) ($payload['locale'] ?? '') === 'de', 'resolved locale mismatch');
    videochat_localization_resources_assert((string) ($payload['direction'] ?? '') === 'ltr', 'resolved direction mismatch');
    videochat_localization_resources_assert(
        (string) (($payload['resources'] ?? [])['common.save'] ?? '') === 'Mandant Speichern',
        'tenant resource should override global resource'
    );
    videochat_localization_resources_assert(
        (string) (($payload['fallback_resources'] ?? [])['common.save'] ?? '') === 'Save',
        'fallback resource should include English default'
    );

    $publicResponse = videochat_handle_localization_routes(
        '/api/localization/resources',
        'GET',
        ['method' => 'GET', 'uri' => '/api/localization/resources?locale=de&namespaces=common'],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_localization_resources_assert(is_array($publicResponse), 'public resource route should return a response');
    videochat_localization_resources_assert((int) ($publicResponse['status'] ?? 0) === 200, 'public resource route status mismatch');
    $publicPayload = videochat_localization_resources_decode($publicResponse);
    videochat_localization_resources_assert(
        (string) (($publicPayload['resources'] ?? [])['common.save'] ?? '') === 'Speichern',
        'public resource route should return global translations'
    );
    videochat_localization_resources_assert(
        (string) (($publicPayload['resources'] ?? [])['common.save'] ?? '') !== 'Mandant Speichern',
        'public resource route must not expose tenant overrides without auth context'
    );

    $unsupportedResponse = videochat_handle_localization_routes(
        '/api/localization/resources',
        'GET',
        ['method' => 'GET', 'uri' => '/api/localization/resources?locale=xx&namespaces=common'],
        $authContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    $unsupportedPayload = videochat_localization_resources_decode($unsupportedResponse ?? []);
    videochat_localization_resources_assert((string) ($unsupportedPayload['locale'] ?? '') === 'en', 'unsupported locale should resolve to default locale');

    @unlink($databasePath);
    fwrite(STDOUT, "[localization-resources-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[localization-resources-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
