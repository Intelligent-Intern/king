<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

interface SqlVectorBridgeTransport
{
    /**
     * @param array<string,mixed> $options
     */
    public function request(string $service, string $method, string $payload, array $options = []): string;
}

final class McpResourceSqlVectorTransport implements SqlVectorBridgeTransport
{
    /** @var resource */
    private $connection;

    /**
     * @param resource $connection
     */
    public function __construct($connection)
    {
        if (!is_resource($connection)) {
            throw new InvalidArgumentException('connection must be an MCP connection resource.');
        }

        $this->connection = $connection;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function request(string $service, string $method, string $payload, array $options = []): string
    {
        $response = \king_mcp_request($this->connection, $service, $method, $payload, $options);
        if (!is_string($response)) {
            $error = \king_mcp_get_error();
            throw new RuntimeException(
                'sql-vector MCP request failed: ' . (is_string($error) && $error !== '' ? $error : 'unknown MCP error.')
            );
        }

        return $response;
    }
}

final class SqlVectorSearchRequest
{
    /** @var list<float> */
    private array $queryVector;

    /** @var array<string,mixed> */
    private array $filters;

    /**
     * @param list<int|float> $queryVector
     * @param array<string,mixed> $filters
     */
    public function __construct(
        private string $index,
        array $queryVector,
        private int $limit = 10,
        array $filters = [],
        private ?string $requestId = null
    ) {
        if ($this->index === '') {
            throw new InvalidArgumentException('index must not be empty.');
        }

        if ($this->limit <= 0) {
            throw new InvalidArgumentException('limit must be greater than zero.');
        }

        if ($queryVector === []) {
            throw new InvalidArgumentException('queryVector must not be empty.');
        }

        $normalized = [];
        foreach ($queryVector as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('queryVector entries must be int or float values.');
            }
            $normalized[] = (float) $value;
        }

        $this->queryVector = $normalized;
        $this->filters = $filters;

        if ($this->requestId !== null && $this->requestId === '') {
            throw new InvalidArgumentException('requestId must be null or a non-empty string.');
        }
    }

    public function requestId(): string
    {
        if (is_string($this->requestId) && $this->requestId !== '') {
            return $this->requestId;
        }

        return 'sql-vector-' . bin2hex(random_bytes(8));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'king.sql_vector.query.v1',
            'operation' => 'similarity_search',
            'request_id' => $this->requestId(),
            'index' => $this->index,
            'query_vector' => $this->queryVector,
            'limit' => $this->limit,
            'filters' => $this->filters,
        ];
    }
}

final class SqlVectorMatch
{
    /** @var array<string,mixed> */
    private array $metadata;

    /** @var list<float>|null */
    private ?array $embedding;

    /**
     * @param array<string,mixed> $metadata
     * @param list<float>|null $embedding
     */
    public function __construct(
        private string $id,
        private float $score,
        array $metadata = [],
        ?array $embedding = null
    ) {
        if ($this->id === '') {
            throw new InvalidArgumentException('match id must not be empty.');
        }

        $this->metadata = $metadata;
        $this->embedding = $embedding;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function score(): float
    {
        return $this->score;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return list<float>|null
     */
    public function embedding(): ?array
    {
        return $this->embedding;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'metadata' => $this->metadata,
            'embedding' => $this->embedding,
        ];
    }
}

final class SqlVectorSearchResponse
{
    /** @var list<SqlVectorMatch> */
    private array $matches;

    /** @var array<string,mixed> */
    private array $stats;

    /**
     * @param list<SqlVectorMatch> $matches
     * @param array<string,mixed> $stats
     */
    public function __construct(
        private string $requestId,
        private string $index,
        array $matches,
        array $stats
    ) {
        if ($this->requestId === '') {
            throw new InvalidArgumentException('requestId must not be empty.');
        }

        if ($this->index === '') {
            throw new InvalidArgumentException('index must not be empty.');
        }

        $this->matches = $matches;
        $this->stats = $stats;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function index(): string
    {
        return $this->index;
    }

    /**
     * @return list<SqlVectorMatch>
     */
    public function matches(): array
    {
        return $this->matches;
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => 'king.sql_vector.result.v1',
            'request_id' => $this->requestId,
            'index' => $this->index,
            'matches' => array_map(
                static fn (SqlVectorMatch $match): array => $match->toArray(),
                $this->matches
            ),
            'stats' => $this->stats,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        if (($payload['schema'] ?? null) !== 'king.sql_vector.result.v1') {
            throw new InvalidArgumentException('sql-vector result payload schema must be king.sql_vector.result.v1.');
        }

        $requestId = is_string($payload['request_id'] ?? null) ? $payload['request_id'] : '';
        $index = is_string($payload['index'] ?? null) ? $payload['index'] : '';
        $matchRows = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];
        $stats = is_array($payload['stats'] ?? null) ? $payload['stats'] : [];

        $matches = [];
        foreach ($matchRows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('sql-vector match entries must be arrays.');
            }

            $id = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($id === '') {
                throw new InvalidArgumentException('sql-vector match id must be a non-empty string.');
            }

            $scoreRaw = $row['score'] ?? null;
            if (!is_int($scoreRaw) && !is_float($scoreRaw)) {
                throw new InvalidArgumentException('sql-vector match score must be numeric.');
            }

            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
            $embedding = null;
            if (isset($row['embedding'])) {
                if (!is_array($row['embedding'])) {
                    throw new InvalidArgumentException('sql-vector match embedding must be a numeric list when provided.');
                }

                $embedding = [];
                foreach ($row['embedding'] as $dimension) {
                    if (!is_int($dimension) && !is_float($dimension)) {
                        throw new InvalidArgumentException('sql-vector embedding dimensions must be numeric.');
                    }
                    $embedding[] = (float) $dimension;
                }
            }

            $matches[] = new SqlVectorMatch($id, (float) $scoreRaw, $metadata, $embedding);
        }

        return new self($requestId, $index, $matches, $stats);
    }
}

final class McpSqlVectorBridge
{
    public function __construct(
        private SqlVectorBridgeTransport $transport,
        private string $service = 'sql_vector',
        private string $method = 'search'
    ) {
        if ($this->service === '' || $this->method === '') {
            throw new InvalidArgumentException('service and method must not be empty.');
        }
    }

    /**
     * @param array<string,mixed> $options
     */
    public function search(SqlVectorSearchRequest $request, array $options = []): SqlVectorSearchResponse
    {
        try {
            $payload = json_encode($request->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('sql-vector request payload could not be encoded: ' . $error->getMessage(), 0, $error);
        }

        $rawResponse = $this->transport->request($this->service, $this->method, $payload, $options);

        try {
            $decoded = json_decode($rawResponse, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('sql-vector response payload is not valid JSON: ' . $error->getMessage(), 0, $error);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('sql-vector response payload must decode to an object-like array.');
        }

        return SqlVectorSearchResponse::fromArray($decoded);
    }
}
