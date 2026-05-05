<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/tenancy/governance_role_assignments.php';

function videochat_handle_admin_user_account_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
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
                $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
                $listing = videochat_admin_list_users(
                    $pdo,
                    (string) ($filters['query'] ?? ''),
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['page_size'] ?? 10),
                    (string) ($filters['order'] ?? 'role_then_name_asc'),
                    (string) ($filters['status'] ?? 'all'),
                    $tenantId
                );
                $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
                $primaryAdminUserId = videochat_primary_admin_user_id($pdo);
            } catch (Throwable $error) {
                return $errorResponse(500, 'admin_user_list_failed', 'Could not load admin user list.', [
                    'reason' => 'internal_error',
                ]);
            }

            $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
            $rows = $tenantId > 0 ? videochat_tenancy_governance_enrich_user_role_rows($pdo, $tenantId, $rows) : $rows;
            $rows = array_map(
                static function ($row) use ($actorUserId, $primaryAdminUserId) {
                    if (!is_array($row)) {
                        return $row;
                    }
                    $permissions = videochat_admin_user_permissions_snapshot($row, $actorUserId, $primaryAdminUserId);
                    return [
                        ...$row,
                        'is_self' => $permissions['is_self'],
                        'is_primary_admin' => $permissions['is_primary_admin'],
                        'permissions' => [
                            'can_change_role' => $permissions['can_change_role'],
                            'can_change_status' => $permissions['can_change_status'],
                            'can_change_theme_editor' => $permissions['can_change_theme_editor'],
                            'can_toggle_status' => $permissions['can_toggle_status'],
                            'can_delete' => $permissions['can_delete'],
                        ],
                    ];
                },
                $rows
            );
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
                    'status' => (string) ($filters['status'] ?? 'all'),
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
            $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
            $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
            $roleValidation = videochat_tenancy_governance_validate_user_roles($pdo, $tenantId, $payload);
            if (!(bool) ($roleValidation['ok'] ?? false)) {
                return $errorResponse(422, 'admin_user_validation_failed', 'User create payload failed validation.', [
                    'fields' => is_array($roleValidation['errors'] ?? null) ? $roleValidation['errors'] : [],
                ]);
            }
            $createResult = videochat_admin_create_user($pdo, $payload, $tenantId);
            if ((bool) ($createResult['ok'] ?? false) && $tenantId > 0 && is_array($createResult['user'] ?? null)) {
                $createdUserId = (int) (($createResult['user'] ?? [])['id'] ?? 0);
                $roleSync = videochat_tenancy_governance_sync_user_roles($pdo, $tenantId, $createdUserId, $actorUserId, $payload);
                if (!(bool) ($roleSync['ok'] ?? false)) {
                    return $errorResponse(422, 'admin_user_validation_failed', 'User create payload failed validation.', [
                        'fields' => is_array($roleSync['errors'] ?? null) ? $roleSync['errors'] : [],
                    ]);
                }
                $createResult['user'] = videochat_tenancy_governance_enrich_user_role_relationships($pdo, $tenantId, (array) $createResult['user']);
            }
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
                    'row' => $createResult['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
                $user = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
                if ($user === null) {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }
                $user = $tenantId > 0 ? videochat_tenancy_governance_enrich_user_role_relationships($pdo, $tenantId, $user) : $user;

                $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
                $primaryAdminUserId = videochat_primary_admin_user_id($pdo);
                $permissions = videochat_admin_user_permissions_snapshot($user, $actorUserId, $primaryAdminUserId);
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_fetch_failed', 'Could not load user.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'user' => [
                        ...$user,
                        'is_self' => $permissions['is_self'],
                        'is_primary_admin' => $permissions['is_primary_admin'],
                        'permissions' => [
                            'can_change_role' => $permissions['can_change_role'],
                            'can_change_status' => $permissions['can_change_status'],
                            'can_change_theme_editor' => $permissions['can_change_theme_editor'],
                            'can_toggle_status' => $permissions['can_toggle_status'],
                            'can_delete' => $permissions['can_delete'],
                        ],
                    ],
                    'row' => [
                        ...$user,
                        'is_self' => $permissions['is_self'],
                        'is_primary_admin' => $permissions['is_primary_admin'],
                        'permissions' => [
                            'can_change_role' => $permissions['can_change_role'],
                            'can_change_status' => $permissions['can_change_status'],
                            'can_change_theme_editor' => $permissions['can_change_theme_editor'],
                            'can_toggle_status' => $permissions['can_toggle_status'],
                            'can_delete' => $permissions['can_delete'],
                        ],
                    ],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'PATCH') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'admin_user_invalid_request_body', 'User update payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $pdo = $openDatabase();
                $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
                $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
                $existingUser = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
                if ($existingUser === null) {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }

                $primaryAdminUserId = videochat_primary_admin_user_id($pdo);
                $existingRole = strtolower(trim((string) ($existingUser['role'] ?? 'user')));
                $existingStatus = strtolower(trim((string) ($existingUser['status'] ?? 'active')));

                if (array_key_exists('email', $payload)) {
                    $nextEmail = videochat_normalize_user_email_address((string) ($payload['email'] ?? ''));
                    $currentEmail = videochat_normalize_user_email_address((string) ($existingUser['email'] ?? ''));
                    if ($nextEmail !== '' && $nextEmail !== $currentEmail) {
                        return $errorResponse(422, 'admin_user_validation_failed', 'Primary email updates require confirmation flow.', [
                            'fields' => [
                                'email' => 'use_additional_email_confirmation_flow',
                            ],
                        ]);
                    }
                    if ($nextEmail === '' || $nextEmail === $currentEmail) {
                        unset($payload['email']);
                    }
                }

                if (array_key_exists('role', $payload)) {
                    $requestedRole = strtolower(trim((string) ($payload['role'] ?? '')));
                    if ($actorUserId > 0 && $actorUserId === $userId && $requestedRole !== '' && $requestedRole !== $existingRole) {
                        return $errorResponse(409, 'admin_user_conflict', 'You cannot change your own role.', [
                            'fields' => [
                                'role' => 'cannot_change_own_role',
                            ],
                        ]);
                    }
                    if ($primaryAdminUserId > 0 && $primaryAdminUserId === $userId && $requestedRole !== '' && $requestedRole !== $existingRole) {
                        return $errorResponse(409, 'admin_user_conflict', 'Primary admin role cannot be changed.', [
                            'fields' => [
                                'role' => 'primary_admin_role_locked',
                            ],
                        ]);
                    }
                }

                if (array_key_exists('status', $payload)) {
                    $requestedStatus = strtolower(trim((string) ($payload['status'] ?? '')));
                    if ($actorUserId > 0 && $actorUserId === $userId && $requestedStatus === 'disabled' && $existingStatus !== 'disabled') {
                        return $errorResponse(409, 'admin_user_conflict', 'You cannot deactivate your own account.', [
                            'fields' => [
                                'status' => 'cannot_deactivate_self',
                            ],
                        ]);
                    }
                    if ($primaryAdminUserId > 0 && $primaryAdminUserId === $userId && $requestedStatus === 'disabled' && $existingStatus !== 'disabled') {
                        return $errorResponse(409, 'admin_user_conflict', 'Primary admin cannot be deactivated.', [
                            'fields' => [
                                'status' => 'primary_admin_cannot_be_disabled',
                            ],
                        ]);
                    }
                }

                if (array_key_exists('theme_editor_enabled', $payload) && $actorUserId > 0 && $actorUserId === $userId) {
                    return $errorResponse(409, 'admin_user_conflict', 'You cannot change your own theme editor access.', [
                        'fields' => [
                            'theme_editor_enabled' => 'cannot_change_own_theme_editor_access',
                        ],
                    ]);
                }

                if (videochat_tenancy_governance_user_payload_has_roles($payload) && $actorUserId > 0 && $actorUserId === $userId) {
                    return $errorResponse(409, 'admin_user_conflict', 'You cannot change your own governance roles.', [
                        'fields' => [
                            'roles' => 'cannot_change_own_governance_roles',
                        ],
                    ]);
                }
                $roleValidation = videochat_tenancy_governance_validate_user_roles($pdo, $tenantId, $payload);
                if (!(bool) ($roleValidation['ok'] ?? false)) {
                    return $errorResponse(422, 'admin_user_validation_failed', 'User update payload failed validation.', [
                        'fields' => is_array($roleValidation['errors'] ?? null) ? $roleValidation['errors'] : [],
                    ]);
                }

                $updateResult = videochat_admin_update_user($pdo, $userId, $payload, $tenantId);
                if ((bool) ($updateResult['ok'] ?? false) && $tenantId > 0 && is_array($updateResult['user'] ?? null)) {
                    $roleSync = videochat_tenancy_governance_sync_user_roles($pdo, $tenantId, $userId, $actorUserId, $payload);
                    if (!(bool) ($roleSync['ok'] ?? false)) {
                        return $errorResponse(422, 'admin_user_validation_failed', 'User update payload failed validation.', [
                            'fields' => is_array($roleSync['errors'] ?? null) ? $roleSync['errors'] : [],
                        ]);
                    }
                    $updateResult['user'] = videochat_tenancy_governance_enrich_user_role_relationships($pdo, $tenantId, (array) $updateResult['user']);
                }
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
                    'row' => $updateResult['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
                $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
                if ($actorUserId > 0 && $actorUserId === $userId) {
                    return $errorResponse(409, 'admin_user_conflict', 'You cannot delete your own account.', [
                        'fields' => [
                            'user_id' => 'cannot_delete_self',
                        ],
                    ]);
                }

                $primaryAdminUserId = videochat_primary_admin_user_id($pdo);
                if ($primaryAdminUserId > 0 && $primaryAdminUserId === $userId) {
                    return $errorResponse(409, 'admin_user_conflict', 'Primary admin account cannot be deleted.', [
                        'fields' => [
                            'user_id' => 'primary_admin_delete_locked',
                        ],
                    ]);
                }

                $deleteResult = videochat_admin_delete_user($pdo, $userId, $tenantId);
                if ((bool) ($deleteResult['ok'] ?? false) && $tenantId > 0) {
                    videochat_tenancy_governance_clear_user_roles($pdo, $tenantId, $userId);
                }
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

        return $errorResponse(405, 'method_not_allowed', 'Use GET, PATCH or DELETE for /api/admin/users/{id}.', [
            'allowed_methods' => ['GET', 'PATCH', 'DELETE'],
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

    return null;
}
