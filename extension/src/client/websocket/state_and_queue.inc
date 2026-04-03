static void king_websocket_set_error(
    const char *format,
    ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

static bool king_websocket_string_has_crlf(
    const char *value,
    size_t value_len
)
{
    size_t i;

    for (i = 0; i < value_len; ++i) {
        if (value[i] == '\r' || value[i] == '\n') {
            return true;
        }
    }

    return false;
}

static king_ws_state *king_websocket_fetch_state(
    zval *websocket,
    uint32_t arg_num
)
{
    king_ws_state *state;

    if (
        Z_TYPE_P(websocket) == IS_OBJECT
        && king_ce_ws_connection != NULL
        && instanceof_function(Z_OBJCE_P(websocket), king_ce_ws_connection)
    ) {
        king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(websocket));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            zend_argument_type_error(
                arg_num,
                "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
            );
            return NULL;
        }

        websocket = &intern->resource;
    }

    if (Z_TYPE_P(websocket) != IS_RESOURCE) {
        zend_argument_type_error(
            arg_num,
            "must be of type resource or King\\WebSocket\\Connection, %s given",
            zend_zval_type_name(websocket)
        );
        return NULL;
    }

    state = (king_ws_state *) zend_fetch_resource(
        Z_RES_P(websocket),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        zend_argument_type_error(
            arg_num,
            "must be a valid King\\WebSocket resource or King\\WebSocket\\Connection object"
        );
    }

    return state;
}

static king_ws_state *king_websocket_object_fetch_state(
    zval *object,
    const char *function_name
)
{
    king_ws_object *intern = php_king_ws_obj_from_zend(Z_OBJ_P(object));
    king_ws_state *state;

    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        king_set_error("King\\WebSocket\\Connection object is not initialized.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() requires an initialized King\\WebSocket\\Connection object.",
            function_name
        );
        return NULL;
    }

    state = zend_fetch_resource(
        Z_RES(intern->resource),
        "King\\WebSocket",
        le_king_ws
    );
    if (state == NULL) {
        king_set_error("King\\WebSocket\\Connection object lost its native resource.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() lost its active King\\WebSocket resource.",
            function_name
        );
    }

    return state;
}

static void king_websocket_message_queue_clear(king_ws_state *state)
{
    king_ws_message *message = state->incoming_head;

    while (message != NULL) {
        king_ws_message *next = message->next;

        if (message->payload != NULL) {
            zend_string_release(message->payload);
        }

        efree(message);
        message = next;
    }

    state->incoming_head = NULL;
    state->incoming_tail = NULL;
    state->queued_message_count = 0;
    state->queued_bytes = 0;
}

static bool king_websocket_message_queue_can_accept(
    king_ws_state *state,
    size_t payload_len
)
{
    if (state->max_queued_messages > 0
        && state->queued_message_count >= state->max_queued_messages) {
        return false;
    }

    if (payload_len > (size_t) ZEND_LONG_MAX) {
        return false;
    }

    if (state->max_queued_bytes > 0) {
        size_t queued_bytes = state->queued_bytes > 0
            ? (size_t) state->queued_bytes
            : 0;

        if (queued_bytes > (size_t) ZEND_LONG_MAX - payload_len) {
            return false;
        }

        if (queued_bytes + payload_len > (size_t) state->max_queued_bytes) {
            return false;
        }
    }

    return true;
}

static zend_result king_websocket_message_queue_push(
    king_ws_state *state,
    const char *payload,
    size_t payload_len,
    bool is_binary
)
{
    king_ws_message *message;

    if (!king_websocket_message_queue_can_accept(state, payload_len)) {
        return FAILURE;
    }

    message = ecalloc(1, sizeof(*message));
    message->payload = zend_string_init(payload, payload_len, 0);
    message->is_binary = is_binary;

    if (state->incoming_tail != NULL) {
        state->incoming_tail->next = message;
    } else {
        state->incoming_head = message;
    }

    state->incoming_tail = message;
    state->queued_message_count++;
    state->queued_bytes += (zend_long) payload_len;
    return SUCCESS;
}

static king_ws_message *king_websocket_message_queue_shift(
    king_ws_state *state
)
{
    king_ws_message *message = state->incoming_head;

    if (message == NULL) {
        return NULL;
    }

    state->incoming_head = message->next;
    if (state->incoming_head == NULL) {
        state->incoming_tail = NULL;
    }

    if (state->queued_message_count > 0) {
        state->queued_message_count--;
    }
    if (state->queued_bytes > 0) {
        zend_long payload_len = message->payload != NULL
            ? (zend_long) ZSTR_LEN(message->payload)
            : 0;

        state->queued_bytes =
            state->queued_bytes >= payload_len
                ? state->queued_bytes - payload_len
                : 0;
    }

    message->next = NULL;
    return message;
}

