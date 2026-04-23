<?php

declare(strict_types=1);

const VIDEOCHAT_GOSSIPMESH_CONTRACT = 'king-video-chat-gossipmesh-runtime';
const VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT = 'king-video-chat-protected-media-transport-envelope';
const VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH = 'wlvc_sfu';
const VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS = 8;
const VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS = 4;
const VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT = 2;
const VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE = 256;
const VIDEOCHAT_GOSSIPMESH_MAX_RELAY_CANDIDATES = 10;

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_forbidden_payload_fields(): array
{
    return [
        'raw_media_key',
        'private_key',
        'shared_secret',
        'plaintext_frame',
        'decoded_audio',
        'decoded_video',
        'websocket',
        'socket',
        'ip',
        'port',
        'sdp',
        'ice_candidate',
        'iceServers',
    ];
}

function videochat_gossipmesh_clamp_int(mixed $value, int $default, int $min, int $max): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
        return $default;
    }

    return max($min, min($max, (int) $value));
}

function videochat_gossipmesh_safe_id(mixed $value): string
{
    $id = trim((string) $value);
    if ($id === '' || strlen($id) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
        return '';
    }

    return $id;
}

function videochat_gossipmesh_is_admitted_member(array $member): bool
{
    if (($member['admitted'] ?? false) === true) {
        return true;
    }

    $state = strtolower(trim((string) ($member['invite_state'] ?? $member['state'] ?? '')));
    if (in_array($state, ['allowed', 'moderator', 'owner', 'admin', 'joined'], true)) {
        return true;
    }

    return trim((string) ($member['joined_at'] ?? '')) !== '' && !in_array($state, ['pending', 'queued', 'invited'], true);
}

/**
 * @return array{id: string, user_id: string, display_name: string, relay_score: int, activity_score: int}|null
 */
function videochat_gossipmesh_normalize_member(array $member): ?array
{
    foreach (videochat_gossipmesh_forbidden_payload_fields() as $field) {
        if (array_key_exists($field, $member)) {
            return null;
        }
    }

    if (!videochat_gossipmesh_is_admitted_member($member)) {
        return null;
    }

    $id = videochat_gossipmesh_safe_id(
        $member['participant_id'] ?? $member['publisher_id'] ?? $member['peer_id'] ?? $member['user_id'] ?? ''
    );
    if ($id === '') {
        return null;
    }

    $displayName = trim((string) ($member['display_name'] ?? $member['name'] ?? $id));
    $displayName = function_exists('mb_substr') ? mb_substr($displayName, 0, 160) : substr($displayName, 0, 160);

    return [
        'id' => $id,
        'user_id' => videochat_gossipmesh_safe_id($member['user_id'] ?? $id),
        'display_name' => $displayName,
        'relay_score' => videochat_gossipmesh_clamp_int($member['relay_score'] ?? $member['bandwidth_score'] ?? 0, 0, 0, 100),
        'activity_score' => videochat_gossipmesh_clamp_int($member['activity_score'] ?? 0, 0, 0, 100),
    ];
}

function videochat_gossipmesh_estimate_ttl(int $memberCount): int
{
    if ($memberCount <= 1) {
        return 0;
    }
    if ($memberCount <= 10) {
        return 3;
    }
    if ($memberCount <= 50) {
        return 4;
    }
    if ($memberCount <= 100) {
        return 5;
    }
    if ($memberCount <= 500) {
        return 6;
    }

    return 7;
}

/**
 * @param array<int, array<string, mixed>> $members
 * @return array{
 *   contract: string,
 *   authority: string,
 *   runtime_path: string,
 *   envelope_contract: string,
 *   call_id: string,
 *   room_id: string,
 *   ttl: int,
 *   forward_count: int,
 *   members: array<int, array{id: string, user_id: string, display_name: string, relay_score: int, activity_score: int}>,
 *   topology: array<string, array<int, string>>,
 *   relay_candidates: array<int, string>,
 *   rejected_members: int
 * }
 */
function videochat_gossipmesh_plan_topology(string $callId, string $roomId, array $members, array $options = []): array
{
    $callId = videochat_gossipmesh_safe_id($callId);
    $roomId = videochat_gossipmesh_safe_id($roomId);
    if ($callId === '' || $roomId === '') {
        throw new InvalidArgumentException('call_id and room_id are required for GossipMesh topology planning');
    }

    $normalizedById = [];
    $rejected = 0;
    foreach ($members as $member) {
        if (!is_array($member)) {
            $rejected++;
            continue;
        }
        $normalized = videochat_gossipmesh_normalize_member($member);
        if ($normalized === null) {
            $rejected++;
            continue;
        }
        $normalizedById[$normalized['id']] = $normalized;
    }

    $normalizedMembers = array_values($normalizedById);
    $seed = (string) ($options['seed'] ?? 'default');
    usort($normalizedMembers, static function (array $left, array $right) use ($callId, $roomId, $seed): int {
        $leftHash = hash('sha256', $callId . '|' . $roomId . '|' . $seed . '|' . $left['id']);
        $rightHash = hash('sha256', $callId . '|' . $roomId . '|' . $seed . '|' . $right['id']);
        return $leftHash <=> $rightHash;
    });

    $memberCount = count($normalizedMembers);
    $neighborLimit = videochat_gossipmesh_clamp_int(
        $options['max_neighbors'] ?? VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
        1,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );
    $forwardCount = videochat_gossipmesh_clamp_int(
        $options['forward_count'] ?? VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        1,
        VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS
    );

    $topology = [];
    if ($memberCount > 1) {
        $perMemberNeighbors = min($neighborLimit, $memberCount - 1);
        foreach ($normalizedMembers as $index => $member) {
            $neighbors = [];
            for ($offset = 1; $offset <= $perMemberNeighbors; $offset++) {
                $neighbors[] = $normalizedMembers[($index + $offset) % $memberCount]['id'];
            }
            $topology[$member['id']] = $neighbors;
        }
    } else {
        foreach ($normalizedMembers as $member) {
            $topology[$member['id']] = [];
        }
    }

    $relayMembers = $normalizedMembers;
    usort($relayMembers, static function (array $left, array $right): int {
        $score = $right['relay_score'] <=> $left['relay_score'];
        return $score !== 0 ? $score : ($left['id'] <=> $right['id']);
    });
    $relayCandidates = array_slice(
        array_values(array_map(static fn(array $member): string => $member['id'], $relayMembers)),
        0,
        VIDEOCHAT_GOSSIPMESH_MAX_RELAY_CANDIDATES
    );

    return [
        'contract' => VIDEOCHAT_GOSSIPMESH_CONTRACT,
        'authority' => 'server',
        'runtime_path' => VIDEOCHAT_GOSSIPMESH_RUNTIME_PATH,
        'envelope_contract' => VIDEOCHAT_GOSSIPMESH_ENVELOPE_CONTRACT,
        'call_id' => $callId,
        'room_id' => $roomId,
        'ttl' => videochat_gossipmesh_estimate_ttl($memberCount),
        'forward_count' => min($forwardCount, max(1, $neighborLimit)),
        'members' => $normalizedMembers,
        'topology' => $topology,
        'relay_candidates' => $relayCandidates,
        'rejected_members' => $rejected,
    ];
}

/**
 * @param array<int, string> $seenWindow
 * @return array{accepted: bool, duplicate: bool, seen_window: array<int, string>}
 */
function videochat_gossipmesh_accept_frame_once(array $seenWindow, string $publisherId, int $sequence, int $maxSize = VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE): array
{
    $publisherId = videochat_gossipmesh_safe_id($publisherId);
    $maxSize = videochat_gossipmesh_clamp_int($maxSize, VIDEOCHAT_GOSSIPMESH_SEEN_WINDOW_SIZE, 1, 4096);
    if ($publisherId === '' || $sequence < 0) {
        return ['accepted' => false, 'duplicate' => false, 'seen_window' => array_values($seenWindow)];
    }

    $key = $publisherId . ':' . $sequence;
    $window = array_values(array_filter($seenWindow, static fn(mixed $value): bool => is_string($value) && $value !== ''));
    if (in_array($key, $window, true)) {
        return ['accepted' => false, 'duplicate' => true, 'seen_window' => $window];
    }

    $window[] = $key;
    if (count($window) > $maxSize) {
        $window = array_slice($window, -$maxSize);
    }

    return ['accepted' => true, 'duplicate' => false, 'seen_window' => $window];
}

/**
 * @param array<int, string> $neighbors
 * @return array<int, string>
 */
function videochat_gossipmesh_select_forward_targets(array $neighbors, string $publisherId, int $sequence, int $ttl, int $forwardCount = VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT): array
{
    if ($ttl <= 0) {
        return [];
    }

    $publisherId = videochat_gossipmesh_safe_id($publisherId);
    if ($publisherId === '') {
        return [];
    }

    $forwardCount = videochat_gossipmesh_clamp_int($forwardCount, VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT, 1, VIDEOCHAT_GOSSIPMESH_MAX_NEIGHBORS);
    $safeNeighbors = [];
    foreach ($neighbors as $neighbor) {
        $neighborId = videochat_gossipmesh_safe_id($neighbor);
        if ($neighborId !== '' && $neighborId !== $publisherId) {
            $safeNeighbors[$neighborId] = $neighborId;
        }
    }

    $safeNeighbors = array_values($safeNeighbors);
    usort($safeNeighbors, static function (string $left, string $right) use ($publisherId, $sequence): int {
        $leftHash = hash('sha256', $publisherId . '|' . $sequence . '|' . $left);
        $rightHash = hash('sha256', $publisherId . '|' . $sequence . '|' . $right);
        return $leftHash <=> $rightHash;
    });

    return array_slice($safeNeighbors, 0, min($forwardCount, count($safeNeighbors)));
}
