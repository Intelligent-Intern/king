/*
 * include/mcp/mcp.h - Public C API for MCP operations
 * ====================================================
 *
 * This header exposes the low-level MCP client functions used by the
 * extension's orchestrator and PHP wrapper code.
 */

#ifndef KING_MCP_H
#define KING_MCP_H

#include <php.h>

/* Opens an MCP connection over QUIC. */
PHP_FUNCTION(king_mcp_connect);

/* Closes an MCP connection resource. */
PHP_FUNCTION(king_mcp_close);

/* Sends a unary request and returns the binary response. */
PHP_FUNCTION(king_mcp_request);

/* Uploads data from a readable PHP stream. */
PHP_FUNCTION(king_mcp_upload_from_stream);

/* Downloads data into a writable PHP stream. */
PHP_FUNCTION(king_mcp_download_to_stream);

/* Returns the last MCP error message. */
PHP_FUNCTION(king_mcp_get_error);

#endif /* KING_MCP_H */
