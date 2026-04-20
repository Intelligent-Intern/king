<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_store.php';
require_once __DIR__ . '/../../http/module_auth.php';

/**
 * #A-3 non-blocking auth middleware.
 *
 * Extracts `Authorization: Bearer <token>` from the incoming request.
 * - Hit: hydrates $request['user'] + $request['session'] and returns the
 *   enriched request.
 * - Miss (no header, invalid token, expired, revoked): returns the
 *   original request with ['user' => null, 'session' => null, 'auth_reason' => ...].
 *
 * The middleware NEVER returns a 401 by itself — any route that wants
 * to block anonymous use has to do so explicitly. /api/auth/whoami and
 * /api/conversations/{id}/messages (owner-gated) are the two places
 * that currently require the hydrated context; everything else keeps
 * anonymous behavior as the default.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function model_inference_auth_apply_middleware(PDO $pdo, array $request): array
{
    $request['user'] = null;
    $request['session'] = null;
    $request['auth_reason'] = 'anonymous';

    $token = model_inference_auth_extract_bearer_token($request);
    if ($token === '') {
        return $request;
    }

    // Ensure schema is present before we probe (safe no-op if already
    // migrated). Middleware runs on every request so the cost has to
    // be microscopic — `CREATE TABLE IF NOT EXISTS` is essentially free
    // on SQLite after the first call within a process.
    model_inference_auth_schema_migrate($pdo);
    $ctx = model_inference_auth_validate_session($pdo, $token);
    if ($ctx === null) {
        $request['auth_reason'] = 'invalid_or_expired_token';
        return $request;
    }
    $request['user'] = $ctx['user'];
    $request['session'] = $ctx['session'];
    $request['auth_reason'] = 'authenticated';
    return $request;
}
