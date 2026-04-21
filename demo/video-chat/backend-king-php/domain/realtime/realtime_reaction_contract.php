<?php

declare(strict_types=1);

function videochat_reaction_state_init(): array
{
    return [
        'rooms' => [],
    ];
}

function videochat_reaction_default_emoji_set(): array
{
    return [
        "\u{1F44D}" => true,
        "\u{2764}\u{FE0F}" => true,
        "\u{1F418}" => true,
        "\u{1F973}" => true,
        "\u{1F602}" => true,
        "\u{1F62E}" => true,
        "\u{1F622}" => true,
        "\u{1F914}" => true,
        "\u{1F44F}" => true,
        "\u{1F44E}" => true,
    ];
}

function videochat_reaction_allowed_emoji_set(): array
{
    $configured = getenv('VIDEOCHAT_WS_REACTION_ALLOWED_EMOJIS');
    if (!is_string($configured) || trim($configured) === '') {
        return videochat_reaction_default_emoji_set();
    }

    $allowed = [];
    foreach (preg_split('/\s*,\s*/', $configured) ?: [] as $entry) {
        if (!is_string($entry)) {
            continue;
        }

        $emoji = trim($entry);
        if ($emoji === '') {
            continue;
        }

        $allowed[$emoji] = true;
    }

    return $allowed !== [] ? $allowed : videochat_reaction_default_emoji_set();
}

function videochat_reaction_max_chars(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_MAX_CHARS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 8;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 32) {
        return 32;
    }

    return $configured;
}

function videochat_reaction_max_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_MAX_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 32;
    }

    if ($configured < 4) {
        return 4;
    }
    if ($configured > 256) {
        return 256;
    }

    return $configured;
}

function videochat_reaction_flood_window_ms(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_WINDOW_MS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_WINDOW_MS'), FILTER_VALIDATE_INT);
    }
    if (!is_int($configured)) {
        $configured = 1000;
    }

    if ($configured < 250) {
        return 250;
    }
    if ($configured > 60000) {
        return 60000;
    }

    return $configured;
}

function videochat_reaction_flood_threshold_per_window(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_THRESHOLD_PER_WINDOW'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_THROTTLE_MAX_PER_WINDOW'), FILTER_VALIDATE_INT);
    }
    if (!is_int($configured)) {
        $configured = 20;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 1000) {
        return 1000;
    }

    return $configured;
}

function videochat_reaction_flood_batch_size(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_FLOOD_BATCH_SIZE'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 25;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 200) {
        return 200;
    }

    return $configured;
}

function videochat_reaction_client_batch_max_count(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_REACTION_CLIENT_BATCH_MAX_COUNT'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 25;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 200) {
        return 200;
    }

    return $configured;
}

function videochat_reaction_payload_length(string $emoji): int
{
    if (function_exists('mb_strlen')) {
        $length = mb_strlen($emoji, 'UTF-8');
        if (is_int($length)) {
            return $length;
        }
    }

    return strlen($emoji);
}

function videochat_reaction_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'reaction_' . $effectiveNowMs . '_' . $suffix;
}

function videochat_reaction_resolve_id(
    array $connection,
    array $command,
    string $roomId,
    ?int $nowUnixMs = null
): string {
    $clientReactionId = is_string($command['client_reaction_id'] ?? null)
        ? trim((string) $command['client_reaction_id'])
        : '';
    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId > 0 && $clientReactionId !== '') {
        $identityHash = hash(
            'sha256',
            videochat_presence_normalize_room_id($roomId) . "\n"
                . $senderUserId . "\n"
                . strtolower($clientReactionId)
        );

        return 'reaction_' . substr($identityHash, 0, 24);
    }

    return videochat_reaction_id($nowUnixMs);
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   emoji: string,
 *   emojis: array<int, string>,
 *   client_reaction_id: ?string,
 *   error: string
 * }
 */
function videochat_reaction_decode_client_reaction_id(mixed $value): array
{
    if (!is_string($value)) {
        return [
            'ok' => true,
            'client_reaction_id' => null,
            'error' => '',
        ];
    }

    $candidate = trim($value);
    if ($candidate === '') {
        return [
            'ok' => true,
            'client_reaction_id' => null,
            'error' => '',
        ];
    }

    if (strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) !== 1) {
        return [
            'ok' => false,
            'client_reaction_id' => null,
            'error' => 'invalid_client_reaction_id',
        ];
    }

    return [
        'ok' => true,
        'client_reaction_id' => $candidate,
        'error' => '',
    ];
}

function videochat_reaction_validate_emoji(string $rawEmoji): array
{
    $emoji = trim($rawEmoji);
    if ($emoji === '') {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'empty_emoji',
        ];
    }

    if (strlen($emoji) > videochat_reaction_max_bytes()) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'emoji_too_large',
        ];
    }

    if (videochat_reaction_payload_length($emoji) > videochat_reaction_max_chars()) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'emoji_too_long',
        ];
    }

    $allowed = videochat_reaction_allowed_emoji_set();
    if (($allowed[$emoji] ?? false) !== true) {
        return [
            'ok' => false,
            'emoji' => '',
            'error' => 'unsupported_emoji',
        ];
    }

    return [
        'ok' => true,
        'emoji' => $emoji,
        'error' => '',
    ];
}

function videochat_reaction_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['reaction/send', 'reaction/send_batch'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => 'unsupported_type',
        ];
    }

    $clientReactionIdResult = videochat_reaction_decode_client_reaction_id($decoded['client_reaction_id'] ?? null);
    if (!(bool) ($clientReactionIdResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => (string) ($clientReactionIdResult['error'] ?? 'invalid_client_reaction_id'),
        ];
    }
    $clientReactionId = $clientReactionIdResult['client_reaction_id'] ?? null;

    if ($type === 'reaction/send_batch') {
        $rawBatch = $decoded['emojis'] ?? null;
        if (!is_array($rawBatch) || $rawBatch === []) {
            return [
                'ok' => false,
                'type' => $type,
                'emoji' => '',
                'emojis' => [],
                'client_reaction_id' => null,
                'error' => 'empty_batch',
            ];
        }
        if (count($rawBatch) > videochat_reaction_client_batch_max_count()) {
            return [
                'ok' => false,
                'type' => $type,
                'emoji' => '',
                'emojis' => [],
                'client_reaction_id' => null,
                'error' => 'batch_too_large',
            ];
        }

        $emojis = [];
        foreach ($rawBatch as $rawEmoji) {
            if (!is_string($rawEmoji)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'emoji' => '',
                    'emojis' => [],
                    'client_reaction_id' => null,
                    'error' => 'invalid_batch_emoji',
                ];
            }
            $validation = videochat_reaction_validate_emoji($rawEmoji);
            if (!(bool) ($validation['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'emoji' => '',
                    'emojis' => [],
                    'client_reaction_id' => null,
                    'error' => (string) ($validation['error'] ?? 'invalid_emoji'),
                ];
            }
            $emojis[] = (string) ($validation['emoji'] ?? '');
        }

        return [
            'ok' => true,
            'type' => 'reaction/send_batch',
            'emoji' => '',
            'emojis' => $emojis,
            'client_reaction_id' => is_string($clientReactionId) ? $clientReactionId : null,
            'error' => '',
        ];
    }

    $rawEmoji = is_string($decoded['emoji'] ?? null) ? (string) $decoded['emoji'] : '';
    $emojiValidation = videochat_reaction_validate_emoji($rawEmoji);
    if (!(bool) ($emojiValidation['ok'] ?? false)) {
        return [
            'ok' => false,
            'type' => $type,
            'emoji' => '',
            'emojis' => [],
            'client_reaction_id' => null,
            'error' => (string) ($emojiValidation['error'] ?? 'invalid_emoji'),
        ];
    }
    $emoji = (string) ($emojiValidation['emoji'] ?? '');

    return [
        'ok' => true,
        'type' => 'reaction/send',
        'emoji' => $emoji,
        'emojis' => [$emoji],
        'client_reaction_id' => $clientReactionId,
        'error' => '',
    ];
}
