#if defined(KING_HTTP3_BACKEND_LSQUIC)
static int king_server_http3_lsquic_select_alpn(
    SSL *ssl,
    const unsigned char **out,
    unsigned char *outlen,
    const unsigned char *in,
    unsigned int inlen,
    void *arg
)
{
    static const unsigned char supported[] = { 2, 'h', '3' };
    int rc;

    (void) ssl;
    (void) arg;

    rc = SSL_select_next_proto(
        (unsigned char **) out,
        outlen,
        in,
        inlen,
        supported,
        sizeof(supported)
    );

    return rc == OPENSSL_NPN_NEGOTIATED
        ? SSL_TLSEXT_ERR_OK
        : SSL_TLSEXT_ERR_ALERT_FATAL;
}

static SSL_CTX *king_server_http3_lsquic_create_ssl_ctx(
    const king_server_http3_options_t *options,
    const char *function_name
)
{
    SSL_CTX *ctx = SSL_CTX_new(TLS_method());

    if (ctx == NULL) {
        king_server_local_set_errorf(
            "%s() failed to allocate the LSQUIC TLS server context.",
            function_name
        );
        return NULL;
    }

#if defined(TLS1_3_VERSION)
    (void) SSL_CTX_set_min_proto_version(ctx, TLS1_3_VERSION);
    (void) SSL_CTX_set_max_proto_version(ctx, TLS1_3_VERSION);
#endif
    SSL_CTX_set_alpn_select_cb(ctx, king_server_http3_lsquic_select_alpn, NULL);

    if (SSL_CTX_use_certificate_chain_file(ctx, options->tls_default_cert_file) != 1) {
        king_server_local_set_errorf(
            "%s() failed to load the LSQUIC HTTP/3 certificate '%s'.",
            function_name,
            options->tls_default_cert_file
        );
        SSL_CTX_free(ctx);
        return NULL;
    }

    if (SSL_CTX_use_PrivateKey_file(ctx, options->tls_default_key_file, SSL_FILETYPE_PEM) != 1) {
        king_server_local_set_errorf(
            "%s() failed to load the LSQUIC HTTP/3 key '%s'.",
            function_name,
            options->tls_default_key_file
        );
        SSL_CTX_free(ctx);
        return NULL;
    }

    if (SSL_CTX_check_private_key(ctx) != 1) {
        king_server_local_set_errorf(
            "%s() received an LSQUIC HTTP/3 certificate/key pair that does not match.",
            function_name
        );
        SSL_CTX_free(ctx);
        return NULL;
    }

    return ctx;
}

static SSL_CTX *king_server_http3_lsquic_lookup_cert(
    void *cert_lu_ctx,
    const struct sockaddr *local,
    const char *sni
)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) cert_lu_ctx;

    (void) local;
    (void) sni;

    return runtime != NULL ? runtime->lsquic_ssl_ctx : NULL;
}

static SSL_CTX *king_server_http3_lsquic_get_ssl_ctx(
    void *peer_ctx,
    const struct sockaddr *local
)
{
    king_server_http3_runtime_t *runtime = (king_server_http3_runtime_t *) peer_ctx;

    (void) local;

    return runtime != NULL ? runtime->lsquic_ssl_ctx : NULL;
}

#endif
