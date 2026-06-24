# DB Ingest

`king_db_ingest()` executes a writer callback under a host-local exclusive
lock. This is useful for small local write paths where multiple processes may
touch the same local state.

## Function, Example 1: Append to a JSONL Ledger Atomically

```php
<?php
$result = king_db_ingest('invoice-ledger', static function (): array {
    $path = __DIR__ . '/var/invoice-ledger.jsonl';
    $entry = ['invoice_id' => 'INV-1001', 'received_at' => date(DATE_ATOM)];

    file_put_contents(
        $path,
        json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    return $entry;
}, [
    'lock_path' => __DIR__ . '/var/invoice-ledger.lock',
    'timeout_ms' => 1500,
]);

var_dump($result);
```

## Function, Example 2: Protect a SQLite Write Transaction

```php
<?php
king_db_ingest('invoice-sqlite', static function (): int {
    $db = new PDO('sqlite:' . __DIR__ . '/var/invoices.sqlite');
    $db->exec('CREATE TABLE IF NOT EXISTS invoices (id TEXT PRIMARY KEY, status TEXT)');

    $stmt = $db->prepare('INSERT OR REPLACE INTO invoices (id, status) VALUES (?, ?)');
    $stmt->execute(['INV-1002', 'accepted']);

    return $stmt->rowCount();
}, [
    'lock_path' => __DIR__ . '/var/invoices.sqlite.lock',
    'timeout_ms' => 3000,
    'poll_us' => 10000,
]);
```

## OO, Example 1: Ingest Adapter

```php
<?php
final class LockedIngest
{
    public function write(string $name, callable $writer, string $lockPath): mixed
    {
        return king_db_ingest($name, $writer, [
            'lock_path' => $lockPath,
            'timeout_ms' => 2000,
        ]);
    }
}

$ingest = new LockedIngest();
$ingest->write('audit', fn () => file_put_contents(__DIR__ . '/var/audit.log', "ok\n", FILE_APPEND), __DIR__ . '/var/audit.lock');
```

## OO, Example 2: Repository with Protected Writer

```php
<?php
final class InvoiceLedgerRepository
{
    public function __construct(private LockedIngest $ingest, private string $root) {}

    public function append(array $entry): array
    {
        return $this->ingest->write(
            'invoice-ledger',
            function () use ($entry): array {
                $line = json_encode($entry, JSON_THROW_ON_ERROR) . PHP_EOL;
                file_put_contents($this->root . '/invoice-ledger.jsonl', $line, FILE_APPEND);

                return $entry;
            },
            $this->root . '/invoice-ledger.lock'
        );
    }
}

$repo = new InvoiceLedgerRepository(new LockedIngest(), __DIR__ . '/var');
$repo->append(['invoice_id' => 'INV-1003', 'status' => 'accepted']);
```
