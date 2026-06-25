# XSLT 2.0/3.0 for E-Invoicing

XSLT is available procedurally through `king_xslt_engine_status()`,
`king_xslt_transform_file()`, and `king_xslt_transform_to_file()`. The native
OO surface is `King\XSLT\Processor`. The processor stores default options such
as SaxonC properties and can override them per transformation run.

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
- `properties`: associative array of SaxonC string properties. Scalar values
  and null are accepted and converted to strings before the native call.

The source XML and stylesheet paths must resolve to readable local files.
Directories are rejected before SaxonC is invoked.

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
            'http://saxon.sf.net/feature/version-warning' => 'false',
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
        'http://saxon.sf.net/feature/version-warning' => 'false',
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
            ['properties' => ['indent' => 'yes']]
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
