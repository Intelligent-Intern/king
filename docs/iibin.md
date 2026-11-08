# IIBIN

> **IIBIN 2.0 interoperability update:** use JavaScript 2.0's `Schema` API and Python 2.0 for native schema messages. Legacy JavaScript 1.x payloads use different wire formats. Updating npm or pip packages does not update the PHP extension; rebuild and deploy the native King update separately.

The native update adds lossless `uint64`/`fixed64` values above `PHP_INT_MAX`
as decimal strings and aligns UTF-8, finite-number and field-tag validation.
King already used protobuf wire encoding; valid existing messages remain in
that format. Inputs previously accepted with invalid UTF-8 or non-finite
floating-point values are now rejected. Large unsigned values previously
rejected on decode now return decimal strings; applications must handle both
PHP integers and those strings. PHP schema APIs (`tag`, named messages,
required/default fields, maps and oneof) continue to have their own API shape.

For a mixed-version rollout, choose the codec through endpoint negotiation or
stored metadata. There is no automatic legacy detector or version header in
the schema payload. Convert old stored JS payloads with the matching legacy
decoder and then encode the mapped values using an explicit schema.

IIBIN is King's native binary serialization format. The procedural API is
`king_proto_*`; the native OO facade is `King\IIBIN`.

## Python and JavaScript wire contract

The native codec uses protobuf wire encoding, matching Python `iibin` 2.0 and
JavaScript `@intelligentintern/iibin` 2.0's `Schema` API. PHP schemas use `tag`
where Python/JavaScript use `id`; a named PHP message type corresponds to a
nested `schema`, and `repeated_int32` corresponds to `type: 'int32', repeated: true`.

Fields encode in ascending tag order. Explicit zero/false/empty scalar values
are preserved. Numeric repeated fields accept packed and unpacked payloads;
unknown fields are skipped and duplicate singular fields use the last value.
String fields require valid UTF-8; `bytes` fields accept arbitrary bytes.
Non-finite float/double values, float32 overflow, invalid field tags and
out-of-range integer encodings are rejected.

`uint64` and `fixed64` accept PHP integers or canonical unsigned decimal
strings from `0` through `18446744073709551615`. Decode returns a PHP integer
when representable, otherwise a decimal string. No float conversion is used.
Python uses `int` and JavaScript uses `bigint` for the same wire values.

For messages exchanged with the Python/JavaScript packages, stay within their
16 MiB frame limit and maximum nesting of 32. King's maps, enum registries,
oneof declarations, required/default fields and named recursive schemas are
additional PHP schema APIs, not features exposed by those packages.

The workspace's `iibin/interop/check.py` verifies fixed wire vectors and every
direction of Python/JavaScript/King decoding. Native regression coverage is in
`extension/tests/716-iibin-cross-language-wire-contract.phpt`.

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
