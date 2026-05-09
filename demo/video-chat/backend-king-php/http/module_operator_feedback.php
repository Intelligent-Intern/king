<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/operator_feedback.php';
require_once __DIR__ . '/../support/auth_request.php';

function videochat_operator_feedback_route_error(array $result, callable $errorResponse, string $fallbackCode): array
{
    $reason = (string) ($result['reason'] ?? 'internal_error');
    if ($reason === 'validation_failed') {
        return $errorResponse(422, $fallbackCode . '_validation_failed', 'Operator feedback request failed validation.', [
            'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        ]);
    }
    if ($reason === 'forbidden') {
        return $errorResponse(403, 'operator_feedback_forbidden', 'You are not allowed to access operator feedback.', [
            'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
        ]);
    }
    if ($reason === 'not_found') {
        return $errorResponse(404, 'operator_feedback_not_found', 'Operator feedback could not be found.', []);
    }

    return $errorResponse(500, $fallbackCode . '_failed', 'Operator feedback operation failed.', [
        'reason' => 'internal_error',
    ]);
}

function videochat_handle_operator_feedback_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    if ($authenticatedUserId <= 0) {
        return null;
    }

    if ($path === '/api/calls/operator-feedback') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/operator-feedback.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $result = videochat_operator_feedback_list_queue(
                $openDatabase(),
                $apiAuthContext,
                videochat_request_query_params($request),
                null
            );
        } catch (Throwable) {
            return $errorResponse(500, 'operator_feedback_queue_failed', 'Could not load operator feedback queue.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_queue');
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'loaded',
                'queue' => $result['queue'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/operator-feedback/([A-Za-z0-9._:-]{1,120})$#', $path, $feedbackMatch) === 1) {
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for /api/calls/operator-feedback/{id}.', [
                'allowed_methods' => ['PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'operator_feedback_invalid_request_body', 'Operator feedback payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $result = videochat_operator_feedback_update_status($openDatabase(), (string) ($feedbackMatch[1] ?? ''), $apiAuthContext, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'operator_feedback_update_failed', 'Could not update operator feedback.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_update');
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'feedback' => $result['feedback'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/operator-feedback/toasts$#', $path, $toastMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/{id}/operator-feedback/toasts.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $result = videochat_operator_feedback_pending_toasts($openDatabase(), (string) ($toastMatch[1] ?? ''), $apiAuthContext);
        } catch (Throwable) {
            return $errorResponse(500, 'operator_feedback_toasts_failed', 'Could not load operator feedback toasts.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_toasts');
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'loaded',
                'toasts' => is_array($result['toasts'] ?? null) ? $result['toasts'] : [],
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/operator-feedback/([A-Za-z0-9._:-]{1,120})/toast-delivered$#', $path, $deliveredMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/operator-feedback/{feedbackId}/toast-delivered.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        try {
            $result = videochat_operator_feedback_mark_toast_delivered(
                $openDatabase(),
                (string) ($deliveredMatch[1] ?? ''),
                (string) ($deliveredMatch[2] ?? ''),
                $apiAuthContext
            );
        } catch (Throwable) {
            return $errorResponse(500, 'operator_feedback_toast_delivered_failed', 'Could not mark operator feedback toast delivered.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_toast_delivered');
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'delivered',
                'feedback' => $result['feedback'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/operator-feedback$#', $path, $callFeedbackMatch) === 1) {
        if ($method === 'POST') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'operator_feedback_invalid_request_body', 'Operator feedback payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $result = videochat_operator_feedback_create_from_payload(
                    $openDatabase(),
                    (string) ($callFeedbackMatch[1] ?? ''),
                    $apiAuthContext,
                    $payload
                );
            } catch (Throwable) {
                return $errorResponse(500, 'operator_feedback_create_failed', 'Could not store operator feedback.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_create');
            }

            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'stored',
                    'feedback' => $result['feedback'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'GET') {
            try {
                $result = videochat_operator_feedback_list_queue(
                    $openDatabase(),
                    $apiAuthContext,
                    videochat_request_query_params($request),
                    (string) ($callFeedbackMatch[1] ?? '')
                );
            } catch (Throwable) {
                return $errorResponse(500, 'operator_feedback_queue_failed', 'Could not load operator feedback queue.', [
                    'reason' => 'internal_error',
                ]);
            }
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_operator_feedback_route_error($result, $errorResponse, 'operator_feedback_queue');
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'loaded',
                    'queue' => $result['queue'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/calls/{id}/operator-feedback.', [
            'allowed_methods' => ['GET', 'POST'],
        ]);
    }

    return null;
}
