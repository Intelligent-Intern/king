# XSLT 2.0/3.0 for E-Invoicing

XSLT is available procedurally through `king_xslt_engine_status()`,
`king_xslt_transform_file()`, and `king_xslt_transform_to_file()`. The native
OO surface is `King\XSLT\Processor`. The processor stores default options such
as serialization properties and can override them per transformation run.

## Native runtime and isolated setup

King uses the **SaxonC core 13 C ABI**, tested with SaxonC-HE 13.1.1 on
Linux x86_64 and PHP 8.4 NTS. The former convenience C wrapper was not a usable
error boundary: its `c_getErrorMessage()` returned NULL even on failures. King
now initializes an actual Graal isolate and Saxon processor and reads the
structured `saxon_error_t` returned by the core transformation. It does not
start Java, a subprocess, or a shell transformer.

```bash
make build
bash infra/scripts/bootstrap-saxonc.sh
bin/king-xslt-php -r 'var_export(king_xslt_engine_status());'
bin/king-xslt-php your-validator.php
```

The bootstrap verifies a pinned SHA-256, stages the native library and all
vendor notices under ignored `extension/build/runtime/saxonc/`, and never
changes the system PHP configuration. The wrapper uses `php -n` and the local
King module. For a different runtime directory set `KING_SAXONC_LIBRARY` to
`SaxonCCoreHE/lib/libsaxonc-core-he.so` from the vendor distribution. `SAXONC_HOME`
may refer to either the distribution root or the library directory. Automatic
bootstrap is currently limited to Linux x86_64; other platforms require a
matching SaxonC 13 core distribution. Do not assume older SaxonC C ABIs work.

`engineStatus()` reports `available=false` with a concrete reason when the
library or symbols cannot be loaded. It reports `abi=saxonc-core-13`, the
loaded path and product after a real isolate and processor have initialized.
The explicit `KING_SAXONC_LIBRARY` setting fails closed instead of silently
falling back to a different installation. The vendor product string omits the
patch level; the bootstrap pins 13.1.1 separately.

## Resource policy and validation boundary

Every transformation forbids network URI protocols, external extension
functions and secondary `xsl:result-document` writes. The native XML parser
rejects all DOCTYPE declarations, including UTF-16 input; it cannot expand
external entities from uploaded invoices. Configuration failure throws rather
than continuing with weaker settings. Serialization options cannot override
these settings. Native compiler/parser/runtime failures throw
`King\ValidationException` with available code, system ID and line information.
SaxonC can also emit diagnostics to stderr; applications must keep service
logs private.

**This is not a filesystem sandbox.** Local stylesheet imports and `doc()`
reads remain possible. Supply only application-owned, hash-pinned stylesheets;
never let an invoice select or upload its own stylesheet. Confine the worker
with a read-only rules mount, no secrets, restricted writable temporary/output
storage, no network, memory/CPU limits and a process timeout. King does not
provide an `allowedReadPaths` option; unsupported options are rejected. XML
preflight and rule-package verification in the invoice library are independent
additional checks.

`ok=true` means the transformation completed; **it does not mean a valid
invoice**. Interpret SVRL `failed-assert` and configured severity rules in the
invoice validator. Keep schema validation, Schematron validation, profile
selection and business approval separate.

`transformToFile()` materializes the native result, writes a private 0600
sibling temporary file, and renames it after successful completion. On failure
it removes temporary files and preserves an existing report. Results are
copied into PHP memory before the isolate is torn down; 100 repeated
status/transform lifecycle cycles were verified without changing system PHP.

## Internal Layout

The native XSLT runtime and object contracts live in
`extension/include/xslt/xslt.h` and are exported through
`extension/include/xslt/index.h`. Runtime code lives under
`extension/src/xslt/`; `extension/src/xslt/php_binding.inc` is the PHP userland
binding. PHP registration metadata lives under `extension/include/xslt/`,
including `arginfo/`, `function_entries.h`, `class_method_entries.h`, and
`class_methods.h`.

## Options

Supported transformation options are deliberately explicit:

- `cwd`: readable and searchable local directory used as the SaxonC working
  directory. When it is omitted, King uses the stylesheet directory.
- `properties`: associative array of serialization properties prefixed with `!`,
  such as `!indent` or `!method`. Scalar values and null are converted to strings.
  Processor configuration, source overrides and arbitrary native properties are
  rejected, so callers cannot weaken the fixed resource policy.

The source XML and stylesheet paths must resolve to readable local files.
Directories are rejected before SaxonC is invoked.
`transformToFile()` writes to a temporary sibling file first and only replaces
the requested output path after SaxonC produced a readable result, so an older
output file cannot be mistaken for a successful transformation.

Unknown options are rejected instead of being silently ignored. Stylesheet
parameters require SaxonC XDM values and are not accepted by this PHP-visible
surface yet.

## Function, Example 1: Check Engine

```php
<?php
$status = king_xslt_engine_status();

if (!($status['available'] ?? false)) {
    throw new RuntimeException($status['error'] ?? 'SaxonC runtime not available');
}

printf(
    "engine=%s product=%s\n",
    $status['engine'],
    $status['product'] ?? $status['loaded_library']
);
```

## Function, Example 2: Transform a UBL 2.1 Invoice into a Validation Report

`invoice.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <cbc:ID>INV-1001</cbc:ID>
  <cbc:IssueDate>2026-06-24</cbc:IssueDate>
  <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>
    <cac:Party><cbc:EndpointID schemeID="9930">DE123456789</cbc:EndpointID></cac:Party>
  </cac:AccountingSupplierParty>
  <cac:LegalMonetaryTotal>
    <cbc:PayableAmount currencyID="EUR">119.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
</Invoice>
```

`ubl-summary.xsl`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="3.0"
    xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
    xmlns:ubl="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
    xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
    xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">
  <xsl:output method="xml" indent="yes"/>
  <xsl:template match="/ubl:Invoice">
    <invoice-report>
      <id><xsl:value-of select="cbc:ID"/></id>
      <issue-date><xsl:value-of select="cbc:IssueDate"/></issue-date>
      <currency><xsl:value-of select="cbc:DocumentCurrencyCode"/></currency>
      <supplier-endpoint><xsl:value-of select="cac:AccountingSupplierParty/cac:Party/cbc:EndpointID"/></supplier-endpoint>
      <payable><xsl:value-of select="cac:LegalMonetaryTotal/cbc:PayableAmount"/></payable>
    </invoice-report>
  </xsl:template>
</xsl:stylesheet>
```

PHP:

```php
<?php
$result = king_xslt_transform_file(
    __DIR__ . '/invoice.xml',
    __DIR__ . '/ubl-summary.xsl',
    [
        'properties' => [
            '!indent' => 'yes',
        ],
    ]
);

file_put_contents(__DIR__ . '/invoice-report.xml', $result['result']);
```

## OO, Example 1: Native King\XSLT\Processor Class

```php
<?php
use King\XSLT\Processor;

$processor = new Processor([
    'properties' => [
        '!indent' => 'yes',
    ],
]);

$status = $processor->engineStatus();
if (!($status['available'] ?? false)) {
    throw new RuntimeException($status['error'] ?? 'SaxonC runtime not available');
}

$result = $processor->transformFile(
    __DIR__ . '/invoice.xml',
    __DIR__ . '/ubl-summary.xsl'
);

echo $result['result'];
```

## OO, Example 2: UBL Validator Service with Output File

```php
<?php
use King\XSLT\Processor;

final class UblXsltValidationService
{
    public function __construct(private Processor $processor)
    {
    }

    public function validateToFile(string $invoicePath, string $reportPath): array
    {
        $stylesheet = __DIR__ . '/ubl-validation-report.xsl';

        $result = $this->processor->transformToFile(
            $invoicePath,
            $stylesheet,
            $reportPath,
            ['properties' => ['!indent' => 'yes']]
        );

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'engine' => $result['engine'] ?? 'saxonc',
            'report_path' => $reportPath,
        ];
    }
}

$processor = new Processor([
    'cwd' => __DIR__,
]);
$service = new UblXsltValidationService($processor);
$report = $service->validateToFile(
    __DIR__ . '/invoice.xml',
    __DIR__ . '/invoice-validation-report.xml'
);

var_dump($report);
```
