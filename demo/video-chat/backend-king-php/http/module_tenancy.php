<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/tenancy/tenant_administration.php';
require_once __DIR__ . '/../domain/tenancy/governance_group_memberships.php';
require_once __DIR__ . '/../domain/tenancy/governance_organization_memberships.php';
require_once __DIR__ . '/../domain/tenancy/governance_permission_grants.php';
require_once __DIR__ . '/../domain/tenancy/governance_policies.php';
require_once __DIR__ . '/../domain/tenancy/governance_portability_jobs.php';
require_once __DIR__ . '/../domain/tenancy/governance_roles.php';
require_once __DIR__ . '/../domain/tenancy/governance_role_assignments.php';
require_once __DIR__ . '/../domain/tenancy/tenant_portability.php';
require_once __DIR__ . '/../support/auth_request.php';

function videochat_handle_tenancy_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    $governanceResponse = videochat_handle_governance_crud_routes(
        $path,
        $method,
        $request,
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    if (is_array($governanceResponse)) {
        return $governanceResponse;
    }

    if ($path === '/api/admin/tenancy/export' || $path === '/api/admin/tenancy/import/dry-run') {
        $admin = videochat_tenancy_require_admin($apiAuthContext);
        if (!(bool) ($admin['ok'] ?? false)) {
            return $errorResponse(403, 'tenant_admin_required', 'Tenant administration requires an active tenant admin membership.', [
                'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
            ]);
        }
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for tenant portability endpoints.', [
                'allowed_methods' => ['POST'],
            ]);
        }
        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'tenant_portability_invalid_request_body', 'Tenant portability payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        try {
            $pdo = $openDatabase();
            $tenantId = (int) ($admin['tenant_id'] ?? 0);
            $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
            $result = $path === '/api/admin/tenancy/export'
                ? videochat_tenant_export_bundle($pdo, $tenantId, $actorUserId, $payload)
                : videochat_tenant_import_dry_run($pdo, $tenantId, $actorUserId, is_array($payload['bundle'] ?? null) ? (array) $payload['bundle'] : $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'tenant_portability_failed', 'Tenant portability operation failed.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return $errorResponse(422, 'tenant_portability_validation_failed', 'Tenant portability payload failed validation.', [
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }
        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => $result,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/tenants') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/tenants.', [
                'allowed_methods' => ['GET'],
            ]);
        }
        $userId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($userId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }
        try {
            $pdo = $openDatabase();
            return $jsonResponse(200, [
                'status' => 'ok',
                'tenant' => $apiAuthContext['tenant'] ?? null,
                'tenants' => videochat_tenancy_list_user_tenants($pdo, $userId),
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'tenants_list_failed', 'Tenant list could not be loaded.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path !== '/api/admin/tenancy/context') {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/tenancy/context.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    $admin = videochat_tenancy_require_admin($apiAuthContext);
    if (!(bool) ($admin['ok'] ?? false)) {
        return $errorResponse(403, 'tenant_admin_required', 'Tenant administration requires an active tenant admin membership.', [
            'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = (int) ($admin['tenant_id'] ?? 0);

        return $jsonResponse(200, [
            'status' => 'ok',
            'tenant' => $apiAuthContext['tenant'] ?? null,
            'organizations' => videochat_tenancy_list_organizations($pdo, $tenantId),
            'groups' => videochat_tenancy_list_groups($pdo, $tenantId),
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'tenant_context_failed', 'Tenant context could not be loaded.', [
            'reason' => 'internal_error',
        ]);
    }
}

function videochat_handle_governance_crud_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/governance/users') {
        return videochat_handle_governance_user_summary_route(
            $method,
            $request,
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $openDatabase
        );
    }

    if (preg_match('#^/api/governance/data-portability-jobs(?:/([^/]+))?$#', $path, $portabilityMatches) === 1) {
        return videochat_handle_governance_portability_routes(
            $method,
            isset($portabilityMatches[1]) ? rawurldecode((string) $portabilityMatches[1]) : '',
            $request,
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
    }

    if (preg_match('#^/api/governance/policies(?:/([^/]+))?$#', $path, $policyMatches) === 1) {
        return videochat_handle_governance_policy_routes(
            $method,
            isset($policyMatches[1]) ? rawurldecode((string) $policyMatches[1]) : '',
            $request,
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
    }

    if (preg_match('#^/api/governance/roles(?:/([^/]+))?$#', $path, $roleMatches) === 1) {
        return videochat_handle_governance_role_routes(
            $method,
            isset($roleMatches[1]) ? rawurldecode((string) $roleMatches[1]) : '',
            $request,
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
    }

    if (preg_match('#^/api/governance/grants(?:/([^/]+))?$#', $path, $grantMatches) === 1) {
        return videochat_handle_governance_grant_routes(
            $method,
            isset($grantMatches[1]) ? rawurldecode((string) $grantMatches[1]) : '',
            $request,
            $apiAuthContext,
            $jsonResponse,
            $errorResponse,
            $decodeJsonBody,
            $openDatabase
        );
    }

    if (preg_match('#^/api/governance/(groups|organizations)(?:/([^/]+))?$#', $path, $matches) !== 1) {
        return null;
    }

    $entity = (string) ($matches[1] ?? '');
    $identifier = isset($matches[2]) ? rawurldecode((string) $matches[2]) : '';
    $hasIdentifier = trim($identifier) !== '';
    $allowedMethods = $hasIdentifier ? ['GET', 'PUT', 'PATCH', 'DELETE'] : ['GET', 'POST'];
    if (!in_array($method, $allowedMethods, true)) {
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for this governance resource.', [
            'allowed_methods' => $allowedMethods,
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', [
                'reason' => 'invalid_tenant_context',
            ]);
        }

        if ($method === 'GET' && !$hasIdentifier) {
            $permission = videochat_tenancy_governance_permission_decision($pdo, $apiAuthContext, $entity, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $sourceRows = videochat_tenancy_list_governance_entities($pdo, $entity, $tenantId);
            if ($entity === 'groups') {
                $sourceRows = videochat_tenancy_governance_enrich_group_rows($pdo, $tenantId, $sourceRows);
                $sourceRows = videochat_tenancy_governance_enrich_group_role_rows($pdo, $tenantId, $sourceRows);
            } elseif ($entity === 'organizations') {
                $sourceRows = videochat_tenancy_governance_enrich_organization_rows($pdo, $tenantId, $sourceRows);
                $sourceRows = videochat_tenancy_governance_enrich_organization_role_rows($pdo, $tenantId, $sourceRows);
            }
            $rows = videochat_tenancy_governance_public_rows($sourceRows);

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'rows' => $rows,
                    'included' => [$entity => $rows],
                ],
                $entity => $rows,
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'GET' && $hasIdentifier) {
            $row = videochat_tenancy_fetch_governance_entity($pdo, $entity, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', [
                    'entity' => $entity,
                ]);
            }
            $permission = videochat_tenancy_governance_permission_decision(
                $pdo,
                $apiAuthContext,
                $entity,
                'read',
                videochat_tenancy_governance_resource_id($row)
            );
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            if ($entity === 'groups') {
                $row = videochat_tenancy_governance_enrich_group_relationships($pdo, $tenantId, $row);
                $row = videochat_tenancy_governance_enrich_group_role_relationships($pdo, $tenantId, $row);
            } elseif ($entity === 'organizations') {
                $row = videochat_tenancy_governance_enrich_organization_relationships($pdo, $tenantId, $row);
                $row = videochat_tenancy_governance_enrich_organization_role_relationships($pdo, $tenantId, $row);
            }
            $publicRow = videochat_tenancy_governance_public_row($row);

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'row' => $publicRow,
                    'included' => [$entity => [$publicRow]],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'POST' && !$hasIdentifier) {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            $permission = videochat_tenancy_governance_permission_decision($pdo, $apiAuthContext, $entity, 'create');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            if ($entity === 'groups') {
                $memberValidation = videochat_tenancy_governance_validate_group_members($pdo, $tenantId, $payload);
                if (!(bool) ($memberValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $memberValidation);
                }
                $permissionValidation = videochat_tenancy_governance_validate_group_permissions($payload);
                if (!(bool) ($permissionValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $permissionValidation);
                }
                $moduleValidation = videochat_tenancy_governance_validate_group_modules($payload);
                if (!(bool) ($moduleValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $moduleValidation);
                }
                $roleValidation = videochat_tenancy_governance_validate_group_roles($pdo, $tenantId, $payload);
                if (!(bool) ($roleValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $roleValidation);
                }
            } elseif ($entity === 'organizations') {
                $userValidation = videochat_tenancy_governance_validate_organization_users($pdo, $tenantId, $payload);
                if (!(bool) ($userValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $userValidation);
                }
                $roleValidation = videochat_tenancy_governance_validate_organization_roles($pdo, $tenantId, $payload);
                if (!(bool) ($roleValidation['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $roleValidation);
                }
            }
            $result = videochat_tenancy_create_governance_entity($pdo, $entity, $tenantId, $actorUserId, $payload);
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $result);
            }
            $savedRow = is_array($result['row'] ?? null) ? $result['row'] : [];
            if ($entity === 'groups') {
                $syncResult = videochat_tenancy_governance_sync_group_members(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $payload
                );
                if (!(bool) ($syncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $syncResult);
                }
                $permissionSyncResult = videochat_tenancy_governance_sync_group_permissions(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $actorUserId,
                    $payload
                );
                if (!(bool) ($permissionSyncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $permissionSyncResult);
                }
                $moduleSyncResult = videochat_tenancy_governance_sync_group_modules(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $actorUserId,
                    $payload
                );
                if (!(bool) ($moduleSyncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $moduleSyncResult);
                }
                $roleSyncResult = videochat_tenancy_governance_sync_group_roles(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $actorUserId,
                    $payload
                );
                if (!(bool) ($roleSyncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $roleSyncResult);
                }
                $savedRow = videochat_tenancy_governance_enrich_group_relationships($pdo, $tenantId, $savedRow);
                $savedRow = videochat_tenancy_governance_enrich_group_role_relationships($pdo, $tenantId, $savedRow);
            } elseif ($entity === 'organizations') {
                $syncResult = videochat_tenancy_governance_sync_organization_users(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $payload
                );
                if (!(bool) ($syncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $syncResult);
                }
                $roleSyncResult = videochat_tenancy_governance_sync_organization_roles(
                    $pdo,
                    $tenantId,
                    (int) ($savedRow['database_id'] ?? 0),
                    $actorUserId,
                    $payload
                );
                if (!(bool) ($roleSyncResult['ok'] ?? false)) {
                    return videochat_tenancy_governance_validation_response($errorResponse, $roleSyncResult);
                }
                $savedRow = videochat_tenancy_governance_enrich_organization_relationships($pdo, $tenantId, $savedRow);
                $savedRow = videochat_tenancy_governance_enrich_organization_role_relationships($pdo, $tenantId, $savedRow);
            }
            $row = videochat_tenancy_governance_public_row($savedRow);

            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'created',
                    'row' => $row,
                    'included' => [$entity => [$row]],
                ],
                'time' => gmdate('c'),
            ]);
        }

        $row = videochat_tenancy_fetch_governance_entity($pdo, $entity, $tenantId, $identifier);
        if (!is_array($row)) {
            return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', [
                'entity' => $entity,
            ]);
        }
        $action = $method === 'DELETE' ? 'delete' : 'update';
        $permission = videochat_tenancy_governance_permission_decision(
            $pdo,
            $apiAuthContext,
            $entity,
            $action,
            videochat_tenancy_governance_resource_id($row)
        );
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }

        if ($method === 'DELETE') {
            $result = videochat_tenancy_delete_governance_entity($pdo, $entity, $tenantId, $identifier);
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $result);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'id' => videochat_tenancy_governance_resource_id($row),
                    'row' => videochat_tenancy_governance_public_row($row),
                ],
                'time' => gmdate('c'),
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        if ($entity === 'groups') {
            $memberValidation = videochat_tenancy_governance_validate_group_members($pdo, $tenantId, $payload);
            if (!(bool) ($memberValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $memberValidation);
            }
            $permissionValidation = videochat_tenancy_governance_validate_group_permissions($payload);
            if (!(bool) ($permissionValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $permissionValidation);
            }
            $moduleValidation = videochat_tenancy_governance_validate_group_modules($payload);
            if (!(bool) ($moduleValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $moduleValidation);
            }
            $roleValidation = videochat_tenancy_governance_validate_group_roles($pdo, $tenantId, $payload);
            if (!(bool) ($roleValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $roleValidation);
            }
        } elseif ($entity === 'organizations') {
            $userValidation = videochat_tenancy_governance_validate_organization_users($pdo, $tenantId, $payload);
            if (!(bool) ($userValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $userValidation);
            }
            $roleValidation = videochat_tenancy_governance_validate_organization_roles($pdo, $tenantId, $payload);
            if (!(bool) ($roleValidation['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $roleValidation);
            }
        }
        $result = videochat_tenancy_update_governance_entity($pdo, $entity, $tenantId, $identifier, $payload);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $savedRow = is_array($result['row'] ?? null) ? $result['row'] : [];
        if ($entity === 'groups') {
            $syncResult = videochat_tenancy_governance_sync_group_members(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $payload
            );
            if (!(bool) ($syncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $syncResult);
            }
            $permissionSyncResult = videochat_tenancy_governance_sync_group_permissions(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $actorUserId,
                $payload
            );
            if (!(bool) ($permissionSyncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $permissionSyncResult);
            }
            $moduleSyncResult = videochat_tenancy_governance_sync_group_modules(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $actorUserId,
                $payload
            );
            if (!(bool) ($moduleSyncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $moduleSyncResult);
            }
            $roleSyncResult = videochat_tenancy_governance_sync_group_roles(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $actorUserId,
                $payload
            );
            if (!(bool) ($roleSyncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $roleSyncResult);
            }
            $savedRow = videochat_tenancy_governance_enrich_group_relationships($pdo, $tenantId, $savedRow);
            $savedRow = videochat_tenancy_governance_enrich_group_role_relationships($pdo, $tenantId, $savedRow);
        } elseif ($entity === 'organizations') {
            $syncResult = videochat_tenancy_governance_sync_organization_users(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $payload
            );
            if (!(bool) ($syncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $syncResult);
            }
            $roleSyncResult = videochat_tenancy_governance_sync_organization_roles(
                $pdo,
                $tenantId,
                (int) ($savedRow['database_id'] ?? 0),
                $actorUserId,
                $payload
            );
            if (!(bool) ($roleSyncResult['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $roleSyncResult);
            }
            $savedRow = videochat_tenancy_governance_enrich_organization_relationships($pdo, $tenantId, $savedRow);
            $savedRow = videochat_tenancy_governance_enrich_organization_role_relationships($pdo, $tenantId, $savedRow);
        }
        $updatedRow = videochat_tenancy_governance_public_row($savedRow);

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'row' => $updatedRow,
                'included' => [$entity => [$updatedRow]],
            ],
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', [
            'reason' => 'internal_error',
        ]);
    }
}

function videochat_handle_governance_user_summary_route(
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): array {
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/governance/users.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', [
                'reason' => 'invalid_tenant_context',
            ]);
        }

        $permission = videochat_tenancy_governance_user_summary_permission_decision($pdo, $apiAuthContext);
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }

        $filters = videochat_admin_user_list_filters(videochat_request_query_params($request));
        if (!(bool) ($filters['ok'] ?? false)) {
            return $errorResponse(422, 'governance_user_list_validation_failed', 'Invalid governance user list query parameters.', [
                'fields' => $filters['errors'] ?? [],
            ]);
        }
        $listing = videochat_admin_list_users(
            $pdo,
            (string) ($filters['query'] ?? ''),
            (int) ($filters['page'] ?? 1),
            (int) ($filters['page_size'] ?? 100),
            (string) ($filters['order'] ?? 'role_then_name_asc'),
            (string) ($filters['status'] ?? 'active'),
            $tenantId
        );
    } catch (Throwable) {
        return $errorResponse(500, 'governance_user_list_failed', 'Could not load governance users.', [
            'reason' => 'internal_error',
        ]);
    }

    $rows = videochat_tenancy_governance_user_summary_rows(is_array($listing['rows'] ?? null) ? $listing['rows'] : []);
    $total = (int) ($listing['total'] ?? 0);
    $pageCount = (int) ($listing['page_count'] ?? 0);
    $page = (int) ($filters['page'] ?? 1);
    $pageSize = (int) ($filters['page_size'] ?? 100);

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'rows' => $rows,
            'included' => ['users' => $rows],
        ],
        'users' => $rows,
        'pagination' => [
            'query' => (string) ($filters['query'] ?? ''),
            'status' => (string) ($filters['status'] ?? 'active'),
            'order' => (string) ($filters['order'] ?? 'role_then_name_asc'),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'page_count' => $pageCount,
            'returned' => count($rows),
            'has_prev' => $page > 1,
            'has_next' => $pageCount > 0 && $page < $pageCount,
        ],
        'time' => gmdate('c'),
    ]);
}

function videochat_tenancy_governance_public_row(array $row): array
{
    unset($row['database_id'], $row['parent_organization_database_id'], $row['organization_database_id']);
    return $row;
}

function videochat_tenancy_governance_public_rows(array $rows): array
{
    return array_map(static fn (array $row): array => videochat_tenancy_governance_public_row($row), $rows);
}

function videochat_tenancy_governance_forbidden_response(callable $errorResponse, array $permission): array
{
    return $errorResponse(403, 'tenant_governance_forbidden', 'Tenant governance permission is required.', [
        'reason' => (string) ($permission['reason'] ?? 'not_granted'),
    ]);
}

function videochat_tenancy_governance_validation_response(callable $errorResponse, array $result): array
{
    if ((string) ($result['reason'] ?? '') === 'not_found') {
        return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.');
    }

    return $errorResponse(422, 'governance_validation_failed', 'Governance payload failed validation.', [
        'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        'reason' => (string) ($result['reason'] ?? 'validation_failed'),
    ]);
}
