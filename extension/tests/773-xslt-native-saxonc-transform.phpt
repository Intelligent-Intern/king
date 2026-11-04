--TEST--
King XSLT uses native SaxonC 13 for XSLT 3 and atomic output
--SKIPIF--
<?php
if (!extension_loaded('king') || !function_exists('king_xslt_engine_status')) die('skip King XSLT unavailable');
if (!(king_xslt_engine_status()['available'] ?? false)) die('skip SaxonC core runtime not installed');
?>
--FILE--
<?php
$dir=sys_get_temp_dir().'/king-xslt-'.bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    file_put_contents("$dir/source.xml",'<invoice><amount>500</amount></invoice>');
    file_put_contents("$dir/rules.xsl",'<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="3.0"><xsl:output omit-xml-declaration="yes"/><xsl:template match="/"><out><xsl:value-of select="string-join((1 to 3)!string(.),\',\')"/></out></xsl:template></xsl:stylesheet>');
    $p=new King\XSLT\Processor(['properties'=>['!indent'=>'no']]);
    echo $p->engineStatus()['abi'],"\n";
    for($i=0;$i<20;$i++) {
        $r=$p->transformFile("$dir/source.xml","$dir/rules.xsl");
        if(!$r['ok'] || trim($r['result'])!=='<out>1,2,3</out>') throw new Exception('bad native result');
    }
    echo "20 native transforms passed\n";
    file_put_contents("$dir/out.xml",'old');
    $r=$p->transformToFile("$dir/source.xml","$dir/rules.xsl","$dir/out.xml");
    echo trim(file_get_contents($r['output_file'])),"\n";
    echo (fileperms($r['output_file']) & 0777)===0600?"private report\n":"WRONG PERMISSIONS\n";
    try{$p->transformFile("$dir/source.xml","$dir/rules.xsl",['properties'=>['s'=>'other.xml']]);}
    catch(King\ValidationException $e){echo "source override rejected\n";}
    try{$p->transformFile("$dir/source.xml","$dir/rules.xsl",['allowedReadPaths'=>[$dir]]);}
    catch(King\ValidationException $e){echo "unsupported sandbox rejected\n";}
} finally { foreach(glob("$dir/*") as $f)unlink($f);rmdir($dir); }
?>
--EXPECT--
saxonc-core-13
20 native transforms passed
<out>1,2,3</out>
private report
source override rejected
unsupported sandbox rejected
