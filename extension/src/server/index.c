/*
 * =========================================================================
 * FILENAME:   src/server/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Local unified server dispatcher for the active skeleton build. This slice
 * resolves the current config snapshot, selects the primary protocol listener
 * for that snapshot, and forwards the call into the protocol-specific server
 * entry point.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/config/config.h"
#include "include/server/http1.h"
#include "include/server/http2.h"
#include "include/server/http3.h"
#include "include/server/index.h"
#include <zend_exceptions.h>

extern int le_king_cfg;

static const char *king_server_select_listener_name(const king_cfg_t *cfg)
{
    if (cfg != NULL && !cfg->tcp.enable) {
        return "king_http3_server_listen";
    }

    if (cfg != NULL && cfg->http2.enable) {
        return "king_http2_server_listen";
    }

    return "king_http1_server_listen";
}

static zend_result king_server_dispatch_prepare_config(
    zval *config,
    uint32_t config_arg_num,
    king_cfg_t **cfg_out,
    zval *normalized_config
)
{
    king_cfg_t *cfg = NULL;

    *cfg_out = NULL;
    ZVAL_UNDEF(normalized_config);

    if (config == NULL || Z_TYPE_P(config) == IS_NULL) {
        cfg = king_config_new_from_options(NULL);
        if (cfg == NULL) {
            return FAILURE;
        }

        *cfg_out = cfg;
        ZVAL_RES(normalized_config, zend_register_resource(cfg, le_king_cfg));
        return SUCCESS;
    }

    if (Z_TYPE_P(config) == IS_ARRAY) {
        cfg = king_config_new_from_options(config);
        if (cfg == NULL) {
            return FAILURE;
        }

        *cfg_out = cfg;
        ZVAL_RES(normalized_config, zend_register_resource(cfg, le_king_cfg));
        return SUCCESS;
    }

    cfg = (king_cfg_t *) king_fetch_config(config);
    if (cfg == NULL) {
        zend_argument_type_error(
            config_arg_num,
            "must be null, array, a King\\Config resource, or a King\\Config object"
        );
        return FAILURE;
    }

    *cfg_out = cfg;
    ZVAL_COPY(normalized_config, config);
    return SUCCESS;
}

static zend_result king_server_dispatch_call_listener(
    const char *listener_name,
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config,
    zval *handler,
    zval *retval
)
{
    zval listener_name_zv;
    zval params[4];
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    ZVAL_STRING(&listener_name_zv, listener_name);

    if (zend_fcall_info_init(&listener_name_zv, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        king_set_error("king_server_listen() failed to resolve the selected server listener.");
        zval_ptr_dtor(&listener_name_zv);
        return FAILURE;
    }

    ZVAL_STRINGL(&params[0], host, host_len);
    ZVAL_LONG(&params[1], port);
    ZVAL_COPY(&params[2], config);
    ZVAL_COPY(&params[3], handler);

    fci.retval = retval;
    fci.param_count = 4;
    fci.params = params;

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        zval_ptr_dtor(&params[0]);
        zval_ptr_dtor(&params[2]);
        zval_ptr_dtor(&params[3]);
        zval_ptr_dtor(&listener_name_zv);
        king_set_error("king_server_listen() failed to invoke the selected server listener.");
        return FAILURE;
    }

    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[2]);
    zval_ptr_dtor(&params[3]);
    zval_ptr_dtor(&listener_name_zv);

    return SUCCESS;
}

PHP_FUNCTION(king_server_listen)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_cfg_t *cfg = NULL;
    const char *listener_name;
    zval normalized_config;
    zval listener_result;
    zend_fcall_info handler_fci;
    zend_fcall_info_cache handler_fcc;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (host_len == 0) {
        king_set_error("king_server_listen() requires a non-empty host.");
        RETURN_FALSE;
    }

    if (port <= 0 || port > 65535) {
        king_set_error("king_server_listen() port must be between 1 and 65535.");
        RETURN_FALSE;
    }

    if (zend_fcall_info_init(handler, 0, &handler_fci, &handler_fcc, NULL, NULL) != SUCCESS) {
        zend_argument_type_error(4, "must be a valid callback");
        RETURN_THROWS();
    }

    if (king_server_dispatch_prepare_config(config, 3, &cfg, &normalized_config) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    listener_name = king_server_select_listener_name(cfg);

    ZVAL_UNDEF(&listener_result);
    if (king_server_dispatch_call_listener(
            listener_name,
            host,
            host_len,
            port,
            &normalized_config,
            handler,
            &listener_result
        ) != SUCCESS) {
        zval_ptr_dtor(&normalized_config);

        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    zval_ptr_dtor(&normalized_config);

    if (EG(exception) != NULL) {
        zval_ptr_dtor(&listener_result);
        RETURN_THROWS();
    }

    if (zend_is_true(&listener_result)) {
        king_set_error("");
        zval_ptr_dtor(&listener_result);
        RETURN_TRUE;
    }

    zval_ptr_dtor(&listener_result);
    RETURN_FALSE;
}
