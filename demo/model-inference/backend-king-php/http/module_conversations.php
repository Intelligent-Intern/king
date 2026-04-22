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
    callable $openDatabase,
    ?array $request = null
): ?array {
    // A-4: "my conversations" list for an authenticated user.
    if ($path === '/api/conversations/me') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path, 'method' => $method, 'allowed' => ['GET'],
            ]);
        }
        $user = is_array($request) ? ($request['user'] ?? null) : null;
        if (!is_array($user) || !isset($user['id'])) {
            return $errorResponse(401, 'invalid_credentials', 'Authentication required for /api/conversations/me.', [
                'field' => 'authorization', 'reason' => 'missing_bearer',
            ]);
        }
        $pdo = $openDatabase();
        model_inference_conversation_schema_migrate($pdo);
        $conversations = model_inference_conversation_list_by_user($pdo, (int) $user['id'], 100);
        return $jsonResponse(200, [
            'status' => 'ok',
            'user' => $user,
            'conversations' => $conversations,
            'count' => count($conversations),
            'time' => gmdate('c'),
        ]);
    }

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

    // A-4 ownership gate: if the conversation is owned (user_ref set),
    // only the owner can read or delete. Anonymous conversations
    // (user_ref NULL) remain open to anyone with the session_id —
    // pre-A-batch behavior preserved.
    $ownershipCheck = model_inference_conversations_check_ownership($pdo, $sessionId, $request, $errorResponse);
    if ($ownershipCheck !== null) {
        return $ownershipCheck;
    }

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

/**
 * A-4 ownership gate for /api/conversations/{session_id}[/messages].
 *
 * Returns null when the caller is allowed to proceed (either the
 * conversation has no owner, or the caller is authenticated as the
 * owner). Returns a 403 error envelope otherwise.
 */
function model_inference_conversations_check_ownership(
    PDO $pdo,
    string $sessionId,
    ?array $request,
    callable $errorResponse
): ?array {
    $meta = model_inference_conversation_get_meta($pdo, $sessionId);
    if ($meta === null) {
        // no conversation yet — anyone (auth or anon) can probe. The
        // downstream handler returns found=false.
        return null;
    }
    $userRef = $meta['user_ref'] ?? null;
    if ($userRef === null) {
        // anonymous conversation — pre-A-batch behavior: open to anyone
        // with the session_id
        return null;
    }
    $caller = is_array($request) ? ($request['user'] ?? null) : null;
    if (!is_array($caller) || !isset($caller['id'])) {
        return $errorResponse(403, 'ownership_denied', 'This conversation is owned by an authenticated user.', [
            'session_id' => $sessionId,
            'reason' => 'caller_anonymous',
        ]);
    }
    if ((int) $caller['id'] !== (int) $userRef) {
        return $errorResponse(403, 'ownership_denied', 'Authenticated user does not own this conversation.', [
            'session_id' => $sessionId,
            'reason' => 'caller_not_owner',
        ]);
    }
    return null;
}
