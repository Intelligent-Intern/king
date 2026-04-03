static zval *king_semantic_dns_find_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len
)
{
    zval *value_zv;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), primary_name, primary_name_len);
    if (value_zv != NULL || alias_name == NULL) {
        return value_zv;
    }

    return zend_hash_str_find(Z_ARRVAL_P(config), alias_name, alias_name_len);
}

static bool king_semantic_dns_require_bool_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    zend_bool *target
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_TRUE && Z_TYPE_P(value_zv) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type bool.",
            primary_name
        );
        return false;
    }

    *target = (Z_TYPE_P(value_zv) == IS_TRUE);
    return true;
}

static bool king_semantic_dns_require_positive_long_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint32_t *target,
    uint32_t max_value
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_LONG) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type int.",
            primary_name
        );
        return false;
    }

    if (Z_LVAL_P(value_zv) <= 0 || (max_value > 0 && Z_LVAL_P(value_zv) > (zend_long) max_value)) {
        if (max_value > 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be between 1 and %u.",
                primary_name,
                max_value
            );
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be greater than 0.",
                primary_name
            );
        }
        return false;
    }

    *target = (uint32_t) Z_LVAL_P(value_zv);
    return true;
}

static bool king_semantic_dns_require_port_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint16_t *target
)
{
    uint32_t parsed_value = 0;

    if (!king_semantic_dns_require_positive_long_option(
            config,
            primary_name,
            primary_name_len,
            alias_name,
            alias_name_len,
            &parsed_value,
            65535
        )) {
        return false;
    }

    if (parsed_value == 0) {
        return true;
    }

    *target = (uint16_t) parsed_value;
    return true;
}

static bool king_semantic_dns_require_non_empty_string_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    char *target,
    size_t target_size
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type string.",
            primary_name
        );
        return false;
    }

    if (Z_STRLEN_P(value_zv) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' cannot be empty.",
            primary_name
        );
        return false;
    }

    strncpy(target, Z_STRVAL_P(value_zv), target_size - 1);
    target[target_size - 1] = '\0';
    return true;
}

static bool king_semantic_dns_reject_unsupported_option(
    zval *config,
    const char *option_name,
    size_t option_name_len
)
{
    if (zend_hash_str_exists(Z_ARRVAL_P(config), option_name, option_name_len)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS v1 does not support init option '%s'.",
            option_name
        );
        return false;
    }

    return true;
}

static bool king_semantic_dns_parse_init_config(
    zval *config,
    king_semantic_dns_config_t *parsed
)
{
    zval *value_zv;

    king_semantic_dns_config_assign_defaults(parsed);

    if (!king_semantic_dns_reject_unsupported_option(
            config,
            "server_enable_tcp",
            sizeof("server_enable_tcp") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "health_check_interval_ms",
            sizeof("health_check_interval_ms") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "mothernode_sync_interval_sec",
            sizeof("mothernode_sync_interval_sec") - 1
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    if (!king_semantic_dns_require_bool_option(
            config,
            "enabled",
            sizeof("enabled") - 1,
            "server_enable",
            sizeof("server_enable") - 1,
            &parsed->enabled
        )
        || !king_semantic_dns_require_port_option(
            config,
            "dns_port",
            sizeof("dns_port") - 1,
            "server_port",
            sizeof("server_port") - 1,
            &parsed->dns_port
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "bind_address",
            sizeof("bind_address") - 1,
            "server_bind_host",
            sizeof("server_bind_host") - 1,
            parsed->bind_address,
            sizeof(parsed->bind_address)
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "default_record_ttl_sec",
            sizeof("default_record_ttl_sec") - 1,
            "service_ttl_seconds",
            sizeof("service_ttl_seconds") - 1,
            &parsed->service_ttl_seconds,
            0
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "service_discovery_max_ips_per_response",
            sizeof("service_discovery_max_ips_per_response") - 1,
            "max_services_per_type",
            sizeof("max_services_per_type") - 1,
            &parsed->service_discovery_max_ips_per_response,
            0
        )
        || !king_semantic_dns_require_bool_option(
            config,
            "semantic_mode_enable",
            sizeof("semantic_mode_enable") - 1,
            NULL,
            0,
            &parsed->semantic_mode_enable
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "mothernode_uri",
            sizeof("mothernode_uri") - 1,
            NULL,
            0,
            parsed->mothernode_uri,
            sizeof(parsed->mothernode_uri)
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    parsed->max_services_per_type = parsed->service_discovery_max_ips_per_response;

    value_zv = zend_hash_str_find(
        Z_ARRVAL_P(config),
        "routing_policies",
        sizeof("routing_policies") - 1
    );
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            king_semantic_dns_config_clear(parsed);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option 'routing_policies' must be of type array."
            );
            return false;
        }

        /* The current core/server-state slice validates routing hints but does
         * not persist request-owned zvals across module lifetime yet. */
    }

    return true;
}

static bool king_semantic_dns_runtime_require_initialized(const char *function_name)
{
    if (king_semantic_dns_runtime.initialized) {
        return true;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() requires prior king_semantic_dns_init().",
        function_name
    );
    return false;
}

#include "include/king_globals.h"

