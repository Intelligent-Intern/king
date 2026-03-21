/*
 * =========================================================================
 * FILENAME:   src/config/config.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Implements the master King\Config resource lifecycle.
 *
 * SKELETON STATUS:
 * king_config_new_from_options() allocates a real composed king_cfg_t
 * snapshot from the active module globals. In the current skeleton build it
 * materializes the safe per-resource override surface that is already wired
 * for network, data/observability, autoscale, MCP/orchestrator, geometry,
 * smart-contract, and SSH-gateway config families. king_connect() can
 * consume and freeze this resource.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/config/cloud_autoscale/config.h"
#include "include/config/config.h"
#include "include/config/http2/config.h"
#include "include/config/mcp_and_orchestrator/config.h"
#include "include/config/native_cdn/config.h"
#include "include/config/native_object_store/config.h"
#include "include/config/open_telemetry/config.h"
#include "include/config/quic_transport/config.h"
#include "include/config/semantic_geometry/config.h"
#include "include/config/smart_contracts/config.h"
#include "include/config/smart_dns/config.h"
#include "include/config/ssh_over_quic/config.h"
#include "include/config/tcp_transport/config.h"
#include "include/config/tls_and_crypto/config.h"
#include "include/king_globals.h"
#include <ext/spl/spl_exceptions.h>
#include "zend_exceptions.h"

extern int le_king_cfg;

typedef enum _king_config_override_module_t {
    KING_CONFIG_OVERRIDE_NONE = 0,
    KING_CONFIG_OVERRIDE_TLS,
    KING_CONFIG_OVERRIDE_QUIC,
    KING_CONFIG_OVERRIDE_HTTP2,
    KING_CONFIG_OVERRIDE_TCP,
    KING_CONFIG_OVERRIDE_AUTOSCALE,
    KING_CONFIG_OVERRIDE_MCP_ORCHESTRATOR,
    KING_CONFIG_OVERRIDE_GEOMETRY,
    KING_CONFIG_OVERRIDE_SMART_CONTRACT,
    KING_CONFIG_OVERRIDE_SSH,
    KING_CONFIG_OVERRIDE_STORAGE,
    KING_CONFIG_OVERRIDE_CDN,
    KING_CONFIG_OVERRIDE_DNS,
    KING_CONFIG_OVERRIDE_OTEL
} king_config_override_module_t;

#include "internal/owned_strings.inc"
#include "internal/snapshot.inc"
#include "internal/overrides.inc"
#include "internal/api.inc"
#include "internal/object.inc"
