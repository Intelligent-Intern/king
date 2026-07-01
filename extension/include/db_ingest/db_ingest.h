/*
 * include/db_ingest/db_ingest.h - Public database-ingest entry point
 * ==================================================================
 *
 * This header declares the exported PHP function behind King's host-local
 * serialized writer lane.
 */

#ifndef KING_DB_INGEST_H
#define KING_DB_INGEST_H

#include <php.h>

PHP_FUNCTION(king_db_ingest);

#endif /* KING_DB_INGEST_H */
