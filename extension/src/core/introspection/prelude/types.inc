/*
 * Prelude types slice: collects the shared includes, process-local registry
 * globals, and small runtime structs that multiple introspection fragments
 * reuse across Smart-DNS, CDN/object-store, telemetry, and proto helpers.
 */

#include "php.h"
#include "php_king.h"
#include <stdlib.h>
#include <string.h>
#include <time.h>
#include <sys/stat.h>
#include <zend_exceptions.h>
#include <zend_hash.h>
#include <zend_smart_str.h>

#include "include/king_globals.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/config/iibin/base_layer.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/config/native_cdn/base_layer.h"
#include "include/config/native_object_store/base_layer.h"
#include "include/config/open_telemetry/base_layer.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/iibin/iibin_internal.h"

HashTable king_semantic_dns_service_registry;
HashTable king_semantic_dns_mother_node_registry;
HashTable king_semantic_dns_mother_node_tombstone_registry;
HashTable king_cdn_cache_registry;
HashTable king_telemetry_metric_registry;

bool king_semantic_dns_registry_initialized = false;
bool king_cdn_cache_registry_initialized    = false;
bool king_telemetry_registry_initialized    = false;

typedef struct _king_semantic_dns_service {
    zend_string *service_id;
    zend_string *service_name;
    zend_string *service_type;
    zend_string *hostname;
    zend_string *status;
    HashTable attributes;
    zend_long port;
    zend_long current_load_percent;
    zend_long active_connections;
    zend_long total_requests;
    time_t last_health_check;
    time_t registered_at;
} king_semantic_dns_service;

typedef struct _king_semantic_dns_mother_node {
    zend_string *node_id;
    zend_string *hostname;
    zend_string *status;
    zend_long port;
    zend_long managed_services_count;
    double trust_score;
    time_t registered_at;
} king_semantic_dns_mother_node;

typedef struct _king_cdn_cache_entry {
    zend_string *object_id;
    zend_long payload_size;
    zend_string *retained_payload;
    time_t cached_at;
    time_t expires_at;
    uint64_t touch_serial;
    /* Edge-state tracking */
    zend_long served_count;
    time_t last_served_at;
} king_cdn_cache_entry;



typedef struct _king_telemetry_metric_record {
    zend_string *metric_name;
    zend_string *metric_type;
    HashTable labels;
    double value;
    time_t recorded_at;
} king_telemetry_metric_record;

static const char * const king_proto_primitive_types[] = {
    "double",
    "float",
    "int64",
    "uint64",
    "int32",
    "uint32",
    "sint32",
    "sint64",
    "fixed64",
    "sfixed64",
    "fixed32",
    "sfixed32",
    "bool",
    "string",
    "bytes"
};


static zend_always_inline const char *king_safe_string(const char *value)
{
    return value != NULL ? value : "";
}
