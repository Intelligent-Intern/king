<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../domain/users/user_settings.php';
require_once __DIR__ . '/../domain/users/avatar_upload.php';

function videochat_handle_user_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    array $corsHeaders,
    string $avatarStorageRoot,
    int $avatarMaxBytes,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/admin/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'admin',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/admin/users') {
        if ($method === 'GET') {
            $queryParams = videochat_request_query_params($request);
            $filters = videochat_admin_user_list_filters($queryParams);
            if (!(bool) ($filters['ok'] ?? false)) {
                return $errorResponse(422, 'admin_user_list_validation_failed', 'Invalid admin user list query parameters.', [
                    'fields' => $filters['errors'] ?? [],
                ]);
            }

            try {
                $pdo = $openDatabase();
                $listing = videochat_admin_list_users(
                    $pdo,
                    (string) ($filters['query'] ?? ''),
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['page_size'] ?? 10),
                    (string) ($filters['order'] ?? 'role_then_name_asc')
                );
            } catch (Throwable $error) {
                return $errorResponse(500, 'admin_user_list_failed', 'Could not load admin user list.', [
                    'reason' => 'internal_error',
                ]);
            }

            $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
            $total = (int) ($listing['total'] ?? 0);
            $pageCount = (int) ($listing['page_count'] ?? 0);
            $page = (int) ($filters['page'] ?? 1);
            $pageSize = (int) ($filters['page_size'] ?? 10);
            $order = (string) ($filters['order'] ?? 'role_then_name_asc');
            $displayNameSecondary = $order === 'role_then_name_desc'
                ? 'display_name_desc'
                : 'display_name_asc';

            return $jsonResponse(200, [
                'status' => 'ok',
                'users' => $rows,
                'pagination' => [
                    'query' => (string) ($filters['query'] ?? ''),
                    'order' => $order,
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'page_count' => $pageCount,
                    'returned' => count($rows),
                    'has_prev' => $page > 1,
                    'has_next' => $pageCount > 0 && $page < $pageCount,
                ],
                'sort' => [
                    'role_priority' => ['admin', 'user'],
                    'secondary' => $displayNameSecondary,
                    'tie_breaker' => 'id_asc',
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/admin/users.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'admin_user_invalid_request_body', 'User create payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_admin_create_user($pdo, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_create_failed', 'Could not create user.', [
                'reason' => 'internal_error',
            ]);
        }

        $createReason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($createReason === 'validation_failed') {
                return $errorResponse(422, 'admin_user_validation_failed', 'User create payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'email_conflict') {
                return $errorResponse(409, 'admin_user_conflict', 'A user with that email already exists.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : ['email' => 'already_exists'],
                ]);
            }

            return $errorResponse(500, 'admin_user_create_failed', 'Could not create user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'user' => $createResult['user'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method === 'PATCH') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'admin_user_invalid_request_body', 'User update payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $pdo = $openDatabase();
                $updateResult = videochat_admin_update_user($pdo, $userId, $payload);
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_update_failed', 'Could not update user.', [
                    'reason' => 'internal_error',
                ]);
            }

            $updateReason = (string) ($updateResult['reason'] ?? 'internal_error');
            if (!(bool) ($updateResult['ok'] ?? false)) {
                if ($updateReason === 'validation_failed') {
                    return $errorResponse(422, 'admin_user_validation_failed', 'User update payload failed validation.', [
                        'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                    ]);
                }
                if ($updateReason === 'email_conflict') {
                    return $errorResponse(409, 'admin_user_conflict', 'A user with that email already exists.', [
                        'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : ['email' => 'already_exists'],
                    ]);
                }
                if ($updateReason === 'not_found') {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }

                return $errorResponse(500, 'admin_user_update_failed', 'Could not update user.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'updated',
                    'user' => $updateResult['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_admin_delete_user($pdo, $userId);
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_delete_failed', 'Could not delete user.', [
                    'reason' => 'internal_error',
                ]);
            }

            $deleteReason = (string) ($deleteResult['reason'] ?? 'internal_error');
            if (!(bool) ($deleteResult['ok'] ?? false)) {
                if ($deleteReason === 'not_found') {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }

                return $errorResponse(500, 'admin_user_delete_failed', 'Could not delete user.', [
                    'reason' => 'internal_error',
                ]);
            }

            $deletedUser = is_array($deleteResult['user'] ?? null) ? $deleteResult['user'] : [];
            $avatarFileRemoved = videochat_avatar_delete_file_if_managed(
                $avatarStorageRoot,
                is_string($deletedUser['avatar_path'] ?? null) ? (string) $deletedUser['avatar_path'] : null
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'user_id' => $userId,
                    'user' => $deletedUser,
                    'deleted_calls' => (int) ($deleteResult['deleted_calls'] ?? 0),
                    'deleted_invite_codes' => (int) ($deleteResult['deleted_invite_codes'] ?? 0),
                    'avatar_file_removed' => $avatarFileRemoved,
                ],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use PATCH or DELETE for /api/admin/users/{id}.', [
            'allowed_methods' => ['PATCH', 'DELETE'],
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)/avatar$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method === 'POST') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'admin_user_avatar_invalid_request_body', 'Admin avatar upload payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $pdo = $openDatabase();
                $uploadResult = videochat_store_avatar_for_user(
                    $pdo,
                    $userId,
                    $payload,
                    $avatarStorageRoot,
                    $avatarMaxBytes
                );
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_avatar_upload_failed', 'Could not upload user avatar.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($uploadResult['ok'] ?? false)) {
                $reason = (string) ($uploadResult['reason'] ?? 'internal_error');
                if ($reason === 'validation_failed') {
                    return $errorResponse(422, 'admin_user_avatar_validation_failed', 'Admin avatar upload payload failed validation.', [
                        'fields' => is_array($uploadResult['errors'] ?? null) ? $uploadResult['errors'] : [],
                    ]);
                }
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }

                return $errorResponse(500, 'admin_user_avatar_upload_failed', 'Could not upload user avatar.', [
                    'reason' => $reason,
                ]);
            }

            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'uploaded',
                    'user_id' => $userId,
                    'avatar_path' => $uploadResult['avatar_path'] ?? null,
                    'content_type' => $uploadResult['content_type'] ?? null,
                    'bytes' => (int) ($uploadResult['bytes'] ?? 0),
                    'file_name' => $uploadResult['file_name'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteAvatarResult = videochat_delete_avatar_for_user($pdo, $userId, $avatarStorageRoot);
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_avatar_delete_failed', 'Could not delete user avatar.', [
                    'reason' => 'internal_error',
                ]);
            }

            $deleteReason = (string) ($deleteAvatarResult['reason'] ?? 'internal_error');
            if (!(bool) ($deleteAvatarResult['ok'] ?? false)) {
                if ($deleteReason === 'not_found') {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }

                return $errorResponse(500, 'admin_user_avatar_delete_failed', 'Could not delete user avatar.', [
                    'reason' => $deleteReason,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => $deleteReason,
                    'user_id' => $userId,
                    'avatar_path' => $deleteAvatarResult['avatar_path'] ?? null,
                    'avatar_file_removed' => (bool) ($deleteAvatarResult['removed_file'] ?? false),
                ],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use POST or DELETE for /api/admin/users/{id}/avatar.', [
            'allowed_methods' => ['POST', 'DELETE'],
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)/deactivate$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/admin/users/{id}/deactivate.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $deactivateResult = videochat_admin_deactivate_user($pdo, $userId);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_deactivate_failed', 'Could not deactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        $deactivateReason = (string) ($deactivateResult['reason'] ?? 'internal_error');
        if (!(bool) ($deactivateResult['ok'] ?? false)) {
            if ($deactivateReason === 'not_found') {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            return $errorResponse(500, 'admin_user_deactivate_failed', 'Could not deactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => $deactivateReason,
                'revoked_sessions' => (int) ($deactivateResult['revoked_sessions'] ?? 0),
                'user' => $deactivateResult['user'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)/reactivate$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/admin/users/{id}/reactivate.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $reactivateResult = videochat_admin_reactivate_user($pdo, $userId);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_reactivate_failed', 'Could not reactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        $reactivateReason = (string) ($reactivateResult['reason'] ?? 'internal_error');
        if (!(bool) ($reactivateResult['ok'] ?? false)) {
            if ($reactivateReason === 'not_found') {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            return $errorResponse(500, 'admin_user_reactivate_failed', 'Could not reactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => $reactivateReason,
                'user' => $reactivateResult['user'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/moderation/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/moderation/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'moderation',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/user/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'user',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/user/avatar') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/user/avatar.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'user_avatar_invalid_request_body', 'Avatar upload payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $uploadResult = videochat_store_avatar_for_user(
                $pdo,
                $authenticatedUserId,
                $payload,
                $avatarStorageRoot,
                $avatarMaxBytes
            );
        } catch (Throwable) {
            return $errorResponse(500, 'user_avatar_upload_failed', 'Could not upload avatar.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($uploadResult['ok'] ?? false)) {
            $reason = (string) ($uploadResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'user_avatar_validation_failed', 'Avatar upload payload failed validation.', [
                    'fields' => is_array($uploadResult['errors'] ?? null) ? $uploadResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $errorResponse(500, 'user_avatar_upload_failed', 'Could not upload avatar.', [
                'reason' => $reason,
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'uploaded',
                'avatar_path' => $uploadResult['avatar_path'] ?? null,
                'content_type' => $uploadResult['content_type'] ?? null,
                'bytes' => (int) ($uploadResult['bytes'] ?? 0),
                'file_name' => $uploadResult['file_name'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/user/avatar-files/([A-Za-z0-9._-]{1,200})$#', $path, $avatarMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/avatar-files/{filename}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $fileName = (string) ($avatarMatch[1] ?? '');
        $resolvedPath = videochat_avatar_resolve_read_path($avatarStorageRoot, $fileName);
        if (!is_string($resolvedPath) || !is_file($resolvedPath)) {
            return $errorResponse(404, 'user_avatar_not_found', 'Avatar file does not exist.', [
                'file_name' => $fileName,
            ]);
        }

        $binary = @file_get_contents($resolvedPath);
        if (!is_string($binary)) {
            return $errorResponse(500, 'user_avatar_read_failed', 'Could not read avatar file.', [
                'reason' => 'read_failed',
            ]);
        }

        $mime = videochat_avatar_detect_mime_from_binary($binary) ?? 'application/octet-stream';
        return [
            'status' => 200,
            'headers' => [
                'content-type' => $mime,
                'cache-control' => 'private, max-age=60',
                ...$corsHeaders,
            ],
            'body' => $binary,
        ];
    }

    if ($path === '/api/user/settings') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $userSettings = videochat_fetch_user_settings($pdo, $authenticatedUserId);
            } catch (Throwable) {
                return $errorResponse(500, 'user_settings_fetch_failed', 'Could not load user settings.', [
                    'reason' => 'internal_error',
                ]);
            }

            if ($userSettings === null) {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'settings' => [
                    'display_name' => (string) ($userSettings['display_name'] ?? ''),
                    'time_format' => (string) ($userSettings['time_format'] ?? '24h'),
                    'theme' => (string) ($userSettings['theme'] ?? 'dark'),
                    'avatar_path' => $userSettings['avatar_path'] ?? null,
                ],
                'user' => $userSettings,
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/user/settings.', [
                'allowed_methods' => ['GET', 'PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'user_settings_invalid_request_body', 'User settings payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $updateResult = videochat_update_user_settings($pdo, $authenticatedUserId, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'user_settings_update_failed', 'Could not update user settings.', [
                'reason' => 'internal_error',
            ]);
        }

        $updateReason = (string) ($updateResult['reason'] ?? 'internal_error');
        if (!(bool) ($updateResult['ok'] ?? false)) {
            if ($updateReason === 'validation_failed') {
                return $errorResponse(422, 'user_settings_validation_failed', 'User settings payload failed validation.', [
                    'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                ]);
            }
            if ($updateReason === 'not_found') {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $errorResponse(500, 'user_settings_update_failed', 'Could not update user settings.', [
                'reason' => 'internal_error',
            ]);
        }

        $updatedUser = is_array($updateResult['user'] ?? null) ? $updateResult['user'] : null;
        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'settings' => [
                    'display_name' => (string) ($updatedUser['display_name'] ?? ''),
                    'time_format' => (string) ($updatedUser['time_format'] ?? '24h'),
                    'theme' => (string) ($updatedUser['theme'] ?? 'dark'),
                    'avatar_path' => $updatedUser['avatar_path'] ?? null,
                ],
                'user' => $updatedUser,
            ],
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
