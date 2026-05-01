<?php

declare(strict_types=1);

function videochat_chat_max_chars(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_CHAT_MAX_CHARS'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 2000;
    }

    if ($configured < 1) {
        return 1;
    }
    if ($configured > 8000) {
        return 8000;
    }

    return $configured;
}

function videochat_chat_max_bytes(): int
{
    $configured = filter_var(getenv('VIDEOCHAT_WS_CHAT_MAX_BYTES'), FILTER_VALIDATE_INT);
    if (!is_int($configured)) {
        $configured = 8192;
    }

    if ($configured < 64) {
        return 64;
    }
    if ($configured > 65536) {
        return 65536;
    }

    return $configured;
}

function videochat_chat_message_length(string $message): int
{
    if (function_exists('mb_strlen')) {
        $length = mb_strlen($message, 'UTF-8');
        if (is_int($length)) {
            return $length;
        }
    }

    return strlen($message);
}

function videochat_chat_message_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'chat_' . $effectiveNowMs . '_' . $suffix;
}

function videochat_chat_resolve_message_id(
    array $connection,
    array $command,
    string $roomId,
    ?int $nowUnixMs = null
): string {
    $clientMessageId = is_string($command['client_message_id'] ?? null)
        ? trim((string) $command['client_message_id'])
        : '';
    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId > 0 && $clientMessageId !== '') {
        $identityHash = hash(
            'sha256',
            videochat_presence_normalize_room_id($roomId) . "\n"
                . $senderUserId . "\n"
                . strtolower($clientMessageId)
        );

        return 'chat_' . substr($identityHash, 0, 24);
    }

    return videochat_chat_message_id($nowUnixMs);
}

function videochat_chat_ack_id(string $messageId): string
{
    $normalizedMessageId = trim($messageId);
    if ($normalizedMessageId === '') {
        $normalizedMessageId = 'missing';
    }

    return 'chat_ack_' . substr(hash('sha256', $normalizedMessageId), 0, 24);
}

/**
 * @return array{ok: bool, error: string, attachments: array<int, string>}
 */
function videochat_chat_decode_attachment_refs(mixed $rawAttachments): array
{
    if ($rawAttachments === null) {
        return [
            'ok' => true,
            'error' => '',
            'attachments' => [],
        ];
    }

    if (!is_array($rawAttachments)) {
        return [
            'ok' => false,
            'error' => 'invalid_attachment_refs',
            'attachments' => [],
        ];
    }

    if (count($rawAttachments) > 10) {
        return [
            'ok' => false,
            'error' => 'attachment_count_exceeded',
            'attachments' => [],
        ];
    }

    $attachmentIds = [];
    $seen = [];
    foreach ($rawAttachments as $entry) {
        if (is_string($entry)) {
            $attachmentId = trim($entry);
        } elseif (is_array($entry) && is_string($entry['id'] ?? null)) {
            $attachmentId = trim((string) $entry['id']);
        } else {
            return [
                'ok' => false,
                'error' => 'invalid_attachment_refs',
                'attachments' => [],
            ];
        }

        if ($attachmentId === '' || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $attachmentId) !== 1) {
            return [
                'ok' => false,
                'error' => 'invalid_attachment_ref',
                'attachments' => [],
            ];
        }
        if (isset($seen[$attachmentId])) {
            return [
                'ok' => false,
                'error' => 'invalid_attachment_ref',
                'attachments' => [],
            ];
        }

        $seen[$attachmentId] = true;
        $attachmentIds[] = $attachmentId;
    }

    return [
        'ok' => true,
        'error' => '',
        'attachments' => $attachmentIds,
    ];
}

/**
 * @return array{
 *   type: string,
 *   room_id: string,
 *   ack_id: string,
 *   message_id: string,
 *   client_message_id: ?string,
 *   server_time: string,
 *   sent_count: int,
 *   time: string
 * }
 */
function videochat_chat_ack_payload(
    string $roomId,
    array $message,
    int $sentCount,
    ?int $ackUnixMs = null
): array {
    $effectiveAckUnixMs = is_int($ackUnixMs) && $ackUnixMs > 0
        ? $ackUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveAckIso = gmdate('c', (int) floor($effectiveAckUnixMs / 1000));
    $messageId = trim((string) ($message['id'] ?? ''));

    return [
        'type' => 'chat/ack',
        'room_id' => videochat_presence_normalize_room_id($roomId),
        'ack_id' => videochat_chat_ack_id($messageId),
        'message_id' => $messageId,
        'client_message_id' => is_string($message['client_message_id'] ?? null)
            ? trim((string) $message['client_message_id'])
            : null,
        'server_time' => is_string($message['server_time'] ?? null) && trim((string) $message['server_time']) !== ''
            ? (string) $message['server_time']
            : $effectiveAckIso,
        'sent_count' => max(0, $sentCount),
        'time' => $effectiveAckIso,
    ];
}

function videochat_chat_deliver_payload(
    array $presenceState,
    string $roomId,
    array $event,
    ?callable $sender = null,
    ?callable $broker = null
): int {
    if ($broker !== null) {
        try {
            return $broker($roomId, $event) === true ? 1 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    return videochat_presence_broadcast_room_event(
        $presenceState,
        $roomId,
        $event,
        null,
        $sender
    );
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   message: string,
 *   attachments: array<int, string>,
 *   client_message_id: ?string,
 *   error: string
 * }
 */
function videochat_chat_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['chat/send', 'chat/message'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'unsupported_type',
        ];
    }

    $rawMessage = is_string($decoded['message'] ?? null) ? (string) $decoded['message'] : '';
    $message = trim($rawMessage);
    $attachments = videochat_chat_decode_attachment_refs($decoded['attachments'] ?? null);
    if (!(bool) ($attachments['ok'] ?? false)) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => (string) ($attachments['error'] ?? 'invalid_attachment_refs'),
        ];
    }

    $attachmentIds = is_array($attachments['attachments'] ?? null) ? $attachments['attachments'] : [];
    if ($message === '' && $attachmentIds === []) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'empty_message',
        ];
    }

    if (strlen($message) > videochat_chat_max_bytes()) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'chat_inline_too_large',
        ];
    }

    if (videochat_chat_message_length($message) > videochat_chat_max_chars()) {
        return [
            'ok' => false,
            'type' => $type,
            'message' => '',
            'attachments' => [],
            'client_message_id' => null,
            'error' => 'chat_inline_too_large',
        ];
    }

    $clientMessageId = null;
    if (is_string($decoded['client_message_id'] ?? null)) {
        $candidate = trim((string) $decoded['client_message_id']);
        if ($candidate !== '') {
            if (strlen($candidate) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $candidate) !== 1) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'message' => '',
                    'attachments' => [],
                    'client_message_id' => null,
                    'error' => 'invalid_client_message_id',
                ];
            }
            $clientMessageId = $candidate;
        }
    }

    return [
        'ok' => true,
        'type' => 'chat/send',
        'message' => $message,
        'attachments' => $attachmentIds,
        'client_message_id' => $clientMessageId,
        'error' => '',
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   event: array<string, mixed>|null,
 *   sent_count: int
 * }
 */
function videochat_chat_publish(
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null,
    ?int $nowUnixMs = null,
    ?callable $broker = null,
    ?callable $attachmentResolver = null
): array {
    if (!(bool) ($command['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'invalid_command',
            'event' => null,
            'sent_count' => 0,
        ];
    }

    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'event' => null,
            'sent_count' => 0,
        ];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    $roomConnections = $presenceState['rooms'][$roomId] ?? null;
    if (
        $connectionId === ''
        || !is_array($roomConnections)
        || !array_key_exists($connectionId, $roomConnections)
    ) {
        return [
            'ok' => false,
            'error' => 'sender_not_in_room',
            'event' => null,
            'sent_count' => 0,
        ];
    }

    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));
    $messageId = videochat_chat_resolve_message_id($connection, $command, $roomId, $effectiveNowMs);
    $attachmentRefs = is_array($command['attachments'] ?? null) ? $command['attachments'] : [];
    $attachmentPayloads = [];
    if ($attachmentRefs !== []) {
        if ($attachmentResolver === null) {
            return [
                'ok' => false,
                'error' => 'attachment_resolver_missing',
                'event' => null,
                'sent_count' => 0,
            ];
        }
        try {
            $resolvedAttachments = $attachmentResolver($attachmentRefs, $roomId, $senderUserId, $messageId, $connection);
        } catch (Throwable) {
            $resolvedAttachments = [
                'ok' => false,
                'error' => 'attachment_resolve_failed',
                'attachments' => [],
            ];
        }
        if (!(bool) ($resolvedAttachments['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($resolvedAttachments['error'] ?? 'attachment_resolve_failed'),
                'event' => null,
                'sent_count' => 0,
            ];
        }
        $attachmentPayloads = is_array($resolvedAttachments['attachments'] ?? null)
            ? array_values($resolvedAttachments['attachments'])
            : [];
    }

    $event = [
        'type' => 'chat/message',
        'room_id' => $roomId,
        'message' => [
            'id' => $messageId,
            'client_message_id' => $command['client_message_id'] ?? null,
            'text' => (string) ($command['message'] ?? ''),
            'attachments' => $attachmentPayloads,
            'sender' => [
                'user_id' => $senderUserId,
                'display_name' => (string) ($connection['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
            ],
            'server_unix_ms' => $effectiveNowMs,
            'server_time' => $effectiveNowIso,
        ],
        'time' => $effectiveNowIso,
    ];

    $sentCount = videochat_chat_deliver_payload($presenceState, $roomId, $event, $sender, $broker);

    if ($sentCount <= 0) {
        return [
            'ok' => false,
            'error' => 'delivery_failed',
            'event' => $event,
            'sent_count' => 0,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'event' => $event,
        'sent_count' => $sentCount,
    ];
}

function videochat_chat_broker_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_chat_broker_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_chat_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    sender_user_id INTEGER NOT NULL,
    event_json TEXT NOT NULL,
    created_at_ms INTEGER NOT NULL,
    UNIQUE(room_id, event_key)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_chat_events_room_id ON realtime_chat_events(room_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_chat_events_created_at ON realtime_chat_events(created_at_ms)');
}

function videochat_chat_broker_event_key(array $event): string
{
    $messageId = trim((string) (($event['message'] ?? [])['id'] ?? ''));
    if ($messageId !== '') {
        return 'message:' . $messageId;
    }

    return 'payload:' . hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($event));
}

function videochat_chat_broker_insert_event(PDO $pdo, string $roomId, array $event): bool
{
    $eventJson = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($eventJson) || $eventJson === '') {
        return false;
    }
    $message = is_array($event['message'] ?? null) ? $event['message'] : [];
    $sender = is_array($message['sender'] ?? null) ? $message['sender'] : [];

    $statement = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO realtime_chat_events(room_id, event_key, sender_user_id, event_json, created_at_ms)
VALUES(:room_id, :event_key, :sender_user_id, :event_json, :created_at_ms)
SQL
    );
    $statement->execute([
        ':room_id' => videochat_presence_normalize_room_id($roomId),
        ':event_key' => videochat_chat_broker_event_key($event),
        ':sender_user_id' => (int) ($sender['user_id'] ?? 0),
        ':event_json' => $eventJson,
        ':created_at_ms' => videochat_chat_broker_now_ms(),
    ]);

    return true;
}

function videochat_chat_broker_latest_event_id(PDO $pdo, string $roomId): int
{
    $statement = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM realtime_chat_events WHERE room_id = :room_id');
    $statement->execute([':room_id' => videochat_presence_normalize_room_id($roomId)]);
    return (int) ($statement->fetchColumn() ?: 0);
}

/**
 * @return array<int, array{id: int, event_json: string}>
 */
function videochat_chat_broker_fetch_events_since(
    PDO $pdo,
    string $roomId,
    int $afterId,
    int $limit = 50
): array {
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, event_json
FROM realtime_chat_events
WHERE room_id = :room_id
  AND id > :after_id
ORDER BY id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', videochat_presence_normalize_room_id($roomId), PDO::PARAM_STR);
    $statement->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $statement->execute();

    $events = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $events[] = [
            'id' => (int) ($row['id'] ?? 0),
            'event_json' => (string) ($row['event_json'] ?? ''),
        ];
    }

    return $events;
}

function videochat_chat_broker_cleanup(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM realtime_chat_events WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_chat_broker_now_ms() - 300_000]);
}

function videochat_chat_broker_poll(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    int &$lastEventId
): void {
    foreach (videochat_chat_broker_fetch_events_since($pdo, $roomId, $lastEventId) as $row) {
        $lastEventId = max($lastEventId, (int) ($row['id'] ?? 0));
        $payload = json_decode((string) ($row['event_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        videochat_presence_send_frame($websocket, $payload);
    }
}
