static zend_result king_mcp_public_server_options_error(const char *message)
{
    king_set_error(message);
    return FAILURE;
}

static zend_result king_mcp_public_server_http_config(zval *server, zval **http_out)
{
    zval *http;
    zval new_http;

    if (server == NULL || Z_TYPE_P(server) != IS_ARRAY || http_out == NULL) {
        return king_mcp_public_server_options_error("MCP server options require a normalized server array.");
    }

    http = king_mcp_public_server_get(server, "streamable_http");
    if (http == NULL) {
        array_init(&new_http);
        add_assoc_zval(server, "streamable_http", &new_http);
        http = king_mcp_public_server_get(server, "streamable_http");
    }

    if (http == NULL || Z_TYPE_P(http) != IS_ARRAY) {
        return king_mcp_public_server_options_error("MCP server streamable_http configuration must be an array.");
    }

    SEPARATE_ARRAY(http);
    *http_out = http;
    return SUCCESS;
}

static zend_result king_mcp_public_server_apply_prefer_sse(zval *server, zval *value, const char *option_name)
{
    zval *http;
    char message[KING_ERR_LEN];

    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        snprintf(
            message,
            sizeof(message),
            "MCP server option '%s' must be boolean.",
            option_name
        );
        return king_mcp_public_server_options_error(message);
    }

    if (king_mcp_public_server_http_config(server, &http) != SUCCESS) {
        return FAILURE;
    }

    add_assoc_bool(http, "prefer_sse", zend_is_true(value));
    return SUCCESS;
}

static zend_result king_mcp_public_server_apply_allowed_origins(zval *server, zval *value, const char *option_name)
{
    zval *http;
    zval origins;
    zval *origin;
    char message[KING_ERR_LEN];

    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(value) != IS_ARRAY || !zend_array_is_list(Z_ARRVAL_P(value))) {
        snprintf(
            message,
            sizeof(message),
            "MCP server option '%s' must be a list of origin strings.",
            option_name
        );
        return king_mcp_public_server_options_error(message);
    }

    array_init(&origins);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), origin) {
        if (Z_TYPE_P(origin) != IS_STRING || Z_STRLEN_P(origin) == 0) {
            zval_ptr_dtor(&origins);
            snprintf(
                message,
                sizeof(message),
                "MCP server option '%s' must contain only non-empty origin strings.",
                option_name
            );
            return king_mcp_public_server_options_error(message);
        }
        add_next_index_str(&origins, zend_string_copy(Z_STR_P(origin)));
    } ZEND_HASH_FOREACH_END();

    if (king_mcp_public_server_http_config(server, &http) != SUCCESS) {
        zval_ptr_dtor(&origins);
        return FAILURE;
    }

    add_assoc_zval(http, "allowed_origins", &origins);
    return SUCCESS;
}

static zend_result king_mcp_public_server_apply_streamable_http_options(zval *server, zval *options)
{
    zend_string *key;
    zval *value;
    char message[KING_ERR_LEN];

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(options) != IS_ARRAY) {
        return king_mcp_public_server_options_error("MCP server option 'streamable_http' must be an array.");
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, value) {
        if (key == NULL) {
            return king_mcp_public_server_options_error(
                "MCP server option 'streamable_http' only accepts string keys."
            );
        }

        if (zend_string_equals_literal(key, "prefer_sse")) {
            if (king_mcp_public_server_apply_prefer_sse(server, value, "streamable_http.prefer_sse") != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        if (zend_string_equals_literal(key, "allowed_origins")) {
            if (
                king_mcp_public_server_apply_allowed_origins(
                    server,
                    value,
                    "streamable_http.allowed_origins"
                ) != SUCCESS
            ) {
                return FAILURE;
            }
            continue;
        }

        snprintf(
            message,
            sizeof(message),
            "MCP server option 'streamable_http.%s' is not supported.",
            ZSTR_VAL(key)
        );
        return king_mcp_public_server_options_error(message);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_mcp_public_server_apply_creation_options(zval *server, zval *options)
{
    zend_string *key;
    zval *value;
    char message[KING_ERR_LEN];

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(options) != IS_ARRAY) {
        return king_mcp_public_server_options_error("MCP server options must be an array.");
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, value) {
        if (key == NULL) {
            return king_mcp_public_server_options_error("MCP server options only accept string keys.");
        }

        if (zend_string_equals_literal(key, "streamable_http")) {
            if (king_mcp_public_server_apply_streamable_http_options(server, value) != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        if (zend_string_equals_literal(key, "prefer_sse")) {
            if (king_mcp_public_server_apply_prefer_sse(server, value, "prefer_sse") != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        if (zend_string_equals_literal(key, "allowed_origins")) {
            if (king_mcp_public_server_apply_allowed_origins(server, value, "allowed_origins") != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        snprintf(
            message,
            sizeof(message),
            "MCP server option '%s' is not supported.",
            ZSTR_VAL(key)
        );
        return king_mcp_public_server_options_error(message);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
