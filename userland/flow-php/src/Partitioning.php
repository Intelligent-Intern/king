<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;

final class PartitionBatch
{
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public function __construct(
        private string $partitionId,
        private string $partitionKey,
        private string $batchId,
        private int $partitionSequence,
        private int $batchSequence,
        array $rows,
        private int $estimatedBytes
    ) {
        if ($partitionId === '') {
            throw new InvalidArgumentException('partitionId must be non-empty.');
        }

        if ($batchId === '') {
            throw new InvalidArgumentException('batchId must be non-empty.');
        }

        if ($partitionSequence < 0) {
            throw new InvalidArgumentException('partitionSequence must be zero or greater.');
        }

        if ($batchSequence < 0) {
            throw new InvalidArgumentException('batchSequence must be zero or greater.');
        }

        if ($estimatedBytes < 1) {
            throw new InvalidArgumentException('estimatedBytes must be greater than zero.');
        }

        if ($rows === []) {
            throw new InvalidArgumentException('rows must contain at least one entry.');
        }

        $this->rows = array_values($rows);
    }

    public function partitionId(): string
    {
        return $this->partitionId;
    }

    public function partitionKey(): string
    {
        return $this->partitionKey;
    }

    public function batchId(): string
    {
        return $this->batchId;
    }

    public function partitionSequence(): int
    {
        return $this->partitionSequence;
    }

    public function batchSequence(): int
    {
        return $this->batchSequence;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function recordCount(): int
    {
        return count($this->rows);
    }

    public function estimatedBytes(): int
    {
        return $this->estimatedBytes;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function executionOptions(array $options = []): array
    {
        $options['partition_id'] = $this->partitionId;
        $options['batch_id'] = $this->batchId;

        return $options;
    }

    /**
     * @param array<string,mixed> $stepDefinition
     * @return array<string,mixed>
     */
    public function annotateStep(array $stepDefinition): array
    {
        $stepDefinition['partition_id'] = $this->partitionId;
        $stepDefinition['batch_id'] = $this->batchId;

        return $stepDefinition;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'partition_id' => $this->partitionId,
            'partition_key' => $this->partitionKey,
            'batch_id' => $this->batchId,
            'partition_sequence' => $this->partitionSequence,
            'batch_sequence' => $this->batchSequence,
            'record_count' => $this->recordCount(),
            'estimated_bytes' => $this->estimatedBytes,
            'rows' => $this->rows,
        ];
    }
}

final class PartitionPlan
{
    /** @var array<int,PartitionBatch> */
    private array $batches;

    /** @var array<int,string> */
    private array $partitionIds;

    public function __construct(
        array $batches,
        private string $mergeStrategy = 'partition_then_batch',
        private string $backpressureContract = 'bounded_partition_batches',
        private int $maxBatchRecords = 1000,
        private int $maxBatchBytes = 1048576
    ) {
        if ($maxBatchRecords < 1) {
            throw new InvalidArgumentException('maxBatchRecords must be greater than zero.');
        }

        if ($maxBatchBytes < 1) {
            throw new InvalidArgumentException('maxBatchBytes must be greater than zero.');
        }

        foreach ($batches as $batch) {
            if (!$batch instanceof PartitionBatch) {
                throw new InvalidArgumentException('batches must contain only PartitionBatch instances.');
            }
        }

        $this->batches = array_values($batches);
        $this->partitionIds = array_values(array_unique(array_map(
            static fn (PartitionBatch $batch): string => $batch->partitionId(),
            $this->batches
        )));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     */
    public static function fromRowsByField(
        array $rows,
        string $partitionField,
        int $maxBatchRecords = 1000,
        int $maxBatchBytes = 1048576
    ): self {
        if ($partitionField === '') {
            throw new InvalidArgumentException('partitionField must be non-empty.');
        }

        if ($maxBatchRecords < 1) {
            throw new InvalidArgumentException('maxBatchRecords must be greater than zero.');
        }

        if ($maxBatchBytes < 1) {
            throw new InvalidArgumentException('maxBatchBytes must be greater than zero.');
        }

        /** @var array<string,list<array<string,mixed>>> $partitions */
        $partitions = [];
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException(
                    sprintf('row %d must be an associative array.', $index)
                );
            }

            if (!array_key_exists($partitionField, $row)) {
                throw new InvalidArgumentException(
                    sprintf("row %d is missing partition field '%s'.", $index, $partitionField)
                );
            }

            $partitionKey = self::normalizePartitionKey($row[$partitionField], $partitionField, $index);
            $partitions[$partitionKey][] = $row;
        }

        ksort($partitions, SORT_STRING);

        $batches = [];
        $partitionSequence = 0;
        foreach ($partitions as $partitionKey => $partitionRows) {
            $partitionId = sprintf(
                'partition-%03d-%s',
                $partitionSequence,
                self::slugPartitionKey($partitionKey)
            );

            $currentRows = [];
            $currentBytes = 0;
            $batchSequence = 0;

            foreach ($partitionRows as $row) {
                $rowBytes = self::estimateRowBytes($row);

                if (
                    $currentRows !== []
                    && (
                        count($currentRows) >= $maxBatchRecords
                        || (($currentBytes + $rowBytes) > $maxBatchBytes)
                    )
                ) {
                    $batches[] = new PartitionBatch(
                        $partitionId,
                        $partitionKey,
                        sprintf('%s-batch-%06d', $partitionId, $batchSequence),
                        $partitionSequence,
                        $batchSequence,
                        $currentRows,
                        $currentBytes
                    );
                    $currentRows = [];
                    $currentBytes = 0;
                    $batchSequence++;
                }

                $currentRows[] = $row;
                $currentBytes += $rowBytes;
            }

            if ($currentRows !== []) {
                $batches[] = new PartitionBatch(
                    $partitionId,
                    $partitionKey,
                    sprintf('%s-batch-%06d', $partitionId, $batchSequence),
                    $partitionSequence,
                    $batchSequence,
                    $currentRows,
                    $currentBytes
                );
            }

            $partitionSequence++;
        }

        return new self(
            $batches,
            'partition_then_batch',
            'bounded_partition_batches',
            $maxBatchRecords,
            $maxBatchBytes
        );
    }

    /**
     * @return array<int,PartitionBatch>
     */
    public function batches(): array
    {
        return $this->batches;
    }

    /**
     * @return array<int,PartitionBatch>
     */
    public function batchesForPartition(string $partitionId): array
    {
        return array_values(array_filter(
            $this->batches,
            static fn (PartitionBatch $batch): bool => $batch->partitionId() === $partitionId
        ));
    }

    /**
     * @return array<int,string>
     */
    public function partitionIds(): array
    {
        return $this->partitionIds;
    }

    public function partitionCount(): int
    {
        return count($this->partitionIds);
    }

    public function batchCount(): int
    {
        return count($this->batches);
    }

    public function mergeStrategy(): string
    {
        return $this->mergeStrategy;
    }

    public function backpressureContract(): string
    {
        return $this->backpressureContract;
    }

    public function maxBatchRecords(): int
    {
        return $this->maxBatchRecords;
    }

    public function maxBatchBytes(): int
    {
        return $this->maxBatchBytes;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'partition_count' => $this->partitionCount(),
            'batch_count' => $this->batchCount(),
            'partition_ids' => $this->partitionIds,
            'merge_strategy' => $this->mergeStrategy,
            'backpressure_contract' => $this->backpressureContract,
            'max_batch_records' => $this->maxBatchRecords,
            'max_batch_bytes' => $this->maxBatchBytes,
            'batches' => array_map(
                static fn (PartitionBatch $batch): array => $batch->toArray(),
                $this->batches
            ),
        ];
    }

    private static function normalizePartitionKey(mixed $value, string $field, int $index): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $normalized = trim((string) $value);
            if ($normalized === '') {
                throw new InvalidArgumentException(
                    sprintf("row %d has an empty value for partition field '%s'.", $index, $field)
                );
            }

            return $normalized;
        }

        throw new InvalidArgumentException(
            sprintf(
                "row %d has a non-scalar value for partition field '%s'.",
                $index,
                $field
            )
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function estimateRowBytes(array $row): int
    {
        return max(1, strlen((string) json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE)));
    }

    private static function slugPartitionKey(string $partitionKey): string
    {
        $slug = strtolower($partitionKey);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'partition';
    }
}

final class PartitionAttempt
{
    public function __construct(
        private string $runId,
        private string $partitionId,
        private string $batchId,
        private string $status,
        private string $queuePhase,
        private string $executionBackend,
        private string $topologyScope,
        private bool $active
    ) {
        if ($runId === '') {
            throw new InvalidArgumentException('runId must be non-empty.');
        }

        if ($partitionId === '') {
            throw new InvalidArgumentException('partitionId must be non-empty.');
        }

        if ($batchId === '') {
            throw new InvalidArgumentException('batchId must be non-empty.');
        }
    }

    public static function fromExecutionSnapshot(ExecutionRunSnapshot $snapshot): ?self
    {
        $adapter = $snapshot->telemetryAdapter() ?? [];
        $partitionId = is_string($adapter['partition_id'] ?? null)
            ? $adapter['partition_id']
            : (is_string($adapter['failed_partition_id'] ?? null) ? $adapter['failed_partition_id'] : null);
        $batchId = is_string($adapter['batch_id'] ?? null)
            ? $adapter['batch_id']
            : (is_string($adapter['failed_batch_id'] ?? null) ? $adapter['failed_batch_id'] : null);

        if ((!is_string($partitionId) || $partitionId === '') || (!is_string($batchId) || $batchId === '')) {
            foreach ($snapshot->steps() as $step) {
                $stepAdapter = $step['telemetry_adapter'] ?? null;
                if (!is_array($stepAdapter)) {
                    continue;
                }

                $stepPartitionId = $stepAdapter['partition_id'] ?? null;
                $stepBatchId = $stepAdapter['batch_id'] ?? null;
                if (
                    is_string($stepPartitionId) && $stepPartitionId !== ''
                    && is_string($stepBatchId) && $stepBatchId !== ''
                ) {
                    $partitionId = $stepPartitionId;
                    $batchId = $stepBatchId;
                    break;
                }
            }
        }

        if (!is_string($partitionId) || $partitionId === '' || !is_string($batchId) || $batchId === '') {
            return null;
        }

        $observability = $snapshot->distributedObservability() ?? [];
        $queuePhase = is_string($observability['queue_phase'] ?? null)
            ? $observability['queue_phase']
            : 'not_queued';
        $status = $snapshot->status();
        $active = in_array($status, ['queued', 'running'], true) || $queuePhase === 'claimed';

        return new self(
            $snapshot->runId(),
            $partitionId,
            $batchId,
            $status,
            $queuePhase,
            $snapshot->executionBackend(),
            $snapshot->topologyScope(),
            $active
        );
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function partitionId(): string
    {
        return $this->partitionId;
    }

    public function batchId(): string
    {
        return $this->batchId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function queuePhase(): string
    {
        return $this->queuePhase;
    }

    public function executionBackend(): string
    {
        return $this->executionBackend;
    }

    public function topologyScope(): string
    {
        return $this->topologyScope;
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function queued(): bool
    {
        return $this->queuePhase === 'queued';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'partition_id' => $this->partitionId,
            'batch_id' => $this->batchId,
            'status' => $this->status,
            'queue_phase' => $this->queuePhase,
            'execution_backend' => $this->executionBackend,
            'topology_scope' => $this->topologyScope,
            'active' => $this->active,
        ];
    }
}

final class PartitionBackpressureWindow
{
    public function __construct(
        private string $submissionMode = 'run_immediately',
        private int $maxConcurrentBatches = 1,
        private int $maxQueuedBatches = 1,
        private int $maxActivePartitions = 1
    ) {
        if (!in_array($submissionMode, ['run_immediately', 'queue_dispatch'], true)) {
            throw new InvalidArgumentException('submissionMode must be run_immediately or queue_dispatch.');
        }

        if ($maxConcurrentBatches < 1) {
            throw new InvalidArgumentException('maxConcurrentBatches must be greater than zero.');
        }

        if ($maxQueuedBatches < 1) {
            throw new InvalidArgumentException('maxQueuedBatches must be greater than zero.');
        }

        if ($maxActivePartitions < 1) {
            throw new InvalidArgumentException('maxActivePartitions must be greater than zero.');
        }
    }

    public static function fromCapabilities(
        ExecutionBackendCapabilities $capabilities,
        int $maxConcurrentBatches = 1,
        int $maxQueuedBatches = 1,
        int $maxActivePartitions = 1
    ): self {
        return new self(
            $capabilities->submissionMode(),
            $maxConcurrentBatches,
            $maxQueuedBatches,
            $maxActivePartitions
        );
    }

    /**
     * @param array<int,PartitionBatch> $pendingBatches
     * @param array<int,PartitionAttempt> $attempts
     */
    public function decision(array $pendingBatches, array $attempts): PartitionDispatchDecision
    {
        $activeAttempts = array_values(array_filter(
            $attempts,
            static fn (PartitionAttempt $attempt): bool => $attempt->active()
        ));
        $activePartitions = [];
        $queuedBatches = 0;

        foreach ($activeAttempts as $attempt) {
            $activePartitions[$attempt->partitionId()] = true;
            if ($attempt->queued()) {
                $queuedBatches++;
            }
        }

        $dispatchable = [];
        $blocked = [];
        $reasons = [];
        $simulatedActiveCount = count($activeAttempts);
        $simulatedQueuedCount = $queuedBatches;
        $simulatedActivePartitions = $activePartitions;

        foreach ($pendingBatches as $batch) {
            $reason = null;

            if ($simulatedActiveCount >= $this->maxConcurrentBatches) {
                $reason = 'max_concurrent_batches';
            } elseif (
                $this->submissionMode === 'queue_dispatch'
                && $simulatedQueuedCount >= $this->maxQueuedBatches
            ) {
                $reason = 'max_queued_batches';
            } elseif (
                !isset($simulatedActivePartitions[$batch->partitionId()])
                && count($simulatedActivePartitions) >= $this->maxActivePartitions
            ) {
                $reason = 'max_active_partitions';
            }

            if ($reason !== null) {
                $blocked[] = $batch;
                $reasons[$batch->batchId()] = $reason;
                continue;
            }

            $dispatchable[] = $batch;
            $simulatedActiveCount++;
            $simulatedActivePartitions[$batch->partitionId()] = true;
            if ($this->submissionMode === 'queue_dispatch') {
                $simulatedQueuedCount++;
            }
        }

        return new PartitionDispatchDecision(
            $dispatchable,
            $blocked,
            $reasons,
            [
                'submission_mode' => $this->submissionMode,
                'active_batch_count' => count($activeAttempts),
                'queued_batch_count' => $queuedBatches,
                'active_partition_count' => count($activePartitions),
                'max_concurrent_batches' => $this->maxConcurrentBatches,
                'max_queued_batches' => $this->maxQueuedBatches,
                'max_active_partitions' => $this->maxActivePartitions,
            ]
        );
    }
}

final class PartitionDispatchDecision
{
    /** @var array<int,PartitionBatch> */
    private array $dispatchable;

    /** @var array<int,PartitionBatch> */
    private array $blocked;

    /** @var array<string,string> */
    private array $blockedReasons;

    /** @var array<string,int|string> */
    private array $summary;

    /**
     * @param array<int,PartitionBatch> $dispatchable
     * @param array<int,PartitionBatch> $blocked
     * @param array<string,string> $blockedReasons
     * @param array<string,int|string> $summary
     */
    public function __construct(
        array $dispatchable,
        array $blocked,
        array $blockedReasons,
        array $summary
    ) {
        $this->dispatchable = array_values($dispatchable);
        $this->blocked = array_values($blocked);
        $this->blockedReasons = $blockedReasons;
        $this->summary = $summary;
    }

    /**
     * @return array<int,PartitionBatch>
     */
    public function dispatchable(): array
    {
        return $this->dispatchable;
    }

    /**
     * @return array<int,PartitionBatch>
     */
    public function blocked(): array
    {
        return $this->blocked;
    }

    public function blockedReasonFor(string $batchId): ?string
    {
        return $this->blockedReasons[$batchId] ?? null;
    }

    /**
     * @return array<string,int|string>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'dispatchable_batch_ids' => array_map(
                static fn (PartitionBatch $batch): string => $batch->batchId(),
                $this->dispatchable
            ),
            'blocked_batch_ids' => array_map(
                static fn (PartitionBatch $batch): string => $batch->batchId(),
                $this->blocked
            ),
            'blocked_reasons' => $this->blockedReasons,
            'summary' => $this->summary,
        ];
    }
}

final class PartitionMergeResult
{
    /** @var array<string,list<mixed>> */
    private array $partitionOutputs;

    /** @var array<int,mixed> */
    private array $mergedOutputs;

    /** @var array<int,string> */
    private array $pendingBatchIds;

    /** @var array<string,string> */
    private array $failedBatches;

    /**
     * @param array<string,list<mixed>> $partitionOutputs
     * @param array<int,mixed> $mergedOutputs
     * @param array<int,string> $pendingBatchIds
     * @param array<string,string> $failedBatches
     */
    public function __construct(
        array $partitionOutputs,
        array $mergedOutputs,
        array $pendingBatchIds,
        array $failedBatches
    ) {
        $this->partitionOutputs = $partitionOutputs;
        $this->mergedOutputs = array_values($mergedOutputs);
        $this->pendingBatchIds = array_values($pendingBatchIds);
        $this->failedBatches = $failedBatches;
    }

    /**
     * @param array<int,ExecutionRunSnapshot> $snapshots
     */
    public static function fromExecutionSnapshots(PartitionPlan $plan, array $snapshots): self
    {
        $completed = [];
        $failed = [];

        foreach ($snapshots as $snapshot) {
            if (!$snapshot instanceof ExecutionRunSnapshot) {
                throw new InvalidArgumentException('snapshots must contain only ExecutionRunSnapshot instances.');
            }

            $attempt = PartitionAttempt::fromExecutionSnapshot($snapshot);
            if (!$attempt instanceof PartitionAttempt) {
                continue;
            }

            $key = $attempt->partitionId() . '|' . $attempt->batchId();
            if ($snapshot->status() === 'completed') {
                $completed[$key] = $snapshot->payload();
                continue;
            }

            $failed[$attempt->batchId()] = $snapshot->status();
        }

        $partitionOutputs = [];
        foreach ($plan->partitionIds() as $partitionId) {
            $partitionOutputs[$partitionId] = [];
        }

        $mergedOutputs = [];
        $pending = [];

        foreach ($plan->batches() as $batch) {
            $key = $batch->partitionId() . '|' . $batch->batchId();
            if (array_key_exists($batch->batchId(), $failed)) {
                continue;
            }

            if (!array_key_exists($key, $completed)) {
                $pending[] = $batch->batchId();
                continue;
            }

            $payload = $completed[$key];
            $partitionOutputs[$batch->partitionId()][] = $payload;
            $mergedOutputs[] = $payload;
        }

        return new self($partitionOutputs, $mergedOutputs, $pending, $failed);
    }

    public function complete(): bool
    {
        return $this->pendingBatchIds === [] && $this->failedBatches === [];
    }

    /**
     * @return array<string,list<mixed>>
     */
    public function partitionOutputs(): array
    {
        return $this->partitionOutputs;
    }

    /**
     * @return array<int,mixed>
     */
    public function mergedOutputs(): array
    {
        return $this->mergedOutputs;
    }

    /**
     * @return array<int,string>
     */
    public function pendingBatchIds(): array
    {
        return $this->pendingBatchIds;
    }

    /**
     * @return array<string,string>
     */
    public function failedBatches(): array
    {
        return $this->failedBatches;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'complete' => $this->complete(),
            'partition_outputs' => $this->partitionOutputs,
            'merged_outputs' => $this->mergedOutputs,
            'pending_batch_ids' => $this->pendingBatchIds,
            'failed_batches' => $this->failedBatches,
        ];
    }
}
