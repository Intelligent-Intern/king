# IIBIN

IIBIN ist Kings native binaere Serialisierung. Die procedural API heisst
`king_proto_*`, die native OO-Fassade heisst `King\IIBIN`.

## Function, Beispiel 1: Schema, Encode, Decode

```php
<?php
king_proto_define_schema('InvoiceHeader', [
    'id' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'tenant_id' => ['tag' => 2, 'type' => 'int32', 'required' => true],
    'currency' => ['tag' => 3, 'type' => 'string', 'default' => 'EUR'],
]);

$binary = king_proto_encode('InvoiceHeader', [
    'id' => 'INV-1001',
    'tenant_id' => 42,
]);

$decoded = king_proto_decode('InvoiceHeader', $binary);
var_dump($decoded);
```

## Function, Beispiel 2: Oneof und Batch

```php
<?php
king_proto_define_schema('InvoiceEvent', [
    'accepted' => ['tag' => 1, 'type' => 'string', 'oneof' => 'result'],
    'rejected' => ['tag' => 2, 'type' => 'string', 'oneof' => 'result'],
    'source' => ['tag' => 3, 'type' => 'string'],
]);

$records = king_proto_encode_batch('InvoiceEvent', [
    ['accepted' => 'NAV-OK-1001', 'source' => 'nav'],
    ['rejected' => 'INVALID_VAT_SUMMARY', 'source' => 'validator'],
]);

$events = king_proto_decode_batch('InvoiceEvent', $records);
var_dump($events);
```

## OO, Beispiel 1: King\IIBIN

```php
<?php
use King\IIBIN;

IIBIN::defineSchema('TenantRef', [
    'tenant_id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'name' => ['tag' => 2, 'type' => 'string'],
]);

$payload = IIBIN::encode('TenantRef', ['tenant_id' => 42, 'name' => 'Acme GmbH']);
var_dump(IIBIN::decode('TenantRef', $payload));
```

## OO, Beispiel 2: Objekt-Hydration

```php
<?php
use King\IIBIN;

final class InvoiceTotal
{
    public string $id;
    public int $payable_cents;
    public string $currency;
}

IIBIN::defineSchema('InvoiceTotal', [
    'id' => ['tag' => 1, 'type' => 'string', 'required' => true],
    'payable_cents' => ['tag' => 2, 'type' => 'int32', 'required' => true],
    'currency' => ['tag' => 3, 'type' => 'string', 'default' => 'EUR'],
]);

$binary = IIBIN::encode('InvoiceTotal', [
    'id' => 'INV-1004',
    'payable_cents' => 11900,
]);

$invoice = IIBIN::decode('InvoiceTotal', $binary, InvoiceTotal::class);
echo $invoice->id . ' ' . $invoice->payable_cents . ' ' . $invoice->currency . PHP_EOL;
```
