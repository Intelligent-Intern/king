<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_typing.php';

function videochat_realtime_typing_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-typing-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_typing_frames_by_type(array $frames, string $socket, string $type): array
{
    $socketFrames = $frames[$socket] ?? [];
    if (!is_array($socketFrames)) {
        return [];
    }

    return array_values(array_filter(
        $socketFrames,
        static fn (mixed $frame): bool => is_array($frame) && (string) ($frame['type'] ?? '') === $type
    ));
}

try {
    $presenceState = videochat_presence_state_init();
    $typingState = videochat_typing_state_init();
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
        [
            'id' => 1,
            'display_name' => 'Admin User',
            'role' => 'admin',
        ],
        'sess-admin',
        'conn-admin',
        'socket-admin',
        'lobby'
    );
    $adminJoin = videochat_presence_join_room($presenceState, $adminConnection, 'lobby', $sender);
    $adminConnection = (array) ($adminJoin['connection'] ?? $adminConnection);

    $userConnection = videochat_presence_connection_descriptor(
        [
            'id' => 2,
            'display_name' => 'Call User',
            'role' => 'user',
        ],
        'sess-user',
        'conn-user',
        'socket-user',
        'lobby'
    );
    $userJoin = videochat_presence_join_room($presenceState, $userConnection, 'lobby', $sender);
    $userConnection = (array) ($userJoin['connection'] ?? $userConnection);

    $otherConnection = videochat_presence_connection_descriptor(
        [
            'id' => 3,
            'display_name' => 'Other User',
            'role' => 'user',
        ],
        'sess-other',
        'conn-other',
        'socket-other',
        'other-room'
    );
    $otherJoin = videochat_presence_join_room($presenceState, $otherConnection, 'other-room', $sender);
    $otherConnection = (array) ($otherJoin['connection'] ?? $otherConnection);

    $frames = [];

    $startCommand = videochat_typing_decode_client_frame(json_encode(['type' => 'typing/start'], JSON_UNESCAPED_SLASHES));
    videochat_realtime_typing_assert((bool) ($startCommand['ok'] ?? false), 'typing/start command should decode');

    $firstStart = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $startCommand,
        $sender,
        1_780_200_000
    );
    videochat_realtime_typing_assert((bool) ($firstStart['ok'] ?? false), 'typing/start should apply');
    videochat_realtime_typing_assert((bool) ($firstStart['emitted'] ?? false), 'first typing/start should emit');
    videochat_realtime_typing_assert((string) ($firstStart['event_type'] ?? '') === 'typing/start', 'first typing/start event type mismatch');
    videochat_realtime_typing_assert((int) ($firstStart['sent_count'] ?? 0) === 1, 'first typing/start should fanout to one lobby peer');

    $adminStartFrames = videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/start');
    $userStartFrames = videochat_realtime_typing_frames_by_type($frames, 'socket-user', 'typing/start');
    $otherStartFrames = videochat_realtime_typing_frames_by_type($frames, 'socket-other', 'typing/start');
    videochat_realtime_typing_assert(count($adminStartFrames) === 1, 'admin should receive one typing/start');
    videochat_realtime_typing_assert(count($userStartFrames) === 0, 'sender must not receive self-echo typing/start');
    videochat_realtime_typing_assert(count($otherStartFrames) === 0, 'other room must not receive typing/start');

    $secondStartDebounced = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $startCommand,
        $sender,
        1_780_200_200
    );
    videochat_realtime_typing_assert((bool) ($secondStartDebounced['ok'] ?? false), 'second debounced typing/start should apply');
    videochat_realtime_typing_assert(!(bool) ($secondStartDebounced['emitted'] ?? true), 'second typing/start inside debounce should not emit');
    videochat_realtime_typing_assert(count(videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/start')) === 1, 'debounced typing/start should not add admin event');

    $thirdStartAfterDebounce = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $startCommand,
        $sender,
        1_780_200_700
    );
    videochat_realtime_typing_assert((bool) ($thirdStartAfterDebounce['emitted'] ?? false), 'typing/start after debounce should emit');
    videochat_realtime_typing_assert(count(videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/start')) === 2, 'typing/start after debounce should add admin event');

    $sweepBeforeExpiry = videochat_typing_sweep_expired(
        $typingState,
        $presenceState,
        $sender,
        1_780_201_000
    );
    videochat_realtime_typing_assert($sweepBeforeExpiry === 0, 'sweep before expiry should not emit stop');

    $stopCommand = videochat_typing_decode_client_frame(json_encode(['type' => 'typing/stop'], JSON_UNESCAPED_SLASHES));
    videochat_realtime_typing_assert((bool) ($stopCommand['ok'] ?? false), 'typing/stop command should decode');

    $explicitStop = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $stopCommand,
        $sender,
        1_780_201_200
    );
    videochat_realtime_typing_assert((bool) ($explicitStop['emitted'] ?? false), 'explicit typing/stop should emit');
    videochat_realtime_typing_assert((string) ($explicitStop['event_type'] ?? '') === 'typing/stop', 'explicit typing/stop event type mismatch');
    $adminStopFrames = videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/stop');
    videochat_realtime_typing_assert(count($adminStopFrames) === 1, 'admin should receive one typing/stop after explicit stop');
    videochat_realtime_typing_assert((string) ($adminStopFrames[0]['reason'] ?? '') === 'explicit_stop', 'explicit typing/stop reason mismatch');

    $startForExpiry = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $startCommand,
        $sender,
        1_780_202_000
    );
    videochat_realtime_typing_assert((bool) ($startForExpiry['emitted'] ?? false), 'typing/start for expiry test should emit');

    $expiredSweep = videochat_typing_sweep_expired(
        $typingState,
        $presenceState,
        $sender,
        1_780_206_000
    );
    videochat_realtime_typing_assert($expiredSweep === 1, 'expiry sweep should emit one typing/stop to lobby peer');
    $adminStopFramesAfterExpiry = videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/stop');
    $lastStopFrame = $adminStopFramesAfterExpiry[count($adminStopFramesAfterExpiry) - 1] ?? [];
    videochat_realtime_typing_assert((string) ($lastStopFrame['reason'] ?? '') === 'expired', 'expiry typing/stop reason mismatch');

    $clearWithoutEntry = videochat_typing_clear_for_connection(
        $typingState,
        $presenceState,
        $userConnection,
        'room_change',
        $sender,
        1_780_206_200
    );
    videochat_realtime_typing_assert(!(bool) ($clearWithoutEntry['cleared'] ?? true), 'clear without active entry should not report cleared');

    $startForRoomChange = videochat_typing_apply_command(
        $typingState,
        $presenceState,
        $userConnection,
        $startCommand,
        $sender,
        1_780_206_500
    );
    videochat_realtime_typing_assert((bool) ($startForRoomChange['emitted'] ?? false), 'typing/start for room change should emit');
    $roomChangeClear = videochat_typing_clear_for_connection(
        $typingState,
        $presenceState,
        $userConnection,
        'room_change',
        $sender,
        1_780_206_800
    );
    videochat_realtime_typing_assert((bool) ($roomChangeClear['cleared'] ?? false), 'room change clear should remove active typing entry');
    $adminStopFramesAfterRoomChange = videochat_realtime_typing_frames_by_type($frames, 'socket-admin', 'typing/stop');
    $lastRoomChangeStop = $adminStopFramesAfterRoomChange[count($adminStopFramesAfterRoomChange) - 1] ?? [];
    videochat_realtime_typing_assert((string) ($lastRoomChangeStop['reason'] ?? '') === 'room_change', 'room change typing/stop reason mismatch');

    $invalidTypeCommand = videochat_typing_decode_client_frame(json_encode(['type' => 'chat/send'], JSON_UNESCAPED_SLASHES));
    videochat_realtime_typing_assert(!(bool) ($invalidTypeCommand['ok'] ?? true), 'unsupported typing command should fail');
    videochat_realtime_typing_assert((string) ($invalidTypeCommand['error'] ?? '') === 'unsupported_type', 'unsupported typing command error mismatch');

    $invalidJsonCommand = videochat_typing_decode_client_frame('{invalid json');
    videochat_realtime_typing_assert(!(bool) ($invalidJsonCommand['ok'] ?? true), 'invalid json typing command should fail');
    videochat_realtime_typing_assert((string) ($invalidJsonCommand['error'] ?? '') === 'invalid_json', 'invalid json typing command error mismatch');

    videochat_presence_remove_connection($presenceState, (string) ($adminConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($userConnection['connection_id'] ?? ''), $sender);
    videochat_presence_remove_connection($presenceState, (string) ($otherConnection['connection_id'] ?? ''), $sender);

    fwrite(STDOUT, "[realtime-typing-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-typing-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
