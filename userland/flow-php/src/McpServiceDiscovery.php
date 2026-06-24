<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use RuntimeException;

interface ServiceDirectory
{
    /**
     * @param array<string,mixed>|null $criteria
     * @return array{
     *   services:list<array<string,mixed>>,
     *   service_type:string,
     *   discovered_at:int,
     *   service_count:int
     * }
     */
    public function discover(string $serviceType, ?array $criteria = null): array;

    /**
     * @param array<string,mixed>|null $clientInfo
     * @return array<string,mixed>
     */
    public function route(string $serviceName, ?array $clientInfo = null): array;
}

final class SemanticDnsServiceDirectory implements ServiceDirectory
{
    /**
     * @param array<string,mixed>|null $criteria
     * @return array{
     *   services:list<array<string,mixed>>,
     *   service_type:string,
     *   discovered_at:int,
     *   service_count:int
     * }
     */
    public function discover(string $serviceType, ?array $criteria = null): array
    {
        return \king_semantic_dns_discover_service($serviceType, $criteria);
    }

    /**
     * @param array<string,mixed>|null $clientInfo
     * @return array<string,mixed>
     */
    public function route(string $serviceName, ?array $clientInfo = null): array
    {
        return \king_semantic_dns_get_optimal_route($serviceName, $clientInfo);
    }
}

final class McpServiceNode
{
    /** @var array<string,mixed> */
    private array $attributes;

    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(
        private string $serviceId,
        private string $serviceName,
        private string $serviceType,
        private string $hostname,
        private int $port,
        private string $status = 'unknown',
        array $attributes = []
    ) {
        if ($this->serviceId === '') {
            throw new InvalidArgumentException('serviceId must not be empty.');
        }
        if ($this->serviceName === '') {
            throw new InvalidArgumentException('serviceName must not be empty.');
        }
        if ($this->serviceType === '') {
            throw new InvalidArgumentException('serviceType must not be empty.');
        }
        if ($this->hostname === '') {
            throw new InvalidArgumentException('hostname must not be empty.');
        }
        if ($this->port <= 0 || $this->port > 65535) {
            throw new InvalidArgumentException('port must be between 1 and 65535.');
        }

        $this->attributes = $attributes;
    }

    public function serviceId(): string
    {
        return $this->serviceId;
    }

    public function serviceName(): string
    {
        return $this->serviceName;
    }

    public function serviceType(): string
    {
        return $this->serviceType;
    }

    public function hostname(): string
    {
        return $this->hostname;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return array<string,mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function endpoint(): string
    {
        return $this->hostname . ':' . $this->port;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'service_id' => $this->serviceId,
            'service_name' => $this->serviceName,
            'service_type' => $this->serviceType,
            'hostname' => $this->hostname,
            'port' => $this->port,
            'status' => $this->status,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $serviceId = is_string($row['service_id'] ?? null) ? trim($row['service_id']) : '';
        $serviceName = is_string($row['service_name'] ?? null) ? trim($row['service_name']) : '';
        $serviceType = is_string($row['service_type'] ?? null) ? trim($row['service_type']) : '';
        $hostname = is_string($row['hostname'] ?? null) ? trim($row['hostname']) : '';
        $portRaw = $row['port'] ?? 0;
        $status = is_string($row['status'] ?? null) ? trim($row['status']) : 'unknown';
        $attributes = is_array($row['attributes'] ?? null) ? $row['attributes'] : [];
        foreach (['current_load_percent', 'active_connections', 'total_requests'] as $metricKey) {
            if (isset($row[$metricKey]) && (is_int($row[$metricKey]) || is_float($row[$metricKey]) || is_string($row[$metricKey]))) {
                $attributes[$metricKey] = (int) $row[$metricKey];
            }
        }

        if (!is_int($portRaw) && !is_float($portRaw) && !is_string($portRaw)) {
            throw new InvalidArgumentException('service row port must be numeric.');
        }

        return new self(
            $serviceId,
            $serviceName,
            $serviceType,
            $hostname,
            (int) $portRaw,
            $status === '' ? 'unknown' : $status,
            $attributes
        );
    }
}

final class McpServiceResolution
{
    /** @var list<McpServiceNode> */
    private array $orderedCandidates;

    private int $activeIndex = 0;

    /**
     * @param list<McpServiceNode> $orderedCandidates
     */
    public function __construct(
        private string $role,
        array $orderedCandidates
    ) {
        if ($this->role === '') {
            throw new InvalidArgumentException('role must not be empty.');
        }

        if ($orderedCandidates === []) {
            throw new InvalidArgumentException('orderedCandidates must not be empty.');
        }

        $this->orderedCandidates = array_values($orderedCandidates);
    }

    public function role(): string
    {
        return $this->role;
    }

    public function current(): McpServiceNode
    {
        return $this->orderedCandidates[$this->activeIndex];
    }

    public function hasFailoverTarget(): bool
    {
        return ($this->activeIndex + 1) < count($this->orderedCandidates);
    }

    public function failover(string $failedServiceId): McpServiceNode
    {
        while (($this->activeIndex + 1) < count($this->orderedCandidates)) {
            $this->activeIndex++;
            $candidate = $this->orderedCandidates[$this->activeIndex];
            if ($candidate->serviceId() !== $failedServiceId) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'mcp service resolution has no remaining failover target for role "' . $this->role . '".'
        );
    }

    /**
     * @return list<string>
     */
    public function orderedServiceIds(): array
    {
        return array_map(
            static fn (McpServiceNode $candidate): string => $candidate->serviceId(),
            $this->orderedCandidates
        );
    }

    /**
     * @return list<McpServiceNode>
     */
    public function candidates(): array
    {
        return $this->orderedCandidates;
    }
}

final class McpServiceDiscovery
{
    /** @var array<string,array{service_name:string,service_type:string}> */
    private array $roleMap;

    /**
     * @param array<string,array{service_name:string,service_type:string}> $roleMap
     */
    public function __construct(
        private ServiceDirectory $directory,
        array $roleMap = []
    ) {
        $this->roleMap = $roleMap !== [] ? $roleMap : [
            'retrieval' => ['service_name' => 'rag-retrieval', 'service_type' => 'rag_retrieval'],
            'embedding' => ['service_name' => 'rag-embedding', 'service_type' => 'rag_embedding'],
            'document' => ['service_name' => 'rag-document', 'service_type' => 'rag_document'],
        ];
    }

    /**
     * @param array<string,mixed>|null $clientInfo
     */
    public function resolve(string $role, ?array $clientInfo = null): McpServiceResolution
    {
        $mapping = $this->roleMap[$role] ?? null;
        if (!is_array($mapping)) {
            throw new InvalidArgumentException('unknown MCP service role "' . $role . '".');
        }

        $serviceName = (string) ($mapping['service_name'] ?? '');
        $serviceType = (string) ($mapping['service_type'] ?? '');
        if ($serviceName === '' || $serviceType === '') {
            throw new InvalidArgumentException('service role mapping must contain non-empty service_name and service_type.');
        }

        $route = $this->directory->route($serviceName, $clientInfo);
        $discovery = $this->directory->discover($serviceType, null);
        $rows = is_array($discovery['services'] ?? null) ? $discovery['services'] : [];

        $nodesById = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $node = McpServiceNode::fromArray($row);
            $nodesById[$node->serviceId()] = $node;
        }

        // If the route result points to a candidate not present in discovery yet,
        // keep it as first-choice and still build explicit fallback order.
        if (is_array($route) && !isset($route['error']) && is_string($route['service_id'] ?? null)) {
            $routeServiceId = trim((string) $route['service_id']);
            if ($routeServiceId !== '' && !isset($nodesById[$routeServiceId])) {
                try {
                    $routeNode = McpServiceNode::fromArray($route);
                    $nodesById[$routeNode->serviceId()] = $routeNode;
                } catch (InvalidArgumentException) {
                    // Prefer discovered rows when route metadata is partial.
                }
            }
        }

        if ($nodesById === []) {
            $error = is_string($route['error'] ?? null) ? $route['error'] : 'no discovered MCP services.';
            throw new RuntimeException(
                'mcp service discovery returned no candidates for role "' . $role . '": ' . $error
            );
        }

        $ordered = array_values($nodesById);
        usort($ordered, static function (McpServiceNode $left, McpServiceNode $right): int {
            $statusOrder = static function (string $status): int {
                return match (strtolower($status)) {
                    'healthy' => 0,
                    'degraded' => 1,
                    default => 2,
                };
            };

            $statusComparison = $statusOrder($left->status()) <=> $statusOrder($right->status());
            if ($statusComparison !== 0) {
                return $statusComparison;
            }

            $leftLoad = (int) ($left->attributes()['current_load_percent'] ?? 0);
            $rightLoad = (int) ($right->attributes()['current_load_percent'] ?? 0);
            $loadComparison = $leftLoad <=> $rightLoad;
            if ($loadComparison !== 0) {
                return $loadComparison;
            }

            return strcmp($left->serviceId(), $right->serviceId());
        });

        if (is_array($route) && !isset($route['error']) && is_string($route['service_id'] ?? null)) {
            $preferredServiceId = trim((string) $route['service_id']);
            if ($preferredServiceId !== '') {
                $preferred = null;
                $fallback = [];

                foreach ($ordered as $candidate) {
                    if ($candidate->serviceId() === $preferredServiceId && $preferred === null) {
                        $preferred = $candidate;
                        continue;
                    }
                    $fallback[] = $candidate;
                }

                if ($preferred instanceof McpServiceNode) {
                    $ordered = [$preferred, ...$fallback];
                }
            }
        }

        return new McpServiceResolution($role, $ordered);
    }
}
