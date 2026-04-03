/*
 * Durable-state slice for Semantic-DNS. Persists and reloads the bounded
 * topology/runtime payload so local server state, registry-derived topology
 * and semantic-mode recovery survive restart.
 */

#include "semantic_dns/semantic_dns_internal.h"
#include <ext/standard/php_var.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <stdio.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>
#include <string.h>
#include <stdint.h>
#include <zend_smart_str.h>

#define KING_SEMANTIC_DNS_STATE_DIR "/tmp/king_semantic_dns_state"
#define KING_SEMANTIC_DNS_STATE_FILE KING_SEMANTIC_DNS_STATE_DIR "/durable_state.bin"
#define KING_SEMANTIC_DNS_STATE_LOCK_FILE KING_SEMANTIC_DNS_STATE_DIR "/durable_state.bin.lock"
#define KING_SEMANTIC_DNS_STATE_MAGIC 0x53444e53 /* 'SDNS' */
#define KING_SEMANTIC_DNS_STATE_VERSION 1
#define KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES 1024U
#define KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES (16U * 1024U * 1024U)

#include "state/serialization_and_locking.inc"
#include "state/transaction_api.inc"
#include "state/save.inc"
#include "state/load.inc"
#include "state/snapshot_files.inc"
