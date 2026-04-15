static zend_result king_server_http3_ensure_lsquic_ready(void)
{
    static unsigned attempted = 0;
    static unsigned ready = 0;

    if (ready) {
        return SUCCESS;
    }

    if (attempted) {
        return FAILURE;
    }

    attempted = 1;

    snprintf(king_server_http3_lsquic.load_error, sizeof(king_server_http3_lsquic.load_error),
        "QUIC runtime not available - HTTP/2 will be used instead");
    
    return FAILURE;
}