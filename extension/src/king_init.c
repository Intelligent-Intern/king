#include "include/king_init.h"
#include <king_base64.h>
#include <king_json.h>
#include <king_url.h>
#include "include/config/security_and_traffic/index.h"
#include "include/config/tls_and_crypto/index.h"
#include "include/config/tcp_transport/index.h"
#include "include/config/quic_transport/index.h"
#include "include/config/http2/index.h"
#include "include/config/app_http3_websockets_webtransport/index.h"
#include "include/config/cluster_and_process/index.h"
#include "include/config/bare_metal_tuning/index.h"
#include "include/config/cloud_autoscale/index.h"
#include "include/config/dynamic_admin_api/index.h"
#include "include/config/native_cdn/index.h"
#include "include/config/native_object_store/index.h"
#include "include/config/open_telemetry/index.h"
#include "include/config/router_and_loadbalancer/index.h"
#include "include/config/state_management/index.h"
#include "include/config/smart_dns/index.h"
#include "include/config/iibin/index.h"
#include "include/config/mcp_and_orchestrator/index.h"
#include "include/config/high_perf_compute_and_ai/index.h"
#include "include/config/semantic_geometry/index.h"
#include "include/config/smart_contracts/index.h"
#include "include/config/ssh_over_quic/index.h"
#include "include/config/tls_and_crypto/base_layer.h"
#include "include/king_globals.h"
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
