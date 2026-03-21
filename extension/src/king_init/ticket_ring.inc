/*
 * Keeps the shared ADR-001 ticket-ring lifecycle grouped while splitting slot
 * hygiene, shared-memory lifecycle and public put/get access into bounded
 * subfragments.
 */

#include "ticket_ring/support.inc"
#include "ticket_ring/lifecycle.inc"
#include "ticket_ring/api.inc"
