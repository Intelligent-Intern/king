--TEST--
Repo-local Flow PHP canonical RAG orchestration reference flow covers chat ingest parse embed retrieve and worker lifecycle on current King building blocks
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/RagOrchestrationReference.php';

use King\Flow\McpServiceDiscovery;
use King\Flow\ObjectStoreIngestor;
use King\Flow\OrchestratorExecutionBackend;
use King\Flow\RagOrchestrationReference;
use King\Flow\ServiceDirectory;

final class FlowRagTestDirectory implements ServiceDirectory
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $routeByServiceName;

    /**
     * @var array<string,list<array<string,mixed>>>
     */
    private array $discoverByServiceType;

    /**
     * @param array<string,array<string,mixed>> $routeByServiceName
     * @param array<string,list<array<string,mixed>>> $discoverByServiceType
     */
    public function __construct(array $routeByServiceName, array $discoverByServiceType)
    {
        $this->routeByServiceName = $routeByServiceName;
        $this->discoverByServiceType = $discoverByServiceType;
    }

    public function discover(string $serviceType, ?array $criteria = null): array
    {
        $services = $this->discoverByServiceType[$serviceType] ?? [];

        return [
            'services' => $services,
            'service_type' => $serviceType,
            'discovered_at' => time(),
            'service_count' => count($services),
        ];
    }

    public function route(string $serviceName, ?array $clientInfo = null): array
    {
        return $this->routeByServiceName[$serviceName] ?? [
            'error' => 'No healthy service found',
        ];
    }
}

/**
 * @var ObjectStoreIngestor
 */
global $flow_rag_ingestor;

function flow_rag_parse_document(array $context): array
{
    global $flow_rag_ingestor;

    $input = is_array($context['input'] ?? null) ? $context['input'] : [];
    $assetId = (string) ($input['asset_id'] ?? '');
    $query = (string) ($input['query'] ?? '');
    $parsed = 'parsed::' . $query;

    $artifactObjectId = $flow_rag_ingestor->storeExtractedArtifact(
        $assetId,
        'parsed-v1',
        $parsed,
        [
            'content_type' => 'text/plain',
            'object_type' => 'document',
            'cache_policy' => 'smart_cdn',
        ]
    );

    $input['parsed_text'] = $parsed;
    $input['ingest']['artifact_object_id'] = $artifactObjectId;
    $input['service_trace']['document'] = (string) (($input['services']['document']['service_id'] ?? ''));
    $input['history'][] = 'parse';
    $input['worker_lifecycle']['events'][] = 'parse';

    return ['output' => $input];
}

function flow_rag_embed_query(array $context): array
{
    $input = is_array($context['input'] ?? null) ? $context['input'] : [];

    $input['query_vector'] = [0.11, 0.22, 0.33];
    $input['service_trace']['embedding'] = (string) (($input['services']['embedding']['service_id'] ?? ''));
    $input['history'][] = 'embed';
    $input['worker_lifecycle']['events'][] = 'embed';

    return ['output' => $input];
}

function flow_rag_retrieve_context(array $context): array
{
    $input = is_array($context['input'] ?? null) ? $context['input'] : [];
    $query = (string) ($input['query'] ?? '');

    $input['retrieved_context'] = [[
        'id' => 'ctx-1',
        'score' => 0.91,
        'text' => 'Policy context for ' . $query,
    ]];
    $input['service_trace']['retrieval'] = (string) (($input['services']['retrieval']['service_id'] ?? ''));
    $input['history'][] = 'retrieve';
    $input['worker_lifecycle']['events'][] = 'retrieve';

    return ['output' => $input];
}

function flow_rag_generate_answer(array $context): array
{
    $input = is_array($context['input'] ?? null) ? $context['input'] : [];
    $query = (string) ($input['query'] ?? '');
    $contextText = (string) ($input['retrieved_context'][0]['text'] ?? '');

    $input['answer'] = sprintf('Answer for "%s" with context "%s"', $query, $contextText);
    $input['history'][] = 'respond';
    $input['worker_lifecycle']['events'][] = 'respond';

    return ['output' => $input];
}

$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $cleanupTree($path . '/' . $entry);
            }
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
};

$root = sys_get_temp_dir() . '/king-flow-rag-reference-' . getmypid();
$cleanupTree($root);
mkdir($root, 0700, true);

var_dump(King\ObjectStore::init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]));

$directory = new FlowRagTestDirectory(
    [
        'rag-document' => [
            'service_id' => 'doc-a',
            'service_name' => 'rag-document',
            'service_type' => 'rag_document',
            'hostname' => 'document-a.internal',
            'port' => 7301,
            'status' => 'healthy',
            'current_load_percent' => 8,
        ],
        'rag-embedding' => [
            'service_id' => 'emb-a',
            'service_name' => 'rag-embedding',
            'service_type' => 'rag_embedding',
            'hostname' => 'embedding-a.internal',
            'port' => 7201,
            'status' => 'healthy',
            'current_load_percent' => 6,
        ],
        'rag-retrieval' => [
            'service_id' => 'ret-a',
            'service_name' => 'rag-retrieval',
            'service_type' => 'rag_retrieval',
            'hostname' => 'retrieval-a.internal',
            'port' => 7101,
            'status' => 'healthy',
            'current_load_percent' => 4,
        ],
    ],
    [
        'rag_document' => [[
            'service_id' => 'doc-a',
            'service_name' => 'rag-document',
            'service_type' => 'rag_document',
            'hostname' => 'document-a.internal',
            'port' => 7301,
            'status' => 'healthy',
            'current_load_percent' => 8,
        ]],
        'rag_embedding' => [[
            'service_id' => 'emb-a',
            'service_name' => 'rag-embedding',
            'service_type' => 'rag_embedding',
            'hostname' => 'embedding-a.internal',
            'port' => 7201,
            'status' => 'healthy',
            'current_load_percent' => 6,
        ]],
        'rag_retrieval' => [[
            'service_id' => 'ret-a',
            'service_name' => 'rag-retrieval',
            'service_type' => 'rag_retrieval',
            'hostname' => 'retrieval-a.internal',
            'port' => 7101,
            'status' => 'healthy',
            'current_load_percent' => 4,
        ]],
    ]
);

$discovery = new McpServiceDiscovery($directory);
$flow_rag_ingestor = new ObjectStoreIngestor('rag-original', 'rag-artifact');
$reference = new RagOrchestrationReference($flow_rag_ingestor, $discovery);

$backend = new OrchestratorExecutionBackend();
$reference->registerCanonicalTools($backend);
$backend->registerHandler(RagOrchestrationReference::TOOL_PARSE, 'flow_rag_parse_document');
$backend->registerHandler(RagOrchestrationReference::TOOL_EMBED, 'flow_rag_embed_query');
$backend->registerHandler(RagOrchestrationReference::TOOL_RETRIEVE, 'flow_rag_retrieve_context');
$backend->registerHandler(RagOrchestrationReference::TOOL_RESPOND, 'flow_rag_generate_answer');

$originalStream = fopen('php://temp', 'w+');
fwrite($originalStream, 'PDF-ORIGINAL-BLOB-DATA');
rewind($originalStream);

$plan = $reference->preparePlan('asset-674', $originalStream, 'Where is the worker policy?');
var_dump($plan->assetId() === 'asset-674');
var_dump($plan->query() === 'Where is the worker policy?');
var_dump($plan->originalObjectId() === 'rag-original--asset-674');
var_dump(King\ObjectStore::get($plan->originalObjectId()) === 'PDF-ORIGINAL-BLOB-DATA');
var_dump(($plan->serviceEndpoints()['document']['service_id'] ?? null) === 'doc-a');
var_dump(($plan->serviceEndpoints()['embedding']['service_id'] ?? null) === 'emb-a');
var_dump(($plan->serviceEndpoints()['retrieval']['service_id'] ?? null) === 'ret-a');
var_dump(count($plan->pipeline()) === 4);

$run = $reference->run($backend, $plan, ['trace_id' => 'flow-rag-reference-674']);
$snapshot = $run->snapshot();
$payload = is_array($snapshot->payload()) ? $snapshot->payload() : [];

var_dump($run->finalStatus() === 'completed');
var_dump(($payload['history'] ?? null) === ['parse', 'embed', 'retrieve', 'respond']);
var_dump(($payload['service_trace']['document'] ?? null) === 'doc-a');
var_dump(($payload['service_trace']['embedding'] ?? null) === 'emb-a');
var_dump(($payload['service_trace']['retrieval'] ?? null) === 'ret-a');

$artifactObjectId = is_string($payload['ingest']['artifact_object_id'] ?? null) ? $payload['ingest']['artifact_object_id'] : '';
var_dump($artifactObjectId === 'rag-artifact--asset-674--parsed-v1');
var_dump(King\ObjectStore::get($artifactObjectId) === 'parsed::Where is the worker policy?');
var_dump(($payload['retrieved_context'][0]['id'] ?? null) === 'ctx-1');
var_dump(str_contains((string) ($payload['answer'] ?? ''), 'Where is the worker policy?'));
var_dump(str_contains((string) ($payload['answer'] ?? ''), 'Policy context'));

$events = $run->lifecycleEvents();
$firstEvent = $events[0] ?? [];
$lastEvent = $events[count($events) - 1] ?? [];
var_dump(count($events) >= 2);
var_dump(($firstEvent['phase'] ?? null) === 'submitted');
var_dump(($lastEvent['phase'] ?? null) === 'final');
var_dump(($lastEvent['status'] ?? null) === 'completed');
var_dump(($run->capabilities()['backend'] ?? null) === 'local');

$viewer = fopen('php://temp', 'w+');
var_dump($reference->deliverOriginalPreview($plan, $viewer, 0, 8));
rewind($viewer);
var_dump(stream_get_contents($viewer) === 'PDF-ORIG');

$cleanupTree($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
