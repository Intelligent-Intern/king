/*
 * =========================================================================
 * FILENAME:   src/client/tls/config.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Runtime TLS config mutators for the client surface. This slice validates
 * user-supplied CA/certificate/key paths and updates the persistent client
 * TLS defaults stored in the active `tls_and_crypto` config state.
 * =========================================================================
 */

static void king_tls_set_ca_file_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    char *path;
    size_t path_len;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STRING(path, path_len)
    ZEND_PARSE_PARAMETERS_END();

    if (king_tls_validate_readable_path(
            path,
            path_len,
            function_name,
            "CA"
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_tls_replace_persistent_string(
        &king_tls_and_crypto_config.tls_default_ca_file,
        path
    );
    king_set_error("");
    RETURN_TRUE;
}

static void king_tls_set_client_cert_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    char *cert;
    size_t cert_len;
    char *key;
    size_t key_len;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(cert, cert_len)
        Z_PARAM_STRING(key, key_len)
    ZEND_PARSE_PARAMETERS_END();

    if (king_tls_validate_readable_path(cert, cert_len, function_name, "client certificate") != SUCCESS) {
        RETURN_FALSE;
    }

    if (king_tls_validate_readable_path(key, key_len, function_name, "client key") != SUCCESS) {
        RETURN_FALSE;
    }

    king_tls_replace_persistent_string(
        &king_tls_and_crypto_config.tls_default_cert_file,
        cert
    );
    king_tls_replace_persistent_string(
        &king_tls_and_crypto_config.tls_default_key_file,
        key
    );
    king_set_error("");
    RETURN_TRUE;
}
