<?php

declare(strict_types=1);

function videochat_handle_realtime_attachment_routes(
    string $path,
    array $request,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/chat/attachments$#', $path, $uploadMatch) === 1) {
        $method = videochat_realtime_method_from_request($request);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{call_id}/chat/attachments.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $restAuth = $authenticateRequest($request, 'rest');
        if (!(bool) ($restAuth['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($restAuth['reason'] ?? 'invalid_session'));
        }

        $rawBody = $request['body'] ?? '';
        if (is_string($rawBody) && strlen($rawBody) > videochat_chat_attachment_max_upload_body_bytes()) {
            return $errorResponse(413, 'attachment_too_large', 'Chat attachment upload request body exceeds the allowed size.', [
                'max_request_bytes' => videochat_chat_attachment_max_upload_body_bytes(),
            ]);
        }

        [$payload, $decodeError] = videochat_realtime_decode_json_request_body($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'chat_attachment_invalid_request_body', 'Chat attachment upload payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $uploadResult = videochat_chat_attachment_upload(
                $pdo,
                (string) ($uploadMatch[1] ?? ''),
                (int) (($restAuth['user']['id'] ?? 0)),
                (string) (($restAuth['user']['role'] ?? 'user')),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'chat_attachment_upload_failed', 'Could not upload chat attachment.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($uploadResult['ok'] ?? false)) {
            $code = (string) ($uploadResult['code'] ?? 'chat_attachment_upload_failed');
            $reason = (string) ($uploadResult['reason'] ?? 'internal_error');
            return $errorResponse(
                videochat_realtime_chat_attachment_error_status($code, $reason),
                $code,
                (string) ($uploadResult['message'] ?? 'Could not upload chat attachment.'),
                is_array($uploadResult['details'] ?? null) ? $uploadResult['details'] : []
            );
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'uploaded',
                'attachment' => $uploadResult['attachment'] ?? null,
                'limits' => [
                    'inline_max_chars' => videochat_chat_attachment_inline_max_chars(),
                    'inline_max_bytes' => videochat_chat_attachment_inline_max_bytes(),
                    'attachment_max_count' => videochat_chat_attachment_max_count(),
                    'attachment_max_images' => videochat_chat_attachment_max_images(),
                    'attachment_max_image_bytes' => videochat_chat_attachment_max_image_bytes(),
                    'attachment_max_document_bytes' => videochat_chat_attachment_max_document_bytes(),
                    'attachment_max_message_bytes' => videochat_chat_attachment_max_message_bytes(),
                    'attachment_max_upload_body_bytes' => videochat_chat_attachment_max_upload_body_bytes(),
                    'attachment_call_soft_quota_bytes' => videochat_chat_attachment_call_soft_quota_bytes(),
                    'attachment_call_hard_quota_bytes' => videochat_chat_attachment_call_hard_quota_bytes(),
                ],
                'quota' => is_array($uploadResult['details']['quota'] ?? null) ? $uploadResult['details']['quota'] : null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/chat/attachments/([A-Za-z0-9._-]{1,80})$#', $path, $downloadMatch) === 1) {
        $method = videochat_realtime_method_from_request($request);
        $restAuth = $authenticateRequest($request, 'rest');
        if (!(bool) ($restAuth['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($restAuth['reason'] ?? 'invalid_session'));
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_chat_attachment_cancel_draft(
                    $pdo,
                    (string) ($downloadMatch[1] ?? ''),
                    (string) ($downloadMatch[2] ?? ''),
                    (int) (($restAuth['user']['id'] ?? 0)),
                    (string) (($restAuth['user']['role'] ?? 'user'))
                );
            } catch (Throwable) {
                return $errorResponse(500, 'chat_attachment_delete_failed', 'Could not delete chat attachment draft.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($deleteResult['ok'] ?? false)) {
                $code = (string) ($deleteResult['code'] ?? 'chat_attachment_delete_failed');
                $reason = (string) ($deleteResult['reason'] ?? 'internal_error');
                return $errorResponse(
                    videochat_realtime_chat_attachment_error_status($code, $reason),
                    $code,
                    (string) ($deleteResult['message'] ?? 'Could not delete chat attachment draft.'),
                    is_array($deleteResult['details'] ?? null) ? $deleteResult['details'] : []
                );
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'attachment_id' => (string) ($downloadMatch[2] ?? ''),
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or DELETE for /api/calls/{call_id}/chat/attachments/{attachment_id}.', [
                'allowed_methods' => ['GET', 'DELETE'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $attachment = videochat_chat_attachment_fetch_for_download(
                $pdo,
                (string) ($downloadMatch[1] ?? ''),
                (string) ($downloadMatch[2] ?? ''),
                (int) (($restAuth['user']['id'] ?? 0)),
                (string) (($restAuth['user']['role'] ?? 'user'))
            );
        } catch (Throwable) {
            return $errorResponse(500, 'chat_attachment_download_failed', 'Could not load chat attachment metadata.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!is_array($attachment)) {
            return $errorResponse(404, 'chat_attachment_not_found', 'Chat attachment does not exist or is not available to this user.', [
                'attachment_id' => (string) ($downloadMatch[2] ?? ''),
            ]);
        }

        $binary = videochat_chat_attachment_store_get((string) ($attachment['object_key'] ?? ''));
        if (!is_string($binary)) {
            return $errorResponse(500, 'chat_attachment_download_failed', 'Could not read chat attachment from the King Object Store.', [
                'attachment_id' => (string) ($attachment['id'] ?? ''),
            ]);
        }

        return [
            'status' => 200,
            'headers' => [
                'content-type' => (string) ($attachment['content_type'] ?? 'application/octet-stream'),
                'content-length' => (string) strlen($binary),
                'content-disposition' => videochat_realtime_content_disposition_filename((string) ($attachment['original_name'] ?? 'attachment')),
                'x-content-type-options' => 'nosniff',
                'cache-control' => 'private, no-store',
                'access-control-allow-methods' => 'GET,POST,OPTIONS',
                'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id',
                'access-control-max-age' => '600',
                'connection' => 'close',
            ],
            'body' => $binary,
        ];
    }

    return null;
}
