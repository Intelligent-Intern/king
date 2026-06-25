/*
 * include/server/registration.h - Server MINIT hooks
 */

#ifndef KING_SERVER_REGISTRATION_H
#define KING_SERVER_REGISTRATION_H

#include <php.h>

void king_server_register_websocket_classes(void);
void king_server_init_object_handlers(void);

#endif /* KING_SERVER_REGISTRATION_H */
