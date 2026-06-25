#include "php_king/init.h"
#include "config/security_and_traffic/index.h"
#include "config/tls_and_crypto/index.h"
#include "config/tcp_transport/index.h"
#include "config/quic_transport/index.h"
#include "config/http2/index.h"
#include "config/app_http3_websockets_webtransport/index.h"
#include "config/cluster_and_process/index.h"
#include "config/bare_metal_tuning/index.h"
#include "config/cloud_autoscale/index.h"
#include "config/dynamic_admin_api/index.h"
#include "config/native_cdn/index.h"
#include "config/native_object_store/index.h"
#include "config/open_telemetry/index.h"
#include "config/router_and_loadbalancer/index.h"
#include "config/state_management/index.h"
#include "config/smart_dns/index.h"
#include "config/iibin/index.h"
#include "config/mcp_and_orchestrator/index.h"
#include "config/high_perf_compute_and_ai/index.h"
#include "config/semantic_geometry/index.h"
#include "config/smart_contracts/index.h"
#include "config/ssh_over_quic/index.h"
#include "config/tls_and_crypto/base_layer.h"
#include "php_king/globals.h"
#include "php_king.h"

#include <errno.h>
#include <fcntl.h>
#include <pthread.h>
#include <stdint.h>
#include <stdio.h>
#include <string.h>
#include <sys/file.h>
#include <sys/mman.h>
#include <sys/random.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

/*
 * Keeps the root lifecycle compile unit compact while delegating the ticket
 * ring and module/request hooks into bounded fragments.
 */

#include "king_init/state.inc"
#include "king_init/ticket_ring.inc"
#include "king_init/modules.inc"
#include "king_init/request.inc"
