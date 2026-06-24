<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use JsonException;
use Throwable;

interface CheckpointStore
{
    public function load(string $checkpointId): ?CheckpointRecord;

    public function create(string $checkpointId, CheckpointState $state): CheckpointCommitResult;

    public function replace(string $checkpointId, CheckpointState $state, CheckpointRecord $expected): CheckpointCommitResult;
}

final class CheckpointState
{
    /** @var array<string,int|float|string|bool|null> */
    private array $offsets;

    /** @var array<string,mixed> */
    private array $replayBoundary;

    /** @var array<string,mixed>|null */
    private ?array $sourceCursor;

    /** @var array<string,mixed>|null */
    private ?array $sinkCursor;

    /** @var array<string,mixed> */
    private array $progress;

    /**
     * @param array<string,int|float|string|bool|null> $offsets
     * @param array<string,mixed> $replayBoundary
     * @param SourceCursor|array<string,mixed>|null $sourceCursor
     * @param SinkCursor|array<string,mixed>|null $sinkCursor
     * @param array<string,mixed> $progress
     */
    public function __construct(
        array $offsets = [],
        array $replayBoundary = [],
        SourceCursor|array|null $sourceCursor = null,
        SinkCursor|array|null $sinkCursor = null,
        array $progress = []
    ) {
        $this->offsets = $offsets;
        $this->replayBoundary = $replayBoundary;
        $this->sourceCursor = self::normalizeSourceCursor($sourceCursor);
        $this->sinkCursor = self::normalizeSinkCursor($sinkCursor);
        $this->progress = $progress;
    }

    /**
     * @return array<string,int|float|string|bool|null>
     */
    public function offsets(): array
    {
        return $this->offsets;
    }

    /**
     * @return array<string,mixed>
     */
    public function replayBoundary(): array
    {
        return $this->replayBoundary;
    }

    public function sourceCursor(): ?SourceCursor
    {
        return $this->sourceCursor === null ? null : SourceCursor::fromArray($this->sourceCursor);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function sourceCursorArray(): ?array
    {
        return $this->sourceCursor;
    }

    public function sinkCursor(): ?SinkCursor
    {
        return $this->sinkCursor === null ? null : SinkCursor::fromArray($this->sinkCursor);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function sinkCursorArray(): ?array
    {
        return $this->sinkCursor;
    }

    /**
     * @return array<string,mixed>
     */
    public function progress(): array
    {
        return $this->progress;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'offsets' => $this->offsets,
            'replay_boundary' => $this->replayBoundary,
            'source_cursor' => $this->sourceCursor,
            'sink_cursor' => $this->sinkCursor,
            'progress' => $this->progress,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_array($data['offsets'] ?? null) ? $data['offsets'] : [],
            is_array($data['replay_boundary'] ?? null) ? $data['replay_boundary'] : [],
            is_array($data['source_cursor'] ?? null) ? $data['source_cursor'] : null,
            is_array($data['sink_cursor'] ?? null) ? $data['sink_cursor'] : null,
            is_array($data['progress'] ?? null) ? $data['progress'] : []
        );
    }

    /**
     * @param SourceCursor|array<string,mixed>|null $cursor
     * @return array<string,mixed>|null
     */
    private static function normalizeSourceCursor(SourceCursor|array|null $cursor): ?array
    {
        if ($cursor instanceof SourceCursor) {
            return $cursor->toArray();
        }

        if ($cursor === null) {
            return null;
        }

        return $cursor;
    }

    /**
     * @param SinkCursor|array<string,mixed>|null $cursor
     * @return array<string,mixed>|null
     */
    private static function normalizeSinkCursor(SinkCursor|array|null $cursor): ?array
    {
        if ($cursor instanceof SinkCursor) {
            return $cursor->toArray();
        }

        if ($cursor === null) {
            return null;
        }

        return $cursor;
    }
}

final class CheckpointRecord
{
    /** @var array<string,mixed> */
    private array $metadata;

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private string $checkpointId,
        private string $objectId,
        private int $version,
        private string $etag,
        private CheckpointState $state,
        array $metadata = [],
        private ?string $checkpointedAt = null
    ) {
        $this->metadata = $metadata;
    }

    public function checkpointId(): string
    {
        return $this->checkpointId;
    }

    public function objectId(): string
    {
        return $this->objectId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function etag(): string
    {
        return $this->etag;
    }

    public function state(): CheckpointState
    {
        return $this->state;
    }

    public function checkpointedAt(): ?string
    {
        return $this->checkpointedAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}

final class CheckpointCommitResult
{
    public function __construct(
        private bool $committed,
        private bool $conflict,
        private ?CheckpointRecord $record = null,
        private ?string $message = null
    ) {
    }

    public function committed(): bool
    {
        return $this->committed;
    }

    public function conflict(): bool
    {
        return $this->conflict;
    }

    public function record(): ?CheckpointRecord
    {
        return $this->record;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}

final class ObjectStoreCheckpointStore implements CheckpointStore
{
    private const OBJECT_ID_V2_PREFIX = 'checkpoint-v2!';
    private const OBJECT_ID_V2_SEPARATOR = '!';

    /** @var array<string,mixed> */
    private array $writeOptions;

    /**
     * @param array<string,mixed> $writeOptions
     */
    public function __construct(
        private string $prefix,
        array $writeOptions = []
    ) {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new InvalidArgumentException('prefix must not be empty.');
        }

        $this->prefix = $prefix;
        $this->writeOptions = $writeOptions;
    }

    public function load(string $checkpointId): ?CheckpointRecord
    {
        $record = $this->loadFromObjectId($checkpointId, $this->objectIdFor($checkpointId));
        if ($record instanceof CheckpointRecord) {
            return $record;
        }

        return $this->loadFromObjectId($checkpointId, $this->legacyObjectIdFor($checkpointId));
    }

    public function create(string $checkpointId, CheckpointState $state): CheckpointCommitResult
    {
        $objectId = $this->objectIdFor($checkpointId);
        $payload = $this->encodePayload($checkpointId, $state);
        $options = $this->writeOptions($payload);
        $options['if_none_match'] = '*';

        return $this->commit($checkpointId, $objectId, $payload, $options);
    }

    public function replace(string $checkpointId, CheckpointState $state, CheckpointRecord $expected): CheckpointCommitResult
    {
        if ($expected->checkpointId() !== $checkpointId) {
            throw new InvalidArgumentException('expected checkpoint record does not match the requested checkpoint id.');
        }

        $objectId = $this->objectIdFor($checkpointId);
        $legacyObjectId = $this->legacyObjectIdFor($checkpointId);
        if ($expected->objectId() !== $objectId && $expected->objectId() !== $legacyObjectId) {
            throw new InvalidArgumentException('expected checkpoint record does not belong to this checkpoint store prefix.');
        }
        if ($expected->objectId() === $legacyObjectId) {
            $objectId = $legacyObjectId;
        }

        $payload = $this->encodePayload($checkpointId, $state);
        $options = $this->writeOptions($payload);
        $options['if_match'] = $expected->etag();
        $options['expected_version'] = $expected->version();

        return $this->commit($checkpointId, $objectId, $payload, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function commit(string $checkpointId, string $objectId, string $payload, array $options): CheckpointCommitResult
    {
        try {
            $written = \king_object_store_put($objectId, $payload, $options);
            if ($written !== true) {
                throw new \RuntimeException('checkpoint write returned false.');
            }
        } catch (Throwable $error) {
            if ($this->isPreconditionConflict($error)) {
                $current = $this->loadFromObjectId($checkpointId, $objectId) ?? $this->load($checkpointId);

                return new CheckpointCommitResult(
                    false,
                    true,
                    $current,
                    $error->getMessage()
                );
            }

            throw $error;
        }

        $record = $this->loadFromObjectId($checkpointId, $objectId) ?? $this->load($checkpointId);
        if (!$record instanceof CheckpointRecord) {
            throw new \RuntimeException('checkpoint write succeeded but the committed record could not be reloaded.');
        }

        return new CheckpointCommitResult(true, false, $record, null);
    }

    private function encodePayload(string $checkpointId, CheckpointState $state): string
    {
        try {
            return json_encode([
                'checkpoint_schema_version' => 1,
                'checkpoint_id' => $checkpointId,
                'checkpointed_at' => gmdate(DATE_ATOM),
                'state' => $state->toArray(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new \RuntimeException('checkpoint state could not be encoded as JSON.', previous: $error);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $payload): array
    {
        $options = $this->writeOptions;
        $options['content_type'] ??= 'application/vnd.king.flow-checkpoint+json';
        $options['object_type'] ??= 'document';
        $options['cache_policy'] ??= 'etag';
        $options['integrity_sha256'] = hash('sha256', $payload);

        return $options;
    }

    private function objectIdFor(string $checkpointId): string
    {
        $checkpointId = $this->normalizeObjectKeyCheckpointId($checkpointId);

        return self::OBJECT_ID_V2_PREFIX
            . rawurlencode($this->prefix)
            . self::OBJECT_ID_V2_SEPARATOR
            . rawurlencode($checkpointId)
            . '.json';
    }

    private function legacyObjectIdFor(string $checkpointId): string
    {
        $checkpointId = $this->normalizeObjectKeyCheckpointId($checkpointId);

        return rawurlencode($this->prefix) . '--' . rawurlencode($checkpointId) . '.json';
    }

    private function normalizeObjectKeyCheckpointId(string $checkpointId): string
    {
        $checkpointId = trim($checkpointId);
        if ($checkpointId === '') {
            throw new InvalidArgumentException('checkpointId must not be empty.');
        }

        return $checkpointId;
    }

    private function loadFromObjectId(string $checkpointId, string $objectId): ?CheckpointRecord
    {
        $metadata = \king_object_store_get_metadata($objectId);
        if ($metadata === false) {
            return null;
        }

        $payload = \king_object_store_get($objectId);
        if (!is_string($payload)) {
            throw new \RuntimeException('checkpoint payload disappeared before it could be read.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('checkpoint payload is not valid JSON.', previous: $error);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('checkpoint payload is not a valid object.');
        }

        if (($decoded['checkpoint_schema_version'] ?? null) !== 1) {
            throw new \RuntimeException('checkpoint payload uses an unsupported schema version.');
        }

        if (($decoded['checkpoint_id'] ?? null) !== $checkpointId) {
            throw new \RuntimeException('checkpoint payload identity does not match the requested checkpoint id.');
        }

        return new CheckpointRecord(
            $checkpointId,
            $objectId,
            (int) ($metadata['version'] ?? 0),
            (string) ($metadata['etag'] ?? ''),
            CheckpointState::fromArray(is_array($decoded['state'] ?? null) ? $decoded['state'] : []),
            $metadata,
            isset($decoded['checkpointed_at']) ? (string) $decoded['checkpointed_at'] : null
        );
    }

    private function isPreconditionConflict(Throwable $error): bool
    {
        if ($error::class !== 'King\\ValidationException') {
            return false;
        }

        $message = $error->getMessage();

        return str_contains($message, 'if_none_match')
            || str_contains($message, 'if_match')
            || str_contains($message, 'expected_version');
    }
}
