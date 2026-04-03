/*
 * src/autoscaling/autoscaling.c - Local Autoscaling Controller Runtime
 * =========================================================================
 *
 * This file owns the in-process autoscaling controller state: config copy,
 * persisted managed-node inventory, live telemetry/system signal collection,
 * monitor-tick decision logic, and the public PHP entry points for init,
 * status/metrics reads, and managed-node lifecycle transitions.
 * =========================================================================
 */
#include "php_king.h"
#include "include/autoscaling/autoscaling.h"
#include "include/config/cloud_autoscale/base_layer.h"
#include "include/config/cloud_autoscale/config.h"
#include "include/king_globals.h"
#include "include/telemetry/telemetry.h"
#include "autoscaling/autoscaling_internal.h"

#include <errno.h>
#include <stdio.h>
#include <strings.h>
#include <time.h>
#include <unistd.h>

#define KING_AUTOSCALING_STATE_VERSION 2

typedef struct _king_autoscaling_signal_snapshot_t {
    king_load_metrics_t metrics;
    zend_bool telemetry_signal_present;
    zend_bool system_signal_present;
    zend_bool cpu_live;
    zend_bool memory_live;
    zend_bool active_connections_live;
    zend_bool requests_per_second_live;
    zend_bool response_time_live;
    zend_bool queue_depth_live;
} king_autoscaling_signal_snapshot_t;

typedef struct _king_autoscaling_signal_descriptor_t {
    uint32_t bit;
    const char *name;
} king_autoscaling_signal_descriptor_t;

#define KING_AUTOSCALING_SIGNAL_CPU                (1u << 0)
#define KING_AUTOSCALING_SIGNAL_MEMORY             (1u << 1)
#define KING_AUTOSCALING_SIGNAL_ACTIVE_CONNECTIONS (1u << 2)
#define KING_AUTOSCALING_SIGNAL_REQUESTS_PER_SECOND (1u << 3)
#define KING_AUTOSCALING_SIGNAL_RESPONSE_TIME      (1u << 4)
#define KING_AUTOSCALING_SIGNAL_QUEUE_DEPTH        (1u << 5)

static const king_autoscaling_signal_descriptor_t king_autoscaling_signal_descriptors[] = {
    {KING_AUTOSCALING_SIGNAL_CPU, "cpu"},
    {KING_AUTOSCALING_SIGNAL_MEMORY, "memory"},
    {KING_AUTOSCALING_SIGNAL_ACTIVE_CONNECTIONS, "active_connections"},
    {KING_AUTOSCALING_SIGNAL_REQUESTS_PER_SECOND, "requests_per_second"},
    {KING_AUTOSCALING_SIGNAL_RESPONSE_TIME, "response_time_ms"},
    {KING_AUTOSCALING_SIGNAL_QUEUE_DEPTH, "queue_depth"},
    {0u, NULL}
};

king_autoscaling_runtime_state_t king_autoscaling_runtime = {0};
uint32_t king_current_instances = 1;


#include "autoscaling/runtime_config.inc"
#include "autoscaling/signal_and_monitoring.inc"
#include "autoscaling/runtime_state.inc"
#include "autoscaling/public_api.inc"
