<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/auth/auth_middleware.php';
require_once __DIR__ . '/../domain/conversation/conversation_store.php';

function realtime_auth_contract_assert(bool $cond, string $msg): void
{
    if ($cond) { return; }
    fwrite(STDERR, "[realtime-auth-contract] FAIL: {$msg}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    $dbPath = sys_get_temp_dir() . '/realtime-auth-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_auth_schema_migrate($pdo);
        model_inference_conversation_schema_migrate($pdo);

        $alice = model_inference_auth_create_user($pdo, 'alice', 'alice123', 'Alice');
        $bob = model_inference_auth_create_user($pdo, 'bob', 'bob123', 'Bob');
        $sess = model_inference_auth_issue_session($pdo, (int) $alice['id'], 3600);

        // 1. The same auth middleware applies to WS upgrade requests.
        //    The WS handshake arrives as a GET request with standard
        //    headers — the router runs the middleware BEFORE routing to
        //    module_realtime, so module_realtime receives the hydrated
        //    $request['user'] exactly like any REST handler.
        $upgradeReq = [
            'method' => 'GET',
            'path' => '/ws',
            'headers' => [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
                'Sec-WebSocket-Version' => '13',
                'Authorization' => 'Bearer ' . $sess['id'],
            ],
        ];
        $hydrated = model_inference_auth_apply_middleware($pdo, $upgradeReq);
        realtime_auth_contract_assert($hydrated['user'] !== null, 'valid bearer on WS upgrade -> user hydrated');
        realtime_auth_contract_assert($hydrated['user']['username'] === 'alice', 'user.username = alice');
        realtime_auth_contract_assert(($hydrated['auth_session']['id'] ?? '') === $sess['id'], 'auth_session.id preserved on WS upgrade');
        $rulesAsserted += 3;

        // 2. WS upgrade without bearer -> anonymous, but handler still
        //    dispatches (we do NOT block anonymous streaming).
        $anonUpgrade = [
            'method' => 'GET', 'path' => '/ws',
            'headers' => ['Upgrade' => 'websocket'],
        ];
        $hydratedAnon = model_inference_auth_apply_middleware($pdo, $anonUpgrade);
        realtime_auth_contract_assert($hydratedAnon['user'] === null, 'anon WS upgrade -> user=null');
        realtime_auth_contract_assert($hydratedAnon['auth_reason'] === 'anonymous', 'anon WS upgrade reason');
        $rulesAsserted += 2;

        // 3. Revoked bearer -> handshake-time validation fails, user=null.
        model_inference_auth_revoke_session($pdo, $sess['id']);
        $revokedUpgrade = [
            'method' => 'GET', 'path' => '/ws',
            'headers' => ['Authorization' => 'Bearer ' . $sess['id']],
        ];
        $hydratedRev = model_inference_auth_apply_middleware($pdo, $revokedUpgrade);
        realtime_auth_contract_assert($hydratedRev['user'] === null, 'revoked bearer on WS upgrade -> user=null');
        realtime_auth_contract_assert($hydratedRev['auth_reason'] === 'invalid_or_expired_token', 'revoked reason');
        $rulesAsserted += 2;

        // 4. Honest fence: per-frame revalidation is NOT implemented.
        //    A token revoked MID-stream (after handshake succeeded) will
        //    NOT kill the active WS session at this leaf. This is the
        //    demo-scope boundary pinned by #A-5.
        $fresh = model_inference_auth_issue_session($pdo, (int) $alice['id'], 3600);
        $upgradeFresh = [
            'method' => 'GET', 'path' => '/ws',
            'headers' => ['Authorization' => 'Bearer ' . $fresh['id']],
        ];
        $hydratedFresh = model_inference_auth_apply_middleware($pdo, $upgradeFresh);
        realtime_auth_contract_assert($hydratedFresh['user'] !== null, 'fresh bearer hydrates at upgrade');
        model_inference_auth_revoke_session($pdo, $fresh['id']);
        // After revoke, the in-flight handler still has the stale
        // $request['user'] it captured at upgrade-time. Re-probing would
        // fail, but the contract at this leaf does NOT require the
        // handler to re-probe; the handler is free to keep streaming.
        $postRevoke = model_inference_auth_apply_middleware($pdo, $upgradeFresh);
        realtime_auth_contract_assert($postRevoke['user'] === null, 're-probing a revoked token post-handshake fails (which would be per-frame revalidation — fenced)');
        $rulesAsserted += 2;

        // 5. Conversation persistence path picks up user_ref on
        //    authenticated WS turns. Simulate what module_realtime does
        //    after the stream summary lands.
        $sess3 = model_inference_auth_issue_session($pdo, (int) $alice['id'], 3600);
        $req = model_inference_auth_apply_middleware($pdo, [
            'method' => 'GET', 'path' => '/ws',
            'headers' => ['Authorization' => 'Bearer ' . $sess3['id']],
        ]);
        $envelope = [
            'session_id' => 'sess-ws-alice',
            'messages' => [['role' => 'user', 'content' => 'hi from ws']],
            'prompt' => null, 'system' => null,
        ];
        $userRef = null;
        if (is_array($req['user']) && isset($req['user']['id'])) {
            $userRef = (int) $req['user']['id'];
        }
        model_inference_conversation_append_turn($pdo, $envelope, 'hi back', 'req-ws-1', ['model_name' => 'SmolLM2', 'quantization' => 'Q4_K'], $userRef);
        $meta = model_inference_conversation_get_meta($pdo, 'sess-ws-alice');
        realtime_auth_contract_assert($meta !== null && $meta['user_ref'] === (int) $alice['id'], 'WS authenticated turn binds user_ref');
        $rulesAsserted++;

        // 6. Anonymous WS turn -> user_ref stays null.
        $anonReq = model_inference_auth_apply_middleware($pdo, [
            'method' => 'GET', 'path' => '/ws', 'headers' => [],
        ]);
        $envelope2 = [
            'session_id' => 'sess-ws-anon',
            'messages' => [['role' => 'user', 'content' => 'hi anon']],
            'prompt' => null, 'system' => null,
        ];
        $userRef2 = null;
        if (is_array($anonReq['user']) && isset($anonReq['user']['id'])) {
            $userRef2 = (int) $anonReq['user']['id'];
        }
        model_inference_conversation_append_turn($pdo, $envelope2, 'hi back', 'req-ws-2', ['model_name' => 'SmolLM2', 'quantization' => 'Q4_K'], $userRef2);
        $meta2 = model_inference_conversation_get_meta($pdo, 'sess-ws-anon');
        realtime_auth_contract_assert($meta2['user_ref'] === null, 'anon WS turn keeps user_ref=null');
        $rulesAsserted++;

        // 7. Case-insensitive header parsing (WS libraries often
        //    lowercase headers).
        $sessCase = model_inference_auth_issue_session($pdo, (int) $bob['id'], 3600);
        $reqCase = model_inference_auth_apply_middleware($pdo, [
            'method' => 'GET', 'path' => '/ws',
            'headers' => ['authorization' => 'bearer ' . $sessCase['id']],
        ]);
        realtime_auth_contract_assert($reqCase['user'] !== null && $reqCase['user']['username'] === 'bob', 'lowercase authorization header honored');
        $rulesAsserted++;

        // 8. Ownership gate protects cross-user access to a WS-created
        //    conversation (integration with A-4).
        require_once __DIR__ . '/../http/module_conversations.php';
        $jsonResponse = static function (int $status, array $payload): array {
            return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES)];
        };
        $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
            return $jsonResponse($status, [
                'status' => 'error',
                'error' => ['code' => $code, 'message' => $message, 'details' => $details],
                'time' => gmdate('c'),
            ]);
        };
        $r = model_inference_handle_conversations_routes(
            '/api/conversations/sess-ws-alice/messages', 'GET',
            $jsonResponse, $errorResponse, static fn (): PDO => $pdo,
            ['user' => $bob]
        );
        realtime_auth_contract_assert($r['status'] === 403, 'bob cannot read alice WS conversation');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[realtime-auth-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-auth-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
