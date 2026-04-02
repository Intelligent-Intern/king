/*
 * =========================================================================
 * FILENAME:   src/config/tls_and_crypto/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the TLS and crypto config
 * family. Peer verification, trust and identity material, cipher and curve
 * policy, ticket and 0-RTT settings, OCSP / ECH / CT / SNI toggles, at-
 * rest encryption, and MCP payload encryption all land in the single
 * `king_tls_and_crypto_config` snapshot.
 * =========================================================================
 */

#include "include/config/tls_and_crypto/base_layer.h"

kg_tls_and_crypto_config_t king_tls_and_crypto_config;
