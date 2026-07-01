typedef struct _king_inference_openai_drain_controls {
    zend_long read_timeout_ms;
    zend_long max_events;
    zend_long max_idle_events;
} king_inference_openai_drain_controls;

static bool king_inference_openai_positive_router_limit(
    zval *options,
    const char *key,
    zend_long fallback,
    zend_long *limit_out,
    const char **message)
{
    zval *field = king_inference_array_find(options, key);

    if (message != NULL) {
        *message = NULL;
    }
    if (limit_out == NULL) {
        return false;
    }
    *limit_out = fallback;
    if (field == NULL) {
        return true;
    }
    if (Z_TYPE_P(field) != IS_LONG || Z_LVAL_P(field) <= 0) {
        if (message != NULL) {
            *message = "King OpenAI router limit and drain control options must be positive integers.";
        }
        return false;
    }

    *limit_out = Z_LVAL_P(field);
    return true;
}

static bool king_inference_openai_drain_controls_from_options(
    zval *options,
    king_inference_openai_drain_controls *controls,
    const char **message)
{
    if (message != NULL) {
        *message = NULL;
    }
    if (controls == NULL) {
        return false;
    }

    return king_inference_openai_positive_router_limit(
        options,
        "read_timeout_ms",
        250,
        &controls->read_timeout_ms,
        message
    ) && king_inference_openai_positive_router_limit(
        options,
        "max_events",
        4096,
        &controls->max_events,
        message
    ) && king_inference_openai_positive_router_limit(
        options,
        "max_idle_events",
        240,
        &controls->max_idle_events,
        message
    );
}
