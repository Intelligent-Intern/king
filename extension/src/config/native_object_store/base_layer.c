/*
 * =========================================================================
 * FILENAME:   src/config/native_object_store/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the native object-store config
 * family. Enablement, S3-compatibility, versioning, redundancy mode,
 * erasure-coding layout, replication/chunk sizing, metadata/discovery
 * endpoints, cache policy, and direct-storage settings all land in the
 * single `king_native_object_store_config` snapshot.
 * =========================================================================
 */

#include "include/config/native_object_store/base_layer.h"

kg_native_object_store_config_t king_native_object_store_config;
