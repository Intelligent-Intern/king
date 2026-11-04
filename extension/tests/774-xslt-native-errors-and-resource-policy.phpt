--TEST--
King XSLT propagates native errors, rejects DTD/network/secondary writes, preserves output
--SKIPIF--
<?php
if (!extension_loaded('king') || !function_exists('king_xslt_engine_status')) die('skip King XSLT unavailable');
if (!(king_xslt_engine_status()['available'] ?? false)) die('skip SaxonC core runtime not installed');
?>
--FILE--
<?php
$dir=sys_get_temp_dir().'/king-xslt-secure-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    $xslStart='<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="3.0"><xsl:template match="/">';
    $xslEnd='</xsl:template></xsl:stylesheet>';
    file_put_contents("$dir/source.xml",'<invoice/>');
    file_put_contents("$dir/doctype.xml",'<!DOCTYPE invoice [<!ENTITY secret SYSTEM "file:///etc/hostname">]><invoice>&secret;</invoice>');
    $utf16="\xff\xfe";
    foreach(str_split(file_get_contents("$dir/doctype.xml")) as $char)$utf16.=$char."\0";
    file_put_contents("$dir/doctype16.xml",$utf16);
    file_put_contents("$dir/valid.xsl",$xslStart.'<ok/>'.$xslEnd);
    file_put_contents("$dir/bad.xsl",$xslStart.'<xsl:value-of select="missing-function()"/>'.$xslEnd);
    file_put_contents("$dir/network.xsl",$xslStart.'<xsl:copy-of select="doc(\'https://example.com/blocked\')"/>'.$xslEnd);
    file_put_contents("$dir/secondary.xsl",$xslStart.'<xsl:result-document href="'.$dir.'/forbidden.xml"><bad/></xsl:result-document>'.$xslEnd);
    $p=new King\XSLT\Processor();
    foreach(['doctype','doctype16','bad','network','secondary'] as $test) {
        try{$p->transformFile("$dir/".(str_starts_with($test,'doctype')?$test:'source').'.xml',"$dir/".(str_starts_with($test,'doctype')?'valid':$test).'.xsl');echo "WRONG SUCCESS $test\n";}
        catch(King\ValidationException $e){echo "rejected $test\n";}
    }
    file_put_contents("$dir/out.xml",'old report');
    try{$p->transformToFile("$dir/source.xml","$dir/bad.xsl","$dir/out.xml");}
    catch(King\ValidationException $e){echo 'preserved '.file_get_contents("$dir/out.xml")."\n";}
    echo file_exists("$dir/forbidden.xml")?'WRONG WRITE':'no secondary write',"\n";
    echo count(glob("$dir/*.kingtmp*"))===0?'no temporary leftovers':'WRONG TEMP',"\n";
} finally {foreach(glob("$dir/*") as $f)unlink($f);rmdir($dir);}
?>
--EXPECTF--
%Arejected doctype
%Arejected doctype16
%Arejected bad
%Arejected network
%Arejected secondary
%Apreserved old report
no secondary write
no temporary leftovers
