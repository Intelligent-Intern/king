#include "include/config/quic_transport/ini.h"
#include "include/config/quic_transport/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <zend_ini.h>
#include <ext/spl/spl_exceptions.h>
#include <strings.h>

static void quic_transport_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

/*
 * The ZEND_INI_ENTRY1_EX() entries in this module pass a direct field pointer
 * in mh_arg1, so the numeric handlers write to that destination explicitly.
 */
static ZEND_INI_MH(OnUpdateQuicPositiveLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for a QUIC transport directive. A positive integer is required.");
        return FAILURE;
    }

    *(zend_long *) mh_arg1 = value;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateQuicNonNegativeLong)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value < 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for a QUIC transport directive. A non-negative integer is required.");
        return FAILURE;
    }

    *(zend_long *) mh_arg1 = value;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAckDelayExponent)
{
    zend_long value = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (value < 0 || value > 20) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for ACK delay exponent. Must be an integer between 0 and 20.");
        return FAILURE;
    }

    king_quic_transport_config.ack_delay_exponent = value;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateCcAlgorithm)
{
    const char *allowed[] = {"cubic", "bbr", NULL};
    bool is_allowed = false;

    for (int i = 0; allowed[i] != NULL; i++) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value for CC algorithm. Must be 'cubic' or 'bbr'.");
        return FAILURE;
    }

    quic_transport_replace_string((char **) mh_arg1, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY1_EX("king.transport_cc_algorithm", "cubic", PHP_INI_SYSTEM,
        OnUpdateCcAlgorithm, &king_quic_transport_config.cc_algorithm, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_cc_initial_cwnd_packets", "32", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.cc_initial_cwnd_packets, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_cc_min_cwnd_packets", "4", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.cc_min_cwnd_packets, NULL)
    STD_PHP_INI_ENTRY("king.transport_cc_enable_hystart_plus_plus", "1", PHP_INI_SYSTEM, OnUpdateBool,
        cc_enable_hystart_plus_plus, kg_quic_transport_config_t, king_quic_transport_config)
    STD_PHP_INI_ENTRY("king.transport_pacing_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        pacing_enable, kg_quic_transport_config_t, king_quic_transport_config)
    ZEND_INI_ENTRY1_EX("king.transport_pacing_max_burst_packets", "10", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.pacing_max_burst_packets, NULL)

    ZEND_INI_ENTRY1_EX("king.transport_max_ack_delay_ms", "25", PHP_INI_SYSTEM,
        OnUpdateQuicNonNegativeLong, &king_quic_transport_config.max_ack_delay_ms, NULL)
    ZEND_INI_ENTRY_EX("king.transport_ack_delay_exponent", "3", PHP_INI_SYSTEM,
        OnUpdateAckDelayExponent, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_pto_timeout_ms_initial", "1000", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.pto_timeout_ms_initial, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_pto_timeout_ms_max", "60000", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.pto_timeout_ms_max, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_max_pto_probes", "5", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.max_pto_probes, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_ping_interval_ms", "15000", PHP_INI_SYSTEM,
        OnUpdateQuicNonNegativeLong, &king_quic_transport_config.ping_interval_ms, NULL)

    ZEND_INI_ENTRY1_EX("king.transport_initial_max_data", "10485760", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_data, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_initial_max_stream_data_bidi_local", "1048576", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_stream_data_bidi_local, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_initial_max_stream_data_bidi_remote", "1048576", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_stream_data_bidi_remote, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_initial_max_stream_data_uni", "1048576", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_stream_data_uni, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_initial_max_streams_bidi", "100", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_streams_bidi, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_initial_max_streams_uni", "100", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.initial_max_streams_uni, NULL)

    ZEND_INI_ENTRY1_EX("king.transport_active_connection_id_limit", "8", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.active_connection_id_limit, NULL)
    STD_PHP_INI_ENTRY("king.transport_stateless_retry_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        stateless_retry_enable, kg_quic_transport_config_t, king_quic_transport_config)
    STD_PHP_INI_ENTRY("king.transport_grease_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        grease_enable, kg_quic_transport_config_t, king_quic_transport_config)
    STD_PHP_INI_ENTRY("king.transport_datagrams_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        datagrams_enable, kg_quic_transport_config_t, king_quic_transport_config)
    ZEND_INI_ENTRY1_EX("king.transport_dgram_recv_queue_len", "1024", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.dgram_recv_queue_len, NULL)
    ZEND_INI_ENTRY1_EX("king.transport_dgram_send_queue_len", "1024", PHP_INI_SYSTEM,
        OnUpdateQuicPositiveLong, &king_quic_transport_config.dgram_send_queue_len, NULL)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_quic_transport_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_quic_transport_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
