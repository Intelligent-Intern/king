# CDN

Die CDN-Primitive liegt procedural unter `king_cdn_*` und haengt am
Object-Store/CDN Runtime-State. Eine native `King\CDN` Klasse ist aktuell
nicht exportiert. Die OO-Beispiele unten sind userland Adapter um die echten
Funktionen.

## Function, Beispiel 1: Objekt in CDN Cache aufnehmen

```php
<?php
king_object_store_init([
    'primary_backend' => 'local_fs',
    'storage_root_path' => __DIR__ . '/var/object-store',
    'cdn_config' => [
        'enabled' => true,
        'cache_size_mb' => 128,
        'default_ttl_seconds' => 300,
    ],
]);

king_object_store_put('public/invoice-report.json', '{"ok":true}', [
    'content_type' => 'application/json',
    'cache_ttl_sec' => 300,
]);

if (!king_cdn_cache_object('public/invoice-report.json', ['ttl_sec' => 300])) {
    throw new RuntimeException('object could not be admitted to CDN cache');
}

$stats = king_object_store_get_stats();
var_dump($stats['cdn']);
```

## Function, Beispiel 2: QUERY als erlaubte CDN-Methode konfigurieren

```php
<?php
$config = king_new_config([
    'cdn.enable' => true,
    'cdn.allowed_http_methods' => 'GET,HEAD,QUERY',
]);

$session = king_connect('127.0.0.1', 443, $config);
$stats = king_get_stats($session);

echo $stats['config_cdn_allowed_http_methods'] . PHP_EOL;
king_close($session);

$removed = king_cdn_invalidate_cache();
echo "invalidated=$removed\n";
```

## OO, Beispiel 1: userland CDN Adapter

```php
<?php
final class CdnCache
{
    public function cache(string $objectId, int $ttlSeconds): void
    {
        if (!king_cdn_cache_object($objectId, ['ttl_sec' => $ttlSeconds])) {
            throw new RuntimeException("CDN cache miss or admission failure: $objectId");
        }
    }

    public function invalidate(?string $objectId = null): int
    {
        return king_cdn_invalidate_cache($objectId);
    }
}

$cdn = new CdnCache();
$cdn->cache('public/invoice-report.json', 300);
```

## OO, Beispiel 2: Cache-Warmup Service

```php
<?php
final class InvoiceReportWarmup
{
    public function __construct(private CdnCache $cdn) {}

    /** @param list<string> $objectIds */
    public function warm(array $objectIds): array
    {
        $result = ['cached' => [], 'failed' => []];

        foreach ($objectIds as $objectId) {
            try {
                $this->cdn->cache($objectId, 600);
                $result['cached'][] = $objectId;
            } catch (Throwable $e) {
                $result['failed'][$objectId] = $e->getMessage();
            }
        }

        return $result;
    }
}

$warmup = new InvoiceReportWarmup(new CdnCache());
var_dump($warmup->warm([
    'public/reports/INV-1001.json',
    'public/reports/INV-1002.json',
]));
```
