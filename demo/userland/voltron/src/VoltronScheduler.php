<?php
declare(strict_types=1);

namespace King\Voltron;

final class VoltronScheduler
{
    /**
     * @param array<int,string> $preferredPeerIds
     * @return array{peers:array<int,string>,source:string}
     */
    public static function discoverPeers(array $preferredPeerIds = []): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn($id) => is_string($id) ? trim($id) : '', $preferredPeerIds),
            static fn(string $id): bool => $id !== ''
        )));
        if ($normalized !== []) {
            return ['peers' => $normalized, 'source' => 'caller'];
        }

        if (function_exists('king_semantic_dns_discover_service')) {
            try {
                $result = king_semantic_dns_discover_service('voltron_peer');
                $services = $result['services'] ?? [];
                if (is_array($services) && $services !== []) {
                    $peerIds = [];
                    foreach ($services as $service) {
                        if (!is_array($service)) {
                            continue;
                        }
                        $id = $service['service_id'] ?? null;
                        if (is_string($id) && $id !== '') {
                            $peerIds[] = $id;
                        }
                    }
                    $peerIds = array_values(array_unique($peerIds));
                    if ($peerIds !== []) {
                        sort($peerIds);
                        return ['peers' => $peerIds, 'source' => 'semantic_dns'];
                    }
                }
            } catch (\Throwable) {
                // Fail closed to deterministic static peers.
            }
        }

        return ['peers' => ['peer-a', 'peer-b'], 'source' => 'fallback'];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,string> $peerIds
     * @return array<string,string> step_id => peer_id
     */
    public static function assignSteps(array $steps, array $peerIds): array
    {
        $peerIds = array_values(array_unique(array_filter($peerIds, static fn($id) => is_string($id) && $id !== '')));
        if ($peerIds === []) {
            $peerIds = ['peer-a'];
        }

        $modelStepIds = [];
        foreach ($steps as $step) {
            $stepId = $step['id'] ?? null;
            if (is_string($stepId) && str_starts_with($stepId, 'voltron.execute_block.')) {
                $modelStepIds[] = $stepId;
            }
        }

        $owners = [];
        if (count($peerIds) === 1 || count($modelStepIds) <= 1) {
            foreach ($modelStepIds as $stepId) {
                $owners[$stepId] = $peerIds[0];
            }
        } else {
            $peerCount = count($peerIds);
            $stepCount = count($modelStepIds);
            $cursor = 0;
            for ($i = 0; $i < $peerCount; $i++) {
                $remainingSteps = $stepCount - $cursor;
                $remainingPeers = $peerCount - $i;
                $chunkSize = (int) ceil($remainingSteps / $remainingPeers);
                for ($j = 0; $j < $chunkSize && $cursor < $stepCount; $j++, $cursor++) {
                    $owners[$modelStepIds[$cursor]] = $peerIds[$i];
                }
            }
        }

        $defaultPeer = $peerIds[0];
        foreach ($steps as $step) {
            $stepId = $step['id'] ?? null;
            if (!is_string($stepId) || $stepId === '') {
                continue;
            }
            if (!isset($owners[$stepId])) {
                $owners[$stepId] = $defaultPeer;
            }
        }

        return $owners;
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<int,string> $preferredPeerIds
     * @return array{
     *   peers:array<int,string>,
     *   discovery_source:string,
     *   step_owners:array<string,string>,
     *   generated_at_ms:int
     * }
     */
    public static function buildSchedule(array $steps, array $preferredPeerIds = []): array
    {
        $discovery = self::discoverPeers($preferredPeerIds);
        $peers = $discovery['peers'];
        $owners = self::assignSteps($steps, $peers);

        return [
            'peers' => $peers,
            'discovery_source' => $discovery['source'],
            'step_owners' => $owners,
            'generated_at_ms' => (int) floor(microtime(true) * 1000),
        ];
    }
}
