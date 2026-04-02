/*
 * =========================================================================
 * FILENAME:   src/config/ssh_over_quic/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the SSH-over-QUIC config family. This slice
 * seeds the baseline gateway-disabled state, bind/target endpoints, auth
 * and mapping modes, MCP/user-profile agent placeholders, timeout values,
 * and session-activity logging before INI and any allowed userland
 * overrides refine the live snapshot.
 * =========================================================================
 */

#include "include/config/ssh_over_quic/default.h"
#include "include/config/ssh_over_quic/base_layer.h"

void kg_config_ssh_over_quic_defaults_load(void)
{
    king_ssh_over_quic_config.gateway_enable = false;
    king_ssh_over_quic_config.gateway_listen_host = pestrdup("0.0.0.0", 1);
    king_ssh_over_quic_config.gateway_listen_port = 2222;
    king_ssh_over_quic_config.gateway_default_target_host = pestrdup("127.0.0.1", 1);
    king_ssh_over_quic_config.gateway_default_target_port = 22;
    king_ssh_over_quic_config.gateway_target_connect_timeout_ms = 5000;
    king_ssh_over_quic_config.gateway_auth_mode = pestrdup("mtls", 1);
    king_ssh_over_quic_config.gateway_mcp_auth_agent_uri = pestrdup("", 1);
    king_ssh_over_quic_config.gateway_target_mapping_mode = pestrdup("static", 1);
    king_ssh_over_quic_config.gateway_user_profile_agent_uri = pestrdup("", 1);
    king_ssh_over_quic_config.gateway_idle_timeout_sec = 1800;
    king_ssh_over_quic_config.gateway_log_session_activity = true;
}
