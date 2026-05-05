--TEST--
King RTP DTLS uses a BoringSSL-safe connected socket BIO selector
--FILE--
<?php
$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root . '/extension/src/media/rtp.c');

function require_contains(string $label, string $haystack, string $needle): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' missing ' . $needle);
    }
}

require_contains('BoringSSL selector', $source, '#if defined(OPENSSL_IS_BORINGSSL)');
require_contains('BoringSSL timeout', $source, 'SO_RCVTIMEO');
require_contains('BoringSSL timeout', $source, 'SO_SNDTIMEO');
require_contains('BoringSSL BIO', $source, 'BIO_new_socket(pfd, BIO_NOCLOSE)');
require_contains('OpenSSL datagram BIO', $source, 'BIO_new_dgram(pfd, BIO_NOCLOSE)');
require_contains('OpenSSL datagram timeout', $source, 'BIO_CTRL_DGRAM_SET_RECV_TIMEOUT');

if (!preg_match('/#if defined\(OPENSSL_IS_BORINGSSL\).*BIO_new_socket\(pfd, BIO_NOCLOSE\).*#else.*BIO_new_dgram\(pfd, BIO_NOCLOSE\).*#endif/s', $source)) {
    throw new RuntimeException('RTP DTLS BIO selector must keep BoringSSL and OpenSSL paths separate.');
}

echo "RTP DTLS BIO selector preserves BoringSSL and OpenSSL paths.\n";
?>
--EXPECT--
RTP DTLS BIO selector preserves BoringSSL and OpenSSL paths.
