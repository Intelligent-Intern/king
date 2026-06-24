# XSLT 2.0/3.0 fuer E-Rechnungen

Die native King-XSLT-Schnittstelle ist aktuell procedural:
`king_xslt_engine_status()`, `king_xslt_transform_file()` und
`king_xslt_transform_to_file()`. Es gibt noch keine exportierte
`King\Xslt` Klasse. Die OO-Beispiele unten sind deshalb bewusst als
userland Adapter gekennzeichnet.

## Function, Beispiel 1: Engine pruefen

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

## Function, Beispiel 2: UBL 2.1 Invoice in Pruefbericht transformieren

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

## OO, Beispiel 1: userland Adapter fuer eine Transformation

```php
<?php
final class XsltProcessor
{
    public function transform(string $xml, string $xsl): string
    {
        $result = king_xslt_transform_file($xml, $xsl);
        return $result['result'];
    }
}

$processor = new XsltProcessor();
echo $processor->transform(__DIR__ . '/invoice.xml', __DIR__ . '/ubl-summary.xsl');
```

## OO, Beispiel 2: UBL Validator Service mit Ausgabedatei

```php
<?php
final class UblXsltValidationService
{
    public function validateToFile(string $invoicePath, string $reportPath): array
    {
        $stylesheet = __DIR__ . '/ubl-validation-report.xsl';

        $result = king_xslt_transform_to_file(
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

$service = new UblXsltValidationService();
$report = $service->validateToFile(
    __DIR__ . '/invoice.xml',
    __DIR__ . '/invoice-validation-report.xml'
);

var_dump($report);
```
