/*
 * include/client/registration.h - Client and session MINIT hooks
 */

#ifndef KING_CLIENT_REGISTRATION_H
#define KING_CLIENT_REGISTRATION_H

#include <php.h>

void king_client_register_session_classes(void);
void king_client_register_http_classes(void);
void king_client_register_websocket_classes(void);
void king_client_init_object_handlers(void);
void king_client_init_session_object_handlers(void);

#endif /* KING_CLIENT_REGISTRATION_H */
