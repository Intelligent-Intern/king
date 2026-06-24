# Object Store

Der Object Store speichert Payloads, Metadaten, Streams, Backups und
resumable Uploads. Procedural heisst die API `king_object_store_*`, OO
heisst sie `King\ObjectStore`.

## Function, Beispiel 1: speichern und lesen

```php
<?php
king_object_store_init([
    'primary_backend' => 'local_fs',
    'storage_root_path' => __DIR__ . '/var/object-store',
    'max_storage_size_bytes' => 1024 * 1024 * 1024,
]);

king_object_store_put(
    'tenant-42/invoices/INV-1001.xml',
    file_get_contents(__DIR__ . '/invoice.xml'),
    [
        'content_type' => 'application/xml',
        'object_type' => 'einvoice',
        'cache_ttl_sec' => 3600,
    ]
);

$metadata = king_object_store_get_metadata('tenant-42/invoices/INV-1001.xml');
$payload = king_object_store_get('tenant-42/invoices/INV-1001.xml');

var_dump($metadata, strlen($payload));
```

## Function, Beispiel 2: Stream, Backup und Restore

```php
<?php
$source = fopen(__DIR__ . '/large-invoice.xml', 'rb');
king_object_store_put_from_stream(
    'tenant-42/invoices/large.xml',
    $source,
    ['content_type' => 'application/xml']
);
fclose($source);

king_object_store_backup_object(
    'tenant-42/invoices/large.xml',
    __DIR__ . '/var/backups'
);

king_object_store_delete('tenant-42/invoices/large.xml');

king_object_store_restore_object(
    'tenant-42/invoices/large.xml',
    __DIR__ . '/var/backups'
);

$out = fopen(__DIR__ . '/restored-large.xml', 'wb');
king_object_store_get_to_stream('tenant-42/invoices/large.xml', $out);
fclose($out);
```

## OO, Beispiel 1: ObjectStore

```php
<?php
use King\ObjectStore;

ObjectStore::init([
    'primary_backend' => 'local_fs',
    'storage_root_path' => __DIR__ . '/var/object-store',
]);

ObjectStore::put('tenant-42/readme.txt', 'ready', ['content_type' => 'text/plain']);
echo ObjectStore::get('tenant-42/readme.txt') . PHP_EOL;
```

## OO, Beispiel 2: resumable Upload

```php
<?php
use King\ObjectStore;

$session = ObjectStore::beginResumableUpload(
    'tenant-42/invoices/bulk.zip',
    ['content_type' => 'application/zip']
);

foreach (glob(__DIR__ . '/chunks/*.part') as $path) {
    $stream = fopen($path, 'rb');
    ObjectStore::appendResumableUploadChunk($session['upload_id'], $stream);
    fclose($stream);
}

$complete = ObjectStore::completeResumableUpload($session['upload_id']);
$stats = ObjectStore::getStats();

var_dump($complete, $stats);
```
