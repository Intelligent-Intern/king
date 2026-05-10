<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby_state.php';
require_once __DIR__ . '/../domain/realtime/realtime_typing.php';
require_once __DIR__ . '/../domain/realtime/realtime_reaction.php';
require_once __DIR__ . '/../http/router.php';

function videochat_governance_route_auth_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[governance-route-authorization-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_governance_route_auth_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_governance_route_auth_seed_session(PDO $pdo, string $sessionId, int $userId): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'governance-route-authorization-contract')
SQL
    );
    $insert->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 120),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
}

function videochat_governance_route_auth_insert_grant(
    PDO $pdo,
    int $tenantId,
    int $actorUserId,
    int $subjectUserId,
    string $resourceType,
    string $resourceId,
    string $action
): void {
    $insertGrant = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, resource_type, resource_id, action, subject_type, user_id,
    valid_from, valid_until, revoked_at, created_by_user_id, permission_key
) VALUES(
    :tenant_id, :resource_type, :resource_id, :action, 'user', :user_id,
    :valid_from, :valid_until, NULL, :created_by_user_id, :permission_key
)
SQL
    );
    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => $resourceType,
        ':resource_id' => $resourceId,
        ':action' => $action,
        ':user_id' => $subjectUserId,
        ':valid_from' => gmdate('c', time() - 60),
        ':valid_until' => gmdate('c', time() + 3600),
        ':created_by_user_id' => $actorUserId,
        ':permission_key' => 'governance.' . str_replace('_', '-', $resourceType) . '.' . $action,
    ]);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-governance-route-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_governance_route_auth_assert($tenantId > 0 && $adminUserId > 0 && $regularUserId > 0, 'fixture ids missing');

    videochat_governance_route_auth_seed_session($pdo, 'sess_governance_route_user', $regularUserId);

    $governanceRule = videochat_rbac_rule_for_path('/api/governance/groups');
    videochat_governance_route_auth_assert(is_array($governanceRule), 'governance RBAC rule missing');
    videochat_governance_route_auth_assert((string) ($governanceRule['id'] ?? '') === 'rest_governance_scope', 'governance RBAC rule id mismatch');
    videochat_governance_route_auth_assert((array) ($governanceRule['allowed_roles'] ?? []) === ['admin', 'user'], 'governance RBAC should pass users to route authorization');

    $adminTenancyRule = videochat_rbac_rule_for_path('/api/admin/tenancy/export');
    videochat_governance_route_auth_assert(is_array($adminTenancyRule), 'admin tenancy RBAC rule missing');
    videochat_governance_route_auth_assert((string) ($adminTenancyRule['id'] ?? '') === 'rest_tenant_administration', 'admin tenancy RBAC rule id mismatch');
    videochat_governance_route_auth_assert((array) ($adminTenancyRule['allowed_roles'] ?? []) === ['admin', 'user'], 'admin tenancy RBAC should pass users to route authorization');

    $jsonResponse = static function (int $status, array $payload, array $headers = []): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'] + $headers,
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };
    $methodFromRequest = static fn (array $request): string => strtoupper(trim((string) ($request['method'] ?? 'GET')));
    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $issueSessionId = static fn (): string => 'sess_governance_route_issued';
    $pathFromRequest = static function (array $request): string {
        $path = $request['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }
        return (string) (parse_url((string) ($request['uri'] ?? '/'), PHP_URL_PATH) ?: '/');
    };
    $runtimeEnvelope = static fn (): array => [
        'service' => 'video-chat-backend-king-php',
        'runtime' => ['ws_path' => '/ws'],
        'time' => gmdate('c'),
    ];

    $activeWebsocketsBySession = [];
    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $typingState = videochat_typing_state_init();
    $reactionState = videochat_reaction_state_init();
    $avatarStorageRoot = sys_get_temp_dir() . '/videochat-governance-route-auth-avatar-' . bin2hex(random_bytes(4));

    $dispatch = static function (string $method, string $path, ?array $payload = null) use (
        &$activeWebsocketsBySession,
        &$presenceState,
        &$lobbyState,
        &$typingState,
        &$reactionState,
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId,
        $pathFromRequest,
        $runtimeEnvelope,
        $avatarStorageRoot
    ): array {
        return videochat_dispatch_request(
            [
                'method' => strtoupper($method),
                'uri' => $path,
                'path' => (string) (parse_url($path, PHP_URL_PATH) ?: $path),
                'headers' => [
                    'Authorization' => 'Bearer sess_governance_route_user',
                    'Content-Type' => 'application/json',
                ],
                'body' => is_array($payload) ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
            ],
            $activeWebsocketsBySession,
            $presenceState,
            $lobbyState,
            $typingState,
            $reactionState,
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $decodeJsonBody,
            $openDatabase,
            $issueSessionId,
            $pathFromRequest,
            $runtimeEnvelope,
            '/ws',
            $avatarStorageRoot,
            1024 * 1024
        );
    };

    $deniedGroupCreate = $dispatch('POST', '/api/governance/groups', ['name' => 'Route Denied Group']);
    $deniedGroupPayload = videochat_governance_route_auth_decode($deniedGroupCreate);
    videochat_governance_route_auth_assert((int) ($deniedGroupCreate['status'] ?? 0) === 403, 'user without grant should be route-denied for governance group create');
    videochat_governance_route_auth_assert((string) (($deniedGroupPayload['error'] ?? [])['code'] ?? '') === 'tenant_governance_forbidden', 'governance group route deny code mismatch');
    videochat_governance_route_auth_assert((string) (($deniedGroupPayload['error']['details'] ?? [])['reason'] ?? '') === 'not_granted', 'governance group route deny reason mismatch');

    videochat_governance_route_auth_insert_grant($pdo, $tenantId, $adminUserId, $regularUserId, 'group', '*', 'create');
    $grantedGroupCreate = $dispatch('POST', '/api/governance/groups', ['name' => 'Route Granted Group']);
    $grantedGroupPayload = videochat_governance_route_auth_decode($grantedGroupCreate);
    videochat_governance_route_auth_assert((int) ($grantedGroupCreate['status'] ?? 0) === 201, 'user resource grant should allow governance group create through router');
    videochat_governance_route_auth_assert((string) ((($grantedGroupPayload['result'] ?? [])['state'] ?? '')) === 'created', 'granted governance group create state mismatch');

    $deniedExport = $dispatch('POST', '/api/admin/tenancy/export', ['scope_type' => 'organization']);
    $deniedExportPayload = videochat_governance_route_auth_decode($deniedExport);
    videochat_governance_route_auth_assert((int) ($deniedExport['status'] ?? 0) === 403, 'user without portability grant should be route-denied for admin tenancy export');
    videochat_governance_route_auth_assert((string) (($deniedExportPayload['error'] ?? [])['code'] ?? '') === 'tenant_governance_forbidden', 'admin tenancy export deny code mismatch');
    videochat_governance_route_auth_assert((string) (($deniedExportPayload['error']['details'] ?? [])['reason'] ?? '') === 'not_granted', 'admin tenancy export deny reason mismatch');

    videochat_governance_route_auth_insert_grant($pdo, $tenantId, $adminUserId, $regularUserId, 'tenant_export_import_job', '*', 'read');
    $grantedExport = $dispatch('POST', '/api/admin/tenancy/export', ['scope_type' => 'organization']);
    $grantedExportPayload = videochat_governance_route_auth_decode($grantedExport);
    videochat_governance_route_auth_assert((int) ($grantedExport['status'] ?? 0) === 200, 'tenant_export_import_job read grant should allow admin tenancy export through router');
    videochat_governance_route_auth_assert((bool) ((($grantedExportPayload['result'] ?? [])['ok'] ?? false)) === true, 'granted export should return ok result');
    videochat_governance_route_auth_assert((string) ((($grantedExportPayload['result'] ?? [])['reason'] ?? '')) === 'export_ready', 'granted export reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[governance-route-authorization-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[governance-route-authorization-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
