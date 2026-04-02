/*
 * =========================================================================
 * FILENAME:   src/config/tls_and_crypto/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the TLS and crypto config family. This slice
 * seeds the baseline verification depth, trust and identity placeholders,
 * cipher and curve policy, ticket / 0-RTT settings, OCSP behavior, and the
 * disabled-at-rest / MCP encryption flags before INI and any allowed
 * userland overrides refine the live crypto snapshot.
 * =========================================================================
 */

#include "include/config/tls_and_crypto/default.h"
#include "include/config/tls_and_crypto/base_layer.h"

void kg_config_tls_and_crypto_defaults_load(void)
{
    king_tls_and_crypto_config.tls_verify_peer = true;
    king_tls_and_crypto_config.tls_verify_depth = 10;
    king_tls_and_crypto_config.tls_default_ca_file = pestrdup("", 1);
    king_tls_and_crypto_config.tls_default_cert_file = pestrdup("", 1);
    king_tls_and_crypto_config.tls_default_key_file = pestrdup("", 1);
    king_tls_and_crypto_config.tls_ticket_key_file = pestrdup("", 1);
    king_tls_and_crypto_config.tls_ciphers_tls13 = pestrdup(
        "TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384:TLS_CHACHA20_POLY1305_SHA256",
        1);
    king_tls_and_crypto_config.tls_ciphers_tls12 = pestrdup(
        "ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384",
        1);
    king_tls_and_crypto_config.tls_curves = pestrdup("P-256:X25519", 1);
    king_tls_and_crypto_config.tls_session_ticket_lifetime_sec = 7200;
    king_tls_and_crypto_config.tls_enable_early_data = false;
    king_tls_and_crypto_config.tls_server_0rtt_cache_size = 100000;
    king_tls_and_crypto_config.tls_enable_ocsp_stapling = true;
    king_tls_and_crypto_config.tcp_tls_min_version_allowed = pestrdup("TLSv1.2", 1);
    king_tls_and_crypto_config.storage_encryption_at_rest_enable = false;
    king_tls_and_crypto_config.storage_encryption_algorithm = pestrdup("", 1);
    king_tls_and_crypto_config.storage_encryption_key_path = pestrdup("", 1);
    king_tls_and_crypto_config.mcp_payload_encryption_enable = false;
    king_tls_and_crypto_config.mcp_payload_encryption_psk_env_var = pestrdup("", 1);
    king_tls_and_crypto_config.tls_enable_ech = false;
    king_tls_and_crypto_config.tls_require_ct_policy = false;
    king_tls_and_crypto_config.tls_disable_sni_validation = false;
    king_tls_and_crypto_config.transport_disable_encryption = false;
}
