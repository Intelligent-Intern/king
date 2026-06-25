/*
 * Database-ingest runtime binding. Owns the host-local serialized writer lane
 * PHP entry point outside of the core php_king bootstrap translation unit.
 */
#include "php_king.h"
#include "db_ingest/db_ingest.h"

#include "php_binding.inc"
