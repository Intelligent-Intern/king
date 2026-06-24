<?php
declare(strict_types=1);

namespace King\Flow;

require_once __DIR__ . '/ObjectStoreDataset.php';

use Closure;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

interface RecordCodec
{
    public function format(): string;

    public function mediaType(): string;

    public function framing(): string;
}

interface LineRecordCodec extends RecordCodec
{
    public function delimiter(): string;

    /**
     * @param array<string,mixed> $state
     */
    public function decodeLine(string $line, int $lineNumber, array &$state): LineDecodeResult;

    /**
     * @param array<string,mixed> $state
     */
    public function encodeRecord(mixed $record, int $recordNumber, array &$state): string;
}

interface PayloadRecordCodec extends RecordCodec
{
    public function replayStrategy(): string;

    public function decodePayload(string $payload): mixed;

    public function encodePayload(mixed $record): string;
}

final class LineDecodeResult
{
    private function __construct(
        private bool $emitRecord,
        private mixed $record = null
    ) {
    }

    public static function skip(): self
    {
        return new self(false);
    }

    public static function emit(mixed $record): self
    {
        return new self(true, $record);
    }

    public function emitRecord(): bool
    {
        return $this->emitRecord;
    }

    public function record(): mixed
    {
        return $this->record;
    }
}

final class SerializedRecordPumpResult
{
    public function __construct(
        private SourceCursor $cursor,
        private bool $complete,
        private int $recordsDelivered,
        private int $bytesDelivered,
        private string $format
    ) {
    }

    public function cursor(): SourceCursor
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function recordsDelivered(): int
    {
        return $this->recordsDelivered;
    }

    public function bytesDelivered(): int
    {
        return $this->bytesDelivered;
    }

    public function format(): string
    {
        return $this->format;
    }
}

final class SerializedRecordWriteResult
{
    /** @var array<string,mixed> */
    private array $details;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        private SinkCursor $cursor,
        private bool $complete,
        private bool $transportCommitted,
        private int $recordsAccepted,
        private int $bytesAccepted,
        private string $format,
        private ?SinkFailure $failure = null,
        array $details = []
    ) {
        $this->details = $details;
    }

    public function cursor(): SinkCursor
    {
        return $this->cursor;
    }

    public function complete(): bool
    {
        return $this->complete;
    }

    public function transportCommitted(): bool
    {
        return $this->transportCommitted;
    }

    public function recordsAccepted(): int
    {
        return $this->recordsAccepted;
    }

    public function bytesAccepted(): int
    {
        return $this->bytesAccepted;
    }

    public function format(): string
    {
        return $this->format;
    }

    public function failure(): ?SinkFailure
    {
        return $this->failure;
    }

    /**
     * @return array<string,mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}

final class SerializedRecordReader
{
    private const TRANSPORT = 'serialized_record_reader';

    public function __construct(
        private StreamingSource $source,
        private RecordCodec $codec
    ) {
    }

    public function pumpRecords(callable $onRecord, ?SourceCursor $cursor = null): SerializedRecordPumpResult
    {
        return $this->codec instanceof LineRecordCodec
            ? $this->pumpLineRecords($this->codec, $onRecord, $cursor)
            : $this->pumpPayloadRecord($this->codec, $onRecord, $cursor);
    }

    private function pumpLineRecords(LineRecordCodec $codec, callable $onRecord, ?SourceCursor $cursor): SerializedRecordPumpResult
    {
        [$innerCursor, $serializationState] = $this->unwrapReaderCursor($cursor);
        $recordsDelivered = 0;
        $lineNumber = (int) ($serializationState['line_number'] ?? 0);
        $recordNumber = (int) ($serializationState['record_number'] ?? 0);
        $complete = true;
        $latestWrappedCursor = null;

        $result = $this->source->pumpLines(
            function (string $line, SourceCursor $nextCursor, bool $sourceComplete) use (
                &$serializationState,
                &$recordsDelivered,
                &$lineNumber,
                &$recordNumber,
                &$complete,
                &$latestWrappedCursor,
                $codec,
                $onRecord
            ): bool {
                $lineNumber++;
                $serializationState['line_number'] = $lineNumber;

                $decoded = $codec->decodeLine($line, $lineNumber, $serializationState);
                if (!$decoded->emitRecord()) {
                    $latestWrappedCursor = $this->wrapReaderCursor(
                        $nextCursor,
                        $serializationState,
                        'serialization_cursor'
                    );

                    return true;
                }

                $recordNumber++;
                $serializationState['record_number'] = $recordNumber;

                $wrappedCursor = $this->wrapReaderCursor(
                    $nextCursor,
                    $serializationState,
                    'serialization_cursor'
                );
                $latestWrappedCursor = $wrappedCursor;
                $recordsDelivered++;

                $continue = $onRecord($decoded->record(), $wrappedCursor, $sourceComplete);
                if ($continue === false) {
                    $complete = false;
                    return false;
                }

                return true;
            },
            $innerCursor,
            $codec->delimiter()
        );

        $finalCursor = $latestWrappedCursor instanceof SourceCursor
            ? $latestWrappedCursor
            : $this->wrapReaderCursor($result->cursor(), $serializationState, 'serialization_cursor');

        return new SerializedRecordPumpResult(
            $finalCursor,
            $complete && $result->complete(),
            $recordsDelivered,
            $result->bytesDelivered(),
            $codec->format()
        );
    }

    private function pumpPayloadRecord(PayloadRecordCodec $codec, callable $onRecord, ?SourceCursor $cursor): SerializedRecordPumpResult
    {
        [, $serializationState] = $this->unwrapReaderCursor($cursor);
        $serializationState['record_number'] = (int) ($serializationState['record_number'] ?? 0);
        $spool = fopen('php://temp/maxmemory:1048576', 'w+');
        if (!is_resource($spool)) {
            throw new RuntimeException('serialization reader could not allocate a temporary payload spool.');
        }

        try {
            $sourceResult = $this->source->pumpBytes(
                static function (string $chunk, SourceCursor $nextCursor, bool $sourceComplete) use ($spool, &$serializationState): bool {
                    fwrite($spool, $chunk);
                    $serializationState['last_source_cursor'] = $nextCursor->toArray();
                    $serializationState['source_complete'] = $sourceComplete;

                    return true;
                }
            );

            rewind($spool);
            $payload = (string) stream_get_contents($spool);
        } finally {
            fclose($spool);
        }

        $record = $codec->decodePayload($payload);
        $serializationState['record_number']++;
        $wrappedCursor = $this->wrapReaderCursor(
            $sourceResult->cursor(),
            $serializationState,
            $codec->replayStrategy()
        );

        $continue = $onRecord($record, $wrappedCursor, true);

        return new SerializedRecordPumpResult(
            $wrappedCursor,
            $continue !== false,
            1,
            $sourceResult->bytesDelivered(),
            $codec->format()
        );
    }

    /**
     * @return array{0:?SourceCursor,1:array<string,mixed>}
     */
    private function unwrapReaderCursor(?SourceCursor $cursor): array
    {
        if ($cursor === null) {
            return [null, []];
        }

        if ($cursor->transport() !== self::TRANSPORT) {
            throw new InvalidArgumentException('serialized reader cursor does not belong to this bridge.');
        }

        $state = $cursor->state();
        if (($state['format'] ?? null) !== $this->codec->format()) {
            throw new InvalidArgumentException('serialized reader cursor format does not match this codec.');
        }

        $inner = is_array($state['source_cursor'] ?? null)
            ? SourceCursor::fromArray($state['source_cursor'])
            : null;

        return [$inner, is_array($state['serialization_state'] ?? null) ? $state['serialization_state'] : []];
    }

    /**
     * @param array<string,mixed> $serializationState
     */
    private function wrapReaderCursor(SourceCursor $innerCursor, array $serializationState, string $resumeStrategy): SourceCursor
    {
        return new SourceCursor(
            self::TRANSPORT,
            $this->codec->format() . ':' . $innerCursor->transport() . ':' . $innerCursor->identity(),
            $innerCursor->bytesConsumed(),
            $resumeStrategy,
            [
                'format' => $this->codec->format(),
                'framing' => $this->codec->framing(),
                'source_cursor' => $innerCursor->toArray(),
                'inner_resume_strategy' => $innerCursor->resumeStrategy(),
                'serialization_state' => $serializationState,
            ]
        );
    }
}

final class SerializedRecordWriter
{
    private const TRANSPORT = 'serialized_record_writer';

    /** @var Closure(?SinkCursor):StreamingSink */
    private Closure $sinkFactory;

    private StreamingSink $sink;

    /** @var array<string,mixed> */
    private array $serializationState = [];

    private int $recordsAccepted = 0;

    /**
     * @param callable(?SinkCursor):StreamingSink $sinkFactory
     */
    public function __construct(
        callable $sinkFactory,
        private RecordCodec $codec,
        ?SinkCursor $cursor = null
    ) {
        $this->sinkFactory = Closure::fromCallable($sinkFactory);
        [$innerCursor, $serializationState, $recordsAccepted] = $this->unwrapWriterCursor($cursor);
        $this->serializationState = $serializationState;
        $this->recordsAccepted = $recordsAccepted;

        $sink = ($this->sinkFactory)($innerCursor);
        if (!$sink instanceof StreamingSink) {
            throw new InvalidArgumentException('sinkFactory must return a StreamingSink.');
        }

        $this->sink = $sink;
    }

    public function writeRecord(mixed $record): SerializedRecordWriteResult
    {
        $payload = $this->codec instanceof LineRecordCodec
            ? $this->codec->encodeRecord($record, $this->recordsAccepted + 1, $this->serializationState)
            : $this->encodePayloadRecord($record);

        $writeResult = $this->sink->write($payload);
        if ($writeResult->failure() === null) {
            $this->recordsAccepted++;
        }

        return $this->wrapWriteResult($writeResult);
    }

    public function complete(mixed $finalRecord = null): SerializedRecordWriteResult
    {
        if ($finalRecord !== null) {
            $writeResult = $this->writeRecord($finalRecord);
            if ($writeResult->failure() !== null) {
                return $writeResult;
            }
        } elseif ($this->codec instanceof PayloadRecordCodec && $this->recordsAccepted === 0) {
            throw new InvalidArgumentException('payload codecs require a record before complete().');
        }

        return $this->wrapWriteResult($this->sink->complete());
    }

    public function abort(): bool
    {
        return $this->sink->abort();
    }

    public function cursor(): SinkCursor
    {
        return $this->wrapSinkCursor($this->sink->cursor());
    }

    private function encodePayloadRecord(mixed $record): string
    {
        if (!$this->codec instanceof PayloadRecordCodec) {
            throw new InvalidArgumentException('codec does not support payload encoding.');
        }

        if ($this->recordsAccepted > 0) {
            throw new InvalidArgumentException('payload codecs accept exactly one record per object payload.');
        }

        $this->serializationState['record_number'] = $this->recordsAccepted + 1;

        return $this->codec->encodePayload($record);
    }

    /**
     * @return array{0:?SinkCursor,1:array<string,mixed>,2:int}
     */
    private function unwrapWriterCursor(?SinkCursor $cursor): array
    {
        if ($cursor === null) {
            return [null, [], 0];
        }

        if ($cursor->transport() !== self::TRANSPORT) {
            throw new InvalidArgumentException('serialized writer cursor does not belong to this bridge.');
        }

        $state = $cursor->state();
        if (($state['format'] ?? null) !== $this->codec->format()) {
            throw new InvalidArgumentException('serialized writer cursor format does not match this codec.');
        }

        $inner = is_array($state['sink_cursor'] ?? null)
            ? SinkCursor::fromArray($state['sink_cursor'])
            : null;

        return [
            $inner,
            is_array($state['serialization_state'] ?? null) ? $state['serialization_state'] : [],
            (int) ($state['records_accepted'] ?? 0),
        ];
    }

    private function wrapWriteResult(SinkWriteResult $writeResult): SerializedRecordWriteResult
    {
        return new SerializedRecordWriteResult(
            $this->wrapSinkCursor($writeResult->cursor()),
            $writeResult->complete(),
            $writeResult->transportCommitted(),
            $this->recordsAccepted,
            $writeResult->bytesAccepted(),
            $this->codec->format(),
            $writeResult->failure(),
            [
                'framing' => $this->codec->framing(),
                'serialization_state' => $this->serializationState,
                'inner_details' => $writeResult->details(),
            ]
        );
    }

    private function wrapSinkCursor(SinkCursor $innerCursor): SinkCursor
    {
        return new SinkCursor(
            self::TRANSPORT,
            $this->codec->format() . ':' . $innerCursor->transport() . ':' . $innerCursor->identity(),
            $innerCursor->bytesAccepted(),
            $this->codec instanceof PayloadRecordCodec ? $this->codec->replayStrategy() : 'serialization_cursor',
            [
                'format' => $this->codec->format(),
                'framing' => $this->codec->framing(),
                'sink_cursor' => $innerCursor->toArray(),
                'inner_resume_strategy' => $innerCursor->resumeStrategy(),
                'records_accepted' => $this->recordsAccepted,
                'serialization_state' => $this->serializationState,
            ]
        );
    }
}

final class JsonDocumentCodec implements PayloadRecordCodec
{
    public function __construct(
        private bool $associative = true
    ) {
    }

    public function format(): string
    {
        return 'json';
    }

    public function mediaType(): string
    {
        return 'application/json';
    }

    public function framing(): string
    {
        return 'single_document';
    }

    public function replayStrategy(): string
    {
        return 'replay_document';
    }

    public function decodePayload(string $payload): mixed
    {
        try {
            return json_decode($payload, $this->associative, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('json document codec could not decode the payload.', previous: $error);
        }
    }

    public function encodePayload(mixed $record): string
    {
        try {
            return (string) json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (JsonException $error) {
            throw new RuntimeException('json document codec could not encode the record.', previous: $error);
        }
    }
}

final class NdjsonCodec implements LineRecordCodec
{
    public function __construct(
        private bool $associative = true
    ) {
    }

    public function format(): string
    {
        return 'ndjson';
    }

    public function mediaType(): string
    {
        return 'application/x-ndjson';
    }

    public function framing(): string
    {
        return 'line_delimited';
    }

    public function delimiter(): string
    {
        return "\n";
    }

    /**
     * @param array<string,mixed> $state
     */
    public function decodeLine(string $line, int $lineNumber, array &$state): LineDecodeResult
    {
        if (trim($line) === '') {
            return LineDecodeResult::skip();
        }

        try {
            return LineDecodeResult::emit(
                json_decode($line, $this->associative, 512, JSON_THROW_ON_ERROR)
            );
        } catch (JsonException $error) {
            throw new RuntimeException(
                'ndjson codec could not decode line ' . $lineNumber . '.',
                previous: $error
            );
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    public function encodeRecord(mixed $record, int $recordNumber, array &$state): string
    {
        try {
            return (string) json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ) . "\n";
        } catch (JsonException $error) {
            throw new RuntimeException(
                'ndjson codec could not encode record ' . $recordNumber . '.',
                previous: $error
            );
        }
    }
}

final class CsvCodec implements LineRecordCodec
{
    /** @var list<string> */
    private array $header;

    /**
     * @param list<string> $header
     */
    public function __construct(
        array $header = [],
        private bool $expectHeader = true,
        private string $delimiter = ',',
        private string $enclosure = '"',
        private string $escape = '\\'
    ) {
        $this->header = array_values($header);
    }

    public function format(): string
    {
        return 'csv';
    }

    public function mediaType(): string
    {
        return 'text/csv';
    }

    public function framing(): string
    {
        return 'line_delimited';
    }

    public function delimiter(): string
    {
        return "\n";
    }

    /**
     * @param array<string,mixed> $state
     */
    public function decodeLine(string $line, int $lineNumber, array &$state): LineDecodeResult
    {
        $row = str_getcsv($line, $this->delimiter, $this->enclosure, $this->escape);
        if (!is_array($row)) {
            throw new RuntimeException('csv codec could not parse line ' . $lineNumber . '.');
        }

        $header = $this->headerFromState($state);
        if ($header === [] && $this->expectHeader) {
            $state['header'] = array_values(array_map('strval', $row));
            return LineDecodeResult::skip();
        }

        if ($header === []) {
            return LineDecodeResult::emit($row);
        }

        if (count($row) !== count($header)) {
            throw new RuntimeException(
                'csv codec line ' . $lineNumber . ' column count does not match the established header.'
            );
        }

        return LineDecodeResult::emit(array_combine($header, $row));
    }

    /**
     * @param array<string,mixed> $state
     */
    public function encodeRecord(mixed $record, int $recordNumber, array &$state): string
    {
        $header = $this->headerFromState($state);
        if ($header === []) {
            $header = $this->deriveHeader($record);
            $state['header'] = $header;
        }

        $payload = '';
        if ($this->expectHeader && !($state['header_written'] ?? false)) {
            $payload .= $this->toCsvLine($header) . "\n";
            $state['header_written'] = true;
        }

        $payload .= $this->toCsvLine($this->valuesForHeader($record, $header, $recordNumber)) . "\n";

        return $payload;
    }

    /**
     * @param array<string,mixed> $state
     * @return list<string>
     */
    private function headerFromState(array $state): array
    {
        $header = is_array($state['header'] ?? null) ? $state['header'] : $this->header;

        return array_values(array_map('strval', $header));
    }

    /**
     * @return list<string>
     */
    private function deriveHeader(mixed $record): array
    {
        if (!is_array($record)) {
            throw new InvalidArgumentException('csv codec can only derive a header from array records.');
        }

        $keys = array_keys($record);
        $stringKeys = array_map('strval', $keys);

        if ($keys === range(0, count($keys) - 1)) {
            throw new InvalidArgumentException('csv codec requires an explicit header for numeric-list records.');
        }

        return $stringKeys;
    }

    /**
     * @param list<string> $header
     * @return list<string>
     */
    private function valuesForHeader(mixed $record, array $header, int $recordNumber): array
    {
        if (!is_array($record)) {
            throw new InvalidArgumentException('csv codec can only encode array records.');
        }

        if (array_is_list($record)) {
            if (count($record) !== count($header)) {
                throw new InvalidArgumentException(
                    'csv codec record ' . $recordNumber . ' does not match the configured header width.'
                );
            }

            return array_map(static fn(mixed $value): string => (string) $value, $record);
        }

        $values = [];
        foreach ($header as $column) {
            if (!array_key_exists($column, $record)) {
                throw new InvalidArgumentException(
                    'csv codec record ' . $recordNumber . ' is missing expected column "' . $column . '".'
                );
            }

            $values[] = (string) $record[$column];
        }

        $extraColumns = array_diff(array_map('strval', array_keys($record)), $header);
        if ($extraColumns !== []) {
            throw new InvalidArgumentException(
                'csv codec record ' . $recordNumber . ' carries columns outside the established header.'
            );
        }

        return $values;
    }

    /**
     * @param list<string> $fields
     */
    private function toCsvLine(array $fields): string
    {
        $stream = fopen('php://temp', 'w+');
        if (!is_resource($stream)) {
            throw new RuntimeException('csv codec could not allocate a formatting stream.');
        }

        try {
            fputcsv($stream, $fields, $this->delimiter, $this->enclosure, $this->escape);
            rewind($stream);

            return rtrim((string) stream_get_contents($stream), "\r\n");
        } finally {
            fclose($stream);
        }
    }
}

final class ProtoSchemaCodec implements PayloadRecordCodec
{
    public function __construct(
        private string $schema,
        private mixed $decodeClassMap = true
    ) {
    }

    public function format(): string
    {
        return 'proto';
    }

    public function mediaType(): string
    {
        return 'application/x-protobuf';
    }

    public function framing(): string
    {
        return 'single_payload';
    }

    public function replayStrategy(): string
    {
        return 'replay_payload';
    }

    public function decodePayload(string $payload): mixed
    {
        return \king_proto_decode($this->schema, $payload, $this->decodeClassMap);
    }

    public function encodePayload(mixed $record): string
    {
        return (string) \king_proto_encode($this->schema, $record);
    }
}

final class IibinSchemaCodec implements PayloadRecordCodec
{
    public function __construct(
        private string $schema,
        private mixed $decodeClassMap = true
    ) {
    }

    public function format(): string
    {
        return 'iibin';
    }

    public function mediaType(): string
    {
        return 'application/x-iibin';
    }

    public function framing(): string
    {
        return 'single_payload';
    }

    public function replayStrategy(): string
    {
        return 'replay_payload';
    }

    public function decodePayload(string $payload): mixed
    {
        return \King\IIBIN::decode($this->schema, $payload, $this->decodeClassMap);
    }

    public function encodePayload(mixed $record): string
    {
        return (string) \King\IIBIN::encode($this->schema, $record);
    }
}

final class BinaryObjectPayload
{
    /** @var array<string,mixed> */
    private array $attributes;

    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(
        private string $payload,
        array $attributes = []
    ) {
        $this->attributes = $attributes;
    }

    public function payload(): string
    {
        return $this->payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }
}

final class BinaryObjectCodec implements PayloadRecordCodec
{
    public function __construct(
        private string $mediaTypeValue = 'application/octet-stream'
    ) {
    }

    public function format(): string
    {
        return 'binary_object';
    }

    public function mediaType(): string
    {
        return $this->mediaTypeValue;
    }

    public function framing(): string
    {
        return 'single_payload';
    }

    public function replayStrategy(): string
    {
        return 'replay_payload';
    }

    public function decodePayload(string $payload): mixed
    {
        return new BinaryObjectPayload($payload);
    }

    public function encodePayload(mixed $record): string
    {
        if ($record instanceof BinaryObjectPayload) {
            return $record->payload();
        }

        if (is_string($record)) {
            return $record;
        }

        throw new InvalidArgumentException('binary object codec expects a string payload or BinaryObjectPayload.');
    }
}
