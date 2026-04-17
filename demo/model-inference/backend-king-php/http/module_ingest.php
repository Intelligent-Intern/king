<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/retrieval/document_store.php';
require_once __DIR__ . '/../domain/retrieval/text_chunker.php';

function model_inference_handle_ingest_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path === '/api/documents') {
        if ($method === 'GET') {
            return model_inference_handle_documents_list($openDatabase, $jsonResponse);
        }
        if ($method === 'POST') {
            return model_inference_handle_document_create($request, $openDatabase, $jsonResponse, $errorResponse);
        }
        return $errorResponse(405, 'method_not_allowed', 'GET or POST required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET', 'POST'],
        ]);
    }

    if (preg_match('#^/api/documents/(doc-[a-f0-9]{16})/chunks$#', $path, $m)) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path,
                'method' => $method,
                'allowed' => ['GET'],
            ]);
        }
        return model_inference_handle_document_chunks($m[1], $openDatabase, $jsonResponse, $errorResponse);
    }

    if (preg_match('#^/api/documents/(doc-[a-f0-9]{16})$#', $path, $m)) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path,
                'method' => $method,
                'allowed' => ['GET'],
            ]);
        }
        return model_inference_handle_document_get($m[1], $openDatabase, $jsonResponse, $errorResponse);
    }

    return null;
}

function model_inference_handle_documents_list(callable $openDatabase, callable $jsonResponse): array
{
    $pdo = $openDatabase();
    $items = model_inference_document_list($pdo);
    return $jsonResponse(200, [
        'status' => 'ok',
        'items' => $items,
        'count' => count($items),
        'time' => gmdate('c'),
    ]);
}

function model_inference_handle_document_create(
    array $request,
    callable $openDatabase,
    callable $jsonResponse,
    callable $errorResponse
): array {
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/documents requires a plain text body.', [
            'field' => 'body',
            'reason' => 'empty_body',
        ]);
    }

    $maxBytes = 10 * 1024 * 1024;
    if (strlen($body) > $maxBytes) {
        return $errorResponse(413, 'document_too_large', 'Document exceeds 10 MB limit.', [
            'field' => 'body',
            'reason' => 'exceeds_max_bytes',
            'observed' => strlen($body),
            'max_bytes' => $maxBytes,
        ]);
    }

    $pdo = $openDatabase();

    try {
        $envelope = model_inference_document_ingest($pdo, $body);
    } catch (Throwable $error) {
        return $errorResponse(500, 'internal_server_error', 'Document ingestion failed.', [
            'reason' => $error->getMessage(),
        ]);
    }

    try {
        $chunks = model_inference_chunk_text($body, $envelope['document_id']);
        if (count($chunks) > 0) {
            model_inference_chunk_persist($pdo, $chunks);
            model_inference_chunk_store_texts($chunks);
        }
        $envelope['chunk_count'] = count($chunks);
    } catch (Throwable $chunkError) {
        $envelope['chunk_count'] = 0;
        $envelope['chunk_warning'] = 'chunking failed: ' . $chunkError->getMessage();
    }

    return $jsonResponse(201, [
        'status' => 'created',
        'document' => $envelope,
        'time' => gmdate('c'),
    ]);
}

function model_inference_handle_document_chunks(
    string $documentId,
    callable $openDatabase,
    callable $jsonResponse,
    callable $errorResponse
): array {
    $pdo = $openDatabase();
    $doc = model_inference_document_get($pdo, $documentId);
    if ($doc === null) {
        return $errorResponse(404, 'document_not_found', 'No document found with this ID.', [
            'document_id' => $documentId,
        ]);
    }

    $chunks = model_inference_chunk_list_by_document($pdo, $documentId);
    return $jsonResponse(200, [
        'status' => 'ok',
        'document_id' => $documentId,
        'items' => $chunks,
        'count' => count($chunks),
        'time' => gmdate('c'),
    ]);
}

function model_inference_handle_document_get(
    string $documentId,
    callable $openDatabase,
    callable $jsonResponse,
    callable $errorResponse
): array {
    $pdo = $openDatabase();
    $doc = model_inference_document_get($pdo, $documentId);
    if ($doc === null) {
        return $errorResponse(404, 'document_not_found', 'No document found with this ID.', [
            'document_id' => $documentId,
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'document' => $doc,
        'time' => gmdate('c'),
    ]);
}
