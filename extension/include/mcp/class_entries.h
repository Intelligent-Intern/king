/*
 * include/mcp/class_entries.h - MCP class-entry externs
 */

#ifndef KING_MCP_CLASS_ENTRIES_H
#define KING_MCP_CLASS_ENTRIES_H

#include <php.h>

extern zend_class_entry *king_ce_mcp;
extern zend_class_entry *king_ce_mcp_server;
extern zend_class_entry *king_ce_mcp_exception;
extern zend_class_entry *king_ce_mcp_connection_error;
extern zend_class_entry *king_ce_mcp_protocol_error;
extern zend_class_entry *king_ce_mcp_timeout;
extern zend_class_entry *king_ce_mcp_data_error;

#endif /* KING_MCP_CLASS_ENTRIES_H */
