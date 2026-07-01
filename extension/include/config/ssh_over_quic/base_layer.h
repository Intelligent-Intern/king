/*
 * =========================================================================
 * FILENAME:   include/config/ssh_over_quic/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for SSH-over-QUIC.
 *
 * ARCHITECTURE:
 * This struct stores the gateway listener, target mapping, and session
 * control settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_SSH_OVER_QUIC_BASE_H
#define KING_CONFIG_SSH_OVER_QUIC_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_ssh_over_quic_config_t {
    /* --- Gateway Listener Configuration --- */
    bool gateway_enable;
    char *gateway_listen_host;
    zend_long gateway_listen_port;

    /* --- Default Upstream Target --- */
    char *gateway_default_target_host;
    zend_long gateway_default_target_port;
    zend_long gateway_target_connect_timeout_ms;

    /* --- Authentication & Target Mapping --- */
    char *gateway_auth_mode;
    char *gateway_mcp_auth_agent_uri;
    char *gateway_target_mapping_mode;
    char *gateway_user_profile_agent_uri;

    /* --- Session Control & Logging --- */
    zend_long gateway_idle_timeout_sec;
    bool gateway_log_session_activity;

} kg_ssh_over_quic_config_t;

/* Module-global configuration instance. */
extern kg_ssh_over_quic_config_t king_ssh_over_quic_config;

#endif /* KING_CONFIG_SSH_OVER_QUIC_BASE_H */
