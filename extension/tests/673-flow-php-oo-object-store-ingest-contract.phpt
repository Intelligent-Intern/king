--TEST--
Repo-local Flow PHP OO object-store ingest pattern covers originals extracted artifacts streamed uploads and viewer delivery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/ObjectStoreIngest.php';

use King\Flow\ObjectStoreIngestor;

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

$root = sys_get_temp_dir() . '/king-flow-oo-ingest-' . getmypid();
$cleanupTree($root);
mkdir($root, 0700, true);

var_dump(King\ObjectStore::init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]));

$ingestor = new ObjectStoreIngestor();

$original = fopen('php://temp', 'w+');
fwrite($original, 'ORIGINAL-PDF-DATA');
rewind($original);

$originalObjectId = $ingestor->storeOriginalFromStream('doc-42', $original, [
    'content_type' => 'application/pdf',
    'object_type' => 'document',
    'cache_policy' => 'etag',
]);
var_dump($originalObjectId === 'ingest-original--doc-42');
var_dump(King\ObjectStore::get($originalObjectId) === 'ORIGINAL-PDF-DATA');

$artifactObjectId = $ingestor->storeExtractedArtifact('doc-42', 'text-v1', 'Extracted text for search.', [
    'content_type' => 'text/plain',
    'object_type' => 'document',
    'cache_policy' => 'smart_cdn',
]);
var_dump($artifactObjectId === 'ingest-artifact--doc-42--text-v1');
var_dump(King\ObjectStore::get($artifactObjectId) === 'Extracted text for search.');

$streamed = $ingestor->startStreamedOriginalUpload('video-42', [
    'content_type' => 'video/mp4',
    'object_type' => 'binary_data',
    'cache_policy' => 'etag',
]);
var_dump($streamed->objectId() === 'ingest-original--video-42');
var_dump($streamed->uploadId() !== '');

$chunkA = fopen('php://temp', 'w+');
fwrite($chunkA, 'video-chunk-a');
rewind($chunkA);
$appendA = $streamed->appendChunk($chunkA, false);
var_dump(is_array($appendA));

$chunkB = fopen('php://temp', 'w+');
fwrite($chunkB, 'video-chunk-b');
rewind($chunkB);
$appendB = $streamed->appendChunk($chunkB, true);
var_dump(is_array($appendB));

$complete = $streamed->complete();
var_dump(is_array($complete));
var_dump(King\ObjectStore::get($streamed->objectId()) === 'video-chunk-avideo-chunk-b');

$viewerArtifact = fopen('php://temp', 'w+');
var_dump($ingestor->deliverToViewer($artifactObjectId, $viewerArtifact, 0, 9));
rewind($viewerArtifact);
var_dump(stream_get_contents($viewerArtifact) === 'Extracted');

$viewerOriginal = fopen('php://temp', 'w+');
var_dump($ingestor->deliverToViewer($streamed->objectId(), $viewerOriginal));
rewind($viewerOriginal);
var_dump(stream_get_contents($viewerOriginal) === 'video-chunk-avideo-chunk-b');

$objects = King\ObjectStore::listObjects();
$objectIds = array_map(
    static function (mixed $entry): string {
        if (is_array($entry)) {
            return (string) ($entry['object_id'] ?? '');
        }

        return is_string($entry) ? $entry : '';
    },
    $objects
);
sort($objectIds);
var_dump($objectIds === [
    'ingest-artifact--doc-42--text-v1',
    'ingest-original--doc-42',
    'ingest-original--video-42',
]);

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
