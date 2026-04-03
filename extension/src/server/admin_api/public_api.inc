PHP_FUNCTION(king_admin_api_listen)
{
    zval *ztarget_server;
    zval *zconfig;
    king_client_session_t *session;
    king_server_admin_api_config_t config;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(ztarget_server)
        Z_PARAM_ZVAL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        ztarget_server,
        1,
        "king_admin_api_listen"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_admin_api_resolve_config(
            zconfig,
            2,
            &config,
            "king_admin_api_listen"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_admin_api_validate_config(&config, "king_admin_api_listen") != SUCCESS) {
        RETURN_FALSE;
    }

    king_server_admin_api_apply_session_snapshot(session, &config);
    if (king_server_admin_api_maybe_serve_request(
            session,
            &config,
            "king_admin_api_listen"
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
