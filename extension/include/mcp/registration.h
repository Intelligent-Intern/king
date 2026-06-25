/*
 * include/mcp/registration.h - MCP MINIT registration hooks
 */

#ifndef KING_MCP_REGISTRATION_H
#define KING_MCP_REGISTRATION_H

#include <php.h>
#include "class_methods.h"

void king_mcp_register_exception_classes(void);
void king_mcp_register_classes(void);
void king_mcp_init_object_handlers(void);

#endif /* KING_MCP_REGISTRATION_H */
