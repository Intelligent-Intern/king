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
                $blocks = videochat_list_appointment_blocks($pdo, $authenticatedUserId);
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
                    'public_path' => '/book/' . $authenticatedUserId,
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
            $saveResult = videochat_save_appointment_blocks($pdo, $authenticatedUserId, $payload);
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
                'public_path' => '/book/' . $authenticatedUserId,
                'blocks' => $saveResult['blocks'] ?? [],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/appointment-calendar/public/(\d+)$#', $path, $publicMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/appointment-calendar/public/{ownerId}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $ownerUserId = (int) ($publicMatch[1] ?? 0);
        try {
            $pdo = $openDatabase();
            $slotsResult = videochat_public_appointment_slots($pdo, $ownerUserId);
        } catch (Throwable) {
            return $errorResponse(500, 'appointment_slots_load_failed', 'Could not load appointment slots.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($slotsResult['ok'] ?? false)) {
            return $errorResponse(404, 'appointment_owner_not_found', 'Appointment calendar owner could not be resolved.', [
                'owner_user_id' => $ownerUserId,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'loaded',
                'owner' => $slotsResult['owner'] ?? null,
                'slots' => $slotsResult['slots'] ?? [],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/appointment-calendar/public/(\d+)/book$#', $path, $bookMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/appointment-calendar/public/{ownerId}/book.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'appointment_booking_invalid_request_body', 'Appointment booking payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $ownerUserId = (int) ($bookMatch[1] ?? 0);
        try {
            $pdo = $openDatabase();
            $bookResult = videochat_book_public_appointment($pdo, $ownerUserId, $payload);
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
