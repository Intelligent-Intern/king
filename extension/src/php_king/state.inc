typedef struct king_cfg_s king_cfg_t;
void king_config_free(king_cfg_t *cfg);

int le_king_session = -1;
int le_king_cfg     = -1;
int le_king_perf    = -1;
int le_king_mcp     = -1;
int le_king_ws      = -1;
int le_king_request_context = -1;

zend_class_entry *king_ce_exception            = NULL;
zend_class_entry *king_ce_stream_exception     = NULL;
zend_class_entry *king_ce_invalid_state        = NULL;
zend_class_entry *king_ce_unknown_stream       = NULL;
zend_class_entry *king_ce_stream_blocked       = NULL;
zend_class_entry *king_ce_stream_limit         = NULL;
zend_class_entry *king_ce_final_size           = NULL;
zend_class_entry *king_ce_stream_stopped       = NULL;
zend_class_entry *king_ce_fin_expected         = NULL;
zend_class_entry *king_ce_invalid_fin_state    = NULL;
zend_class_entry *king_ce_done                 = NULL;
zend_class_entry *king_ce_quic_exception       = NULL;
zend_class_entry *king_ce_congestion_control   = NULL;
zend_class_entry *king_ce_too_many_streams     = NULL;
zend_class_entry *king_ce_runtime_exception    = NULL;
zend_class_entry *king_ce_system_exception     = NULL;
zend_class_entry *king_ce_validation_exception = NULL;
zend_class_entry *king_ce_timeout_exception    = NULL;
zend_class_entry *king_ce_network_exception    = NULL;
zend_class_entry *king_ce_tls_exception        = NULL;
zend_class_entry *king_ce_protocol_exception   = NULL;
zend_class_entry *king_ce_mcp_exception        = NULL;
zend_class_entry *king_ce_mcp_connection_error = NULL;
zend_class_entry *king_ce_mcp_protocol_error   = NULL;
zend_class_entry *king_ce_mcp_timeout          = NULL;
zend_class_entry *king_ce_mcp_data_error       = NULL;
zend_class_entry *king_ce_ws_exception         = NULL;
zend_class_entry *king_ce_ws_connection_error  = NULL;
zend_class_entry *king_ce_ws_protocol_error    = NULL;
zend_class_entry *king_ce_ws_timeout           = NULL;
zend_class_entry *king_ce_ws_closed            = NULL;
zend_class_entry *king_ce_cancel_token         = NULL;
zend_class_entry *king_ce_config               = NULL;
zend_class_entry *king_ce_session              = NULL;
zend_class_entry *king_ce_stream               = NULL;
zend_class_entry *king_ce_response             = NULL;
zend_class_entry *king_ce_mcp                  = NULL;
zend_class_entry *king_ce_client_http          = NULL;
zend_class_entry *king_ce_client_http1         = NULL;
zend_class_entry *king_ce_client_http2         = NULL;
zend_class_entry *king_ce_client_http3         = NULL;
zend_class_entry *king_ce_ws_connection        = NULL;
static zend_object_handlers king_cancel_token_object_handlers;
static zend_object_handlers king_config_object_handlers;
static zend_object_handlers king_stream_object_handlers;
static zend_object_handlers king_response_object_handlers;
static zend_object_handlers king_mcp_object_handlers;
static zend_object_handlers king_http_client_object_handlers;
static zend_object_handlers king_session_object_handlers;
static zend_object_handlers king_ws_object_handlers;

char king_last_error[KING_ERR_LEN] = {0};

void king_set_error(const char *msg)
{
    if (msg) {
        strncpy(king_last_error, msg, KING_ERR_LEN - 1);
        king_last_error[KING_ERR_LEN - 1] = '\0';
    }
}

const char *king_get_error(void)
{
    return king_last_error;
}

void *king_fetch_config(zval *zcfg)
{
    if (Z_TYPE_P(zcfg) == IS_OBJECT
        && king_ce_config != NULL
        && instanceof_function(Z_OBJCE_P(zcfg), king_ce_config)) {
        king_config_object *intern = php_king_config_obj_from_zend(Z_OBJ_P(zcfg));

        if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
            return NULL;
        }

        zcfg = &intern->resource;
    }

    if (Z_TYPE_P(zcfg) != IS_RESOURCE) {
        return NULL;
    }

    return zend_fetch_resource(Z_RES_P(zcfg), "King\\Config", le_king_cfg);
}
