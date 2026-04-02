/*
 * =========================================================================
 * FILENAME:   src/config/smart_dns/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the Smart-DNS config family.
 * Server enablement, bind host and port, TTL and response fan-out limits,
 * the bounded v1 mode selector, semantic-mode toggle, mothernode URI, and
 * the system-managed live-probe allowlist all land in the single
 * `king_smart_dns_config` snapshot.
 * =========================================================================
 */

#include "include/config/smart_dns/base_layer.h"

kg_smart_dns_config_t king_smart_dns_config = {0};
