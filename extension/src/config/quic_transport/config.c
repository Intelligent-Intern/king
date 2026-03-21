#include "include/config/quic_transport/config.h"
#include "include/config/quic_transport/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int quic_apply_bool(zval *value, const char *param_name, bool *target)
{
    if (kg_validate_bool(value, param_name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int quic_apply_positive_long(zval *value, zend_long *target)
{
    return kg_validate_positive_long(value, target);
}

static int quic_apply_non_negative_long(zval *value, const char *param_name, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "%s must be a non-negative integer.", param_name);
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

int kg_config_quic_transport_apply_userland_config_to(
    kg_quic_transport_config_t *target,
    zval *config_arr)
{
    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "cc_algorithm")) {
            const char *allowed[] = {"cubic", "bbr", NULL};
            if (kg_validate_string_from_allowlist(value, allowed,
                    &target->cc_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cc_initial_cwnd_packets")) {
            if (quic_apply_positive_long(value, &target->cc_initial_cwnd_packets) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cc_min_cwnd_packets")) {
            if (quic_apply_positive_long(value, &target->cc_min_cwnd_packets) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cc_enable_hystart_plus_plus")) {
            if (quic_apply_bool(value, "cc_enable_hystart_plus_plus", &target->cc_enable_hystart_plus_plus) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "pacing_enable")) {
            if (quic_apply_bool(value, "pacing_enable", &target->pacing_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "pacing_max_burst_packets")) {
            if (quic_apply_positive_long(value, &target->pacing_max_burst_packets) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_ack_delay_ms")) {
            if (quic_apply_non_negative_long(value, "max_ack_delay_ms", &target->max_ack_delay_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ack_delay_exponent")) {
            if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0 || Z_LVAL_P(value) > 20) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
                    "ack_delay_exponent must be an integer between 0 and 20.");
                return FAILURE;
            }
            target->ack_delay_exponent = Z_LVAL_P(value);
        } else if (zend_string_equals_literal(key, "pto_timeout_ms_initial")) {
            if (quic_apply_positive_long(value, &target->pto_timeout_ms_initial) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "pto_timeout_ms_max")) {
            if (quic_apply_positive_long(value, &target->pto_timeout_ms_max) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_pto_probes")) {
            if (quic_apply_positive_long(value, &target->max_pto_probes) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ping_interval_ms")) {
            if (quic_apply_non_negative_long(value, "ping_interval_ms", &target->ping_interval_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_data")) {
            if (quic_apply_positive_long(value, &target->initial_max_data) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_stream_data_bidi_local")) {
            if (quic_apply_positive_long(value, &target->initial_max_stream_data_bidi_local) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_stream_data_bidi_remote")) {
            if (quic_apply_positive_long(value, &target->initial_max_stream_data_bidi_remote) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_stream_data_uni")) {
            if (quic_apply_positive_long(value, &target->initial_max_stream_data_uni) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_streams_bidi")) {
            if (quic_apply_positive_long(value, &target->initial_max_streams_bidi) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "initial_max_streams_uni")) {
            if (quic_apply_positive_long(value, &target->initial_max_streams_uni) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "active_connection_id_limit")) {
            if (quic_apply_positive_long(value, &target->active_connection_id_limit) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "stateless_retry_enable")) {
            if (quic_apply_bool(value, "stateless_retry_enable", &target->stateless_retry_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "grease_enable")) {
            if (quic_apply_bool(value, "grease_enable", &target->grease_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "datagrams_enable")) {
            if (quic_apply_bool(value, "datagrams_enable", &target->datagrams_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dgram_recv_queue_len")) {
            if (quic_apply_positive_long(value, &target->dgram_recv_queue_len) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "dgram_send_queue_len")) {
            if (quic_apply_positive_long(value, &target->dgram_send_queue_len) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_quic_transport_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_quic_transport_apply_userland_config_to(
        &king_quic_transport_config,
        config_arr
    );
}
