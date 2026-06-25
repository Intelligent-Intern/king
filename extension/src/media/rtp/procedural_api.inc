/* ── PHP functions ──────────────────────────────────────────────────────── */

PHP_FUNCTION(king_rtp_bind)
{
    char *host; size_t hlen; zend_long port;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(host, hlen)
        Z_PARAM_LONG(port)
    ZEND_PARSE_PARAMETERS_END();

    char errbuf[256] = {0};
    king_rtp_socket_t *sock = king_rtp_socket_create(host, (int)port,
                                                      errbuf, sizeof(errbuf));
    if (!sock) {
        php_error_docref(NULL, E_WARNING, "king_rtp_bind: %s", errbuf);
        RETURN_FALSE;
    }
    RETURN_RES(zend_register_resource(sock, le_king_rtp_socket));
}

PHP_FUNCTION(king_rtp_ice_credentials)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;
    array_init(return_value);
    add_assoc_string(return_value, "ufrag", s->ufrag);
    add_assoc_string(return_value, "pwd",   s->pwd);
}

/*
 * king_rtp_dtls_fingerprint(resource $socket): string
 * Returns "SHA-256 XX:XX:…" for the SDP a=fingerprint attribute.
 */
PHP_FUNCTION(king_rtp_dtls_fingerprint)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;
    RETURN_STRING(s->fingerprint);
}

/*
 * king_rtp_dtls_accept(resource $socket, string $peer_ip, int $peer_port,
 *                      int $timeout_ms = 5000): bool
 *
 * Performs the DTLS handshake with the named peer, extracts SRTP keying
 * material and initialises libsrtp2 send/recv contexts.
 * After this call king_rtp_recv() / king_rtp_send() transparently en/decrypt.
 */
PHP_FUNCTION(king_rtp_dtls_accept)
{
    zval *z; char *ip; size_t iplen; zend_long port; zend_long tms = 5000;
    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_STRING(ip, iplen)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(tms)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *s = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                               "King\\RtpSocket", le_king_rtp_socket);
    if (!s) RETURN_FALSE;

    char errbuf[256] = {0};
    int rc = king_rtp_dtls_do_accept(s, ip, (int)port, (int)tms,
                                      errbuf, sizeof(errbuf));
    if (rc != 0) {
        php_error_docref(NULL, E_WARNING, "king_rtp_dtls_accept: %s", errbuf);
        RETURN_FALSE;
    }
    RETURN_TRUE;
}

PHP_FUNCTION(king_rtp_recv)
{
    zval *z; zend_long tms = 0;
    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(tms)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *sock = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                                  "King\\RtpSocket", le_king_rtp_socket);
    if (!sock) RETURN_FALSE;

    king_rtp_receive_result_t packet;
    if (!king_rtp_socket_recv_packet(sock, (int)tms, &packet)) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_long(   return_value, "ssrc",          (zend_long)packet.ssrc);
    add_assoc_long(   return_value, "payload_type",  packet.payload_type);
    add_assoc_long(   return_value, "seq",           packet.seq);
    add_assoc_long(   return_value, "timestamp",     (zend_long)packet.timestamp);
    add_assoc_bool(   return_value, "marker",        packet.marker);
    add_assoc_stringl(return_value, "data",          (char *)packet.data, packet.data_len);
    add_assoc_string( return_value, "from_ip",       packet.from_ip);
    add_assoc_long(   return_value, "from_port",     packet.from_port);
    add_assoc_bool(   return_value, "ice_consented", packet.ice_consented);
    add_assoc_bool(   return_value, "srtp",          packet.srtp);
}

PHP_FUNCTION(king_rtp_send)
{
    zval *z; char *host, *data; size_t hlen, dlen; zend_long port;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_RESOURCE(z)
        Z_PARAM_STRING(host, hlen)
        Z_PARAM_LONG(port)
        Z_PARAM_STRING(data, dlen)
    ZEND_PARSE_PARAMETERS_END();

    king_rtp_socket_t *sock = (king_rtp_socket_t *)zend_fetch_resource(Z_RES_P(z),
                                  "King\\RtpSocket", le_king_rtp_socket);
    if (!sock) RETURN_FALSE;

    RETURN_BOOL(king_rtp_socket_send_packet(sock, host, (int)port, data, dlen));
}

PHP_FUNCTION(king_rtp_close)
{
    zval *z; ZEND_PARSE_PARAMETERS_START(1,1) Z_PARAM_RESOURCE(z) ZEND_PARSE_PARAMETERS_END();
    zend_list_close(Z_RES_P(z));
}
