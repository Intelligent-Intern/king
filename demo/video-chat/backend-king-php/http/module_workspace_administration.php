<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_administration.php';
require_once __DIR__ . '/../domain/workspace/workspace_app_configuration.php';

function videochat_workspace_branding_file_response(
    string $filename,
    string $storageRoot,
    callable $errorResponse
): array {
    $decoded = rawurldecode($filename);
    if (!preg_match('/^brand-(sidebar|modal)-[a-f0-9]{16}\.(png|jpg|webp)$/', $decoded)) {
        return $errorResponse(404, 'branding_file_not_found', 'Branding file could not be found.', [
            'reason' => 'invalid_filename',
        ]);
    }

    $path = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'branding' . DIRECTORY_SEPARATOR . $decoded;
    $realDir = realpath(rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'branding');
    $realPath = realpath($path);
    if (!is_string($realDir) || !is_string($realPath) || !is_file($realPath) || !str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR)) {
        return $errorResponse(404, 'branding_file_not_found', 'Branding file could not be found.', [
            'reason' => 'not_found',
        ]);
    }

    $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'image/png',
    };

    return [
        'status' => 200,
        'headers' => [
            'content-type' => $contentType,
            'cache-control' => 'public, max-age=31536000, immutable',
            'x-content-type-options' => 'nosniff',
        ],
        'body' => (string) @file_get_contents($realPath),
    ];
}

function videochat_handle_workspace_administration_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    string $brandingStorageRoot,
    int $brandingMaxBytes,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/workspace/appearance') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/workspace/appearance.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $appearance = videochat_workspace_public_appearance($pdo);
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_appearance_failed', 'Could not load workspace appearance.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => $appearance,
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/workspace/branding-files/([^/]+)$#', $path, $match) === 1) {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for workspace branding files.', [
                'allowed_methods' => ['GET', 'HEAD'],
            ]);
        }

        return videochat_workspace_branding_file_response((string) ($match[1] ?? ''), $brandingStorageRoot, $errorResponse);
    }

    if (preg_match('#^/api/workspace/background-images/([^/]+)$#', $path, $match) === 1) {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for workspace background images.', [
                'allowed_methods' => ['GET', 'HEAD'],
            ]);
        }

        return videochat_workspace_background_file_response((string) ($match[1] ?? ''), $brandingStorageRoot, $errorResponse);
    }

    if ($path === '/api/public-leads') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/public-leads.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'public_lead_invalid_request_body', 'Lead payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $validation = videochat_workspace_validate_public_lead_payload($payload, $request);
        if (!(bool) ($validation['ok'] ?? false)) {
            return $errorResponse(422, 'public_lead_validation_failed', 'Lead payload failed validation.', [
                'fields' => is_array($validation['errors'] ?? null) ? $validation['errors'] : [],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $lead = is_array($validation['data'] ?? null) ? (array) $validation['data'] : [];
            $leadId = videochat_workspace_store_public_lead($pdo, $lead);
            $notifications = videochat_workspace_send_public_lead_notifications($pdo, $lead);
        } catch (Throwable) {
            return $errorResponse(500, 'public_lead_submit_failed', 'Could not submit lead.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'submitted',
                'lead_id' => $leadId,
                'notification_count' => count($notifications),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/workspace-administration/email-texts(?:/([^/]+))?$#', $path, $emailTextMatch) === 1) {
        $authenticatedUser = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
        if ((int) ($authenticatedUser['id'] ?? 0) !== videochat_workspace_primary_admin_user_id()) {
            return $errorResponse(403, 'primary_admin_required', 'Only the primary admin user can manage app configuration email texts.', [
                'required_user_id' => videochat_workspace_primary_admin_user_id(),
            ]);
        }

        $emailTextId = isset($emailTextMatch[1]) ? rawurldecode((string) $emailTextMatch[1]) : '';
        try {
            $pdo = $openDatabase();
            $tenantId = videochat_workspace_effective_tenant_id($pdo, videochat_tenant_id_from_auth_context($apiAuthContext));
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_email_texts_failed', 'Could not access email texts.', [
                'reason' => 'internal_error',
            ]);
        }

        if ($method === 'GET' && $emailTextId === '') {
            $filters = videochat_workspace_app_filters(videochat_request_query_params($request), 10);
            if (!(bool) ($filters['ok'] ?? false)) {
                return $errorResponse(422, 'workspace_email_texts_query_invalid', 'Email text query failed validation.', [
                    'fields' => $filters['errors'] ?? [],
                ]);
            }
            try {
                $listing = videochat_workspace_list_email_texts(
                    $pdo,
                    $tenantId,
                    (string) ($filters['query'] ?? ''),
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['page_size'] ?? 10)
                );
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_email_texts_failed', 'Could not load email texts.', [
                    'reason' => 'internal_error',
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'rows' => is_array($listing['rows'] ?? null) ? $listing['rows'] : [],
                    'pagination' => [
                        'query' => (string) ($filters['query'] ?? ''),
                        'page' => (int) ($listing['page'] ?? 1),
                        'page_size' => (int) ($listing['page_size'] ?? 10),
                        'total' => (int) ($listing['total'] ?? 0),
                        'page_count' => (int) ($listing['page_count'] ?? 1),
                    ],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if (($method === 'POST' && $emailTextId === '') || ($method === 'PATCH' && $emailTextId !== '')) {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'workspace_email_text_invalid_request_body', 'Email text payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            try {
                $save = videochat_workspace_save_email_text($pdo, $tenantId, $payload, $emailTextId === '' ? null : $emailTextId);
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_email_text_save_failed', 'Could not save email text.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($save['ok'] ?? false)) {
                $reason = (string) ($save['reason'] ?? 'validation_failed');
                return $errorResponse($reason === 'not_found' ? 404 : 422, 'workspace_email_text_validation_failed', 'Email text payload failed validation.', [
                    'reason' => $reason,
                    'fields' => is_array($save['errors'] ?? null) ? $save['errors'] : [],
                ]);
            }
            return $jsonResponse($method === 'POST' ? 201 : 200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'saved',
                    'row' => is_array($save['row'] ?? null) ? $save['row'] : null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE' && $emailTextId !== '') {
            try {
                $delete = videochat_workspace_delete_email_text($pdo, $tenantId, $emailTextId);
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_email_text_delete_failed', 'Could not delete email text.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($delete['ok'] ?? false)) {
                $reason = (string) ($delete['reason'] ?? 'not_found');
                return $errorResponse($reason === 'system_row_locked' ? 409 : 404, 'workspace_email_text_delete_failed', 'Could not delete email text.', [
                    'reason' => $reason,
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => ['state' => 'deleted'],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use GET, POST, PATCH, or DELETE for workspace email texts.', [
            'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE'],
        ]);
    }

    if (preg_match('#^/api/admin/workspace-administration/background-images(?:/([^/]+))?$#', $path, $backgroundMatch) === 1) {
        $authenticatedUser = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
        if ((int) ($authenticatedUser['id'] ?? 0) !== videochat_workspace_primary_admin_user_id()) {
            return $errorResponse(403, 'primary_admin_required', 'Only the primary admin user can manage background images.', [
                'required_user_id' => videochat_workspace_primary_admin_user_id(),
            ]);
        }

        $backgroundId = isset($backgroundMatch[1]) ? rawurldecode((string) $backgroundMatch[1]) : '';
        try {
            $pdo = $openDatabase();
            $tenantId = videochat_workspace_effective_tenant_id($pdo, videochat_tenant_id_from_auth_context($apiAuthContext));
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_background_images_failed', 'Could not access background images.', [
                'reason' => 'internal_error',
            ]);
        }

        if ($method === 'GET' && $backgroundId === '') {
            $filters = videochat_workspace_app_filters(videochat_request_query_params($request), 12);
            if (!(bool) ($filters['ok'] ?? false)) {
                return $errorResponse(422, 'workspace_background_images_query_invalid', 'Background image query failed validation.', [
                    'fields' => $filters['errors'] ?? [],
                ]);
            }
            try {
                $listing = videochat_workspace_list_background_images(
                    $pdo,
                    $tenantId,
                    (string) ($filters['query'] ?? ''),
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['page_size'] ?? 12)
                );
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_background_images_failed', 'Could not load background images.', [
                    'reason' => 'internal_error',
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'rows' => is_array($listing['rows'] ?? null) ? $listing['rows'] : [],
                    'pagination' => [
                        'query' => (string) ($filters['query'] ?? ''),
                        'page' => (int) ($listing['page'] ?? 1),
                        'page_size' => (int) ($listing['page_size'] ?? 12),
                        'total' => (int) ($listing['total'] ?? 0),
                        'page_count' => (int) ($listing['page_count'] ?? 1),
                    ],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'POST' && $backgroundId === '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'workspace_background_invalid_request_body', 'Background image payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            try {
                $create = videochat_workspace_create_background_images($pdo, $tenantId, $payload, $brandingStorageRoot, $brandingMaxBytes);
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_background_upload_failed', 'Could not upload background images.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($create['ok'] ?? false)) {
                return $errorResponse(422, 'workspace_background_upload_failed', 'Background image payload failed validation.', [
                    'reason' => (string) ($create['reason'] ?? 'validation_failed'),
                    'fields' => is_array($create['errors'] ?? null) ? $create['errors'] : [],
                ]);
            }
            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'stored',
                    'rows' => is_array($create['rows'] ?? null) ? $create['rows'] : [],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE' && $backgroundId !== '') {
            try {
                $delete = videochat_workspace_delete_background_image($pdo, $tenantId, $backgroundId, $brandingStorageRoot);
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_background_delete_failed', 'Could not delete background image.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($delete['ok'] ?? false)) {
                return $errorResponse(404, 'workspace_background_not_found', 'Background image could not be found.', [
                    'reason' => (string) ($delete['reason'] ?? 'not_found'),
                ]);
            }
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => ['state' => 'deleted'],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use GET, POST, or DELETE for workspace background images.', [
            'allowed_methods' => ['GET', 'POST', 'DELETE'],
        ]);
    }

    if (preg_match('#^/api/admin/workspace-administration/themes/([^/]+)$#', $path, $themeMatch) === 1) {
        if ($method !== 'DELETE') {
            return $errorResponse(405, 'method_not_allowed', 'Use DELETE for workspace administration themes.', [
                'allowed_methods' => ['DELETE'],
            ]);
        }

        $authenticatedUser = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
        try {
            $pdo = $openDatabase();
            $canEditThemes = videochat_workspace_user_can_edit_themes($pdo, $authenticatedUser);
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_theme_delete_failed', 'Could not delete theme.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!$canEditThemes) {
            return $errorResponse(403, 'theme_editor_access_required', 'Theme editor access is required.', [
                'required_permission' => 'theme_editor_enabled',
            ]);
        }

        try {
            $deleteResult = videochat_workspace_delete_theme($pdo, rawurldecode((string) ($themeMatch[1] ?? '')), videochat_tenant_id_from_auth_context($apiAuthContext));
            if (!(bool) ($deleteResult['ok'] ?? false)) {
                $reason = (string) ($deleteResult['reason'] ?? 'theme_delete_failed');
                if ($reason === 'system_theme_locked') {
                    return $errorResponse(409, 'workspace_theme_locked', 'System themes cannot be deleted.', [
                        'reason' => $reason,
                    ]);
                }
                return $errorResponse(404, 'workspace_theme_not_found', 'Theme could not be found.', [
                    'reason' => $reason,
                ]);
            }
            $appearance = videochat_workspace_public_appearance($pdo, videochat_tenant_id_from_auth_context($apiAuthContext));
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_theme_delete_failed', 'Could not delete theme.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'deleted',
                'theme' => $deleteResult,
                'appearance' => $appearance,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/admin/workspace-administration') {
        $authenticatedUser = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
        $authenticatedUserId = (int) ($authenticatedUser['id'] ?? 0);
        $isPrimaryAdmin = $authenticatedUserId === videochat_workspace_primary_admin_user_id();
        try {
            $pdo = $openDatabase();
            $canEditThemes = videochat_workspace_user_can_edit_themes($pdo, $authenticatedUser);
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_administration_load_failed', 'Could not load administration settings.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!$isPrimaryAdmin && !$canEditThemes) {
            return $errorResponse(403, 'theme_editor_access_required', 'Theme editor access is required.', [
                'required_permission' => 'theme_editor_enabled',
            ]);
        }

        if ($method === 'GET') {
            try {
                $settings = $isPrimaryAdmin
                    ? videochat_workspace_settings_payload(videochat_workspace_get_admin_settings_row($pdo, videochat_tenant_id_from_auth_context($apiAuthContext)), false)
                    : [];
                $appearance = videochat_workspace_public_appearance($pdo, videochat_tenant_id_from_auth_context($apiAuthContext));
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_administration_load_failed', 'Could not load administration settings.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'loaded',
                    'settings' => $settings,
                    'appearance' => $appearance,
                    'permissions' => [
                        'can_manage_mail_server' => $isPrimaryAdmin,
                        'can_manage_lead_notifications' => $isPrimaryAdmin,
                        'can_manage_branding' => $isPrimaryAdmin,
                        'can_manage_email_texts' => $isPrimaryAdmin,
                        'can_manage_background_images' => $isPrimaryAdmin,
                        'can_edit_themes' => $canEditThemes,
                    ],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/admin/workspace-administration.', [
                'allowed_methods' => ['GET', 'PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'workspace_administration_invalid_request_body', 'Administration payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        if (!$isPrimaryAdmin && !videochat_workspace_payload_has_only_theme($payload)) {
            return $errorResponse(403, 'primary_admin_required', 'Only the primary admin user can change mail, lead, and branding settings.', [
                'required_user_id' => videochat_workspace_primary_admin_user_id(),
            ]);
        }

        $validation = videochat_workspace_validate_admin_payload($payload, $brandingStorageRoot, $brandingMaxBytes);
        if (!(bool) ($validation['ok'] ?? false)) {
            return $errorResponse(422, 'workspace_administration_validation_failed', 'Administration payload failed validation.', [
                'fields' => is_array($validation['errors'] ?? null) ? $validation['errors'] : [],
            ]);
        }

        try {
            $saveResult = videochat_workspace_save_admin_settings(
                $pdo,
                is_array($validation['settings'] ?? null) ? (array) $validation['settings'] : [],
                is_array($validation['theme'] ?? null) ? (array) $validation['theme'] : null,
                videochat_tenant_id_from_auth_context($apiAuthContext)
            );
            $settings = is_array($saveResult['settings'] ?? null) ? (array) $saveResult['settings'] : [];
            $appearance = videochat_workspace_public_appearance($pdo, videochat_tenant_id_from_auth_context($apiAuthContext));
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_administration_save_failed', 'Could not save administration settings.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'saved',
                'settings' => $settings,
                'saved_theme' => is_array($saveResult['saved_theme'] ?? null) ? (array) $saveResult['saved_theme'] : null,
                'appearance' => $appearance,
            ],
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
