<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/invite_codes.php';
require_once __DIR__ . '/../http/module_invites.php';

function videochat_invite_copy_boundary_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[invite-code-copy-boundary-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_invite_copy_boundary_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

function videochat_invite_copy_boundary_uuid_v4_like(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        strtolower($value)
    ) === 1;
}

try {
    $sampleSecret = '11111111-2222-4333-8444-555555555555';
    $sampleInvite = [
        'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'code' => $sampleSecret,
        'scope' => 'call',
        'room_id' => null,
        'call_id' => 'call-123',
        'issued_by_user_id' => 10,
        'expires_at' => gmdate('c', time() + 3600),
        'expires_in_seconds' => 3600,
        'max_redemptions' => 1,
        'redemption_count' => 0,
        'created_at' => gmdate('c', time()),
        'context' => ['call' => ['id' => 'call-123']],
    ];

    $samplePreview = videochat_invite_code_preview($sampleInvite);
    videochat_invite_copy_boundary_assert(!array_key_exists('code', $samplePreview), 'preview helper must not expose raw code');
    videochat_invite_copy_boundary_assert(($samplePreview['secret_available'] ?? true) === false, 'preview helper must mark secret unavailable');
    videochat_invite_copy_boundary_assert((int) ($samplePreview['remaining_redemptions'] ?? -1) === 1, 'preview helper remaining redemption mismatch');

    $sampleCopy = videochat_invite_code_copy_payload($sampleInvite);
    videochat_invite_copy_boundary_assert((string) ($sampleCopy['code'] ?? '') === $sampleSecret, 'copy helper must expose explicit code');
    videochat_invite_copy_boundary_assert((string) ($sampleCopy['copy_text'] ?? '') === $sampleSecret, 'copy helper copy_text mismatch');
    videochat_invite_copy_boundary_assert((string) (($sampleCopy['redeem_payload'] ?? [])['code'] ?? '') === $sampleSecret, 'copy helper redeem payload mismatch');

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[invite-code-copy-boundary-contract] PASS (persistence skipped: pdo_sqlite unavailable)\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-invite-copy-boundary-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_copy_boundary_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_copy_boundary_assert($standardUserId > 0, 'expected seeded standard user');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Invite Copy Boundary Call',
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_invite_copy_boundary_assert((bool) ($createCall['ok'] ?? false), 'call create should succeed for copy boundary contract');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_invite_copy_boundary_assert($callId !== '', 'call id must be present');

    $adminSessionId = 'sess_invite_copy_boundary_admin';
    $userSessionId = 'sess_invite_copy_boundary_user';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'invite-copy-boundary-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'invite-copy-boundary-user',
    ]);

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $adminAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_invite_copy_boundary_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/invite-codes',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_invite_copy_boundary_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $adminRequestTemplate = [
        'uri' => '/api/invite-codes',
        'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $createResponse = videochat_handle_invite_routes(
        '/api/invite-codes',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'scope' => 'call',
                'call_id' => $callId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($createResponse), 'create response must be an array');
    videochat_invite_copy_boundary_assert((int) ($createResponse['status'] ?? 0) === 201, 'create status should be 201');
    $createPayload = videochat_invite_copy_boundary_decode($createResponse);
    $createPreview = (array) ((($createPayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_copy_boundary_assert(!array_key_exists('code', $createPreview), 'create response must expose preview without raw code');
    videochat_invite_copy_boundary_assert(($createPreview['secret_available'] ?? true) === false, 'create preview must mark secret unavailable');
    $copyPath = (string) (((($createPayload['result'] ?? [])['copy'] ?? [])['endpoint'] ?? ''));
    videochat_invite_copy_boundary_assert($copyPath !== '', 'create response must expose explicit copy endpoint');

    $forbiddenCopy = videochat_handle_invite_routes(
        $copyPath,
        'POST',
        [
            'method' => 'POST',
            'uri' => $copyPath,
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => '',
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($forbiddenCopy), 'forbidden copy response must be an array');
    videochat_invite_copy_boundary_assert((int) ($forbiddenCopy['status'] ?? 0) === 403, 'forbidden copy status should be 403');
    $forbiddenCopyBody = videochat_invite_copy_boundary_decode($forbiddenCopy);
    videochat_invite_copy_boundary_assert((string) (($forbiddenCopyBody['error'] ?? [])['code'] ?? '') === 'invite_codes_forbidden', 'forbidden copy error code mismatch');

    $copyResponse = videochat_handle_invite_routes(
        $copyPath,
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'uri' => $copyPath,
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($copyResponse), 'copy response must be an array');
    videochat_invite_copy_boundary_assert((int) ($copyResponse['status'] ?? 0) === 200, 'copy status should be 200');
    $copyPayload = videochat_invite_copy_boundary_decode($copyResponse);
    videochat_invite_copy_boundary_assert((string) ((($copyPayload['result'] ?? [])['state'] ?? '')) === 'copy_ready', 'copy state mismatch');
    $copyPreview = (array) ((($copyPayload['result'] ?? [])['invite_code'] ?? []));
    videochat_invite_copy_boundary_assert(!array_key_exists('code', $copyPreview), 'copy response preview must not expose raw code');
    $copy = (array) ((($copyPayload['result'] ?? [])['copy'] ?? []));
    $copiedCode = (string) ($copy['code'] ?? '');
    videochat_invite_copy_boundary_assert(videochat_invite_copy_boundary_uuid_v4_like($copiedCode), 'copied invite code should be uuid-v4');
    videochat_invite_copy_boundary_assert((string) ($copy['copy_text'] ?? '') === $copiedCode, 'copy_text should equal copied code');
    videochat_invite_copy_boundary_assert((string) (($copy['redeem_payload'] ?? [])['code'] ?? '') === $copiedCode, 'copy redeem payload should contain copied code');

    $copyMethodNotAllowed = videochat_handle_invite_routes(
        $copyPath,
        'GET',
        [
            ...$adminRequestTemplate,
            'method' => 'GET',
            'uri' => $copyPath,
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($copyMethodNotAllowed), 'copy method-not-allowed response must be an array');
    videochat_invite_copy_boundary_assert((int) ($copyMethodNotAllowed['status'] ?? 0) === 405, 'copy method-not-allowed status should be 405');

    $missingCopy = videochat_handle_invite_routes(
        '/api/invite-codes/00000000-0000-4000-8000-000000000000/copy',
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'uri' => '/api/invite-codes/00000000-0000-4000-8000-000000000000/copy',
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($missingCopy), 'missing copy response must be an array');
    videochat_invite_copy_boundary_assert((int) ($missingCopy['status'] ?? 0) === 404, 'missing copy status should be 404');

    $expiredInvite = videochat_create_invite_code(
        $pdo,
        $adminUserId,
        'admin',
        ['scope' => 'call', 'call_id' => $callId],
        time() - videochat_invite_scope_ttl_seconds('call') - 60
    );
    videochat_invite_copy_boundary_assert((bool) ($expiredInvite['ok'] ?? false), 'expired invite setup should create row');
    $expiredInviteId = (string) ((($expiredInvite['invite_code'] ?? [])['id'] ?? ''));
    $expiredCopyPath = '/api/invite-codes/' . $expiredInviteId . '/copy';
    $expiredCopy = videochat_handle_invite_routes(
        $expiredCopyPath,
        'POST',
        [
            ...$adminRequestTemplate,
            'method' => 'POST',
            'uri' => $expiredCopyPath,
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_invite_copy_boundary_assert(is_array($expiredCopy), 'expired copy response must be an array');
    videochat_invite_copy_boundary_assert((int) ($expiredCopy['status'] ?? 0) === 410, 'expired copy status should be 410');
    $expiredCopyBody = videochat_invite_copy_boundary_decode($expiredCopy);
    videochat_invite_copy_boundary_assert((string) (($expiredCopyBody['error'] ?? [])['code'] ?? '') === 'invite_codes_expired', 'expired copy error code mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[invite-code-copy-boundary-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[invite-code-copy-boundary-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
