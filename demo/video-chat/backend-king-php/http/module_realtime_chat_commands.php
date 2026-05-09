<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/operator_feedback.php';
require_once __DIR__ . '/../domain/realtime/chat_archive.php';
require_once __DIR__ . '/../domain/realtime/realtime_chat.php';

function videochat_realtime_handle_chat_websocket_command(
    array $chatCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    ?PDO $chatBrokerDatabase,
    callable $openDatabase
): ?array {
    if (!(bool) ($chatCommand['ok'] ?? false)) {
        if ((string) ($chatCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => (string) ($chatCommand['error'] ?? 'chat_invalid_payload'),
                'message' => 'Chat message payload is invalid.',
                'details' => [
                    'type' => 'chat/send',
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    $chatBroker = $chatBrokerDatabase instanceof PDO
        ? static function (string $roomId, array $event) use ($chatBrokerDatabase): bool {
            return videochat_chat_broker_insert_event($chatBrokerDatabase, $roomId, $event);
        }
        : null;
    $chatPublish = videochat_chat_publish(
        $presenceState,
        $presenceConnection,
        $chatCommand,
        null,
        null,
        $chatBroker,
        static function (array $attachmentIds, string $roomId, int $senderUserId, string $messageId, array $resolverConnection) use ($openDatabase): array {
            $callId = videochat_realtime_connection_call_id($resolverConnection);
            if ($callId === '') {
                return [
                    'ok' => false,
                    'error' => 'attachment_call_missing',
                    'attachments' => [],
                ];
            }

            return videochat_chat_attachment_resolve_for_message(
                $openDatabase(),
                $attachmentIds,
                $callId,
                $roomId,
                $senderUserId,
                $messageId
            );
        }
    );
    if (!(bool) ($chatPublish['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'chat_publish_failed',
                'message' => 'Could not publish chat message.',
                'details' => [
                    'error' => (string) ($chatPublish['error'] ?? 'unknown'),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    $message = is_array($chatPublish['event']['message'] ?? null) ? $chatPublish['event']['message'] : [];
    $chatRoomId = (string) ($chatPublish['event']['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'));
    $chatArchiveCallId = videochat_realtime_connection_call_id($presenceConnection);
    if ($chatArchiveCallId !== '') {
        try {
            $archiveResult = videochat_chat_archive_append_message(
                $openDatabase(),
                $chatArchiveCallId,
                $chatRoomId,
                is_array($chatPublish['event'] ?? null) ? $chatPublish['event'] : []
            );
        } catch (Throwable) {
            $archiveResult = [
                'ok' => false,
                'reason' => 'archive_exception',
            ];
        }

        if (!(bool) ($archiveResult['ok'] ?? false)) {
            videochat_presence_send_frame(
                $websocket,
                [
                    'type' => 'system/error',
                    'code' => 'chat_archive_failed',
                    'message' => 'Chat message was sent but could not be archived.',
                    'details' => [
                        'error' => (string) ($archiveResult['reason'] ?? 'unknown'),
                        'room_id' => $chatRoomId,
                    ],
                    'time' => gmdate('c'),
                ]
            );
        }

        videochat_realtime_store_operator_feedback($websocket, $openDatabase, $presenceConnection, $chatPublish, $chatCommand, $chatRoomId);
    }

    videochat_presence_send_frame(
        $websocket,
        videochat_chat_ack_payload($chatRoomId, $message, (int) ($chatPublish['sent_count'] ?? 0))
    );
    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_store_operator_feedback(
    mixed $websocket,
    callable $openDatabase,
    array $presenceConnection,
    array $chatPublish,
    array $chatCommand,
    string $chatRoomId
): void {
    if (!videochat_operator_feedback_requested_from_payload($chatCommand)) {
        return;
    }

    try {
        $feedbackResult = videochat_operator_feedback_persist_from_chat_event(
            $openDatabase(),
            $presenceConnection,
            is_array($chatPublish['event'] ?? null) ? $chatPublish['event'] : [],
            $chatCommand
        );
    } catch (Throwable) {
        $feedbackResult = [
            'ok' => false,
            'reason' => 'operator_feedback_exception',
            'feedback' => null,
        ];
    }

    if (!(bool) ($feedbackResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'operator_feedback_store_failed',
                'message' => 'Chat message was sent but operator feedback could not be stored.',
                'details' => [
                    'error' => (string) ($feedbackResult['reason'] ?? 'unknown'),
                    'room_id' => $chatRoomId,
                    'fields' => is_array($feedbackResult['errors'] ?? null) ? $feedbackResult['errors'] : [],
                ],
                'time' => gmdate('c'),
            ]
        );
        return;
    }

    $feedback = is_array($feedbackResult['feedback'] ?? null) ? $feedbackResult['feedback'] : [];
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'operator-feedback/ack',
            'room_id' => $chatRoomId,
            'feedback_id' => (string) ($feedback['id'] ?? ''),
            'message_id' => (string) ($feedback['chat_message_id'] ?? ''),
            'status' => (string) ($feedback['status'] ?? 'open'),
            'time' => gmdate('c'),
        ]
    );
}
