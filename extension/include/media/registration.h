/*
 * include/media/registration.h - Media MINIT hooks
 */

#ifndef KING_MEDIA_REGISTRATION_H
#define KING_MEDIA_REGISTRATION_H

#include <php.h>

void king_media_register_classes(void);
void king_media_init_object_handlers(void);

#endif /* KING_MEDIA_REGISTRATION_H */
