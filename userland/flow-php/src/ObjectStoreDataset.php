<?php
declare(strict_types=1);

namespace King\Flow;

require_once __DIR__ . '/StreamingSource.php';
require_once __DIR__ . '/StreamingSink.php';

use InvalidArgumentException;
use RuntimeException;

final class ObjectStoreDataset
{
    public function __construct(
        private string $objectId,
        private int $chunkBytes = 8192
    ) {
        $this->objectId = trim($objectId);
        if ($this->objectId === '') {
            throw new InvalidArgumentException('objectId must not be empty.');
        }

        if ($chunkBytes <= 0) {
            throw new InvalidArgumentException('chunkBytes must be greater than zero.');
        }
    }

    public function objectId(): string
    {
        return $this->objectId;
    }

    public function chunkBytes(): int
    {
        return $this->chunkBytes;
    }

    public function describe(): ?ObjectStoreDatasetDescriptor
    {
        $metadata = \king_object_store_get_metadata($this->objectId);
        if (!is_array($metadata)) {
            return null;
        }

        return ObjectStoreDatasetDescriptor::fromMetadata($metadata);
    }

    /**
     * @param array<string,mixed> $readOptions
     */
    public function source(int $offset = 0, ?int $length = null, array $readOptions = []): ObjectStoreDatasetSource
    {
        return new ObjectStoreDatasetSource(
            $this->objectId,
            $this->chunkBytes,
            $offset,
            $length,
            $readOptions
        );
    }

    /**
     * @param array<string,mixed> $writeOptions
     */
    public function sink(array $writeOptions = [], ?SinkCursor $cursor = null): ObjectStoreDatasetWriter
    {
        return new ObjectStoreDatasetWriter($this, $writeOptions, $cursor);
    }
}

final class ObjectStoreDatasetDescriptor
{
    /** @var array<string,mixed> */
    private array $metadata;

    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private string $objectId,
        private int $contentLength,
        private string $etag,
        private int $version,
        private ?string $contentType,
        private ?string $contentEncoding,
        private ?string $integritySha256,
        private int|string|null $expiresAt,
        private bool $isExpired,
        private ?string $objectTypeName,
        private ?string $cachePolicyName,
        private ?int $cacheTtlSeconds,
        private ObjectStoreDatasetTopology $topology,
        array $metadata = []
    ) {
        $this->metadata = $metadata;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        if (!isset($metadata['object_id'])) {
            throw new InvalidArgumentException('object-store metadata is missing object_id.');
        }

        return new self(
            (string) $metadata['object_id'],
            (int) ($metadata['content_length'] ?? 0),
            (string) ($metadata['etag'] ?? ''),
            (int) ($metadata['version'] ?? 0),
            self::stringField($metadata, 'content_type'),
            self::stringField($metadata, 'content_encoding'),
            self::stringField($metadata, 'integrity_sha256'),
            self::normalizeExpiresAt($metadata['expires_at'] ?? null),
            (bool) ($metadata['is_expired'] ?? false),
            self::stringField($metadata, 'object_type_name'),
            self::stringField($metadata, 'cache_policy_name'),
            array_key_exists('cache_ttl_seconds', $metadata) ? (int) $metadata['cache_ttl_seconds'] : null,
            ObjectStoreDatasetTopology::fromMetadata($metadata),
            $metadata
        );
    }

    public function objectId(): string
    {
        return $this->objectId;
    }

    public function contentLength(): int
    {
        return $this->contentLength;
    }

    public function etag(): string
    {
        return $this->etag;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function contentType(): ?string
    {
        return $this->contentType;
    }

    public function contentEncoding(): ?string
    {
        return $this->contentEncoding;
    }

    public function integritySha256(): ?string
    {
        return $this->integritySha256;
    }

    public function expiresAt(): int|string|null
    {
        return $this->expiresAt;
    }

    public function isExpired(): bool
    {
        return $this->isExpired;
    }

    public function objectTypeName(): ?string
    {
        return $this->objectTypeName;
    }

    public function cachePolicyName(): ?string
    {
        return $this->cachePolicyName;
    }

    public function cacheTtlSeconds(): ?int
    {
        return $this->cacheTtlSeconds;
    }

    public function topology(): ObjectStoreDatasetTopology
    {
        return $this->topology;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'object_id' => $this->objectId,
            'content_length' => $this->contentLength,
            'etag' => $this->etag,
            'version' => $this->version,
            'content_type' => $this->contentType,
            'content_encoding' => $this->contentEncoding,
            'integrity_sha256' => $this->integritySha256,
            'expires_at' => $this->expiresAt,
            'is_expired' => $this->isExpired,
            'object_type_name' => $this->objectTypeName,
            'cache_policy_name' => $this->cachePolicyName,
            'cache_ttl_seconds' => $this->cacheTtlSeconds,
            'topology' => $this->topology->toArray(),
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private static function stringField(array $metadata, string $key): ?string
    {
        if (!array_key_exists($key, $metadata) || $metadata[$key] === null) {
            return null;
        }

        return (string) $metadata[$key];
    }

    private static function normalizeExpiresAt(mixed $expiresAt): int|string|null
    {
        if ($expiresAt === null || $expiresAt === '') {
            return null;
        }

        if (is_int($expiresAt) || is_string($expiresAt)) {
            return $expiresAt;
        }

        if (is_float($expiresAt)) {
            return (int) $expiresAt;
        }

        return (string) $expiresAt;
    }
}

final class ObjectStoreDatasetTopology
{
    public function __construct(
        private bool $localFsPresent,
        private bool $distributedPresent,
        private bool $cloudS3Present,
        private bool $cloudGcsPresent,
        private bool $cloudAzurePresent,
        private bool $backedUp,
        private int $replicationStatus,
        private bool $distributed,
        private int $distributionPeerCount
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            (int) ($metadata['local_fs_present'] ?? 0) === 1,
            (int) ($metadata['distributed_present'] ?? 0) === 1,
            (int) ($metadata['cloud_s3_present'] ?? 0) === 1,
            (int) ($metadata['cloud_gcs_present'] ?? 0) === 1,
            (int) ($metadata['cloud_azure_present'] ?? 0) === 1,
            (int) ($metadata['is_backed_up'] ?? 0) === 1,
            (int) ($metadata['replication_status'] ?? 0),
            (int) ($metadata['is_distributed'] ?? 0) === 1,
            (int) ($metadata['distribution_peer_count'] ?? 0)
        );
    }

    public function localFsPresent(): bool
    {
        return $this->localFsPresent;
    }

    public function distributedPresent(): bool
    {
        return $this->distributedPresent;
    }

    public function cloudS3Present(): bool
    {
        return $this->cloudS3Present;
    }

    public function cloudGcsPresent(): bool
    {
        return $this->cloudGcsPresent;
    }

    public function cloudAzurePresent(): bool
    {
        return $this->cloudAzurePresent;
    }

    public function backedUp(): bool
    {
        return $this->backedUp;
    }

    public function replicationStatus(): int
    {
        return $this->replicationStatus;
    }

    public function distributed(): bool
    {
        return $this->distributed;
    }

    public function distributionPeerCount(): int
    {
        return $this->distributionPeerCount;
    }

    /**
     * @return list<string>
     */
    public function activeBackends(): array
    {
        $backends = [];
        if ($this->localFsPresent) {
            $backends[] = 'local_fs';
        }

        if ($this->distributedPresent) {
            $backends[] = 'distributed';
        }

        if ($this->cloudS3Present) {
            $backends[] = 'cloud_s3';
        }

        if ($this->cloudGcsPresent) {
            $backends[] = 'cloud_gcs';
        }

        if ($this->cloudAzurePresent) {
            $backends[] = 'cloud_azure';
        }

        return $backends;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'active_backends' => $this->activeBackends(),
            'local_fs_present' => $this->localFsPresent,
            'distributed_present' => $this->distributedPresent,
            'cloud_s3_present' => $this->cloudS3Present,
            'cloud_gcs_present' => $this->cloudGcsPresent,
            'cloud_azure_present' => $this->cloudAzurePresent,
            'is_backed_up' => $this->backedUp,
            'replication_status' => $this->replicationStatus,
            'is_distributed' => $this->distributed,
            'distribution_peer_count' => $this->distributionPeerCount,
        ];
    }
}

final class ObjectStoreDatasetSource extends AbstractStreamingSource
{
    /** @var array<string,mixed> */
    private array $options;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        private string $objectId,
        private int $chunkBytes = 8192,
        private int $offset = 0,
        private ?int $length = null,
        array $options = []
    ) {
        if ($chunkBytes <= 0) {
            throw new InvalidArgumentException('chunkBytes must be greater than zero.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be zero or greater.');
        }

        if ($length !== null && $length < 0) {
            throw new InvalidArgumentException('length must be zero or greater when provided.');
        }

        if (array_key_exists('offset', $options) || array_key_exists('length', $options)) {
            throw new InvalidArgumentException('offset and length are managed by the dataset source.');
        }

        $this->options = $options;
    }

    public function pumpBytes(callable $onChunk, ?SourceCursor $cursor = null): SourcePumpResult
    {
        $identity = $this->rangeIdentity();
        $nextOffset = $cursor === null
            ? $this->offset
            : $this->resumeOffset($cursor, 'object_store_dataset', $identity);

        $metadata = \king_object_store_get_metadata($this->objectId);
        if (!is_array($metadata)) {
            throw new RuntimeException('dataset source could not resolve metadata for the requested object.');
        }

        $contentLength = (int) ($metadata['content_length'] ?? 0);
        if ($this->offset > $contentLength) {
            throw new InvalidArgumentException('dataset range offset is beyond the end of the current object payload.');
        }

        $rangeEnd = $this->length === null
            ? $contentLength
            : min($contentLength, $this->offset + $this->length);

        if ($nextOffset < $this->offset || $nextOffset > $rangeEnd) {
            throw new InvalidArgumentException('dataset resume cursor is outside the configured range window.');
        }

        $chunksDelivered = 0;
        $bytesDelivered = 0;

        while ($nextOffset < $rangeEnd) {
            $readLength = min($this->chunkBytes, $rangeEnd - $nextOffset);
            $stream = fopen('php://temp/maxmemory:' . (string) max($readLength, 1), 'w+');
            if (!is_resource($stream)) {
                throw new RuntimeException('dataset source could not allocate a bounded read buffer.');
            }

            $readOptions = $this->options;
            $readOptions['offset'] = $nextOffset;
            $readOptions['length'] = $readLength;

            try {
                $ok = \king_object_store_get_to_stream($this->objectId, $stream, $readOptions);
                if ($ok !== true) {
                    throw new RuntimeException('dataset source read failed for the requested range.');
                }

                rewind($stream);
                $chunk = (string) stream_get_contents($stream);
            } finally {
                fclose($stream);
            }

            if ($chunk === '') {
                throw new RuntimeException('dataset source read returned an empty chunk before the range window ended.');
            }

            $nextOffset += strlen($chunk);
            $chunksDelivered++;
            $bytesDelivered += strlen($chunk);

            $nextCursor = $this->cursorFor($identity, $contentLength, $rangeEnd, $nextOffset);
            $continue = $onChunk($chunk, $nextCursor, $nextOffset >= $rangeEnd);
            if ($continue === false) {
                return new SourcePumpResult($nextCursor, false, $chunksDelivered, $bytesDelivered);
            }
        }

        return new SourcePumpResult(
            $this->cursorFor($identity, $contentLength, $rangeEnd, $nextOffset),
            true,
            $chunksDelivered,
            $bytesDelivered
        );
    }

    private function rangeIdentity(): string
    {
        return $this->objectId . '@' . $this->offset . ':' . ($this->length === null ? '*' : (string) $this->length);
    }

    private function cursorFor(string $identity, int $contentLength, int $rangeEnd, int $nextOffset): SourceCursor
    {
        return new SourceCursor(
            'object_store_dataset',
            $identity,
            $nextOffset,
            'range_offset',
            [
                'object_id' => $this->objectId,
                'range_start_offset' => $this->offset,
                'range_length' => $this->length,
                'range_end_offset' => $rangeEnd,
                'content_length' => $contentLength,
                'next_offset' => $nextOffset,
                'range_bytes_delivered' => $nextOffset - $this->offset,
            ]
        );
    }
}

final class ObjectStoreDatasetWriter implements StreamingSink
{
    private ObjectStoreByteSink $sink;

    /**
     * @param array<string,mixed> $writeOptions
     */
    public function __construct(
        private ObjectStoreDataset $dataset,
        array $writeOptions = [],
        ?SinkCursor $cursor = null
    ) {
        $this->sink = new ObjectStoreByteSink($dataset->objectId(), $writeOptions, $cursor);
    }

    public function dataset(): ObjectStoreDataset
    {
        return $this->dataset;
    }

    public function descriptor(): ?ObjectStoreDatasetDescriptor
    {
        return $this->dataset->describe();
    }

    public function write(string $chunk): SinkWriteResult
    {
        return $this->sink->write($chunk);
    }

    public function complete(?string $finalChunk = null): SinkWriteResult
    {
        return $this->sink->complete($finalChunk);
    }

    public function abort(): bool
    {
        return $this->sink->abort();
    }

    public function cursor(): SinkCursor
    {
        return $this->sink->cursor();
    }
}
