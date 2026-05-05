<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/user_management.php';
require_once __DIR__ . '/../domain/users/user_directory.php';
require_once __DIR__ . '/../domain/users/user_settings.php';
require_once __DIR__ . '/../domain/users/avatar_upload.php';
require_once __DIR__ . '/../domain/users/user_emails.php';
require_once __DIR__ . '/../domain/realtime/client_diagnostics.php';
require_once __DIR__ . '/../domain/realtime/turn_ice.php';
require_once __DIR__ . '/module_users_admin_accounts.php';

/**
 * @param array<string, mixed> $userRow
 * @return array<string, bool>
 */
function videochat_admin_user_permissions_snapshot(array $userRow, int $actorUserId, int $primaryAdminUserId): array
{
    $targetUserId = (int) ($userRow['id'] ?? 0);
    $isSelf = $actorUserId > 0 && $targetUserId > 0 && $actorUserId === $targetUserId;
    $isPrimaryAdmin = $primaryAdminUserId > 0 && $targetUserId > 0 && $primaryAdminUserId === $targetUserId;

    $canChangeRole = !$isSelf && !$isPrimaryAdmin;
    $canChangeStatus = !$isSelf && !$isPrimaryAdmin;
    $canChangeThemeEditor = !$isSelf;

    return [
        'is_self' => $isSelf,
        'is_primary_admin' => $isPrimaryAdmin,
        'can_change_role' => $canChangeRole,
        'can_change_status' => $canChangeStatus,
        'can_change_theme_editor' => $canChangeThemeEditor,
        'can_toggle_status' => $canChangeStatus,
        'can_delete' => !$isSelf && !$isPrimaryAdmin,
    ];
}

function videochat_email_change_frontend_origin(): string
{
    $configured = trim((string) (getenv('VIDEOCHAT_FRONTEND_ORIGIN') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return 'http://127.0.0.1:5176';
}

function videochat_build_email_change_confirmation_url(string $token): string
{
    $base = videochat_email_change_frontend_origin();
    return $base . '/login?email_change_token=' . rawurlencode($token);
}

/**
 * @return array{sent: bool, channel: string}
 */
function videochat_send_email_change_confirmation_mail(string $recipientEmail, string $recipientName, string $confirmationUrl): array
{
    $to = trim($recipientEmail);
    if ($to === '') {
        return ['sent' => false, 'channel' => 'none'];
    }

    $displayName = trim($recipientName);
    if ($displayName === '') {
        $displayName = 'there';
    }

    $subject = 'Confirm your new email address';
    $body = "Hello {$displayName},\n\n"
        . "Please confirm your new email address by opening this link:\n"
        . "{$confirmationUrl}\n\n"
        . "The link expires in 30 minutes.\n";
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "From: no-reply@intelligent-intern.local\r\n";

    $sent = false;
    if (function_exists('mail')) {
        try {
            $sent = @mail($to, $subject, $body, $headers);
        } catch (Throwable) {
            $sent = false;
        }
    }

    if ($sent) {
        return ['sent' => true, 'channel' => 'mail'];
    }

    $outboxPath = trim((string) (getenv('VIDEOCHAT_EMAIL_OUTBOX_PATH') ?: (__DIR__ . '/../.local/email-outbox.log')));
    $outboxDir = dirname($outboxPath);
    if (!is_dir($outboxDir)) {
        @mkdir($outboxDir, 0775, true);
    }
    $stamp = gmdate('c');
    $entry = "[{$stamp}] TO={$to}\nSUBJECT={$subject}\n{$body}\n---\n";
    @file_put_contents($outboxPath, $entry, FILE_APPEND | LOCK_EX);

    return ['sent' => false, 'channel' => 'outbox'];
}

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
    if ($path === '/api/user/media/ice-servers') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/media/ice-servers.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => videochat_turn_ice_server_payload(),
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/user/directory') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/directory.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $queryParams = videochat_request_query_params($request);
        $filters = videochat_user_directory_filters($queryParams);
        if (!(bool) ($filters['ok'] ?? false)) {
            return $errorResponse(422, 'user_directory_validation_failed', 'Invalid user directory query parameters.', [
                'fields' => $filters['errors'] ?? [],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
            $listing = videochat_user_directory_list(
                $pdo,
                (string) ($filters['query'] ?? ''),
                (int) ($filters['page'] ?? 1),
                (int) ($filters['page_size'] ?? 10),
                (string) ($filters['order'] ?? 'name_asc'),
                $authenticatedUserId,
                videochat_tenant_id_from_auth_context($apiAuthContext)
            );
        } catch (Throwable) {
            return $errorResponse(500, 'user_directory_failed', 'Could not load user directory.', [
                'reason' => 'internal_error',
            ]);
        }

        $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
        $total = (int) ($listing['total'] ?? 0);
        $pageCount = (int) ($listing['page_count'] ?? 0);
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['page_size'] ?? 10);

        return $jsonResponse(200, [
            'status' => 'ok',
            'users' => $rows,
            'pagination' => [
                'query' => (string) ($filters['query'] ?? ''),
                'status' => 'active',
                'order' => (string) ($filters['order'] ?? 'name_asc'),
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

    if ($path === '/api/user/client-diagnostics') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/user/client-diagnostics.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'client_diagnostics_invalid_request_body', 'Client diagnostics payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $normalized = videochat_client_diagnostics_normalize_batch(
            $payload,
            $authenticatedUserId,
            trim((string) ($apiAuthContext['session']['id'] ?? ''))
        );
        if (!(bool) ($normalized['ok'] ?? false)) {
            return $errorResponse(422, 'client_diagnostics_validation_failed', 'Client diagnostics payload failed validation.', [
                'fields' => is_array($normalized['errors'] ?? null) ? $normalized['errors'] : [],
            ]);
        }

        $entries = is_array($normalized['entries'] ?? null) ? $normalized['entries'] : [];
        videochat_log_client_diagnostics_entries($entries);

        $storeMode = 'log_only';
        $storedCount = 0;
        try {
            $pdo = $openDatabase();
            $storeResult = videochat_store_client_diagnostics($pdo, $entries);
            $storeMode = 'database';
            $storedCount = (int) ($storeResult['stored_count'] ?? 0);
        } catch (Throwable) {
            $storeMode = 'log_only';
            $storedCount = 0;
        }

        return $jsonResponse(202, [
            'status' => 'ok',
            'result' => [
                'accepted_count' => count($entries),
                'stored_count' => $storedCount,
                'store_mode' => $storeMode,
                'invalid_count' => (int) ($normalized['invalid_count'] ?? 0),
                'dropped_count' => (int) ($normalized['dropped_count'] ?? 0),
            ],
            'time' => gmdate('c'),
        ]);
    }

    $adminAccountRouteResponse = videochat_handle_admin_user_account_routes(
        $path,
        $method,
        $request,
        $apiAuthContext,
        $avatarStorageRoot,
        $avatarMaxBytes,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    if ($adminAccountRouteResponse !== null) {
        return $adminAccountRouteResponse;
    }

    if (preg_match('#^/api/admin/users/(\d+)/emails$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $targetUser = videochat_fetch_user_auth_snapshot($pdo, $userId);
                if ($targetUser === null) {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }
                $emails = videochat_list_user_emails($pdo, $userId);
            } catch (Throwable) {
                return $errorResponse(500, 'admin_user_emails_list_failed', 'Could not load user emails.', [
                    'reason' => 'internal_error',
                ]);
            }

            $rows = array_map(
                static function ($emailRow) {
                    if (!is_array($emailRow)) {
                        return $emailRow;
                    }
                    return [
                        ...$emailRow,
                        'can_delete' => !((bool) ($emailRow['is_verified'] ?? false)),
                    ];
                },
                $emails
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'user_id' => $userId,
                    'emails' => array_values($rows),
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/admin/users/{id}/emails.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'admin_user_emails_invalid_request_body', 'User email payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $newEmail = trim((string) ($payload['email'] ?? ''));
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));

        try {
            $pdo = $openDatabase();
            $targetUser = videochat_fetch_user_auth_snapshot($pdo, $userId);
            if ($targetUser === null) {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            $createPendingResult = videochat_create_pending_user_email($pdo, $userId, $newEmail, $actorUserId);
            if (!(bool) ($createPendingResult['ok'] ?? false)) {
                $reason = (string) ($createPendingResult['reason'] ?? 'internal_error');
                $fields = is_array($createPendingResult['errors'] ?? null) ? $createPendingResult['errors'] : [];
                if ($reason === 'validation_failed') {
                    return $errorResponse(422, 'admin_user_emails_validation_failed', 'User email payload failed validation.', [
                        'fields' => $fields,
                    ]);
                }
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                        'user_id' => $userId,
                    ]);
                }
                if ($reason === 'email_conflict') {
                    return $errorResponse(409, 'admin_user_conflict', 'Email is already in use.', [
                        'fields' => $fields,
                    ]);
                }

                return $errorResponse(500, 'admin_user_emails_create_failed', 'Could not create user email confirmation request.', [
                    'reason' => $reason,
                ]);
            }

            $pendingToken = (string) ($createPendingResult['token'] ?? '');
            $expiresAt = (string) ($createPendingResult['expires_at'] ?? '');
            $emailRow = is_array($createPendingResult['email_row'] ?? null) ? $createPendingResult['email_row'] : null;
            $recipientEmail = is_array($emailRow) ? (string) ($emailRow['email'] ?? '') : '';
            $confirmationUrl = videochat_build_email_change_confirmation_url($pendingToken);
            $delivery = videochat_send_email_change_confirmation_mail(
                $recipientEmail,
                (string) ($targetUser['display_name'] ?? ''),
                $confirmationUrl
            );

            $appEnv = strtolower(trim((string) (getenv('VIDEOCHAT_KING_ENV') ?: 'development')));
            $includeDebugUrl = $appEnv !== 'production';

            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'pending_confirmation',
                    'user_id' => $userId,
                    'email' => $emailRow,
                    'expires_at' => $expiresAt,
                    'delivery' => $delivery,
                    'debug_confirmation_url' => $includeDebugUrl ? $confirmationUrl : null,
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_emails_create_failed', 'Could not create user email confirmation request.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (preg_match('#^/api/admin/users/(\d+)/emails/(\d+)$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        $userEmailId = (int) ($matches[2] ?? 0);
        if ($method !== 'DELETE') {
            return $errorResponse(405, 'method_not_allowed', 'Use DELETE for /api/admin/users/{id}/emails/{emailId}.', [
                'allowed_methods' => ['DELETE'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $targetUser = videochat_fetch_user_auth_snapshot($pdo, $userId);
            if ($targetUser === null) {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            $deleteResult = videochat_delete_unverified_user_email($pdo, $userId, $userEmailId);
            if (!(bool) ($deleteResult['ok'] ?? false)) {
                $reason = (string) ($deleteResult['reason'] ?? 'internal_error');
                $fields = is_array($deleteResult['errors'] ?? null) ? $deleteResult['errors'] : [];
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'admin_user_email_not_found', 'The requested user email does not exist.', [
                        'user_id' => $userId,
                        'email_id' => $userEmailId,
                    ]);
                }
                if ($reason === 'validation_failed') {
                    return $errorResponse(409, 'admin_user_conflict', 'Only unconfirmed emails can be deleted.', [
                        'fields' => $fields,
                    ]);
                }

                return $errorResponse(500, 'admin_user_email_delete_failed', 'Could not delete user email.', [
                    'reason' => $reason,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'user_id' => $userId,
                    'email' => $deleteResult['email_row'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_email_delete_failed', 'Could not delete user email.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if (preg_match('#^/api/admin/users/(\d+)/deactivate$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/admin/users/{id}/deactivate.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        if ($actorUserId > 0 && $actorUserId === $userId) {
            return $errorResponse(409, 'admin_user_conflict', 'You cannot deactivate your own account.', [
                'fields' => [
                    'user_id' => 'cannot_deactivate_self',
                ],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $primaryAdminUserId = videochat_primary_admin_user_id($pdo);
            if ($primaryAdminUserId > 0 && $primaryAdminUserId === $userId) {
                return $errorResponse(409, 'admin_user_conflict', 'Primary admin cannot be deactivated.', [
                    'fields' => [
                        'user_id' => 'primary_admin_cannot_be_disabled',
                    ],
                ]);
            }
            $deactivateResult = videochat_admin_deactivate_user($pdo, $userId, videochat_tenant_id_from_auth_context($apiAuthContext));
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
            $reactivateResult = videochat_admin_reactivate_user($pdo, $userId, videochat_tenant_id_from_auth_context($apiAuthContext));
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
                    'date_format' => (string) ($userSettings['date_format'] ?? 'dmy_dot'),
                    'theme' => (string) ($userSettings['theme'] ?? 'dark'),
                    'avatar_path' => $userSettings['avatar_path'] ?? null,
                    'post_logout_landing_url' => (string) ($userSettings['post_logout_landing_url'] ?? ''),
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
                    'date_format' => (string) ($updatedUser['date_format'] ?? 'dmy_dot'),
                    'theme' => (string) ($updatedUser['theme'] ?? 'dark'),
                    'avatar_path' => $updatedUser['avatar_path'] ?? null,
                    'post_logout_landing_url' => (string) ($updatedUser['post_logout_landing_url'] ?? ''),
                ],
                'user' => $updatedUser,
            ],
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
