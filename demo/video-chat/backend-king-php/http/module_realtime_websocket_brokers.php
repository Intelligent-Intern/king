<?php

declare(strict_types=1);

function videochat_realtime_poll_websocket_brokers(
    int $pollNowMs,
    mixed $websocket,
    array $presenceConnection,
    ?PDO $reactionBrokerDatabase,
    ?PDO $chatBrokerDatabase,
    ?PDO $signalingBrokerDatabase,
    string &$lastReactionBrokerRoomId,
    int &$lastReactionBrokerEventId,
    int &$nextReactionBrokerPollMs,
    int &$nextReactionBrokerCleanupMs,
    string &$lastChatBrokerRoomId,
    int &$lastChatBrokerEventId,
    int &$nextChatBrokerPollMs,
    int &$nextChatBrokerCleanupMs,
    string &$lastSignalingBrokerRoomId,
    int &$lastSignalingBrokerUserId,
    int &$lastSignalingBrokerEventId,
    int &$nextSignalingBrokerPollMs,
    int &$nextSignalingBrokerCleanupMs,
    int $signalingBrokerAttachedAtMs
): void {
    if ($reactionBrokerDatabase instanceof PDO && $pollNowMs >= $nextReactionBrokerPollMs) {
        try {
            $currentReactionRoomId = videochat_presence_normalize_room_id(
                (string) ($presenceConnection['room_id'] ?? 'lobby')
            );
            if ($currentReactionRoomId !== $lastReactionBrokerRoomId) {
                $lastReactionBrokerRoomId = $currentReactionRoomId;
                $lastReactionBrokerEventId = videochat_reaction_broker_latest_event_id(
                    $reactionBrokerDatabase,
                    $lastReactionBrokerRoomId
                );
            }
            videochat_reaction_broker_poll(
                $reactionBrokerDatabase,
                $websocket,
                $lastReactionBrokerRoomId,
                (int) ($presenceConnection['user_id'] ?? 0),
                $lastReactionBrokerEventId
            );
            if ($pollNowMs >= $nextReactionBrokerCleanupMs) {
                videochat_reaction_broker_cleanup($reactionBrokerDatabase);
                $nextReactionBrokerCleanupMs = $pollNowMs + 5000;
            }
        } catch (Throwable) {
            // Keep the websocket alive; direct local reaction broadcast remains the fallback.
        }
        $nextReactionBrokerPollMs = $pollNowMs + 100;
    }

    if ($chatBrokerDatabase instanceof PDO && $pollNowMs >= $nextChatBrokerPollMs) {
        try {
            $currentChatRoomId = videochat_presence_normalize_room_id(
                (string) ($presenceConnection['room_id'] ?? 'lobby')
            );
            if ($currentChatRoomId !== $lastChatBrokerRoomId) {
                $lastChatBrokerRoomId = $currentChatRoomId;
                $lastChatBrokerEventId = videochat_chat_broker_latest_event_id($chatBrokerDatabase, $lastChatBrokerRoomId);
            }
            videochat_chat_broker_poll($chatBrokerDatabase, $websocket, $lastChatBrokerRoomId, $lastChatBrokerEventId);
            if ($pollNowMs >= $nextChatBrokerCleanupMs) {
                videochat_chat_broker_cleanup($chatBrokerDatabase);
                $nextChatBrokerCleanupMs = $pollNowMs + 5000;
            }
        } catch (Throwable) {
            // Keep the websocket alive; direct local chat broadcast remains the fallback.
        }
        $nextChatBrokerPollMs = $pollNowMs + 100;
    }

    if ($signalingBrokerDatabase instanceof PDO && $pollNowMs >= $nextSignalingBrokerPollMs) {
        try {
            $currentSignalingRoomId = videochat_presence_normalize_room_id(
                (string) ($presenceConnection['room_id'] ?? 'lobby')
            );
            $currentSignalingUserId = (int) ($presenceConnection['user_id'] ?? 0);
            if (
                $currentSignalingRoomId !== $lastSignalingBrokerRoomId
                || $currentSignalingUserId !== $lastSignalingBrokerUserId
            ) {
                $lastSignalingBrokerRoomId = $currentSignalingRoomId;
                $lastSignalingBrokerUserId = $currentSignalingUserId;
                $lastSignalingBrokerEventId = videochat_signaling_broker_latest_event_id_before(
                    $signalingBrokerDatabase,
                    $lastSignalingBrokerRoomId,
                    $lastSignalingBrokerUserId,
                    $signalingBrokerAttachedAtMs
                );
            }
            videochat_signaling_broker_poll(
                $signalingBrokerDatabase,
                $websocket,
                $lastSignalingBrokerRoomId,
                $lastSignalingBrokerUserId,
                $lastSignalingBrokerEventId
            );
            if ($pollNowMs >= $nextSignalingBrokerCleanupMs) {
                videochat_signaling_broker_cleanup($signalingBrokerDatabase);
                $nextSignalingBrokerCleanupMs = $pollNowMs + 5000;
            }
        } catch (Throwable) {
            // Keep the websocket alive; direct local signaling remains the fallback.
        }
        $nextSignalingBrokerPollMs = $pollNowMs + 100;
    }
}
