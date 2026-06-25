#define KING_MCP_PUBLIC_PROTOCOL_VERSION "2025-06-18"

static zend_result king_mcp_public_json_encode(zval *value, zend_string **json_out)
{
    smart_str json = {0};

    if (json_out == NULL) {
        return FAILURE;
    }

    *json_out = NULL;
    if (php_json_encode(&json, value, PHP_JSON_UNESCAPED_SLASHES) != SUCCESS) {
        smart_str_free(&json);
        king_set_error("MCP JSON-RPC response encoding failed.");
        return FAILURE;
    }

    smart_str_0(&json);
    *json_out = json.s != NULL ? json.s : zend_string_init("", 0, 0);
    return SUCCESS;
}

static zend_result king_mcp_public_json_decode(zend_string *json, zval *decoded_out)
{
    if (json == NULL || decoded_out == NULL) {
        return FAILURE;
    }

    ZVAL_UNDEF(decoded_out);
    if (php_json_decode(decoded_out, ZSTR_VAL(json), ZSTR_LEN(json), 1, 512) != SUCCESS) {
        king_set_error("MCP JSON-RPC message decoding failed.");
        return FAILURE;
    }

    return SUCCESS;
}

static bool king_mcp_public_array_is_list(zval *value)
{
    if (value == NULL || Z_TYPE_P(value) != IS_ARRAY) {
        return false;
    }

    return zend_array_is_list(Z_ARRVAL_P(value));
}

static void king_mcp_public_copy_id_or_null(zval *message, zval *target)
{
    zval *id = zend_hash_str_find(Z_ARRVAL_P(message), "id", sizeof("id") - 1);

    if (id == NULL) {
        ZVAL_NULL(target);
        return;
    }

    ZVAL_COPY(target, id);
}

static void king_mcp_public_build_error_response(
    zval *return_value,
    zval *id,
    zend_long code,
    const char *message)
{
    zval error;
    zval id_copy;

    array_init(return_value);
    add_assoc_string(return_value, "jsonrpc", "2.0");
    if (id != NULL) {
        ZVAL_COPY(&id_copy, id);
    } else {
        ZVAL_NULL(&id_copy);
    }
    add_assoc_zval(return_value, "id", &id_copy);

    array_init(&error);
    add_assoc_long(&error, "code", code);
    add_assoc_string(&error, "message", message);
    add_assoc_zval(return_value, "error", &error);
}

static void king_mcp_public_build_result_response(
    zval *return_value,
    zval *id,
    zval *result)
{
    zval id_copy;
    zval result_copy;

    array_init(return_value);
    add_assoc_string(return_value, "jsonrpc", "2.0");
    ZVAL_COPY(&id_copy, id);
    add_assoc_zval(return_value, "id", &id_copy);
    ZVAL_COPY(&result_copy, result);
    add_assoc_zval(return_value, "result", &result_copy);
}

static zval *king_mcp_public_server_get(zval *server, const char *key)
{
    if (server == NULL || Z_TYPE_P(server) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(Z_ARRVAL_P(server), key, strlen(key));
}

static zend_result king_mcp_public_server_normalize(zval *definition, zval *return_value)
{
    zval server_info;

    if (definition == NULL || Z_TYPE_P(definition) != IS_ARRAY) {
        king_set_error("MCP server definition must be an array.");
        return FAILURE;
    }

    ZVAL_DUP(return_value, definition);
    SEPARATE_ARRAY(return_value);
    add_assoc_bool(return_value, "__king_mcp_server", 1);

    if (king_mcp_public_server_get(return_value, "protocol_version") == NULL) {
        add_assoc_string(return_value, "protocol_version", KING_MCP_PUBLIC_PROTOCOL_VERSION);
    }

    if (king_mcp_public_server_get(return_value, "serverInfo") == NULL) {
        array_init(&server_info);
        add_assoc_string(&server_info, "name", "king-mcp-server");
        add_assoc_string(&server_info, "version", "1.0.0");
        add_assoc_zval(return_value, "serverInfo", &server_info);
    }

    return SUCCESS;
}

static zend_string *king_mcp_public_protocol_version(zval *server, zval *message)
{
    zval *params;
    zval *client_version;
    zval *server_version = king_mcp_public_server_get(server, "protocol_version");

    params = zend_hash_str_find(Z_ARRVAL_P(message), "params", sizeof("params") - 1);
    if (params != NULL && Z_TYPE_P(params) == IS_ARRAY) {
        client_version = zend_hash_str_find(
            Z_ARRVAL_P(params),
            "protocolVersion",
            sizeof("protocolVersion") - 1
        );
        if (client_version != NULL && Z_TYPE_P(client_version) == IS_STRING) {
            return zend_string_copy(Z_STR_P(client_version));
        }
    }

    if (server_version != NULL && Z_TYPE_P(server_version) == IS_STRING) {
        return zend_string_copy(Z_STR_P(server_version));
    }

    return zend_string_init(KING_MCP_PUBLIC_PROTOCOL_VERSION, sizeof(KING_MCP_PUBLIC_PROTOCOL_VERSION) - 1, 0);
}

static bool king_mcp_public_has_registry(zval *server, const char *key)
{
    zval *registry = king_mcp_public_server_get(server, key);

    return registry != NULL && Z_TYPE_P(registry) == IS_ARRAY && zend_hash_num_elements(Z_ARRVAL_P(registry)) > 0;
}

static void king_mcp_public_add_capability_object(zval *capabilities, const char *name)
{
    zval capability;

    object_init(&capability);
    add_property_bool(&capability, "listChanged", 0);
    add_assoc_zval(capabilities, name, &capability);
}

static void king_mcp_public_build_initialize_result(zval *server, zval *message, zval *result)
{
    zval capabilities;
    zval server_info_copy;
    zval *server_info;
    zval *instructions;
    zend_string *protocol_version = king_mcp_public_protocol_version(server, message);

    array_init(result);
    add_assoc_str(result, "protocolVersion", protocol_version);

    array_init(&capabilities);
    if (king_mcp_public_has_registry(server, "tools")) {
        king_mcp_public_add_capability_object(&capabilities, "tools");
    }
    if (king_mcp_public_has_registry(server, "resources")) {
        king_mcp_public_add_capability_object(&capabilities, "resources");
    }
    if (king_mcp_public_has_registry(server, "prompts")) {
        king_mcp_public_add_capability_object(&capabilities, "prompts");
    }
    add_assoc_zval(result, "capabilities", &capabilities);

    server_info = king_mcp_public_server_get(server, "serverInfo");
    if (server_info != NULL) {
        ZVAL_COPY(&server_info_copy, server_info);
        add_assoc_zval(result, "serverInfo", &server_info_copy);
    }

    instructions = king_mcp_public_server_get(server, "instructions");
    if (instructions != NULL && Z_TYPE_P(instructions) == IS_STRING) {
        add_assoc_str(result, "instructions", zend_string_copy(Z_STR_P(instructions)));
    }
}

static zval *king_mcp_public_find_named_entry(zval *server, const char *registry_name, zend_string *name)
{
    zval *registry = king_mcp_public_server_get(server, registry_name);
    zval *entry;

    if (registry == NULL || Z_TYPE_P(registry) != IS_ARRAY || name == NULL) {
        return NULL;
    }

    entry = zend_hash_find(Z_ARRVAL_P(registry), name);
    if (entry != NULL) {
        return entry;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(registry), entry) {
        zval *entry_name;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }

        entry_name = zend_hash_str_find(Z_ARRVAL_P(entry), "name", sizeof("name") - 1);
        if (entry_name != NULL && Z_TYPE_P(entry_name) == IS_STRING && zend_string_equals(Z_STR_P(entry_name), name)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static zval *king_mcp_public_find_resource_by_uri(zval *server, zend_string *uri)
{
    zval *resource = king_mcp_public_find_named_entry(server, "resources", uri);
    zval *registry;
    zval *entry;

    if (resource != NULL) {
        return resource;
    }

    registry = king_mcp_public_server_get(server, "resources");
    if (registry == NULL || Z_TYPE_P(registry) != IS_ARRAY) {
        return NULL;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(registry), entry) {
        zval *entry_uri;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }

        entry_uri = zend_hash_str_find(Z_ARRVAL_P(entry), "uri", sizeof("uri") - 1);
        if (entry_uri != NULL && Z_TYPE_P(entry_uri) == IS_STRING && zend_string_equals(Z_STR_P(entry_uri), uri)) {
            return entry;
        }
    } ZEND_HASH_FOREACH_END();

    return NULL;
}

static void king_mcp_public_copy_optional_field(zval *target, zval *source, const char *key)
{
    zval *value;
    zval copy;

    if (source == NULL || Z_TYPE_P(source) != IS_ARRAY) {
        return;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(source), key, strlen(key));
    if (value != NULL) {
        ZVAL_COPY(&copy, value);
        add_assoc_zval(target, key, &copy);
    }
}

static void king_mcp_public_build_tools_list(zval *server, zval *result)
{
    zval tools_list;
    zval *registry = king_mcp_public_server_get(server, "tools");

    array_init(result);
    array_init(&tools_list);

    if (registry != NULL && Z_TYPE_P(registry) == IS_ARRAY) {
        zend_string *key;
        zval *entry;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(registry), key, entry) {
            zval tool;
            zval *name;

            if (Z_TYPE_P(entry) != IS_ARRAY) {
                continue;
            }

            array_init(&tool);
            name = zend_hash_str_find(Z_ARRVAL_P(entry), "name", sizeof("name") - 1);
            if (name != NULL && Z_TYPE_P(name) == IS_STRING) {
                add_assoc_str(&tool, "name", zend_string_copy(Z_STR_P(name)));
            } else if (key != NULL) {
                add_assoc_str(&tool, "name", zend_string_copy(key));
            } else {
                zval_ptr_dtor(&tool);
                continue;
            }

            king_mcp_public_copy_optional_field(&tool, entry, "title");
            king_mcp_public_copy_optional_field(&tool, entry, "description");
            king_mcp_public_copy_optional_field(&tool, entry, "inputSchema");
            king_mcp_public_copy_optional_field(&tool, entry, "outputSchema");
            king_mcp_public_copy_optional_field(&tool, entry, "annotations");
            add_next_index_zval(&tools_list, &tool);
        } ZEND_HASH_FOREACH_END();
    }

    add_assoc_zval(result, "tools", &tools_list);
}

static zend_result king_mcp_public_call_handler(
    zval *handler,
    zval *arguments,
    zval *context,
    zval *handler_result)
{
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    zval params[2];
    zend_result status;

    if (zend_fcall_info_init(handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        king_set_error("MCP handler is not callable.");
        return FAILURE;
    }

    ZVAL_COPY(&params[0], arguments);
    if (context != NULL) {
        ZVAL_COPY(&params[1], context);
    } else {
        array_init(&params[1]);
    }

    ZVAL_UNDEF(handler_result);
    fci.params = params;
    fci.param_count = 2;
    fci.retval = handler_result;
    status = zend_call_function(&fci, &fcc);

    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[1]);

    if (status != SUCCESS || EG(exception)) {
        if (!EG(exception)) {
            king_set_error("MCP handler invocation failed.");
        }
        return FAILURE;
    }

    return SUCCESS;
}

static void king_mcp_public_tool_result_from_handler(zval *handler_result, zval *result)
{
    zval content;
    zval item;
    zval structured;
    zend_string *json;

    if (Z_TYPE_P(handler_result) == IS_ARRAY) {
        if (
            zend_hash_str_exists(Z_ARRVAL_P(handler_result), "content", sizeof("content") - 1)
            || zend_hash_str_exists(Z_ARRVAL_P(handler_result), "structuredContent", sizeof("structuredContent") - 1)
            || zend_hash_str_exists(Z_ARRVAL_P(handler_result), "isError", sizeof("isError") - 1)
        ) {
            ZVAL_COPY(result, handler_result);
            return;
        }
    }

    array_init(result);
    array_init(&content);
    array_init(&item);
    add_assoc_string(&item, "type", "text");

    if (Z_TYPE_P(handler_result) == IS_STRING) {
        add_assoc_str(&item, "text", zend_string_copy(Z_STR_P(handler_result)));
    } else if (king_mcp_public_json_encode(handler_result, &json) == SUCCESS) {
        add_assoc_str(&item, "text", json);
        ZVAL_COPY(&structured, handler_result);
        add_assoc_zval(result, "structuredContent", &structured);
    } else {
        add_assoc_string(&item, "text", "null");
    }

    add_next_index_zval(&content, &item);
    add_assoc_zval(result, "content", &content);
}

static zend_result king_mcp_public_handle_tool_call(
    zval *server,
    zval *message,
    zval *context,
    zval *result)
{
    zval *params;
    zval *name;
    zval *arguments;
    zval *tool;
    zval *handler;
    zval empty_arguments;
    zval handler_result;

    params = zend_hash_str_find(Z_ARRVAL_P(message), "params", sizeof("params") - 1);
    if (params == NULL || Z_TYPE_P(params) != IS_ARRAY) {
        king_set_error("MCP tools/call requires params.");
        return FAILURE;
    }

    name = zend_hash_str_find(Z_ARRVAL_P(params), "name", sizeof("name") - 1);
    if (name == NULL || Z_TYPE_P(name) != IS_STRING || Z_STRLEN_P(name) == 0) {
        king_set_error("MCP tools/call requires params.name.");
        return FAILURE;
    }

    tool = king_mcp_public_find_named_entry(server, "tools", Z_STR_P(name));
    if (tool == NULL || Z_TYPE_P(tool) != IS_ARRAY) {
        king_set_error("MCP tool is not registered.");
        return FAILURE;
    }

    handler = zend_hash_str_find(Z_ARRVAL_P(tool), "handler", sizeof("handler") - 1);
    if (handler == NULL) {
        king_set_error("MCP tool has no handler.");
        return FAILURE;
    }

    arguments = zend_hash_str_find(Z_ARRVAL_P(params), "arguments", sizeof("arguments") - 1);
    if (arguments == NULL || Z_TYPE_P(arguments) == IS_NULL) {
        array_init(&empty_arguments);
        arguments = &empty_arguments;
    } else {
        ZVAL_UNDEF(&empty_arguments);
    }

    if (king_mcp_public_call_handler(handler, arguments, context, &handler_result) != SUCCESS) {
        if (!Z_ISUNDEF(empty_arguments)) {
            zval_ptr_dtor(&empty_arguments);
        }
        return FAILURE;
    }

    king_mcp_public_tool_result_from_handler(&handler_result, result);
    zval_ptr_dtor(&handler_result);
    if (!Z_ISUNDEF(empty_arguments)) {
        zval_ptr_dtor(&empty_arguments);
    }

    return SUCCESS;
}

static void king_mcp_public_build_registry_list(
    zval *server,
    const char *registry_name,
    const char *result_key,
    zval *result)
{
    zval entries;
    zval *registry = king_mcp_public_server_get(server, registry_name);

    array_init(result);
    array_init(&entries);

    if (registry != NULL && Z_TYPE_P(registry) == IS_ARRAY) {
        zend_string *key;
        zval *entry;

        ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(registry), key, entry) {
            zval listed;
            zval *name;

            if (Z_TYPE_P(entry) != IS_ARRAY) {
                continue;
            }

            array_init(&listed);
            name = zend_hash_str_find(Z_ARRVAL_P(entry), "name", sizeof("name") - 1);
            if (name != NULL && Z_TYPE_P(name) == IS_STRING) {
                add_assoc_str(&listed, "name", zend_string_copy(Z_STR_P(name)));
            } else if (key != NULL) {
                add_assoc_str(&listed, "name", zend_string_copy(key));
            }
            king_mcp_public_copy_optional_field(&listed, entry, "uri");
            king_mcp_public_copy_optional_field(&listed, entry, "title");
            king_mcp_public_copy_optional_field(&listed, entry, "description");
            king_mcp_public_copy_optional_field(&listed, entry, "mimeType");
            king_mcp_public_copy_optional_field(&listed, entry, "arguments");
            add_next_index_zval(&entries, &listed);
        } ZEND_HASH_FOREACH_END();
    }

    add_assoc_zval(result, result_key, &entries);
}

static zend_result king_mcp_public_handle_resource_read(
    zval *server,
    zval *message,
    zval *context,
    zval *result)
{
    zval *params = zend_hash_str_find(Z_ARRVAL_P(message), "params", sizeof("params") - 1);
    zval *uri;
    zval *resource;
    zval *handler;
    zval handler_result;
    zval contents;
    zval content;

    if (params == NULL || Z_TYPE_P(params) != IS_ARRAY) {
        king_set_error("MCP resources/read requires params.");
        return FAILURE;
    }

    uri = zend_hash_str_find(Z_ARRVAL_P(params), "uri", sizeof("uri") - 1);
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING) {
        king_set_error("MCP resources/read requires params.uri.");
        return FAILURE;
    }

    resource = king_mcp_public_find_resource_by_uri(server, Z_STR_P(uri));
    if (resource == NULL || Z_TYPE_P(resource) != IS_ARRAY) {
        king_set_error("MCP resource is not registered.");
        return FAILURE;
    }

    handler = zend_hash_str_find(Z_ARRVAL_P(resource), "handler", sizeof("handler") - 1);
    if (handler != NULL) {
        if (king_mcp_public_call_handler(handler, params, context, &handler_result) != SUCCESS) {
            return FAILURE;
        }
        ZVAL_COPY(result, &handler_result);
        zval_ptr_dtor(&handler_result);
        return SUCCESS;
    }

    array_init(result);
    array_init(&contents);
    array_init(&content);
    add_assoc_str(&content, "uri", zend_string_copy(Z_STR_P(uri)));
    king_mcp_public_copy_optional_field(&content, resource, "mimeType");
    king_mcp_public_copy_optional_field(&content, resource, "text");
    king_mcp_public_copy_optional_field(&content, resource, "blob");
    add_next_index_zval(&contents, &content);
    add_assoc_zval(result, "contents", &contents);
    return SUCCESS;
}

static zend_result king_mcp_public_handle_prompt_get(
    zval *server,
    zval *message,
    zval *context,
    zval *result)
{
    zval *params = zend_hash_str_find(Z_ARRVAL_P(message), "params", sizeof("params") - 1);
    zval *name;
    zval *prompt;
    zval *handler;
    zval handler_result;

    if (params == NULL || Z_TYPE_P(params) != IS_ARRAY) {
        king_set_error("MCP prompts/get requires params.");
        return FAILURE;
    }

    name = zend_hash_str_find(Z_ARRVAL_P(params), "name", sizeof("name") - 1);
    if (name == NULL || Z_TYPE_P(name) != IS_STRING) {
        king_set_error("MCP prompts/get requires params.name.");
        return FAILURE;
    }

    prompt = king_mcp_public_find_named_entry(server, "prompts", Z_STR_P(name));
    if (prompt == NULL || Z_TYPE_P(prompt) != IS_ARRAY) {
        king_set_error("MCP prompt is not registered.");
        return FAILURE;
    }

    handler = zend_hash_str_find(Z_ARRVAL_P(prompt), "handler", sizeof("handler") - 1);
    if (handler != NULL) {
        if (king_mcp_public_call_handler(handler, params, context, &handler_result) != SUCCESS) {
            return FAILURE;
        }
        ZVAL_COPY(result, &handler_result);
        zval_ptr_dtor(&handler_result);
        return SUCCESS;
    }

    ZVAL_COPY(result, prompt);
    return SUCCESS;
}

static zend_result king_mcp_public_dispatch_request(
    zval *server,
    zval *message,
    zval *context,
    zval *result)
{
    zval *method = zend_hash_str_find(Z_ARRVAL_P(message), "method", sizeof("method") - 1);

    if (method == NULL || Z_TYPE_P(method) != IS_STRING) {
        king_set_error("MCP JSON-RPC request requires method.");
        return FAILURE;
    }

    if (zend_string_equals_literal(Z_STR_P(method), "initialize")) {
        king_mcp_public_build_initialize_result(server, message, result);
        return SUCCESS;
    }
    if (zend_string_equals_literal(Z_STR_P(method), "ping")) {
        object_init(result);
        return SUCCESS;
    }
    if (zend_string_equals_literal(Z_STR_P(method), "tools/list")) {
        king_mcp_public_build_tools_list(server, result);
        return SUCCESS;
    }
    if (zend_string_equals_literal(Z_STR_P(method), "tools/call")) {
        return king_mcp_public_handle_tool_call(server, message, context, result);
    }
    if (zend_string_equals_literal(Z_STR_P(method), "resources/list")) {
        king_mcp_public_build_registry_list(server, "resources", "resources", result);
        return SUCCESS;
    }
    if (zend_string_equals_literal(Z_STR_P(method), "resources/read")) {
        return king_mcp_public_handle_resource_read(server, message, context, result);
    }
    if (zend_string_equals_literal(Z_STR_P(method), "prompts/list")) {
        king_mcp_public_build_registry_list(server, "prompts", "prompts", result);
        return SUCCESS;
    }
    if (zend_string_equals_literal(Z_STR_P(method), "prompts/get")) {
        return king_mcp_public_handle_prompt_get(server, message, context, result);
    }

    king_set_error("MCP JSON-RPC method is not registered.");
    return FAILURE;
}

static zend_result king_mcp_public_handle_message_zval(
    zval *server,
    zval *message,
    zval *context,
    zval *response_out,
    bool *has_response)
{
    zval id;
    zval result;
    zval *jsonrpc;
    zval *method;

    if (has_response != NULL) {
        *has_response = false;
    }

    if (message == NULL || Z_TYPE_P(message) != IS_ARRAY || king_mcp_public_array_is_list(message)) {
        ZVAL_NULL(&id);
        king_mcp_public_build_error_response(response_out, &id, -32600, "Invalid JSON-RPC request.");
        if (has_response != NULL) {
            *has_response = true;
        }
        return SUCCESS;
    }

    jsonrpc = zend_hash_str_find(Z_ARRVAL_P(message), "jsonrpc", sizeof("jsonrpc") - 1);
    if (jsonrpc == NULL || Z_TYPE_P(jsonrpc) != IS_STRING || !zend_string_equals_literal(Z_STR_P(jsonrpc), "2.0")) {
        king_mcp_public_copy_id_or_null(message, &id);
        king_mcp_public_build_error_response(response_out, &id, -32600, "JSON-RPC version must be 2.0.");
        zval_ptr_dtor(&id);
        if (has_response != NULL) {
            *has_response = true;
        }
        return SUCCESS;
    }

    method = zend_hash_str_find(Z_ARRVAL_P(message), "method", sizeof("method") - 1);
    if (method == NULL) {
        return SUCCESS;
    }

    if (!zend_hash_str_exists(Z_ARRVAL_P(message), "id", sizeof("id") - 1)) {
        return SUCCESS;
    }

    king_mcp_public_copy_id_or_null(message, &id);
    ZVAL_UNDEF(&result);
    if (king_mcp_public_dispatch_request(server, message, context, &result) != SUCCESS) {
        king_mcp_public_build_error_response(
            response_out,
            &id,
            -32601,
            king_get_error()[0] != '\0' ? king_get_error() : "MCP method failed."
        );
        zval_ptr_dtor(&id);
        if (has_response != NULL) {
            *has_response = true;
        }
        return SUCCESS;
    }

    king_mcp_public_build_result_response(response_out, &id, &result);
    zval_ptr_dtor(&id);
    zval_ptr_dtor(&result);
    if (has_response != NULL) {
        *has_response = true;
    }

    return SUCCESS;
}

static zend_result king_mcp_public_handle_jsonrpc(
    zval *server,
    zval *message_input,
    zval *context,
    zval *response_out,
    bool *has_response)
{
    zval decoded;
    zend_result status;

    if (Z_TYPE_P(message_input) == IS_STRING) {
        if (king_mcp_public_json_decode(Z_STR_P(message_input), &decoded) != SUCCESS) {
            zval id;
            ZVAL_NULL(&id);
            king_mcp_public_build_error_response(response_out, &id, -32700, "Parse error.");
            if (has_response != NULL) {
                *has_response = true;
            }
            return SUCCESS;
        }
        status = king_mcp_public_handle_message_zval(server, &decoded, context, response_out, has_response);
        zval_ptr_dtor(&decoded);
        return status;
    }

    return king_mcp_public_handle_message_zval(server, message_input, context, response_out, has_response);
}

static zend_result king_mcp_public_handle_jsonrpc_encoded(
    zval *server,
    zval *message_input,
    zval *context,
    zend_string **json_out,
    bool *has_response)
{
    zval response;

    ZVAL_UNDEF(&response);
    if (king_mcp_public_handle_jsonrpc(server, message_input, context, &response, has_response) != SUCCESS) {
        return FAILURE;
    }

    if (has_response != NULL && !*has_response) {
        return SUCCESS;
    }

    if (king_mcp_public_json_encode(&response, json_out) != SUCCESS) {
        zval_ptr_dtor(&response);
        return FAILURE;
    }

    zval_ptr_dtor(&response);
    return SUCCESS;
}
