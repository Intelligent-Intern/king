<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/calls/appointment_calendar.php';

function videochat_handle_appointment_calendar_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/appointment-calendar/settings') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $settings = videochat_get_or_create_appointment_settings($pdo, $authenticatedUserId, videochat_tenant_id_from_auth_context($apiAuthContext));
            } catch (Throwable) {
                return $errorResponse(500, 'appointment_settings_load_failed', 'Could not load appointment settings.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'loaded',
                    'owner_user_id' => $authenticatedUserId,
                    'public_path' => '/book/' . (string) ($settings['public_id'] ?? ''),
                    'settings' => $settings,
                    'defaults' => [
                        'mail_subject_template' => videochat_default_appointment_email_subject_template(),
                        'mail_body_template' => videochat_default_appointment_email_body_template(),
                    ],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/appointment-calendar/settings.', [
                'allowed_methods' => ['GET', 'PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'appointment_settings_invalid_request_body', 'Appointment settings payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $settingsValidation = videochat_validate_appointment_settings_payload($payload);
        if (!(bool) ($settingsValidation['ok'] ?? false)) {
            return $errorResponse(422, 'appointment_settings_validation_failed', 'Appointment settings payload failed validation.', [
                'fields' => is_array($settingsValidation['errors'] ?? null) ? $settingsValidation['errors'] : [],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $existingSettings = videochat_get_or_create_appointment_settings($pdo, $authenticatedUserId, videochat_tenant_id_from_auth_context($apiAuthContext));
            $settings = videochat_save_appointment_settings(
                $pdo,
                $authenticatedUserId,
                (string) ($existingSettings['public_id'] ?? ''),
                is_array($settingsValidation['settings'] ?? null) ? (array) $settingsValidation['settings'] : [],
                videochat_tenant_id_from_auth_context($apiAuthContext)
            );
        } catch (Throwable) {
            return $errorResponse(500, 'appointment_settings_save_failed', 'Could not save appointment settings.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'saved',
                'owner_user_id' => $authenticatedUserId,
                'public_path' => '/book/' . (string) ($settings['public_id'] ?? ''),
                'settings' => $settings,
                'defaults' => [
                    'mail_subject_template' => videochat_default_appointment_email_subject_template(),
                    'mail_body_template' => videochat_default_appointment_email_body_template(),
                ],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/appointment-calendar/blocks') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $blocks = videochat_list_appointment_blocks($pdo, $authenticatedUserId, videochat_tenant_id_from_auth_context($apiAuthContext));
                $settings = videochat_get_or_create_appointment_settings($pdo, $authenticatedUserId, videochat_tenant_id_from_auth_context($apiAuthContext));
            } catch (Throwable) {
                return $errorResponse(500, 'appointment_blocks_load_failed', 'Could not load appointment blocks.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'loaded',
                    'owner_user_id' => $authenticatedUserId,
                    'public_path' => '/book/' . (string) ($settings['public_id'] ?? ''),
                    'settings' => $settings,
                    'blocks' => $blocks,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PUT') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PUT for /api/appointment-calendar/blocks.', [
                'allowed_methods' => ['GET', 'PUT'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'appointment_blocks_invalid_request_body', 'Appointment blocks payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $saveResult = videochat_save_appointment_blocks($pdo, $authenticatedUserId, $payload, videochat_tenant_id_from_auth_context($apiAuthContext));
        } catch (Throwable) {
            return $errorResponse(500, 'appointment_blocks_save_failed', 'Could not save appointment blocks.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($saveResult['ok'] ?? false)) {
            $reason = (string) ($saveResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'appointment_blocks_validation_failed', 'Appointment blocks payload failed validation.', [
                    'fields' => is_array($saveResult['errors'] ?? null) ? $saveResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'appointment_owner_not_found', 'Appointment owner could not be resolved.', [
                    'fields' => is_array($saveResult['errors'] ?? null) ? $saveResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'appointment_blocks_save_failed', 'Could not save appointment blocks.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'saved',
                'owner_user_id' => $authenticatedUserId,
                'public_path' => '/book/' . (string) (($saveResult['settings'] ?? [])['public_id'] ?? ''),
                'settings' => $saveResult['settings'] ?? null,
                'blocks' => $saveResult['blocks'] ?? [],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/appointment-calendar/public/([A-Fa-f0-9-]{36})$#', $path, $publicMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/appointment-calendar/public/{calendarId}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $publicCalendarId = (string) ($publicMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            $slotsResult = videochat_public_appointment_slots($pdo, $publicCalendarId);
        } catch (Throwable) {
            return $errorResponse(500, 'appointment_slots_load_failed', 'Could not load appointment slots.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($slotsResult['ok'] ?? false)) {
            return $errorResponse(404, 'appointment_owner_not_found', 'Appointment calendar owner could not be resolved.', [
                'calendar_id' => $publicCalendarId,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'loaded',
                'owner' => $slotsResult['owner'] ?? null,
                'settings' => $slotsResult['settings'] ?? null,
                'slots' => $slotsResult['slots'] ?? [],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/appointment-calendar/public/([A-Fa-f0-9-]{36})/book$#', $path, $bookMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/appointment-calendar/public/{calendarId}/book.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'appointment_booking_invalid_request_body', 'Appointment booking payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $publicCalendarId = (string) ($bookMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            $bookResult = videochat_book_public_appointment($pdo, $publicCalendarId, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'appointment_booking_failed', 'Could not book appointment.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($bookResult['ok'] ?? false)) {
            $reason = (string) ($bookResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'appointment_booking_validation_failed', 'Appointment booking payload failed validation.', [
                    'fields' => is_array($bookResult['errors'] ?? null) ? $bookResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'appointment_slot_not_found', 'Appointment slot could not be resolved.', [
                    'fields' => is_array($bookResult['errors'] ?? null) ? $bookResult['errors'] : [],
                ]);
            }
            if ($reason === 'conflict') {
                return $errorResponse(409, 'appointment_slot_unavailable', 'Appointment slot is no longer available.', [
                    'fields' => is_array($bookResult['errors'] ?? null) ? $bookResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'appointment_booking_failed', 'Could not book appointment.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'booked',
                'booking' => $bookResult['booking'] ?? null,
                'call' => $bookResult['call'] ?? null,
                'join_path' => $bookResult['join_path'] ?? '',
            ],
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
