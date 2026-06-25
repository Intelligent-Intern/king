PHP_METHOD(King_MCPServer, __construct)
{
    zval *definition;
    zval *options = NULL;
    zval normalized;
    king_mcp_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(definition)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&normalized);
    if (king_mcp_public_server_normalize(definition, &normalized) != SUCCESS) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "Invalid MCP server definition."
        );
        RETURN_THROWS();
    }

    if (king_mcp_public_server_apply_creation_options(&normalized, options) != SUCCESS) {
        zval_ptr_dtor(&normalized);
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "Invalid MCP server options."
        );
        RETURN_THROWS();
    }

    intern = php_king_mcp_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->definition)) {
        zval_ptr_dtor(&intern->definition);
    }
    ZVAL_COPY_VALUE(&intern->definition, &normalized);
    king_set_error("");
}

PHP_METHOD(King_MCPServer, handleJsonRpc)
{
    zval *message;
    zval *context = NULL;
    zend_string *json = NULL;
    bool has_response = false;
    king_mcp_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ZVAL(message)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(context)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_mcp_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (Z_ISUNDEF(intern->definition) || Z_TYPE(intern->definition) != IS_ARRAY) {
        zend_throw_exception(king_ce_runtime_exception, "King\\MCPServer is not initialized.", 0);
        RETURN_THROWS();
    }

    if (
        king_mcp_public_handle_jsonrpc_encoded(
            &intern->definition,
            message,
            context,
            &json,
            &has_response
        ) != SUCCESS
    ) {
        if (EG(exception)) {
            RETURN_THROWS();
        }
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()[0] != '\0' ? king_get_error() : "MCP JSON-RPC handling failed."
        );
        RETURN_THROWS();
    }

    if (!has_response) {
        RETURN_NULL();
    }

    king_set_error("");
    RETURN_STR(json);
}

PHP_METHOD(King_MCPServer, handleHttp)
{
    zval *request;
    zval *context = NULL;
    king_mcp_server_object *intern;
    zval params[3];
    zval function_name;
    zval result;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_ARRAY(request)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(context)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_mcp_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (Z_ISUNDEF(intern->definition) || Z_TYPE(intern->definition) != IS_ARRAY) {
        zend_throw_exception(king_ce_runtime_exception, "King\\MCPServer is not initialized.", 0);
        RETURN_THROWS();
    }

    ZVAL_COPY(&params[0], &intern->definition);
    ZVAL_COPY(&params[1], request);
    if (context != NULL) {
        ZVAL_COPY(&params[2], context);
    } else {
        ZVAL_NULL(&params[2]);
    }
    ZVAL_STRING(&function_name, "king_mcp_server_handle_http");
    ZVAL_UNDEF(&result);

    if (call_user_function(EG(function_table), NULL, &function_name, &result, 3, params) != SUCCESS) {
        zval_ptr_dtor(&function_name);
        zval_ptr_dtor(&params[0]);
        zval_ptr_dtor(&params[1]);
        zval_ptr_dtor(&params[2]);
        if (EG(exception)) {
            RETURN_THROWS();
        }
        RETURN_FALSE;
    }

    zval_ptr_dtor(&function_name);
    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[1]);
    zval_ptr_dtor(&params[2]);
    if (EG(exception)) {
        if (!Z_ISUNDEF(result)) {
            zval_ptr_dtor(&result);
        }
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 1);
}

PHP_METHOD(King_MCPServer, runStdio)
{
    zval *options = NULL;
    king_mcp_server_object *intern;
    zval params[2];
    zval function_name;
    zval result;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_mcp_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (Z_ISUNDEF(intern->definition) || Z_TYPE(intern->definition) != IS_ARRAY) {
        zend_throw_exception(king_ce_runtime_exception, "King\\MCPServer is not initialized.", 0);
        RETURN_THROWS();
    }

    ZVAL_COPY(&params[0], &intern->definition);
    if (options != NULL) {
        ZVAL_COPY(&params[1], options);
    } else {
        ZVAL_NULL(&params[1]);
    }
    ZVAL_STRING(&function_name, "king_mcp_server_stdio");
    ZVAL_UNDEF(&result);

    if (call_user_function(EG(function_table), NULL, &function_name, &result, 2, params) != SUCCESS) {
        zval_ptr_dtor(&function_name);
        zval_ptr_dtor(&params[0]);
        zval_ptr_dtor(&params[1]);
        if (EG(exception)) {
            RETURN_THROWS();
        }
        RETURN_FALSE;
    }

    zval_ptr_dtor(&function_name);
    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[1]);
    if (EG(exception)) {
        if (!Z_ISUNDEF(result)) {
            zval_ptr_dtor(&result);
        }
        RETURN_THROWS();
    }

    RETURN_ZVAL(&result, 0, 1);
}
