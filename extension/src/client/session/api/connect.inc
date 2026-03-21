static king_client_session_t *king_client_session_open(
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config,
    uint32_t config_arg_num)
{
    king_cfg_t *cfg = NULL;
    king_client_session_t *session;
    time_t now;

    if (host_len == 0) {
        king_set_error("king_connect() requires a non-empty host.");
        return NULL;
    }

    if (port <= 0 || port > 65535) {
        king_set_error("king_connect() port must be between 1 and 65535.");
        return NULL;
    }

    session = ecalloc(1, sizeof(*session));
    session->host = zend_string_init(host, host_len, 0);
    session->port = port;
    king_client_session_init_cancel_state(session);
    king_client_session_init_transport_state(session);
    king_client_session_init_tls_state(session);
    king_client_session_init_config_state(session);
    king_server_session_prepare(session);

    if (config != NULL && Z_TYPE_P(config) != IS_NULL) {
        if (Z_TYPE_P(config) == IS_ARRAY) {
            session->config_binding = "inline_array";
            session->config_option_count = zend_hash_num_elements(Z_ARRVAL_P(config));
        } else {
            cfg = (king_cfg_t *) king_fetch_config(config);
            if (cfg != NULL) {
                king_client_session_apply_config_resource(session, cfg);
            } else {
                king_client_session_free(session);
                zend_argument_type_error(
                    config_arg_num,
                    "must be null, array, a King\\Config resource, or a King\\Config object"
                );
                return NULL;
            }
        }
    }

    if (king_client_session_open_udp_transport(
            session,
            host,
            port,
            "king_connect"
        ) != SUCCESS) {
        king_client_session_free(session);
        return NULL;
    }

    now = time(NULL);
    session->connected_at = now;
    session->last_activity_at = now;

    return session;
}

PHP_FUNCTION(king_connect)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_ZVAL(config)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_open(host, host_len, port, config, 3);
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_RES(zend_register_resource(session, le_king_session));
}

PHP_FUNCTION(king_close)
{
    zval *zsession;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(zsession)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!session->is_closed) {
        king_client_session_close_socket(session);
        session->is_closed = true;
        session->last_activity_at = time(NULL);
    }

    king_set_error("");
    RETURN_TRUE;
}
