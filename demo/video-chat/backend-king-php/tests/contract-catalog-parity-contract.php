<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_runtime.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';
require_once __DIR__ . '/../domain/realtime/realtime_typing.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';
require_once __DIR__ . '/../domain/realtime/realtime_reaction.php';

function videochat_contract_catalog_fail(string $message): never
{
    fwrite(STDERR, "[contract-catalog-parity-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_contract_catalog_assert(bool $condition, string $message): void
{
    if (!$condition) {
        videochat_contract_catalog_fail($message);
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_contract_catalog_decode_json(string $raw, string $label): array
{
    try {
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        videochat_contract_catalog_fail("{$label} JSON decode failed: " . $error->getMessage());
    }

    if (!is_array($decoded)) {
        videochat_contract_catalog_fail("{$label} payload must decode to object/array.");
    }

    return $decoded;
}

/**
 * @param array<int, string> $errors
 */
function videochat_contract_catalog_validate_value(mixed $value, mixed $schema, string $path, array &$errors): void
{
    if (!is_array($schema)) {
        $errors[] = "{$path}: schema node must be an object.";
        return;
    }

    if (isset($schema['one_of'])) {
        $variants = $schema['one_of'];
        if (!is_array($variants) || $variants === []) {
            $errors[] = "{$path}: one_of must contain at least one schema.";
            return;
        }

        foreach ($variants as $variant) {
            $variantErrors = [];
            videochat_contract_catalog_validate_value($value, $variant, $path, $variantErrors);
            if ($variantErrors === []) {
                return;
            }
        }

        $errors[] = "{$path}: value did not match any one_of variant.";
        return;
    }

    $type = $schema['type'] ?? null;
    if (!is_string($type) || trim($type) === '') {
        $errors[] = "{$path}: schema type is missing.";
        return;
    }

    $type = strtolower(trim($type));
    if ($type === 'any') {
        return;
    }

    if ($type === 'null') {
        if ($value !== null) {
            $errors[] = "{$path}: expected null.";
        }
        return;
    }

    if ($type === 'string') {
        if (!is_string($value)) {
            $errors[] = "{$path}: expected string.";
            return;
        }

        $minLength = $schema['min_length'] ?? null;
        if (is_int($minLength) && strlen($value) < $minLength) {
            $errors[] = "{$path}: string shorter than min_length {$minLength}.";
        }

        $enum = $schema['enum'] ?? null;
        if (is_array($enum) && $enum !== [] && !in_array($value, $enum, true)) {
            $errors[] = "{$path}: value '{$value}' is not in enum.";
        }

        $pattern = $schema['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '' && preg_match($pattern, $value) !== 1) {
            $errors[] = "{$path}: value '{$value}' does not match pattern {$pattern}.";
        }
        return;
    }

    if ($type === 'int') {
        if (!is_int($value)) {
            $errors[] = "{$path}: expected int.";
            return;
        }

        $min = $schema['min'] ?? null;
        if (is_int($min) && $value < $min) {
            $errors[] = "{$path}: int {$value} is below min {$min}.";
        }
        $max = $schema['max'] ?? null;
        if (is_int($max) && $value > $max) {
            $errors[] = "{$path}: int {$value} is above max {$max}.";
        }
        return;
    }

    if ($type === 'bool') {
        if (!is_bool($value)) {
            $errors[] = "{$path}: expected bool.";
        }
        return;
    }

    if ($type === 'array') {
        if (!is_array($value)) {
            $errors[] = "{$path}: expected array.";
            return;
        }

        $itemSchema = $schema['items'] ?? null;
        if ($itemSchema === null) {
            return;
        }

        foreach ($value as $index => $itemValue) {
            videochat_contract_catalog_validate_value(
                $itemValue,
                $itemSchema,
                "{$path}[{$index}]",
                $errors
            );
        }
        return;
    }

    if ($type === 'object') {
        if (!is_array($value)) {
            $errors[] = "{$path}: expected object(array).";
            return;
        }

        $required = $schema['required'] ?? [];
        if (!is_array($required)) {
            $errors[] = "{$path}: required must be an object.";
            return;
        }

        foreach ($required as $requiredKey => $requiredSchema) {
            if (!is_string($requiredKey) || $requiredKey === '') {
                $errors[] = "{$path}: required key must be a non-empty string.";
                continue;
            }
            if (!array_key_exists($requiredKey, $value)) {
                $errors[] = "{$path}.{$requiredKey}: missing required key.";
                continue;
            }
            videochat_contract_catalog_validate_value(
                $value[$requiredKey],
                $requiredSchema,
                "{$path}.{$requiredKey}",
                $errors
            );
        }

        $optional = $schema['optional'] ?? [];
        if (is_array($optional)) {
            foreach ($optional as $optionalKey => $optionalSchema) {
                if (!is_string($optionalKey) || $optionalKey === '') {
                    $errors[] = "{$path}: optional key must be a non-empty string.";
                    continue;
                }
                if (!array_key_exists($optionalKey, $value)) {
                    continue;
                }
                videochat_contract_catalog_validate_value(
                    $value[$optionalKey],
                    $optionalSchema,
                    "{$path}.{$optionalKey}",
                    $errors
                );
            }
        }

        $allowAdditional = $schema['allow_additional'] ?? true;
        if ($allowAdditional === false) {
            $allowed = [];
            foreach (array_keys($required) as $key) {
                if (is_string($key) && $key !== '') {
                    $allowed[$key] = true;
                }
            }
            if (is_array($optional)) {
                foreach (array_keys($optional) as $key) {
                    if (is_string($key) && $key !== '') {
                        $allowed[$key] = true;
                    }
                }
            }

            foreach (array_keys($value) as $actualKey) {
                if (!is_string($actualKey)) {
                    $errors[] = "{$path}: object key must be string, got non-string key.";
                    continue;
                }
                if (!isset($allowed[$actualKey])) {
                    $errors[] = "{$path}.{$actualKey}: unexpected key (allow_additional=false).";
                }
            }
        }

        return;
    }

    $errors[] = "{$path}: unsupported schema type '{$type}'.";
}

/**
 * @param array<string, mixed> $catalog
 * @param array<string, mixed> $payload
 */
function videochat_contract_catalog_assert_payload(array $catalog, string $section, string $name, array $payload): void
{
    $schema = $catalog[$section][$name] ?? null;
    if (!is_array($schema)) {
        videochat_contract_catalog_fail("missing schema for {$section}.{$name}");
    }

    $errors = [];
    videochat_contract_catalog_validate_value($payload, $schema, "{$section}.{$name}", $errors);
    if ($errors !== []) {
        videochat_contract_catalog_fail(
            "schema mismatch for {$section}.{$name}:\n- " . implode("\n- ", $errors)
        );
    }
}

function videochat_contract_catalog_last_frame(array $frames, string $socket): array
{
    $socketFrames = $frames[$socket] ?? null;
    if (!is_array($socketFrames) || $socketFrames === []) {
        return [];
    }

    $last = end($socketFrames);
    return is_array($last) ? $last : [];
}

try {
    $catalogPath = __DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json';
    $catalogRaw = file_get_contents($catalogPath);
    if (!is_string($catalogRaw) || trim($catalogRaw) === '') {
        videochat_contract_catalog_fail("catalog file is missing or empty: {$catalogPath}");
    }
    $catalog = videochat_contract_catalog_decode_json($catalogRaw, 'catalog');
    videochat_contract_catalog_assert((string) ($catalog['catalog_name'] ?? '') === 'king-video-chat-api-ws', 'catalog_name mismatch');
    videochat_contract_catalog_assert((string) ($catalog['catalog_version'] ?? '') !== '', 'catalog_version must be non-empty');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'video-chat-backend-king-php',
            'app' => [
                'name' => 'king-video-chat-backend',
                'version' => 'contract-catalog',
                'environment' => 'test',
            ],
            'runtime' => [
                'king_version' => '1.0.5',
                'transport' => 'king_http1_server_listen_once',
                'ws_path' => '/ws',
                'health' => [
                    'module_status' => 'ok',
                    'system_status' => 'not_initialized',
                    'build' => 'v1',
                    'module_version' => '1.0.5',
                    'active_runtime_count' => 1,
                ],
            ],
            'database' => [
                'path' => '/tmp/video-chat.sqlite',
                'schema_version' => 4,
                'migrations_total' => 4,
                'migrations_applied' => 4,
                'demo_users' => [
                    ['email' => 'admin@intelligent-intern.com', 'display_name' => 'Platform Admin', 'role' => 'admin'],
                    ['email' => 'user@intelligent-intern.com', 'display_name' => 'Call User', 'role' => 'user'],
                ],
            ],
            'auth' => [
                'login_endpoint' => '/api/auth/login',
                'session_endpoint' => '/api/auth/session',
                'refresh_endpoint' => '/api/auth/refresh',
                'logout_endpoint' => '/api/auth/logout',
                'settings_endpoint' => '/api/user/settings',
                'avatar_upload_endpoint' => '/api/user/avatar',
            ],
            'calls' => [
                'list_endpoint' => '/api/calls',
                'invite_code_create_endpoint' => '/api/invite-codes',
                'invite_code_redeem_endpoint' => '/api/invite-codes/redeem',
            ],
            'time' => gmdate('c'),
        ];
    };

    $runtimeResponse = videochat_handle_runtime_routes('/api/runtime', 'GET', $jsonResponse, $runtimeEnvelope, '/ws');
    videochat_contract_catalog_assert(is_array($runtimeResponse), 'runtime route should respond');
    videochat_contract_catalog_assert((int) ($runtimeResponse['status'] ?? 0) === 200, 'runtime status must be 200');
    $runtimePayload = videochat_contract_catalog_decode_json((string) ($runtimeResponse['body'] ?? ''), 'runtime_payload');
    videochat_contract_catalog_assert_payload($catalog, 'api', 'runtime_health', $runtimePayload);

    $bootstrapResponse = videochat_handle_runtime_routes('/api/bootstrap', 'GET', $jsonResponse, $runtimeEnvelope, '/ws');
    videochat_contract_catalog_assert(is_array($bootstrapResponse), 'bootstrap route should respond');
    videochat_contract_catalog_assert((int) ($bootstrapResponse['status'] ?? 0) === 200, 'bootstrap status must be 200');
    $bootstrapPayload = videochat_contract_catalog_decode_json((string) ($bootstrapResponse['body'] ?? ''), 'bootstrap_payload');
    videochat_contract_catalog_assert_payload($catalog, 'api', 'bootstrap', $bootstrapPayload);

    $presenceState = videochat_presence_state_init();
    $typingState = videochat_typing_state_init();
    $lobbyState = videochat_lobby_state_init();
    $reactionState = videochat_reaction_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $adminConnection = videochat_presence_connection_descriptor(
        ['id' => 100, 'display_name' => 'Platform Admin', 'role' => 'admin'],
        'sess-admin',
        'conn-admin',
        'socket-admin',
        'lobby',
        1_780_200_000
    );
    $userConnection = videochat_presence_connection_descriptor(
        ['id' => 200, 'display_name' => 'Call User', 'role' => 'user'],
        'sess-user',
        'conn-user',
        'socket-user',
        'lobby',
        1_780_200_005
    );

    $adminJoin = videochat_presence_join_room($presenceState, $adminConnection, 'lobby', $sender);
    $userJoin = videochat_presence_join_room($presenceState, $userConnection, 'lobby', $sender);
    $adminConnection = is_array($adminJoin['connection'] ?? null) ? $adminJoin['connection'] : $adminConnection;
    $userConnection = is_array($userJoin['connection'] ?? null) ? $userJoin['connection'] : $userConnection;
    $frames = [];

    $snapshotSent = videochat_presence_send_room_snapshot($presenceState, $adminConnection, 'contract_check', $sender);
    videochat_contract_catalog_assert($snapshotSent, 'room snapshot send should succeed');
    $roomSnapshot = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($roomSnapshot !== [], 'room snapshot frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'room_snapshot', $roomSnapshot);

    $frames = [];
    $chatCommand = videochat_chat_decode_client_frame(json_encode([
        'type' => 'chat/send',
        'message' => 'Catalog check',
        'client_message_id' => 'catalog-1',
    ], JSON_UNESCAPED_SLASHES));
    videochat_contract_catalog_assert((bool) ($chatCommand['ok'] ?? false), 'chat command should decode');
    $chatPublish = videochat_chat_publish($presenceState, $userConnection, $chatCommand, $sender, 1_780_200_010_000);
    videochat_contract_catalog_assert((bool) ($chatPublish['ok'] ?? false), 'chat publish should succeed');
    $chatPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($chatPayload !== [], 'chat frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'chat_message', $chatPayload);
    $chatMessage = is_array($chatPayload['message'] ?? null) ? $chatPayload['message'] : [];
    $chatAckPayload = videochat_chat_ack_payload(
        (string) ($chatPayload['room_id'] ?? 'lobby'),
        $chatMessage,
        (int) ($chatPublish['sent_count'] ?? 0),
        1_780_200_010_500
    );
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'chat_ack', $chatAckPayload);

    $frames = [];
    $typingCommand = videochat_typing_decode_client_frame(json_encode(['type' => 'typing/start'], JSON_UNESCAPED_SLASHES));
    videochat_contract_catalog_assert((bool) ($typingCommand['ok'] ?? false), 'typing command should decode');
    $typingApply = videochat_typing_apply_command($typingState, $presenceState, $userConnection, $typingCommand, $sender, 1_780_200_020_000);
    videochat_contract_catalog_assert((bool) ($typingApply['ok'] ?? false), 'typing apply should succeed');
    videochat_contract_catalog_assert((bool) ($typingApply['emitted'] ?? false), 'typing apply should emit start');
    $typingPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($typingPayload !== [], 'typing frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'typing_start', $typingPayload);
    $typingStopCommand = videochat_typing_decode_client_frame(json_encode(['type' => 'typing/stop'], JSON_UNESCAPED_SLASHES));
    videochat_contract_catalog_assert((bool) ($typingStopCommand['ok'] ?? false), 'typing stop command should decode');
    $typingStopApply = videochat_typing_apply_command($typingState, $presenceState, $userConnection, $typingStopCommand, $sender, 1_780_200_020_500);
    videochat_contract_catalog_assert((bool) ($typingStopApply['ok'] ?? false), 'typing stop apply should succeed');
    videochat_contract_catalog_assert((bool) ($typingStopApply['emitted'] ?? false), 'typing stop apply should emit');
    $typingStopPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($typingStopPayload !== [], 'typing stop frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'typing_stop', $typingStopPayload);

    $frames = [];
    $lobbyCommand = videochat_lobby_decode_client_frame(json_encode(['type' => 'lobby/queue/join'], JSON_UNESCAPED_SLASHES));
    videochat_contract_catalog_assert((bool) ($lobbyCommand['ok'] ?? false), 'lobby command should decode');
    $lobbyApply = videochat_lobby_apply_command($lobbyState, $presenceState, $userConnection, $lobbyCommand, $sender, 1_780_200_030_000);
    videochat_contract_catalog_assert((bool) ($lobbyApply['ok'] ?? false), 'lobby apply should succeed');
    $lobbyPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($lobbyPayload !== [], 'lobby frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'lobby_snapshot', $lobbyPayload);

    $frames = [];
    $signalCommand = videochat_signaling_decode_client_frame(json_encode([
        'type' => 'call/offer',
        'target_user_id' => 100,
        'payload' => ['sdp' => 'v=0'],
    ], JSON_UNESCAPED_SLASHES));
    videochat_contract_catalog_assert((bool) ($signalCommand['ok'] ?? false), 'signaling command should decode');
    $signalPublish = videochat_signaling_publish($presenceState, $userConnection, $signalCommand, $sender, 1_780_200_040_000);
    videochat_contract_catalog_assert((bool) ($signalPublish['ok'] ?? false), 'signaling publish should succeed');
    $signalPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($signalPayload !== [], 'signaling frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'signaling_event', $signalPayload);

    $frames = [];
    $reactionCommand = videochat_reaction_decode_client_frame(json_encode([
        'type' => 'reaction/send',
        'emoji' => "\u{1F44D}",
        'client_reaction_id' => 'catalog-rx-1',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    videochat_contract_catalog_assert((bool) ($reactionCommand['ok'] ?? false), 'reaction command should decode');
    $reactionPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $userConnection,
        $reactionCommand,
        $sender,
        1_780_200_050_000
    );
    videochat_contract_catalog_assert((bool) ($reactionPublish['ok'] ?? false), 'reaction publish should succeed');
    $reactionPayload = videochat_contract_catalog_last_frame($frames, 'socket-admin');
    videochat_contract_catalog_assert($reactionPayload !== [], 'reaction frame should be captured');
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'reaction_event', $reactionPayload);

    $batchReaction = is_array($reactionPayload['reaction'] ?? null) ? $reactionPayload['reaction'] : [];
    videochat_contract_catalog_assert($batchReaction !== [], 'reaction batch source payload should exist');
    $reactionBatchPayload = videochat_reaction_batch_event_payload(
        'lobby',
        videochat_reaction_sender_payload($userConnection),
        [$batchReaction],
        'flood',
        1_780_200_050_500
    );
    videochat_contract_catalog_assert_payload($catalog, 'ws', 'reaction_batch', $reactionBatchPayload);

    fwrite(STDOUT, "[contract-catalog-parity-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[contract-catalog-parity-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
