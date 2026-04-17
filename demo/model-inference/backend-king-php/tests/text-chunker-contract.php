<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/retrieval/document_store.php';
require_once __DIR__ . '/../domain/retrieval/text_chunker.php';

function text_chunker_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[text-chunker-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Basic chunking: 100-byte text, chunk_size=30, overlap=10.
    $text = str_repeat('a', 100);
    $chunks = model_inference_chunk_text($text, 'doc-0000000000000001', ['chunk_size' => 30, 'overlap' => 10]);
    text_chunker_contract_assert(count($chunks) > 0, 'must produce at least one chunk');
    text_chunker_contract_assert(count($chunks) === 5, 'step=20, text=100 → 5 chunks (got ' . count($chunks) . ')');
    $rulesAsserted += 2;

    // 2. First chunk starts at offset 0.
    text_chunker_contract_assert($chunks[0]['byte_offset'] === 0, 'first chunk byte_offset must be 0');
    text_chunker_contract_assert($chunks[0]['sequence'] === 0, 'first chunk sequence must be 0');
    $rulesAsserted += 2;

    // 3. Chunk IDs follow the format chk-{8hex}-{4digit}.
    foreach ($chunks as $i => $chunk) {
        text_chunker_contract_assert(
            preg_match('/^chk-[a-f0-9]{8}-\d{4}$/', $chunk['chunk_id']) === 1,
            "chunk[$i] ID must match chk-{8hex}-{4digit} (got {$chunk['chunk_id']})"
        );
        $rulesAsserted++;
    }

    // 4. Overlap: consecutive chunks overlap by the specified amount.
    for ($i = 1; $i < count($chunks); $i++) {
        $prevEnd = $chunks[$i - 1]['byte_offset'] + $chunks[$i - 1]['byte_length'];
        $currStart = $chunks[$i]['byte_offset'];
        $actualOverlap = $prevEnd - $currStart;
        text_chunker_contract_assert(
            $actualOverlap === 10,
            "overlap between chunk[" . ($i - 1) . "] and chunk[$i] must be 10 (got $actualOverlap)"
        );
        $rulesAsserted++;
    }

    // 5. Each chunk's text matches the expected slice.
    foreach ($chunks as $i => $chunk) {
        $expected = substr($text, $chunk['byte_offset'], $chunk['byte_length']);
        text_chunker_contract_assert(
            $chunk['text'] === $expected,
            "chunk[$i] text must match the source slice"
        );
        $rulesAsserted++;
    }

    // 6. Metadata is populated correctly.
    foreach ($chunks as $i => $chunk) {
        text_chunker_contract_assert($chunk['metadata']['strategy'] === 'fixed_size', "chunk[$i] strategy must be fixed_size");
        text_chunker_contract_assert($chunk['metadata']['chunk_size'] === 30, "chunk[$i] chunk_size must be 30");
        text_chunker_contract_assert($chunk['metadata']['overlap'] === 10, "chunk[$i] overlap must be 10");
        $rulesAsserted += 3;
    }

    // 7. document_id is preserved.
    foreach ($chunks as $i => $chunk) {
        text_chunker_contract_assert($chunk['document_id'] === 'doc-0000000000000001', "chunk[$i] document_id must be preserved");
        $rulesAsserted++;
    }

    // 8. Empty text produces zero chunks.
    $emptyChunks = model_inference_chunk_text('', 'doc-0000000000000002');
    text_chunker_contract_assert(count($emptyChunks) === 0, 'empty text must produce 0 chunks');
    $rulesAsserted++;

    // 9. Text shorter than chunk_size produces exactly one chunk.
    $shortChunks = model_inference_chunk_text('hello', 'doc-0000000000000003', ['chunk_size' => 512]);
    text_chunker_contract_assert(count($shortChunks) === 1, 'text shorter than chunk_size must produce exactly 1 chunk');
    text_chunker_contract_assert($shortChunks[0]['text'] === 'hello', 'single chunk text must be the full input');
    $rulesAsserted += 2;

    // 10. No overlap (overlap=0) produces non-overlapping chunks.
    $noOverlap = model_inference_chunk_text(str_repeat('b', 60), 'doc-0000000000000004', ['chunk_size' => 20, 'overlap' => 0]);
    text_chunker_contract_assert(count($noOverlap) === 3, 'no-overlap: 60 bytes / 20 chunk_size = 3 chunks');
    text_chunker_contract_assert($noOverlap[1]['byte_offset'] === 20, 'no-overlap: second chunk starts at 20');
    $rulesAsserted += 2;

    // 11. Invalid parameters rejected.
    $rejectedOverlap = false;
    try {
        model_inference_chunk_text('test', 'doc-0000000000000005', ['chunk_size' => 10, 'overlap' => 10]);
    } catch (InvalidArgumentException $e) {
        $rejectedOverlap = true;
    }
    text_chunker_contract_assert($rejectedOverlap, 'overlap >= chunk_size must be rejected');
    $rulesAsserted++;

    $rejectedSize = false;
    try {
        model_inference_chunk_text('test', 'doc-0000000000000006', ['chunk_size' => 0]);
    } catch (InvalidArgumentException $e) {
        $rejectedSize = true;
    }
    text_chunker_contract_assert($rejectedSize, 'chunk_size < 1 must be rejected');
    $rulesAsserted++;

    // 12. Schema migration creates chunks table.
    $dbPath = sys_get_temp_dir() . '/chunker-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_document_schema_migrate($pdo);
        model_inference_chunk_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(chunks)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        foreach (['chunk_id', 'document_id', 'sequence', 'byte_offset', 'byte_length', 'char_length', 'strategy', 'chunk_size', 'overlap', 'created_at'] as $col) {
            text_chunker_contract_assert(in_array($col, $columnNames, true), "chunks table must have {$col} column");
            $rulesAsserted++;
        }

        // 13. Chunk persistence round-trip.
        $pdo->exec("INSERT INTO documents (document_id, object_store_key, byte_length, sha256_hex, content_type, ingested_at) VALUES ('doc-0000000000000001', 'doc-0000000000000001', 100, '" . str_repeat('a', 64) . "', 'text/plain', '" . gmdate('c') . "')");
        model_inference_chunk_persist($pdo, $chunks);
        $loaded = model_inference_chunk_list_by_document($pdo, 'doc-0000000000000001');
        text_chunker_contract_assert(count($loaded) === count($chunks), 'persisted chunk count must match');
        text_chunker_contract_assert($loaded[0]['chunk_id'] === $chunks[0]['chunk_id'], 'first loaded chunk_id must match');
        text_chunker_contract_assert($loaded[0]['sequence'] === 0, 'first loaded chunk sequence must be 0');
        $rulesAsserted += 3;
    } finally {
        @unlink($dbPath);
    }

    // 14. Defaults: chunk_size=512, overlap=64.
    $defaultChunks = model_inference_chunk_text(str_repeat('x', 1024), 'doc-0000000000000007');
    text_chunker_contract_assert($defaultChunks[0]['metadata']['chunk_size'] === 512, 'default chunk_size must be 512');
    text_chunker_contract_assert($defaultChunks[0]['metadata']['overlap'] === 64, 'default overlap must be 64');
    $rulesAsserted += 2;

    fwrite(STDOUT, "[text-chunker-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[text-chunker-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
