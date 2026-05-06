--TEST--
King LSQUIC bootstrap has deterministic archive fallback URLs for transient GitHub archive failures
--FILE--
<?php
$root = dirname(__DIR__, 2);
$script = (string) file_get_contents($root . '/infra/scripts/bootstrap-lsquic.sh');
$docs = (string) file_get_contents($root . '/documentation/dependency-provenance.md');

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

require_contains('bootstrap script', $script, 'KING_LSQUIC_ARCHIVE_MIRROR_BASE');
require_contains('bootstrap script', $script, 'github_codeload_url()');
require_contains('bootstrap script', $script, 'archive_candidate_urls()');
require_contains('bootstrap script', $script, 'https://codeload.github.com/%s/tar.gz/%s');
require_contains('bootstrap script', $script, '--retry-all-errors');
require_contains('bootstrap script', $script, 'verify_archive "${component}" "${tmp}" "${expected_sha}" "${expected_bytes}"');
require_contains('bootstrap script', $script, 'Failed to fetch ${component} archive from all deterministic candidates');
require_contains('provenance docs', $docs, 'KING_LSQUIC_ARCHIVE_MIRROR_BASE');
require_contains('provenance docs', $docs, 'codeload.github.com');
require_contains('provenance docs', $docs, 'SHA-256 and byte-size verification');

echo "OK\n";
?>
--EXPECT--
OK
