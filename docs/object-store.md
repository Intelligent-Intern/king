# Object Store

The object store persists payloads, metadata, streams, backups, and resumable
uploads. The procedural API is `king_object_store_*`; the OO API is
`King\ObjectStore`.

## Function, Example 1: Store and Read

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

## Function, Example 2: Stream, Backup, and Restore

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

## OO, Example 1: ObjectStore

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

## OO, Example 2: Resumable Upload

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
