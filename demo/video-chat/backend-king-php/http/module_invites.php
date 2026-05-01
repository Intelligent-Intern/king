<?php

declare(strict_types=1);

function videochat_handle_invite_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/invite-codes') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/invite-codes.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'invite_codes_invalid_request_body', 'Invite-code payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_create_invite_code(
                $pdo,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'invite_codes_create_failed', 'Could not create invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        $createReason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($createReason === 'validation_failed') {
                return $errorResponse(422, 'invite_codes_validation_failed', 'Invite-code payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'not_found') {
                return $errorResponse(404, 'invite_codes_not_found', 'Invite context does not exist or is not active.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'forbidden') {
                return $errorResponse(403, 'invite_codes_forbidden', 'You are not allowed to issue invite codes for this context.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'conflict') {
                return $errorResponse(409, 'invite_codes_conflict', 'Could not allocate a unique invite code. Please retry.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'invite_codes_create_failed', 'Could not create invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        $invitePreview = is_array($createResult['invite_code'] ?? null)
            ? videochat_invite_code_preview($createResult['invite_code'])
            : null;
        $inviteId = is_array($invitePreview) ? (string) ($invitePreview['id'] ?? '') : '';

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'invite_code' => $invitePreview,
                'copy' => [
                    'method' => 'POST',
                    'endpoint' => $inviteId !== '' ? '/api/invite-codes/' . rawurlencode($inviteId) . '/copy' : '',
                    'endpoint_template' => '/api/invite-codes/{id}/copy',
                ],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/invite-codes/([A-Za-z0-9._-]{1,200})/copy$#', $path, $copyMatch) === 1) {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/invite-codes/{id}/copy.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $copyResult = videochat_prepare_invite_code_copy(
                $pdo,
                (string) ($copyMatch[1] ?? ''),
                $authenticatedUserId,
                $authenticatedUserRole
            );
        } catch (Throwable) {
            return $errorResponse(500, 'invite_codes_copy_failed', 'Could not prepare invite code for copy.', [
                'reason' => 'internal_error',
            ]);
        }

        $copyReason = (string) ($copyResult['reason'] ?? 'internal_error');
        if (!(bool) ($copyResult['ok'] ?? false)) {
            if ($copyReason === 'not_found') {
                return $errorResponse(404, 'invite_codes_not_found', 'Invite code does not exist.', [
                    'fields' => is_array($copyResult['errors'] ?? null) ? $copyResult['errors'] : [],
                ]);
            }
            if ($copyReason === 'forbidden') {
                return $errorResponse(403, 'invite_codes_forbidden', 'You are not allowed to copy this invite code.', [
                    'fields' => is_array($copyResult['errors'] ?? null) ? $copyResult['errors'] : [],
                ]);
            }
            if ($copyReason === 'expired') {
                return $errorResponse(410, 'invite_codes_expired', 'Invite code has expired.', [
                    'fields' => is_array($copyResult['errors'] ?? null) ? $copyResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'invite_codes_copy_failed', 'Could not prepare invite code for copy.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'copy_ready',
                'invite_code' => $copyResult['invite_code'] ?? null,
                'copy' => $copyResult['copy'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/invite-codes/redeem') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/invite-codes/redeem.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'invite_codes_redeem_invalid_request_body', 'Invite-code redeem payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $redeemResult = videochat_redeem_invite_code(
                $pdo,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'invite_codes_redeem_failed', 'Could not redeem invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        $redeemReason = (string) ($redeemResult['reason'] ?? 'internal_error');
        if (!(bool) ($redeemResult['ok'] ?? false)) {
            if ($redeemReason === 'validation_failed') {
                return $errorResponse(422, 'invite_codes_redeem_validation_failed', 'Invite-code redeem payload failed validation.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'not_found') {
                return $errorResponse(404, 'invite_codes_redeem_not_found', 'Invite code does not exist.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'expired') {
                return $errorResponse(410, 'invite_codes_redeem_expired', 'Invite code has expired.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'exhausted') {
                return $errorResponse(409, 'invite_codes_redeem_exhausted', 'Invite code has reached its redemption limit.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'conflict') {
                return $errorResponse(409, 'invite_codes_redeem_conflict', 'Invite code resolved to a non-joinable destination.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'forbidden') {
                return $errorResponse(403, 'invite_codes_redeem_forbidden', 'You are not allowed to redeem this invite code.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'invite_codes_redeem_failed', 'Could not redeem invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'redeemed',
                'redemption' => $redeemResult['redemption'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }


    return null;
}
