<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use King\ObjectStore;
use RuntimeException;
use Throwable;

final class ObjectStoreIngestor
{
    public function __construct(
        private string $originalPrefix = 'ingest-original',
        private string $artifactPrefix = 'ingest-artifact'
    ) {
        $this->originalPrefix = trim($this->originalPrefix);
        $this->artifactPrefix = trim($this->artifactPrefix);

        if ($this->originalPrefix === '' || $this->artifactPrefix === '') {
            throw new InvalidArgumentException('ObjectStore ingest prefixes must not be empty.');
        }
    }

    /**
     * @param resource $stream
     * @param array<string,mixed>|null $options
     */
    public function storeOriginalFromStream(string $assetId, $stream, ?array $options = null): string
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('original stream must be a resource.');
        }

        $objectId = $this->originalObjectIdFor($assetId);
        if (!ObjectStore::putFromStream($objectId, $stream, $options)) {
            throw new RuntimeException('failed to store original asset via ObjectStore::putFromStream().');
        }

        return $objectId;
    }

    /**
     * @param array<string,mixed>|null $options
     */
    public function storeExtractedArtifact(
        string $assetId,
        string $artifactId,
        string $payload,
        ?array $options = null
    ): string {
        $objectId = $this->artifactObjectIdFor($assetId, $artifactId);
        if (!ObjectStore::put($objectId, $payload, $options)) {
            throw new RuntimeException('failed to store extracted artifact via ObjectStore::put().');
        }

        return $objectId;
    }

    /**
     * @param array<string,mixed>|null $options
     */
    public function startStreamedOriginalUpload(string $assetId, ?array $options = null): ObjectStoreIngestUpload
    {
        $objectId = $this->originalObjectIdFor($assetId);
        try {
            $state = ObjectStore::beginResumableUpload($objectId, $options);
            $uploadId = is_string($state['upload_id'] ?? null) ? trim((string) $state['upload_id']) : '';

            if ($uploadId === '') {
                throw new RuntimeException('ObjectStore::beginResumableUpload() did not return a valid upload_id.');
            }

            return ObjectStoreIngestUpload::providerNative($objectId, $uploadId);
        } catch (Throwable $error) {
            $message = $error->getMessage();
            if (!str_contains($message, 'Provider-native resumable upload sessions require a real cloud primary backend')) {
                throw $error;
            }

            $spoolPath = tempnam(sys_get_temp_dir(), 'king-flow-ingest-');
            if (!is_string($spoolPath) || $spoolPath === '') {
                throw new RuntimeException(
                    'failed to create local spool for staged streamed upload fallback.',
                    0,
                    $error
                );
            }

            $spool = fopen($spoolPath, 'c+b');
            if (!is_resource($spool)) {
                @unlink($spoolPath);
                throw new RuntimeException(
                    'failed to open local spool for staged streamed upload fallback.',
                    0,
                    $error
                );
            }

            return ObjectStoreIngestUpload::localStaged($objectId, $spool, $spoolPath, $options);
        }
    }

    /**
     * @param resource $destination
     */
    public function deliverToViewer(
        string $objectId,
        $destination,
        int $offset = 0,
        ?int $length = null
    ): bool {
        if (!is_resource($destination)) {
            throw new InvalidArgumentException('viewer destination must be a stream resource.');
        }

        $objectId = trim($objectId);
        if ($objectId === '') {
            throw new InvalidArgumentException('objectId must not be empty.');
        }

        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be greater than or equal to zero.');
        }

        if ($length !== null && $length < 0) {
            throw new InvalidArgumentException('length must be null or greater than or equal to zero.');
        }

        $options = [];
        if ($offset > 0) {
            $options['offset'] = $offset;
        }
        if ($length !== null) {
            $options['length'] = $length;
        }

        $ok = ObjectStore::getToStream($objectId, $destination, $options === [] ? null : $options);
        if (!$ok) {
            throw new RuntimeException('failed to deliver object payload via ObjectStore::getToStream().');
        }

        return true;
    }

    private function originalObjectIdFor(string $assetId): string
    {
        return $this->originalPrefix . '--' . $this->encodeSegment($assetId, 'assetId');
    }

    private function artifactObjectIdFor(string $assetId, string $artifactId): string
    {
        return $this->artifactPrefix
            . '--' . $this->encodeSegment($assetId, 'assetId')
            . '--' . $this->encodeSegment($artifactId, 'artifactId');
    }

    private function encodeSegment(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException($name . ' must not be empty.');
        }

        return rawurlencode($value);
    }
}

final class ObjectStoreIngestUpload
{
    /** @var resource|null */
    private $localSpool;

    /** @var array<string,mixed>|null */
    private ?array $localPutOptions;

    private ?string $localSpoolPath;

    private bool $providerNative;

    private bool $closed = false;

    /**
     * @param resource|null $localSpool
     * @param array<string,mixed>|null $localPutOptions
     */
    private function __construct(
        private string $objectId,
        private string $uploadId,
        bool $providerNative,
        $localSpool = null,
        ?string $localSpoolPath = null,
        ?array $localPutOptions = null
    ) {
        $this->objectId = trim($this->objectId);
        $this->uploadId = trim($this->uploadId);
        $this->providerNative = $providerNative;
        $this->localSpool = $localSpool;
        $this->localSpoolPath = $localSpoolPath;
        $this->localPutOptions = $localPutOptions;

        if ($this->objectId === '' || $this->uploadId === '') {
            throw new InvalidArgumentException('objectId and uploadId must not be empty.');
        }

        if (!$this->providerNative && !is_resource($this->localSpool)) {
            throw new InvalidArgumentException('local staged upload requires a spool stream resource.');
        }
    }

    public static function providerNative(string $objectId, string $uploadId): self
    {
        return new self($objectId, $uploadId, true);
    }

    /**
     * @param resource $localSpool
     * @param array<string,mixed>|null $localPutOptions
     */
    public static function localStaged(
        string $objectId,
        $localSpool,
        string $localSpoolPath,
        ?array $localPutOptions = null
    ): self {
        return new self(
            $objectId,
            'local-staged-' . bin2hex(random_bytes(8)),
            false,
            $localSpool,
            $localSpoolPath,
            $localPutOptions
        );
    }

    public function objectId(): string
    {
        return $this->objectId;
    }

    public function uploadId(): string
    {
        return $this->uploadId;
    }

    /**
     * @param resource $stream
     * @return array<string,mixed>
     */
    public function appendChunk($stream, bool $final = false): array
    {
        if (!is_resource($stream)) {
            throw new InvalidArgumentException('append stream must be a resource.');
        }

        if ($this->providerNative) {
            return ObjectStore::appendResumableUploadChunk($this->uploadId, $stream, ['final' => $final]);
        }

        if ($this->closed || !is_resource($this->localSpool)) {
            throw new RuntimeException('local staged upload spool is no longer available.');
        }

        $written = stream_copy_to_stream($stream, $this->localSpool);
        if ($written === false) {
            throw new RuntimeException('failed to append chunk into local staged upload spool.');
        }

        fflush($this->localSpool);
        $bytesReceived = ftell($this->localSpool);

        return [
            'upload_id' => $this->uploadId,
            'bytes_received' => is_int($bytesReceived) ? $bytesReceived : 0,
            'upload_complete' => $final,
            'mode' => 'local_staged',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(): array
    {
        if ($this->providerNative) {
            return ObjectStore::completeResumableUpload($this->uploadId);
        }

        if ($this->closed || !is_resource($this->localSpool)) {
            throw new RuntimeException('local staged upload spool is no longer available.');
        }

        rewind($this->localSpool);
        if (!ObjectStore::putFromStream($this->objectId, $this->localSpool, $this->localPutOptions)) {
            throw new RuntimeException('failed to commit local staged upload via ObjectStore::putFromStream().');
        }

        $this->closeLocalSpool();

        return [
            'upload_id' => $this->uploadId,
            'upload_complete' => true,
            'mode' => 'local_staged',
        ];
    }

    public function abort(): bool
    {
        if ($this->providerNative) {
            return ObjectStore::abortResumableUpload($this->uploadId);
        }

        $this->closeLocalSpool();

        return true;
    }

    public function __destruct()
    {
        $this->closeLocalSpool();
    }

    private function closeLocalSpool(): void
    {
        if ($this->providerNative || $this->closed) {
            return;
        }

        if (is_resource($this->localSpool)) {
            fclose($this->localSpool);
        }

        if (is_string($this->localSpoolPath) && $this->localSpoolPath !== '') {
            @unlink($this->localSpoolPath);
        }

        $this->localSpool = null;
        $this->localSpoolPath = null;
        $this->closed = true;
    }
}
