<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/conversation/conversation_store.php';

/**
 * C-batch (#V.8) — conversation persistence HTTP surface.
 *
 * Routes:
 *   GET  /api/conversations/{session_id}/messages   → ordered message list + meta
 *   GET  /api/conversations/{session_id}            → meta only (turn_count, timestamps)
 *   DELETE /api/conversations/{session_id}          → clear all messages and meta
 *
 * Scope fence: session_id is client-supplied and NOT authenticated. Any
 * caller with the session_id string can read / delete its messages. This
 * matches the inference-request envelope contract and is an accepted
 * fence for the demo.
 */
function model_inference_handle_conversations_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if (!preg_match('#^/api/conversations(?:/([A-Za-z0-9_.:\\-]+)(?:/(messages))?)?$#', $path, $m)) {
        return null;
    }
    $sessionId = $m[1] ?? '';
    $sub = $m[2] ?? '';

    if ($sessionId === '') {
        return $errorResponse(404, 'not_implemented', 'Collection-level /api/conversations not supported.', [
            'path' => $path, 'reason' => 'session_id_required',
        ]);
    }

    $pdo = $openDatabase();
    model_inference_conversation_schema_migrate($pdo);

    if ($sub === 'messages') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path, 'method' => $method, 'allowed' => ['GET'],
            ]);
        }
        $meta = model_inference_conversation_get_meta($pdo, $sessionId);
        $messages = model_inference_conversation_list_messages($pdo, $sessionId, 1000);
        return $jsonResponse(200, [
            'status' => 'ok',
            'session_id' => $sessionId,
            'found' => $meta !== null,
            'meta' => $meta,
            'messages' => $messages,
            'count' => count($messages),
            'time' => gmdate('c'),
        ]);
    }

    if ($sub === '') {
        if ($method === 'GET') {
            $meta = model_inference_conversation_get_meta($pdo, $sessionId);
            return $jsonResponse(200, [
                'status' => 'ok',
                'session_id' => $sessionId,
                'found' => $meta !== null,
                'meta' => $meta,
                'time' => gmdate('c'),
            ]);
        }
        if ($method === 'DELETE') {
            $deleted = model_inference_conversation_delete($pdo, $sessionId);
            return $jsonResponse(200, [
                'status' => 'ok',
                'session_id' => $sessionId,
                'deleted_message_count' => $deleted,
                'time' => gmdate('c'),
            ]);
        }
        return $errorResponse(405, 'method_not_allowed', 'GET or DELETE required.', [
            'path' => $path, 'method' => $method, 'allowed' => ['GET', 'DELETE'],
        ]);
    }

    return $errorResponse(404, 'not_implemented', 'Unknown conversations sub-route.', [
        'path' => $path, 'observed' => $sub,
    ]);
}
