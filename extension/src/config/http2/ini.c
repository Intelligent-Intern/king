/*
 * =========================================================================
 * FILENAME:   src/config/http2/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the HTTP/2 config family.
 * This file exposes the system-level enablement, flow-control, stream-limit,
 * header-list, push, and frame-size directives and keeps
 * `king_http2_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/http2/ini.h"
#include "include/config/http2/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <zend_ini.h>
#include <ext/spl/spl_exceptions.h>

static ZEND_INI_MH(OnUpdateHttp2PositiveLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an HTTP/2 directive. A positive integer is required.");
        return FAILURE;
    }

    /* ZEND_INI_ENTRY1_EX() passes the target field directly via mh_arg1. */
    *(zend_long *) mh_arg1 = value;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateHttp2NonNegativeLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for an HTTP/2 directive. A non-negative integer is required.");
        return FAILURE;
    }

    /* ZEND_INI_ENTRY1_EX() passes the target field directly via mh_arg1. */
    *(zend_long *) mh_arg1 = value;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateHttp2MaxFrameSize)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value < 16384 || value > 16777215) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for HTTP/2 max_frame_size. Must be between 16384 and 16777215.");
        return FAILURE;
    }

    /* ZEND_INI_ENTRY1_EX() passes the target field directly via mh_arg1. */
    *(zend_long *) mh_arg1 = value;
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.http2_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        enable, kg_http2_config_t, king_http2_config)
    ZEND_INI_ENTRY1_EX("king.http2_initial_window_size", "65535", PHP_INI_SYSTEM,
        OnUpdateHttp2PositiveLong, &king_http2_config.initial_window_size, NULL)
    ZEND_INI_ENTRY1_EX("king.http2_max_concurrent_streams", "100", PHP_INI_SYSTEM,
        OnUpdateHttp2PositiveLong, &king_http2_config.max_concurrent_streams, NULL)
    ZEND_INI_ENTRY1_EX("king.http2_max_header_list_size", "0", PHP_INI_SYSTEM,
        OnUpdateHttp2NonNegativeLong, &king_http2_config.max_header_list_size, NULL)
    STD_PHP_INI_ENTRY("king.http2_enable_push", "1", PHP_INI_SYSTEM, OnUpdateBool,
        enable_push, kg_http2_config_t, king_http2_config)
    ZEND_INI_ENTRY1_EX("king.http2_max_frame_size", "16384", PHP_INI_SYSTEM,
        OnUpdateHttp2MaxFrameSize, &king_http2_config.max_frame_size, NULL)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_http2_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_http2_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
