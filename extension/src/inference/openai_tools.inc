static bool king_inference_openai_tools_active(zval *source)
{
    zval *tools = king_inference_array_find(source, "tools");

    return king_inference_openai_array_is_list(tools)
        && zend_hash_num_elements(Z_ARRVAL_P(tools)) > 0;
}

static bool king_inference_openai_tool_choice_valid(zval *source, const char **message)
{
    zval *choice = king_inference_array_find(source, "tool_choice");
    zval *type;
    zval *function;
    zval *name;

    if (choice == NULL || Z_TYPE_P(choice) == IS_NULL || Z_TYPE_P(choice) == IS_FALSE) {
        return true;
    }
    if (Z_TYPE_P(choice) == IS_STRING) {
        return zend_string_equals_literal(Z_STR_P(choice), "auto")
            || zend_string_equals_literal(Z_STR_P(choice), "none")
            || zend_string_equals_literal(Z_STR_P(choice), "required");
    }
    if (!king_inference_openai_array_is_object(choice)) {
        if (message != NULL) {
            *message = "OpenAI tool_choice must be auto, none, required, or a function tool-choice object.";
        }
        return false;
    }

    type = king_inference_array_find(choice, "type");
    function = king_inference_array_find(choice, "function");
    name = function != NULL && king_inference_openai_array_is_object(function)
        ? king_inference_array_find(function, "name")
        : NULL;

    if (type == NULL
        || Z_TYPE_P(type) != IS_STRING
        || !zend_string_equals_literal(Z_STR_P(type), "function")
        || name == NULL
        || Z_TYPE_P(name) != IS_STRING
        || Z_STRLEN_P(name) == 0) {
        if (message != NULL) {
            *message = "OpenAI function tool_choice requires type=function and function.name.";
        }
        return false;
    }

    return true;
}

static bool king_inference_openai_tools_valid(zval *source, const char **message)
{
    zval *tools = king_inference_array_find(source, "tools");
    zval *parallel = king_inference_array_find(source, "parallel_tool_calls");
    zval *tool;

    if (message != NULL) {
        *message = NULL;
    }
    if (parallel != NULL && Z_TYPE_P(parallel) != IS_TRUE && Z_TYPE_P(parallel) != IS_FALSE && Z_TYPE_P(parallel) != IS_NULL) {
        if (message != NULL) {
            *message = "OpenAI parallel_tool_calls must be a boolean when provided.";
        }
        return false;
    }
    if (!king_inference_openai_tool_choice_valid(source, message)) {
        return false;
    }
    if (tools == NULL || Z_TYPE_P(tools) == IS_NULL) {
        return true;
    }
    if (!king_inference_openai_array_is_list(tools)) {
        if (message != NULL) {
            *message = "OpenAI tools must be an array when provided.";
        }
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tools), tool) {
        zval *type;
        zval *function;
        zval *name;
        zval *description;
        zval *parameters;
        zval *strict;

        if (!king_inference_openai_array_is_object(tool)) {
            if (message != NULL) {
                *message = "Each OpenAI tool must be an object.";
            }
            return false;
        }

        type = king_inference_array_find(tool, "type");
        function = king_inference_array_find(tool, "function");
        if (type == NULL
            || Z_TYPE_P(type) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(type), "function")
            || !king_inference_openai_array_is_object(function)) {
            if (message != NULL) {
                *message = "King local inference currently accepts OpenAI function tools only.";
            }
            return false;
        }

        name = king_inference_array_find(function, "name");
        description = king_inference_array_find(function, "description");
        parameters = king_inference_array_find(function, "parameters");
        strict = king_inference_array_find(function, "strict");
        if (name == NULL || Z_TYPE_P(name) != IS_STRING || Z_STRLEN_P(name) == 0) {
            if (message != NULL) {
                *message = "OpenAI function tools require a non-empty function.name.";
            }
            return false;
        }
        if (description != NULL && Z_TYPE_P(description) != IS_STRING && Z_TYPE_P(description) != IS_NULL) {
            if (message != NULL) {
                *message = "OpenAI function tool description must be a string when provided.";
            }
            return false;
        }
        if (parameters != NULL && !king_inference_openai_array_is_object(parameters) && Z_TYPE_P(parameters) != IS_NULL) {
            if (message != NULL) {
                *message = "OpenAI function tool parameters must be a JSON object when provided.";
            }
            return false;
        }
        if (strict != NULL && Z_TYPE_P(strict) != IS_TRUE && Z_TYPE_P(strict) != IS_FALSE && Z_TYPE_P(strict) != IS_NULL) {
            if (message != NULL) {
                *message = "OpenAI function tool strict must be a boolean when provided.";
            }
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_inference_openai_message_tool_calls_valid(zval *message, const char **error)
{
    zval *tool_calls = king_inference_array_find(message, "tool_calls");
    zval *call;

    if (error != NULL) {
        *error = NULL;
    }
    if (tool_calls == NULL || Z_TYPE_P(tool_calls) == IS_NULL) {
        return true;
    }
    if (!king_inference_openai_array_is_list(tool_calls)) {
        if (error != NULL) {
            *error = "Assistant tool_calls must be an array when provided.";
        }
        return false;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tool_calls), call) {
        zval *id;
        zval *type;
        zval *function;
        zval *name;
        zval *arguments;

        if (!king_inference_openai_array_is_object(call)) {
            if (error != NULL) {
                *error = "Each assistant tool_call must be an object.";
            }
            return false;
        }
        id = king_inference_array_find(call, "id");
        type = king_inference_array_find(call, "type");
        function = king_inference_array_find(call, "function");
        name = function != NULL && king_inference_openai_array_is_object(function)
            ? king_inference_array_find(function, "name")
            : NULL;
        arguments = function != NULL && king_inference_openai_array_is_object(function)
            ? king_inference_array_find(function, "arguments")
            : NULL;

        if (id != NULL && Z_TYPE_P(id) != IS_STRING) {
            if (error != NULL) {
                *error = "Assistant tool_call id must be a string when provided.";
            }
            return false;
        }
        if (type == NULL
            || Z_TYPE_P(type) != IS_STRING
            || !zend_string_equals_literal(Z_STR_P(type), "function")
            || name == NULL
            || Z_TYPE_P(name) != IS_STRING
            || Z_STRLEN_P(name) == 0
            || arguments == NULL
            || (Z_TYPE_P(arguments) != IS_STRING && !king_inference_openai_array_is_object(arguments))) {
            if (error != NULL) {
                *error = "Assistant tool_calls require type=function, function.name, and function.arguments.";
            }
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}

static bool king_inference_openai_message_has_tool_calls(zval *message)
{
    zval *tool_calls = king_inference_array_find(message, "tool_calls");

    return king_inference_openai_array_is_list(tool_calls)
        && zend_hash_num_elements(Z_ARRVAL_P(tool_calls)) > 0;
}

static zend_result king_inference_openai_append_message_tool_calls_text(zval *message, smart_str *buffer)
{
    zval *tool_calls = king_inference_array_find(message, "tool_calls");
    zend_string *json = NULL;

    if (!king_inference_openai_message_has_tool_calls(message)) {
        return FAILURE;
    }
    if (king_inference_openai_json_encode(tool_calls, &json) != SUCCESS || json == NULL) {
        return FAILURE;
    }
    smart_str_append(buffer, json);
    zend_string_release(json);
    return SUCCESS;
}

static void king_inference_openai_append_tool_prompt(zval *source, smart_str *buffer)
{
    zval *tools = king_inference_array_find(source, "tools");
    zval *choice = king_inference_array_find(source, "tool_choice");
    zend_string *tools_json = NULL;
    zend_string *choice_json = NULL;

    if (!king_inference_openai_tools_active(source)) {
        return;
    }
    if (king_inference_openai_json_encode(tools, &tools_json) != SUCCESS || tools_json == NULL) {
        return;
    }
    if (choice != NULL && Z_TYPE_P(choice) != IS_NULL) {
        king_inference_openai_json_encode(choice, &choice_json);
    }

    smart_str_appends(buffer, "system: Tools are available. If a tool call is required or useful, answer only with compact JSON using this exact shape: {\"tool_calls\":[{\"name\":\"tool_name\",\"arguments\":{}}]}. Do not wrap tool-call JSON in markdown.\n");
    smart_str_appends(buffer, "system: Available tools JSON: ");
    smart_str_append(buffer, tools_json);
    smart_str_appendc(buffer, '\n');
    if (choice_json != NULL) {
        smart_str_appends(buffer, "system: Tool choice JSON: ");
        smart_str_append(buffer, choice_json);
        smart_str_appendc(buffer, '\n');
    }

    zend_string_release(tools_json);
    if (choice_json != NULL) {
        zend_string_release(choice_json);
    }
}

static zend_string *king_inference_openai_tool_json_slice(zend_string *content)
{
    const char *start;
    const char *end;
    const char *cursor;

    if (content == NULL || ZSTR_LEN(content) == 0) {
        return NULL;
    }

    start = memchr(ZSTR_VAL(content), '{', ZSTR_LEN(content));
    if (start == NULL) {
        return NULL;
    }

    end = NULL;
    cursor = ZSTR_VAL(content) + ZSTR_LEN(content);
    while (cursor > start) {
        cursor--;
        if (*cursor == '}') {
            end = cursor;
            break;
        }
    }
    if (end == NULL || end < start) {
        return NULL;
    }

    return zend_string_init(start, (size_t) (end - start + 1), 0);
}

static zend_string *king_inference_openai_tool_call_name(zval *entry)
{
    zval *function;
    zval *name;

    if (!king_inference_openai_array_is_object(entry)) {
        return NULL;
    }

    function = king_inference_array_find(entry, "function");
    name = function != NULL && king_inference_openai_array_is_object(function)
        ? king_inference_array_find(function, "name")
        : king_inference_array_find(entry, "name");
    if (name == NULL || Z_TYPE_P(name) != IS_STRING || Z_STRLEN_P(name) == 0) {
        return NULL;
    }

    return zend_string_copy(Z_STR_P(name));
}

static zend_string *king_inference_openai_tool_call_arguments(zval *entry)
{
    zval *function;
    zval *arguments;
    zend_string *json = NULL;

    if (!king_inference_openai_array_is_object(entry)) {
        return NULL;
    }

    function = king_inference_array_find(entry, "function");
    arguments = function != NULL && king_inference_openai_array_is_object(function)
        ? king_inference_array_find(function, "arguments")
        : king_inference_array_find(entry, "arguments");
    if (arguments == NULL) {
        arguments = king_inference_array_find(entry, "args");
    }
    if (arguments == NULL || Z_TYPE_P(arguments) == IS_NULL) {
        return zend_string_init("{}", sizeof("{}") - 1, 0);
    }
    if (Z_TYPE_P(arguments) == IS_STRING) {
        return zend_string_copy(Z_STR_P(arguments));
    }
    if (!king_inference_openai_array_is_object(arguments)) {
        return NULL;
    }
    if (king_inference_openai_json_encode(arguments, &json) != SUCCESS) {
        return NULL;
    }

    return json;
}

static zend_result king_inference_openai_append_normalized_tool_call(
    zval *target,
    zval *entry,
    zend_long index)
{
    zend_string *name = king_inference_openai_tool_call_name(entry);
    zend_string *arguments = king_inference_openai_tool_call_arguments(entry);
    zval call;
    zval function;

    if (name == NULL || arguments == NULL) {
        if (name != NULL) {
            zend_string_release(name);
        }
        if (arguments != NULL) {
            zend_string_release(arguments);
        }
        return FAILURE;
    }

    array_init(&call);
    add_assoc_str(&call, "id", strpprintf(0, "call_king_" ZEND_LONG_FMT, index));
    add_assoc_string(&call, "type", "function");
    array_init(&function);
    add_assoc_str(&function, "name", name);
    add_assoc_str(&function, "arguments", arguments);
    add_assoc_zval(&call, "function", &function);
    add_next_index_zval(target, &call);
    return SUCCESS;
}

static bool king_inference_openai_tool_calls_from_content(zend_string *content, zval *tool_calls)
{
    zend_string *json = king_inference_openai_tool_json_slice(content);
    zval decoded;
    zval *entries;
    zval single_entry;
    zval *entry;
    zend_long index = 0;

    if (tool_calls == NULL) {
        return false;
    }
    ZVAL_UNDEF(tool_calls);
    if (json == NULL) {
        return false;
    }
    ZVAL_UNDEF(&decoded);
    if (king_inference_openai_json_decode(json, &decoded) != SUCCESS || !king_inference_openai_array_is_object(&decoded)) {
        zend_string_release(json);
        if (!Z_ISUNDEF(decoded)) {
            zval_ptr_dtor(&decoded);
        }
        return false;
    }
    zend_string_release(json);

    entries = king_inference_array_find(&decoded, "tool_calls");
    if (!king_inference_openai_array_is_list(entries)) {
        entries = king_inference_array_find(&decoded, "tool_call");
        if (king_inference_openai_array_is_object(entries)) {
            array_init(&single_entry);
            Z_TRY_ADDREF_P(entries);
            add_next_index_zval(&single_entry, entries);
            entries = &single_entry;
        } else {
            zend_string *decoded_name = king_inference_openai_tool_call_name(&decoded);
            if (decoded_name == NULL) {
                zval_ptr_dtor(&decoded);
                return false;
            }
            zend_string_release(decoded_name);
            array_init(&single_entry);
            Z_TRY_ADDREF(decoded);
            add_next_index_zval(&single_entry, &decoded);
            entries = &single_entry;
        }
    } else {
        ZVAL_UNDEF(&single_entry);
    }

    array_init(tool_calls);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(entries), entry) {
        if (king_inference_openai_append_normalized_tool_call(tool_calls, entry, index++) != SUCCESS) {
            zval_ptr_dtor(tool_calls);
            ZVAL_UNDEF(tool_calls);
            if (!Z_ISUNDEF(single_entry)) {
                zval_ptr_dtor(&single_entry);
            }
            zval_ptr_dtor(&decoded);
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    if (!Z_ISUNDEF(single_entry)) {
        zval_ptr_dtor(&single_entry);
    }
    zval_ptr_dtor(&decoded);
    if (zend_hash_num_elements(Z_ARRVAL_P(tool_calls)) == 0) {
        zval_ptr_dtor(tool_calls);
        ZVAL_UNDEF(tool_calls);
        return false;
    }

    return true;
}
