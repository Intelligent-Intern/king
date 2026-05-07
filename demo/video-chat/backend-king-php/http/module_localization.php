<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/localization/translation_imports.php';
require_once __DIR__ . '/../support/auth_request.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_localization_actor_is_admin(array $apiAuthContext): bool
{
    $user = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
    return (string) ($user['role'] ?? '') === 'admin';
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

function videochat_localization_normalized_resource_from_payload(PDO $pdo, mixed $entry, int $index): array
{
    if (!is_array($entry)) {
        return ['error' => ['index' => $index, 'field' => 'resource', 'code' => 'invalid_resource']];
    }

    $locale = videochat_normalize_locale_code($entry['locale'] ?? '');
    $namespace = videochat_translation_clean_text($entry['namespace'] ?? '', 120);
    $resourceKey = videochat_translation_clean_text($entry['resource_key'] ?? '', 240);
    $tenantId = isset($entry['tenant_id']) && is_scalar($entry['tenant_id']) && trim((string) $entry['tenant_id']) !== ''
        ? (int) $entry['tenant_id']
        : null;

    if ($locale === '' || !videochat_locale_is_supported($pdo, $locale)) {
        return ['error' => ['index' => $index, 'field' => 'locale', 'code' => 'unsupported_locale']];
    }
    if ($namespace === '' || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,119}$/', $namespace) !== 1) {
        return ['error' => ['index' => $index, 'field' => 'namespace', 'code' => 'invalid_namespace']];
    }
    if ($resourceKey === '' || preg_match('/^[A-Za-z0-9_.:-]{1,240}$/', $resourceKey) !== 1) {
        return ['error' => ['index' => $index, 'field' => 'resource_key', 'code' => 'invalid_resource_key']];
    }
    if (!videochat_translation_tenant_exists($pdo, $tenantId)) {
        return ['error' => ['index' => $index, 'field' => 'tenant_id', 'code' => 'unknown_tenant']];
    }

    return [
        'resource' => [
            'tenant_id' => $tenantId,
            'locale' => $locale,
            'namespace' => $namespace,
            'resource_key' => $resourceKey,
            'value' => (string) ($entry['value'] ?? ''),
        ],
    ];
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

    if ($path === '/api/admin/localization/resources') {
        if ($method !== 'PUT') {
            return $errorResponse(405, 'method_not_allowed', 'Use PUT for /api/admin/localization/resources.', [
                'allowed_methods' => ['PUT'],
            ]);
        }
        if (!videochat_localization_actor_is_admin($apiAuthContext)) {
            return $errorResponse(403, 'localization_admin_required', 'Only admins can edit localization resources.', [
                'required_role' => 'admin',
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload) || !is_array($payload['resources'] ?? null)) {
            return $errorResponse(400, 'localization_resources_invalid_request_body', 'Localization resources payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $resources = [];
            $errors = [];
            foreach ((array) $payload['resources'] as $index => $entry) {
                $normalized = videochat_localization_normalized_resource_from_payload($pdo, $entry, (int) $index);
                if (isset($normalized['error'])) {
                    $errors[] = $normalized['error'];
                    continue;
                }
                $resources[] = $normalized['resource'];
            }
            if ($errors !== []) {
                return $errorResponse(422, 'localization_resources_validation_failed', 'Localization resources failed validation.', [
                    'errors' => $errors,
                ]);
            }

            $actorUserId = (int) (($apiAuthContext['user'] ?? [])['id'] ?? 0);
            $startedTransaction = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }
            try {
                foreach ($resources as $resource) {
                    videochat_upsert_translation_resource($pdo, $resource, $actorUserId);
                }
                if ($startedTransaction) {
                    $pdo->commit();
                }
            } catch (Throwable $error) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'saved_count' => count($resources),
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'localization_resources_save_failed', 'Could not save localization resources.', [
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
        return $errorResponse(410, 'localization_csv_import_disabled', 'Localization CSV imports are disabled.', [
            'replacement' => '/api/admin/localization/resources',
        ]);
    }

    return null;
}
