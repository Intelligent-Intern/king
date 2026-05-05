#ifndef KING_RUNTIME_LIBCURL_CANDIDATES_H
#define KING_RUNTIME_LIBCURL_CANDIDATES_H

#if defined(__APPLE__)
# define KING_LIBCURL_RUNTIME_CANDIDATES \
    "/opt/homebrew/opt/curl/lib/libcurl.4.dylib", \
    "/opt/homebrew/opt/curl/lib/libcurl.dylib", \
    "/usr/local/opt/curl/lib/libcurl.4.dylib", \
    "/usr/local/opt/curl/lib/libcurl.dylib", \
    "libcurl.4.dylib", \
    "libcurl.dylib", \
    "libcurl.so.4", \
    "libcurl.so", \
    NULL
# define KING_LIBCURL_RUNTIME_CANDIDATE_NAMES \
    "libcurl.4.dylib, libcurl.dylib, libcurl.so.4, or libcurl.so"
#elif defined(__linux__)
# define KING_LIBCURL_RUNTIME_CANDIDATES \
    "libcurl.so.4", \
    "libcurl.so", \
    NULL
# define KING_LIBCURL_RUNTIME_CANDIDATE_NAMES \
    "libcurl.so.4 or libcurl.so"
#else
# define KING_LIBCURL_RUNTIME_CANDIDATES \
    "libcurl.4.dylib", \
    "libcurl.dylib", \
    "libcurl.so.4", \
    "libcurl.so", \
    NULL
# define KING_LIBCURL_RUNTIME_CANDIDATE_NAMES \
    "libcurl.4.dylib, libcurl.dylib, libcurl.so.4, or libcurl.so"
#endif

#endif
