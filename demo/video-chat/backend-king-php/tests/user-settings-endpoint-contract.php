<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/users/user_settings.php';
require_once __DIR__ . '/../http/module_users.php';
require_once __DIR__ . '/../http/module_auth_session.php';

function videochat_user_settings_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[user-settings-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_user_settings_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-user-settings-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $userQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    );
    $userId = (int) $userQuery->fetchColumn();
    videochat_user_settings_endpoint_assert($userId > 0, 'expected seeded user account');

    $sessionId = 'sess_user_settings_endpoint_contract';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'user-settings-endpoint-contract')
SQL
    );
    $insertSession->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
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

    $requestTemplate = [
        'uri' => '/api/user/settings',
        'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        'remote_address' => '127.0.0.1',
    ];

    $apiAuthContext = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/user/settings',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_user_settings_endpoint_assert((bool) ($apiAuthContext['ok'] ?? false), 'auth context should be valid');

    $getResponse = videochat_handle_user_routes(
        '/api/user/settings',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($getResponse), 'GET settings response must be an array');
    videochat_user_settings_endpoint_assert((int) ($getResponse['status'] ?? 0) === 200, 'GET settings status should be 200');
    $getPayload = videochat_user_settings_endpoint_decode($getResponse);
    videochat_user_settings_endpoint_assert((string) ($getPayload['status'] ?? '') === 'ok', 'GET settings payload status mismatch');
    videochat_user_settings_endpoint_assert(is_array($getPayload['settings'] ?? null), 'GET settings payload should include settings object');
    videochat_user_settings_endpoint_assert((string) (($getPayload['settings'] ?? [])['locale'] ?? '') === 'en', 'GET settings locale default mismatch');
    videochat_user_settings_endpoint_assert((string) (($getPayload['settings'] ?? [])['direction'] ?? '') === 'ltr', 'GET settings direction mismatch');
    videochat_user_settings_endpoint_assert((string) (($getPayload['settings'] ?? [])['about_me'] ?? 'missing') === '', 'GET settings about_me default mismatch');
    videochat_user_settings_endpoint_assert((($getPayload['settings'] ?? [])['web_app_notifications_enabled'] ?? null) === false, 'GET settings web app notifications default mismatch');
    videochat_user_settings_endpoint_assert((($getPayload['settings'] ?? [])['web_app_notification_sound_enabled'] ?? null) === true, 'GET settings web app notification sound default mismatch');
    videochat_user_settings_endpoint_assert(!array_key_exists('messenger_contacts', (array) ($getPayload['settings'] ?? [])), 'GET settings must not expose removed messenger contacts');
    videochat_user_settings_endpoint_assert(!array_key_exists('onboarding_badges', (array) ($getPayload['settings'] ?? [])), 'GET settings must not expose onboarding badges');
    videochat_user_settings_endpoint_assert(
        count((array) ((($getPayload['localization'] ?? [])['supported_locales'] ?? []))) >= 28,
        'GET settings supported locale metadata missing'
    );

    $patchInvalidJson = videochat_handle_user_routes(
        '/api/user/settings',
        'PATCH',
        [...$requestTemplate, 'method' => 'PATCH', 'body' => 'not-json'],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($patchInvalidJson), 'PATCH invalid-json response must be an array');
    videochat_user_settings_endpoint_assert((int) ($patchInvalidJson['status'] ?? 0) === 400, 'PATCH invalid-json status should be 400');
    $patchInvalidJsonPayload = videochat_user_settings_endpoint_decode($patchInvalidJson);
    videochat_user_settings_endpoint_assert(
        (string) (($patchInvalidJsonPayload['error'] ?? [])['code'] ?? '') === 'user_settings_invalid_request_body',
        'PATCH invalid-json error code mismatch'
    );

    $patchInvalidValue = videochat_handle_user_routes(
        '/api/user/settings',
        'PATCH',
        [
            ...$requestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'time_format' => '99h',
                'date_format' => 'broken',
                'locale' => 'unknown',
                'post_logout_landing_url' => 'https://evil.example/logout',
                'linkedin_url' => 'https://example.com/in/user',
                'web_app_notification_sound_enabled' => 'loud',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($patchInvalidValue), 'PATCH invalid-value response must be an array');
    videochat_user_settings_endpoint_assert((int) ($patchInvalidValue['status'] ?? 0) === 422, 'PATCH invalid-value status should be 422');
    $patchInvalidValuePayload = videochat_user_settings_endpoint_decode($patchInvalidValue);
    videochat_user_settings_endpoint_assert(
        (string) (($patchInvalidValuePayload['error'] ?? [])['code'] ?? '') === 'user_settings_validation_failed',
        'PATCH invalid-value error code mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['time_format'] ?? '') === 'must_be_24h_or_12h',
        'PATCH invalid-value field mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['date_format'] ?? '') === 'must_be_supported_date_format',
        'PATCH invalid-value date_format field mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['locale'] ?? '') === 'must_be_supported_locale',
        'PATCH invalid-value locale field mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['post_logout_landing_url'] ?? '') === 'must_be_same_origin_path',
        'PATCH invalid-value post_logout_landing_url field mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['linkedin_url'] ?? '') === 'host_not_allowed',
        'PATCH invalid-value linkedin_url field mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchInvalidValuePayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['web_app_notification_sound_enabled'] ?? '') === 'must_be_boolean',
        'PATCH invalid-value web app notification boolean field mismatch'
    );
    $patchUnknownField = videochat_handle_user_routes(
        '/api/user/settings',
        'PATCH',
        [
            ...$requestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'role' => 'admin',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($patchUnknownField), 'PATCH unknown-field response must be an array');
    videochat_user_settings_endpoint_assert((int) ($patchUnknownField['status'] ?? 0) === 422, 'PATCH unknown-field status should be 422');
    $patchUnknownFieldPayload = videochat_user_settings_endpoint_decode($patchUnknownField);
    videochat_user_settings_endpoint_assert(
        (string) (((($patchUnknownFieldPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['role'] ?? '') === 'field_not_updatable',
        'PATCH unknown-field validation mismatch'
    );

    $patchValid = videochat_handle_user_routes(
        '/api/user/settings',
        'PATCH',
        [
            ...$requestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'display_name' => '  Endpoint User Updated  ',
                'time_format' => '12h',
                'date_format' => 'mdy_slash',
                'theme' => 'light',
                'locale' => 'sgd',
                'avatar_path' => ' /avatars/endpoint-user-updated.png ',
                'post_logout_landing_url' => ' /call-goodbye?from=settings ',
                'about_me' => '  Endpoint profile text.  ',
                'linkedin_url' => ' https://linkedin.com/in/endpoint-user ',
                'x_url' => 'https://x.com/endpointuser',
                'youtube_url' => 'https://youtu.be/abcdefghijk',
                'web_app_notifications_enabled' => true,
                'web_app_notification_sound_enabled' => false,
                'web_app_notification_call_invites_enabled' => true,
                'web_app_notification_call_reminders_enabled' => false,
                'web_app_notification_chat_mentions_enabled' => true,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($patchValid), 'PATCH valid response must be an array');
    videochat_user_settings_endpoint_assert((int) ($patchValid['status'] ?? 0) === 200, 'PATCH valid status should be 200');
    $patchValidPayload = videochat_user_settings_endpoint_decode($patchValid);
    videochat_user_settings_endpoint_assert((string) ($patchValidPayload['status'] ?? '') === 'ok', 'PATCH valid payload status mismatch');
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['display_name'] ?? '')) === 'Endpoint User Updated',
        'PATCH valid display_name should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['time_format'] ?? '')) === '12h',
        'PATCH valid time_format mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['date_format'] ?? '')) === 'mdy_slash',
        'PATCH valid date_format mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['theme'] ?? '')) === 'light',
        'PATCH valid theme mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['locale'] ?? '')) === 'sgd',
        'PATCH valid locale mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['direction'] ?? '')) === 'rtl',
        'PATCH valid direction mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['avatar_path'] ?? '')) === '/avatars/endpoint-user-updated.png',
        'PATCH valid avatar_path should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['post_logout_landing_url'] ?? '')) === '/call-goodbye?from=settings',
        'PATCH valid post_logout_landing_url should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['about_me'] ?? '')) === 'Endpoint profile text.',
        'PATCH valid about_me should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['linkedin_url'] ?? '')) === 'https://linkedin.com/in/endpoint-user',
        'PATCH valid linkedin_url should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['x_url'] ?? '')) === 'https://x.com/endpointuser',
        'PATCH valid x_url should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (string) (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['youtube_url'] ?? '')) === 'https://youtu.be/abcdefghijk',
        'PATCH valid youtube_url should be normalized'
    );
    videochat_user_settings_endpoint_assert(
        (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['web_app_notifications_enabled'] ?? null)) === true,
        'PATCH valid web app notifications mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['web_app_notification_sound_enabled'] ?? null)) === false,
        'PATCH valid web app notification sound mismatch'
    );
    videochat_user_settings_endpoint_assert(
        (((($patchValidPayload['result'] ?? [])['settings'] ?? [])['web_app_notification_call_reminders_enabled'] ?? null)) === false,
        'PATCH valid web app call reminder notifications mismatch'
    );
    videochat_user_settings_endpoint_assert(
        !array_key_exists('messenger_contacts', (array) ((($patchValidPayload['result'] ?? [])['settings'] ?? []))),
        'PATCH settings must not expose removed messenger contacts'
    );

    $reauth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_user_settings_endpoint_assert((bool) ($reauth['ok'] ?? false), 'reauth should stay valid after settings patch');

    $activeWebsocketsBySession = [];
    $issueSessionId = static fn (): string => 'sess_unused_user_settings_endpoint';

    $sessionResponse = videochat_handle_auth_session_routes(
        '/api/auth/session',
        'GET',
        ['method' => 'GET', 'uri' => '/api/auth/session', 'headers' => ['Authorization' => 'Bearer ' . $sessionId]],
        $reauth,
        $activeWebsocketsBySession,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $issueSessionId
    );
    videochat_user_settings_endpoint_assert(is_array($sessionResponse), 'session-check response must be an array');
    videochat_user_settings_endpoint_assert((int) ($sessionResponse['status'] ?? 0) === 200, 'session-check status should be 200');
    $sessionPayload = videochat_user_settings_endpoint_decode($sessionResponse);
    videochat_user_settings_endpoint_assert((string) ($sessionPayload['status'] ?? '') === 'ok', 'session-check payload status mismatch');
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['display_name'] ?? '')) === 'Endpoint User Updated',
        'session-check should reflect updated display_name'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['time_format'] ?? '')) === '12h',
        'session-check should reflect updated time_format'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['date_format'] ?? '')) === 'mdy_slash',
        'session-check should reflect updated date_format'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['theme'] ?? '')) === 'light',
        'session-check should reflect updated theme'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['locale'] ?? '')) === 'sgd',
        'session-check should reflect updated locale'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['direction'] ?? '')) === 'rtl',
        'session-check should reflect updated direction'
    );
    videochat_user_settings_endpoint_assert(
        count((array) ((($sessionPayload['user'] ?? [])['supported_locales'] ?? []))) >= 28,
        'session-check should include supported locale metadata'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['avatar_path'] ?? '')) === '/avatars/endpoint-user-updated.png',
        'session-check should reflect updated avatar_path'
    );
    videochat_user_settings_endpoint_assert(
        (string) ((($sessionPayload['user'] ?? [])['post_logout_landing_url'] ?? '')) === '/call-goodbye?from=settings',
        'session-check should reflect updated post_logout_landing_url'
    );
    videochat_user_settings_endpoint_assert(
        (($sessionPayload['user'] ?? [])['web_app_notifications_enabled'] ?? null) === true,
        'session-check should reflect updated web app notifications'
    );
    videochat_user_settings_endpoint_assert(
        (($sessionPayload['user'] ?? [])['web_app_notification_sound_enabled'] ?? null) === false,
        'session-check should reflect updated web app notification sound'
    );

    $emailsResponse = videochat_handle_user_routes(
        '/api/user/emails',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($emailsResponse), 'GET emails response must be an array');
    videochat_user_settings_endpoint_assert((int) ($emailsResponse['status'] ?? 0) === 200, 'GET emails status should be 200');
    $emailsPayload = videochat_user_settings_endpoint_decode($emailsResponse);
    videochat_user_settings_endpoint_assert(
        count((array) (($emailsPayload['result'] ?? [])['emails'] ?? [])) >= 1,
        'GET emails should include primary email'
    );

    $addEmailResponse = videochat_handle_user_routes(
        '/api/user/emails',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode(['email' => 'endpoint-alt@example.test'], JSON_UNESCAPED_SLASHES),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($addEmailResponse), 'POST emails response must be an array');
    videochat_user_settings_endpoint_assert((int) ($addEmailResponse['status'] ?? 0) === 201, 'POST emails status should be 201');
    $addEmailPayload = videochat_user_settings_endpoint_decode($addEmailResponse);
    $pendingEmailId = (int) (((($addEmailPayload['result'] ?? [])['email'] ?? [])['id'] ?? 0));
    videochat_user_settings_endpoint_assert($pendingEmailId > 0, 'POST emails should return pending email id');

    $deleteEmailResponse = videochat_handle_user_routes(
        '/api/user/emails/' . $pendingEmailId,
        'DELETE',
        [...$requestTemplate, 'method' => 'DELETE', 'body' => ''],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($deleteEmailResponse), 'DELETE email response must be an array');
    videochat_user_settings_endpoint_assert((int) ($deleteEmailResponse['status'] ?? 0) === 200, 'DELETE email status should be 200');

    $otherSessionId = 'sess_user_settings_endpoint_other';
    $pdo->prepare(
        'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, NULL, NULL)'
    )->execute([
        ':id' => $otherSessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);
    $passwordResponse = videochat_handle_user_routes(
        '/api/user/password',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'current_password' => 'user123',
                'new_password' => 'endpoint-new-password',
                'repeat_password' => 'endpoint-new-password',
            ], JSON_UNESCAPED_SLASHES),
        ],
        $apiAuthContext,
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($passwordResponse), 'POST password response must be an array');
    videochat_user_settings_endpoint_assert((int) ($passwordResponse['status'] ?? 0) === 200, 'POST password status should be 200');
    $passwordPayload = videochat_user_settings_endpoint_decode($passwordResponse);
    videochat_user_settings_endpoint_assert(
        (int) (($passwordPayload['result'] ?? [])['revoked_sessions'] ?? 0) === 1,
        'POST password should revoke other sessions'
    );

    $invalidUserContextResponse = videochat_handle_user_routes(
        '/api/user/settings',
        'GET',
        [...$requestTemplate, 'method' => 'GET', 'body' => ''],
        ['user' => ['id' => 0]],
        [],
        sys_get_temp_dir(),
        512000,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_user_settings_endpoint_assert(is_array($invalidUserContextResponse), 'invalid user-context response must be an array');
    videochat_user_settings_endpoint_assert((int) ($invalidUserContextResponse['status'] ?? 0) === 401, 'invalid user-context status should be 401');

    @unlink($databasePath);
    fwrite(STDOUT, "[user-settings-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[user-settings-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
