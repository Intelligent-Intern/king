<?php
declare(strict_types=1);

namespace King\Flow;

require_once __DIR__ . '/ExecutionBackend.php';
require_once __DIR__ . '/McpServiceDiscovery.php';
require_once __DIR__ . '/ObjectStoreIngest.php';

use InvalidArgumentException;

final class RagReferencePlan
{
    /** @var array<string,array<string,mixed>> */
    private array $serviceEndpoints;

    /** @var array<int,array<string,mixed>> */
    private array $pipeline;

    /** @var array<string,mixed> */
    private array $initialData;

    /**
     * @param array<string,array<string,mixed>> $serviceEndpoints
     * @param array<int,array<string,mixed>> $pipeline
     * @param array<string,mixed> $initialData
     */
    public function __construct(
        private string $assetId,
        private string $query,
        private string $originalObjectId,
        array $serviceEndpoints,
        array $pipeline,
        array $initialData
    ) {
        $this->assetId = trim($this->assetId);
        $this->query = trim($this->query);
        $this->originalObjectId = trim($this->originalObjectId);
        $this->serviceEndpoints = $serviceEndpoints;
        $this->pipeline = $pipeline;
        $this->initialData = $initialData;

        if ($this->assetId === '' || $this->query === '' || $this->originalObjectId === '') {
            throw new InvalidArgumentException('assetId, query, and originalObjectId must not be empty.');
        }
    }

    public function assetId(): string
    {
        return $this->assetId;
    }

    public function query(): string
    {
        return $this->query;
    }

    public function originalObjectId(): string
    {
        return $this->originalObjectId;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function serviceEndpoints(): array
    {
        return $this->serviceEndpoints;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pipeline(): array
    {
        return $this->pipeline;
    }

    /**
     * @return array<string,mixed>
     */
    public function initialData(): array
    {
        return $this->initialData;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'asset_id' => $this->assetId,
            'query' => $this->query,
            'original_object_id' => $this->originalObjectId,
            'service_endpoints' => $this->serviceEndpoints,
            'pipeline' => $this->pipeline,
            'initial_data' => $this->initialData,
        ];
    }
}

final class RagReferenceRun
{
    /** @var list<array<string,mixed>> */
    private array $lifecycleEvents;

    /** @var array<string,mixed> */
    private array $capabilities;

    /**
     * @param list<array<string,mixed>> $lifecycleEvents
     * @param array<string,mixed> $capabilities
     */
    public function __construct(
        private RagReferencePlan $plan,
        private ExecutionRunSnapshot $snapshot,
        array $lifecycleEvents,
        array $capabilities
    ) {
        $this->lifecycleEvents = $lifecycleEvents;
        $this->capabilities = $capabilities;
    }

    public function plan(): RagReferencePlan
    {
        return $this->plan;
    }

    public function snapshot(): ExecutionRunSnapshot
    {
        return $this->snapshot;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function lifecycleEvents(): array
    {
        return $this->lifecycleEvents;
    }

    /**
     * @return array<string,mixed>
     */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function finalStatus(): string
    {
        return $this->snapshot->status();
    }

    public function answer(): ?string
    {
        $payload = $this->snapshot->payload();

        return is_array($payload) && is_string($payload['answer'] ?? null)
            ? $payload['answer']
            : null;
    }
}

final class RagOrchestrationReference
{
    public const TOOL_PARSE = 'rag-parse-document';
    public const TOOL_EMBED = 'rag-embed-query';
    public const TOOL_RETRIEVE = 'rag-retrieve-context';
    public const TOOL_RESPOND = 'rag-generate-answer';

    public function __construct(
        private ObjectStoreIngestor $ingestor,
        private McpServiceDiscovery $serviceDiscovery
    ) {
    }

    public function registerCanonicalTools(ExecutionBackend $backend): void
    {
        $backend->registerTool(self::TOOL_PARSE, [
            'stage' => 'parse',
            'role' => 'document',
            'description' => 'Parse uploaded original into extraction-ready text.',
        ]);
        $backend->registerTool(self::TOOL_EMBED, [
            'stage' => 'embed',
            'role' => 'embedding',
            'description' => 'Generate query embedding from parsed user input.',
        ]);
        $backend->registerTool(self::TOOL_RETRIEVE, [
            'stage' => 'retrieve',
            'role' => 'retrieval',
            'description' => 'Retrieve ranked context documents for the query embedding.',
        ]);
        $backend->registerTool(self::TOOL_RESPOND, [
            'stage' => 'respond',
            'role' => 'chat',
            'description' => 'Generate final answer from user query and retrieved context.',
        ]);
    }

    /**
     * @param resource $originalStream
     * @param array<string,mixed>|null $ingestOptions
     */
    public function preparePlan(
        string $assetId,
        $originalStream,
        string $query,
        ?array $ingestOptions = null
    ): RagReferencePlan {
        if (!is_resource($originalStream)) {
            throw new InvalidArgumentException('originalStream must be a resource.');
        }

        $assetId = trim($assetId);
        $query = trim($query);
        if ($assetId === '' || $query === '') {
            throw new InvalidArgumentException('assetId and query must not be empty.');
        }

        $originalObjectId = $this->ingestor->storeOriginalFromStream(
            $assetId,
            $originalStream,
            $ingestOptions ?? [
                'content_type' => 'application/octet-stream',
                'object_type' => 'document',
                'cache_policy' => 'etag',
            ]
        );

        $documentNode = $this->serviceDiscovery->resolve('document')->current();
        $embeddingNode = $this->serviceDiscovery->resolve('embedding')->current();
        $retrievalNode = $this->serviceDiscovery->resolve('retrieval')->current();

        $serviceEndpoints = [
            'document' => $documentNode->toArray(),
            'embedding' => $embeddingNode->toArray(),
            'retrieval' => $retrievalNode->toArray(),
        ];

        $initialData = [
            'asset_id' => $assetId,
            'query' => $query,
            'history' => [],
            'ingest' => [
                'original_object_id' => $originalObjectId,
                'artifact_object_id' => null,
            ],
            'services' => $serviceEndpoints,
            'worker_lifecycle' => [
                'submitted_at_ms' => (int) (hrtime(true) / 1000000),
                'events' => [],
            ],
        ];

        $pipeline = [
            ['tool' => self::TOOL_PARSE],
            ['tool' => self::TOOL_EMBED],
            ['tool' => self::TOOL_RETRIEVE],
            ['tool' => self::TOOL_RESPOND],
        ];

        return new RagReferencePlan(
            $assetId,
            $query,
            $originalObjectId,
            $serviceEndpoints,
            $pipeline,
            $initialData
        );
    }

    /**
     * @param array<string,mixed> $runOptions
     */
    public function run(ExecutionBackend $backend, RagReferencePlan $plan, array $runOptions = []): RagReferenceRun
    {
        $capabilitiesObject = $backend->capabilities();
        $capabilities = $capabilitiesObject->toArray();

        $snapshot = $backend->start($plan->initialData(), $plan->pipeline(), $runOptions);
        $lifecycle = [];
        $lifecycle[] = $this->event('submitted', $snapshot, $capabilities);

        if ($capabilitiesObject->supportsClaimNext() && !self::isTerminalStatus($snapshot->status())) {
            for ($attempt = 0; $attempt < 64; $attempt++) {
                $claimed = $backend->claimNext();
                if (!$claimed instanceof ExecutionRunSnapshot) {
                    $lifecycle[] = [
                        'phase' => 'worker_idle',
                        'attempt' => $attempt + 1,
                        'at_ms' => (int) (hrtime(true) / 1000000),
                    ];
                    break;
                }

                $snapshot = $claimed;
                $lifecycle[] = $this->event('worker_claim', $snapshot, $capabilities);
                if (self::isTerminalStatus($snapshot->status())) {
                    break;
                }
            }
        }

        if ($capabilitiesObject->supportsResumeById() && !self::isTerminalStatus($snapshot->status())) {
            for ($attempt = 0; $attempt < 64; $attempt++) {
                $snapshot = $backend->continueRun($snapshot->runId());
                $lifecycle[] = $this->event('worker_progress', $snapshot, $capabilities);
                if (self::isTerminalStatus($snapshot->status())) {
                    break;
                }
            }
        }

        $lifecycle[] = $this->event('final', $snapshot, $capabilities);

        return new RagReferenceRun($plan, $snapshot, $lifecycle, $capabilities);
    }

    /**
     * @param resource $destination
     */
    public function deliverOriginalPreview(
        RagReferencePlan $plan,
        $destination,
        int $offset = 0,
        ?int $length = 4096
    ): bool {
        return $this->ingestor->deliverToViewer(
            $plan->originalObjectId(),
            $destination,
            $offset,
            $length
        );
    }

    /**
     * @param array<string,mixed> $capabilities
     * @return array<string,mixed>
     */
    private function event(string $phase, ExecutionRunSnapshot $snapshot, array $capabilities): array
    {
        return [
            'phase' => $phase,
            'run_id' => $snapshot->runId(),
            'status' => $snapshot->status(),
            'backend' => $snapshot->executionBackend() !== '' ? $snapshot->executionBackend() : ($capabilities['backend'] ?? ''),
            'topology_scope' => $snapshot->topologyScope() !== '' ? $snapshot->topologyScope() : ($capabilities['topology_scope'] ?? ''),
            'completed_step_count' => $snapshot->completedStepCount(),
            'step_count' => $snapshot->stepCount(),
            'at_ms' => (int) (hrtime(true) / 1000000),
        ];
    }

    private static function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'cancelled'], true);
    }
}

