typedef struct _king_config_override_bucket_t {
    zend_bool initialized;
    zval values;
} king_config_override_bucket_t;

typedef struct _king_config_override_state_t {
    king_config_override_bucket_t tls;
    king_config_override_bucket_t quic;
    king_config_override_bucket_t http2;
    king_config_override_bucket_t tcp;
    king_config_override_bucket_t autoscale;
    king_config_override_bucket_t mcp;
    king_config_override_bucket_t geometry;
    king_config_override_bucket_t smartcontract;
    king_config_override_bucket_t ssh;
    king_config_override_bucket_t storage;
    king_config_override_bucket_t cdn;
    king_config_override_bucket_t dns;
    king_config_override_bucket_t otel;
} king_config_override_state_t;

static king_config_override_bucket_t *king_config_override_bucket_for_module(
    king_config_override_state_t *state,
    king_config_override_module_t module)
{
    switch (module) {
        case KING_CONFIG_OVERRIDE_TLS:
            return &state->tls;
        case KING_CONFIG_OVERRIDE_QUIC:
            return &state->quic;
        case KING_CONFIG_OVERRIDE_HTTP2:
            return &state->http2;
        case KING_CONFIG_OVERRIDE_TCP:
            return &state->tcp;
        case KING_CONFIG_OVERRIDE_AUTOSCALE:
            return &state->autoscale;
        case KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR:
            return &state->mcp;
        case KING_CONFIG_OVERRIDE_GEOMETRY:
            return &state->geometry;
        case KING_CONFIG_OVERRIDE_SMART_CONTRACT:
            return &state->smartcontract;
        case KING_CONFIG_OVERRIDE_SSH:
            return &state->ssh;
        case KING_CONFIG_OVERRIDE_STORAGE:
            return &state->storage;
        case KING_CONFIG_OVERRIDE_CDN:
            return &state->cdn;
        case KING_CONFIG_OVERRIDE_DNS:
            return &state->dns;
        case KING_CONFIG_OVERRIDE_OTEL:
            return &state->otel;
        case KING_CONFIG_OVERRIDE_NONE:
            return NULL;
    }

    return NULL;
}

static int king_config_add_override_to_bucket(
    king_config_override_bucket_t *bucket,
    zend_string *source_key,
    zend_string *normalized_key,
    zval *value)
{
    if (!bucket->initialized) {
        array_init(&bucket->values);
        bucket->initialized = 1;
    }

    if (zend_hash_exists(Z_ARRVAL(bucket->values), normalized_key)) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Duplicate runtime config override '%s' resolves to normalized key '%s', which was already provided. Use one canonical key per setting.",
            ZSTR_VAL(source_key),
            ZSTR_VAL(normalized_key)
        );
        return FAILURE;
    }

    Z_TRY_ADDREF_P(value);
    add_assoc_zval_ex(
        &bucket->values,
        ZSTR_VAL(normalized_key),
        ZSTR_LEN(normalized_key),
        value
    );

    return SUCCESS;
}

static void king_config_destroy_override_bucket(king_config_override_bucket_t *bucket)
{
    if (!bucket->initialized) {
        return;
    }

    zval_ptr_dtor(&bucket->values);
    bucket->initialized = 0;
}

static void king_config_cleanup_override_state(king_config_override_state_t *state)
{
    king_config_destroy_override_bucket(&state->tls);
    king_config_destroy_override_bucket(&state->quic);
    king_config_destroy_override_bucket(&state->http2);
    king_config_destroy_override_bucket(&state->tcp);
    king_config_destroy_override_bucket(&state->autoscale);
    king_config_destroy_override_bucket(&state->mcp);
    king_config_destroy_override_bucket(&state->geometry);
    king_config_destroy_override_bucket(&state->smartcontract);
    king_config_destroy_override_bucket(&state->ssh);
    king_config_destroy_override_bucket(&state->storage);
    king_config_destroy_override_bucket(&state->cdn);
    king_config_destroy_override_bucket(&state->dns);
    king_config_destroy_override_bucket(&state->otel);
}
