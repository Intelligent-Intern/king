<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/localization/translation_imports.php';
require_once __DIR__ . '/../support/auth_request.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_localization_actor_is_superadmin(array $apiAuthContext): bool
{
    $user = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
    return (string) ($user['role'] ?? '') === 'admin' && (int) ($user['id'] ?? 0) === 1;
}

function videochat_localization_csv_from_payload(array $payload): string
{
    return (string) ($payload['csv'] ?? ($payload['content'] ?? ''));
}

/**
 * @return array<int, string>
 */
function videochat_localization_namespaces_from_query(array $queryParams): array
{
    $raw = (string) ($queryParams['namespaces'] ?? ($queryParams['namespace'] ?? ''));
    $namespaces = [];
    foreach (explode(',', $raw) as $namespace) {
        $normalized = trim($namespace);
        if ($normalized === '' || preg_match('/^[a-z][a-z0-9_.-]{0,119}$/', $normalized) !== 1) {
            continue;
        }
        $namespaces[] = $normalized;
    }

    return array_values(array_unique($namespaces));
}

function videochat_handle_localization_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/localization/resources') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/localization/resources.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $queryParams = videochat_request_query_params($request);
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $requestedLocale = (string) ($queryParams['locale'] ?? (($apiAuthContext['user'] ?? [])['locale'] ?? 'en'));
        $namespaces = videochat_localization_namespaces_from_query($queryParams);

        try {
            $pdo = $openDatabase();
            $payload = videochat_localization_payload($pdo, $requestedLocale);
            $locale = (string) ($payload['locale'] ?? videochat_default_locale_code());
            $defaultLocale = videochat_default_locale_code();
            $resources = videochat_fetch_translation_resources($pdo, $locale, $tenantId > 0 ? $tenantId : null, $namespaces);
            $fallbackResources = $locale === $defaultLocale
                ? $resources
                : videochat_fetch_translation_resources($pdo, $defaultLocale, $tenantId > 0 ? $tenantId : null, $namespaces);

            return $jsonResponse(200, [
                'status' => 'ok',
                'locale' => $locale,
                'direction' => (string) ($payload['direction'] ?? 'ltr'),
                'tenant_id' => $tenantId > 0 ? $tenantId : null,
                'namespaces' => $namespaces,
                'resources' => $resources,
                'fallback_locale' => $defaultLocale,
                'fallback_resources' => $fallbackResources,
                'supported_locales' => is_array($payload['supported_locales'] ?? null) ? $payload['supported_locales'] : [],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_resources_failed', 'Could not load translation resources.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (!str_starts_with($path, '/api/admin/localization')) {
        return null;
    }

    if ($path === '/api/admin/localization/locales') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/localization/locales.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            return $jsonResponse(200, [
                'status' => 'ok',
                'locales' => videochat_supported_locale_payload($pdo),
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_locale_list_failed', 'Could not load supported locales.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/admin/localization/bundles') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/localization/bundles.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            return $jsonResponse(200, [
                'status' => 'ok',
                'bundles' => videochat_list_translation_bundles($pdo),
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_bundle_list_failed', 'Could not load translation bundles.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (preg_match('#^/api/admin/localization/bundles/([A-Za-z0-9_-]+)/([A-Za-z0-9_.-]+)$#', $path, $matches) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/localization bundle details.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $queryParams = videochat_request_query_params($request);
        $tenantId = isset($queryParams['tenant_id']) && trim((string) $queryParams['tenant_id']) !== ''
            ? (int) $queryParams['tenant_id']
            : null;
        try {
            $pdo = $openDatabase();
            $bundle = videochat_fetch_translation_bundle($pdo, (string) $matches[1], (string) $matches[2], $tenantId);
            if (!is_array($bundle)) {
                return $errorResponse(404, 'localization_bundle_not_found', 'The requested translation bundle does not exist.', [
                    'locale' => (string) $matches[1],
                    'namespace' => (string) $matches[2],
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'bundle' => $bundle,
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_bundle_fetch_failed', 'Could not load translation bundle.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/admin/localization/imports') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/localization/imports.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $queryParams = videochat_request_query_params($request);
        try {
            $pdo = $openDatabase();
            $listing = videochat_list_translation_imports(
                $pdo,
                (int) ($queryParams['page'] ?? 1),
                (int) ($queryParams['page_size'] ?? 20)
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'imports' => $listing['rows'],
                'pagination' => [
                    'page' => (int) $listing['page'],
                    'page_size' => (int) $listing['page_size'],
                    'total' => (int) $listing['total'],
                    'page_count' => (int) $listing['page_count'],
                    'has_prev' => (int) $listing['page'] > 1,
                    'has_next' => (int) $listing['page'] < (int) $listing['page_count'],
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_import_list_failed', 'Could not load localization imports.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (
        !in_array($path, ['/api/admin/localization/imports/preview', '/api/admin/localization/imports/commit'], true)
        && preg_match('#^/api/admin/localization/imports/([A-Za-z0-9_:-]+)$#', $path, $matches) === 1
    ) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/localization import details.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $import = videochat_fetch_translation_import($pdo, (string) $matches[1]);
            if (!is_array($import)) {
                return $errorResponse(404, 'localization_import_not_found', 'The requested localization import does not exist.', [
                    'import_id' => (string) $matches[1],
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'import' => $import,
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_import_fetch_failed', 'Could not load localization import.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (in_array($path, ['/api/admin/localization/imports/preview', '/api/admin/localization/imports/commit'], true)) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for localization CSV import operations.', [
                'allowed_methods' => ['POST'],
            ]);
        }
        if (!videochat_localization_actor_is_superadmin($apiAuthContext)) {
            return $errorResponse(403, 'localization_superadmin_required', 'Only the primary superadmin can import language CSV files.', [
                'required_user_id' => 1,
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'localization_import_invalid_request_body', 'Localization CSV import payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $csv = videochat_localization_csv_from_payload($payload);
        $fileName = videochat_translation_clean_text($payload['file_name'] ?? '', 255);
        $tenantId = isset($payload['tenant_id']) && is_scalar($payload['tenant_id']) && trim((string) $payload['tenant_id']) !== ''
            ? (int) $payload['tenant_id']
            : null;

        try {
            $pdo = $openDatabase();
            if ($path === '/api/admin/localization/imports/preview') {
                $preview = videochat_preview_translation_csv($pdo, $csv, $tenantId);
                return $jsonResponse(200, [
                    'status' => 'ok',
                    'result' => [
                        'state' => 'previewed',
                        'preview' => $preview,
                    ],
                    'time' => gmdate('c'),
                ]);
            }

            $actorUserId = (int) (($apiAuthContext['user'] ?? [])['id'] ?? 0);
            $commit = videochat_commit_translation_csv($pdo, $actorUserId, $csv, $fileName, $tenantId);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_import_failed', 'Localization CSV import failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($commit['ok'] ?? false)) {
            $reason = (string) ($commit['reason'] ?? 'import_failed');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'localization_import_validation_failed', 'Localization CSV import failed validation.', [
                    'preview' => is_array($commit['preview'] ?? null) ? $commit['preview'] : null,
                ]);
            }

            return $errorResponse(500, 'localization_import_failed', 'Localization CSV import failed due to a backend error.', [
                'reason' => $reason,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'committed',
                'preview' => $commit['preview'] ?? null,
                'import' => $commit['import'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
