/*
 * Root Smart-DNS introspection aggregator: keeps runtime registry, routing,
 * topology, discovery, mother-node, and state-payload slices grouped in one
 * logical fragment while the deeper helpers remain in the subdirectory.
 */

#include "include/semantic_dns/semantic_dns_internal.h"
#include "semantic_dns/service_registry.inc"
#include "semantic_dns/live_signals.inc"
#include "semantic_dns/mother_nodes.inc"
#include "semantic_dns/topology.inc"
#include "semantic_dns/discovery.inc"
#include "semantic_dns/routing.inc"
#include "semantic_dns/state_payload.inc"
