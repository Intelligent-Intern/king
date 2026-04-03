/*
 * Root object-store registry slice: implements the public CRUD, stream,
 * metadata, backup/restore, and option-validation helpers for the active
 * object-store runtime, including mutation locking and strict option
 * validation for cloud/header-facing metadata.
 */

#include "object_store/object_store_internal.h"
#include "main/php_streams.h"
#include "ext/hash/php_hash.h"
#include "ext/hash/php_hash_sha.h"
#include <inttypes.h>
#include <ctype.h>
#include <unistd.h>


#include "registry/options_and_preconditions.inc"
#include "registry/streaming_helpers.inc"
#include "registry/write_api.inc"
#include "registry/read_and_maintenance_api.inc"
