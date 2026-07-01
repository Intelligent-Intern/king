# IIBIN

IIBIN is King's native binary serialization format. The procedural API is
`king_proto_*`; the native OO facade is `King\IIBIN`.

## Internal Layout

The public C contract lives under `extension/include/iibin/`. The module
umbrella is `extension/include/iibin/index.h`, the procedural declarations are
in `extension/include/iibin/iibin.h`, and the binding arginfo is pulled through
`extension/include/iibin/arginfo/index.h`.

The PHP binding metadata is owned by the module under `extension/include/iibin/`,
including `arginfo/`, `function_entries.h`, `class_method_entries.h`, and
`class_methods.h`. `extension/src/php_king.c` consumes those declarations
through `extension/include/php_king/` and only keeps the central extension
bootstrap and shared function table assembly.

## Function, Example 1: Schema, Encode, Decode

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

## Function, Example 2: Oneof and Batch

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

## OO, Example 1: King\IIBIN

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

## OO, Example 2: Object Hydration

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
