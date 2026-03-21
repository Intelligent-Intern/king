#include "include/config/iibin/ini.h"
#include "include/config/iibin/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>

static ZEND_INI_MH(OnUpdateIibinPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for an IIBIN directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.iibin_max_schema_fields")) {
        king_iibin_config.max_schema_fields = val;
    } else if (zend_string_equals_literal(entry->name, "king.iibin_max_recursion_depth")) {
        king_iibin_config.max_recursion_depth = val;
    } else if (zend_string_equals_literal(entry->name, "king.io_default_buffer_size_kb")) {
        king_iibin_config.default_buffer_size_kb = val;
    } else if (zend_string_equals_literal(entry->name, "king.io_shm_total_memory_mb")) {
        king_iibin_config.shm_total_memory_mb = val;
    }

    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY("king.iibin_max_schema_fields", "256", PHP_INI_SYSTEM, OnUpdateIibinPositiveLong)
    ZEND_INI_ENTRY("king.iibin_max_recursion_depth", "32", PHP_INI_SYSTEM, OnUpdateIibinPositiveLong)
    STD_PHP_INI_ENTRY("king.iibin_string_interning_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, string_interning_enable, kg_iibin_config_t, king_iibin_config)
    STD_PHP_INI_ENTRY("king.io_use_shared_memory_buffers", "0", PHP_INI_SYSTEM, OnUpdateBool, use_shared_memory_buffers, kg_iibin_config_t, king_iibin_config)
    ZEND_INI_ENTRY("king.io_default_buffer_size_kb", "64", PHP_INI_SYSTEM, OnUpdateIibinPositiveLong)
    ZEND_INI_ENTRY("king.io_shm_total_memory_mb", "256", PHP_INI_SYSTEM, OnUpdateIibinPositiveLong)
    STD_PHP_INI_ENTRY("king.io_shm_path", "/king_io_shm", PHP_INI_SYSTEM, OnUpdateString, shm_path, kg_iibin_config_t, king_iibin_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_iibin_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_iibin_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
