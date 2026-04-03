/*
 * Core Semantic-DNS runtime slice. Owns the config-backed init/start
 * lifecycle, the process-local server/runtime state, the bounded UDP DNS
 * listener, and the internal helper surface that the routing, mother-node,
 * and durable-state modules build on.
 */

#include "php_king.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/semantic_dns/semantic_dns.h"

#include <arpa/inet.h>
#include <errno.h>
#include <poll.h>
#include <signal.h>
#include <stdio.h>
#include <strings.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/wait.h>
#include <time.h>
#include <unistd.h>

#include "semantic_dns/semantic_dns_internal.h"

#define KING_SEMANTIC_DNS_WIRE_HEADER_BYTES 12
#define KING_SEMANTIC_DNS_WIRE_MAX_PACKET_BYTES 512
#define KING_SEMANTIC_DNS_WIRE_MAX_NAME_BYTES 255
#define KING_SEMANTIC_DNS_WIRE_MAX_ANSWERS 64

typedef struct _king_semantic_dns_wire_query {
    uint16_t id;
    uint16_t flags;
    uint16_t qtype;
    uint16_t qclass;
    size_t question_length;
    char qname[KING_SEMANTIC_DNS_WIRE_MAX_NAME_BYTES + 1];
} king_semantic_dns_wire_query;

typedef struct _king_semantic_dns_wire_candidate {
    struct in_addr address;
    int rank;
    zend_long current_load_percent;
    zend_long active_connections;
    zend_long total_requests;
    zend_long registered_at;
} king_semantic_dns_wire_candidate;

king_semantic_dns_runtime_state king_semantic_dns_runtime;


#include "semantic_dns/runtime_config.inc"
#include "semantic_dns/wire_listener.inc"
#include "semantic_dns/init_config.inc"
#include "semantic_dns/public_runtime_api.inc"
