--TEST--
Repo-local Flow PHP MCP service discovery resolves retrieval embedding and document roles with explicit Semantic-DNS failover order
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/McpServiceDiscovery.php';

use King\Flow\McpServiceDiscovery;
use King\Flow\SemanticDnsServiceDirectory;

var_dump(king_semantic_dns_register_service([
    'service_id' => 'ret-a',
    'service_name' => 'rag-retrieval',
    'service_type' => 'rag_retrieval',
    'hostname' => 'retrieval-a.internal',
    'port' => 7101,
    'status' => 'healthy',
    'current_load_percent' => 5,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'ret-b',
    'service_name' => 'rag-retrieval',
    'service_type' => 'rag_retrieval',
    'hostname' => 'retrieval-b.internal',
    'port' => 7102,
    'status' => 'healthy',
    'current_load_percent' => 35,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'ret-c',
    'service_name' => 'rag-retrieval',
    'service_type' => 'rag_retrieval',
    'hostname' => 'retrieval-c.internal',
    'port' => 7103,
    'status' => 'degraded',
    'current_load_percent' => 2,
]));

var_dump(king_semantic_dns_register_service([
    'service_id' => 'emb-a',
    'service_name' => 'rag-embedding',
    'service_type' => 'rag_embedding',
    'hostname' => 'embedding-a.internal',
    'port' => 7201,
    'status' => 'healthy',
    'current_load_percent' => 12,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'emb-b',
    'service_name' => 'rag-embedding',
    'service_type' => 'rag_embedding',
    'hostname' => 'embedding-b.internal',
    'port' => 7202,
    'status' => 'degraded',
    'current_load_percent' => 3,
]));

var_dump(king_semantic_dns_register_service([
    'service_id' => 'doc-a',
    'service_name' => 'rag-document',
    'service_type' => 'rag_document',
    'hostname' => 'document-a.internal',
    'port' => 7301,
    'status' => 'healthy',
    'current_load_percent' => 9,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'doc-b',
    'service_name' => 'rag-document',
    'service_type' => 'rag_document',
    'hostname' => 'document-b.internal',
    'port' => 7302,
    'status' => 'degraded',
    'current_load_percent' => 1,
]));

$discovery = new McpServiceDiscovery(new SemanticDnsServiceDirectory());

$retrieval = $discovery->resolve('retrieval');
var_dump($retrieval->current()->serviceId() === 'ret-a');
var_dump($retrieval->orderedServiceIds() === ['ret-a', 'ret-b', 'ret-c']);
var_dump($retrieval->current()->endpoint() === 'retrieval-a.internal:7101');
var_dump($retrieval->hasFailoverTarget());

$retrievalFallbackOne = $retrieval->failover('ret-a');
var_dump($retrievalFallbackOne->serviceId() === 'ret-b');
$retrievalFallbackTwo = $retrieval->failover('ret-b');
var_dump($retrievalFallbackTwo->serviceId() === 'ret-c');

$retrievalExhausted = false;
try {
    $retrieval->failover('ret-c');
} catch (RuntimeException $error) {
    $retrievalExhausted = str_contains($error->getMessage(), 'no remaining failover target');
}
var_dump($retrievalExhausted);

$embedding = $discovery->resolve('embedding');
var_dump($embedding->current()->serviceId() === 'emb-a');
var_dump($embedding->orderedServiceIds() === ['emb-a', 'emb-b']);
var_dump($embedding->failover('emb-a')->serviceId() === 'emb-b');

$document = $discovery->resolve('document');
var_dump($document->current()->serviceId() === 'doc-a');
var_dump($document->orderedServiceIds() === ['doc-a', 'doc-b']);
var_dump($document->hasFailoverTarget());
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
