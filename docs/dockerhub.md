# King PHP runtime — IIBIN 2 interoperability update

**`v1.0.13-beta` includes the native King update for Python `iibin` 2.0 and
JavaScript `@intelligentintern/iibin` 2.0's schema codec.** The image bundles
PHP 8.5.12 or later and King 1.0.13-beta for Linux amd64 and arm64.

```bash
docker pull intelligentintern/king:v1.0.13-beta
docker run --rm intelligentintern/king:v1.0.13-beta \
  php -r 'echo "PHP ", PHP_VERSION, " King ", phpversion("king"), PHP_EOL;'
```

The `iibin2` and `php8.5` tags select the same current beta runtime. Use the
versioned tag or image digest when pinning a deployment. A running container
must be recreated after pulling the update.

## What changed

- Full `uint64`/`fixed64` values through `18446744073709551615`, using PHP
  integers or canonical decimal strings above `PHP_INT_MAX`.
- UTF-8, finite-number and field-tag validation aligned with the Python and
  JavaScript schema codecs.
- PHP 8.5.12 or later runtime and an explicit King 1.0.13-beta version inside the image.
- Migration notes in `/opt/king/package/docs/IIBIN-MIGRATION.md`.

**Legacy JavaScript 1.x payloads use different formats.** Use its matching
decoder under `@intelligentintern/iibin/legacy` for old data, or decode, map
the application fields and re-encode using an agreed schema. JavaScript 2.0,
Python 2.0 and King exchange protobuf wire messages with matching field IDs
and types. PHP uses `tag` where Python/JavaScript use `id`.

King already used protobuf wire encoding. This update does not add a PHP
version header or an automatic legacy detector. Select the format through
endpoint negotiation or stored metadata. npm/pip upgrades do not replace
the King binary loaded by PHP; pull and recreate this runtime separately.

## Example

```bash
docker run --rm intelligentintern/king:v1.0.13-beta php -r '
King\IIBIN::defineSchema("Counter", [
    "value" => ["tag" => 1, "type" => "uint64"],
]);
$wire = King\IIBIN::encode("Counter", ["value" => "18446744073709551615"]);
echo bin2hex($wire), PHP_EOL;
var_export(King\IIBIN::decode("Counter", $wire));
'
```

The wire bytes are `08ffffffffffffffffff01`; decode preserves the value as
a PHP decimal string. Python represents this value as `int`, JavaScript as
`bigint`. For interchange, stay within the Python/JavaScript limits of 16 MiB
per frame and nesting depth 32.

This image is a quick way to try King. For host-specific development and
performance work, use the native setup in the
[King repository](https://github.com/Intelligent-Intern/king).
