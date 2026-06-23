#ifndef KING_RUNTIME_SAXONC_CANDIDATES_H
#define KING_RUNTIME_SAXONC_CANDIDATES_H

#if defined(__APPLE__)
# define KING_SAXONC_RUNTIME_CANDIDATES \
    "libsaxon-hec.dylib", \
    "libsaxon-hec-13.0.dylib", \
    "libsaxon-hec-12.9.dylib", \
    "libsaxon-pec.dylib", \
    "libsaxon-eec.dylib", \
    "libsaxonc.dylib", \
    NULL
# define KING_SAXONC_RUNTIME_CANDIDATE_NAMES \
    "libsaxon-hec.dylib, libsaxon-hec-13.0.dylib, libsaxon-hec-12.9.dylib, libsaxon-pec.dylib, libsaxon-eec.dylib, or libsaxonc.dylib"
#elif defined(__linux__)
# define KING_SAXONC_RUNTIME_CANDIDATES \
    "libsaxon-hec.so", \
    "libsaxon-hec-13.0.so", \
    "libsaxon-hec-12.9.so", \
    "libsaxon-pec.so", \
    "libsaxon-eec.so", \
    "libsaxonc.so", \
    NULL
# define KING_SAXONC_RUNTIME_CANDIDATE_NAMES \
    "libsaxon-hec.so, libsaxon-hec-13.0.so, libsaxon-hec-12.9.so, libsaxon-pec.so, libsaxon-eec.so, or libsaxonc.so"
#else
# define KING_SAXONC_RUNTIME_CANDIDATES \
    "libsaxon-hec.so", \
    "libsaxon-hec.dylib", \
    "libsaxon-pec.so", \
    "libsaxon-eec.so", \
    "libsaxonc.so", \
    NULL
# define KING_SAXONC_RUNTIME_CANDIDATE_NAMES \
    "libsaxon-hec, libsaxon-pec, libsaxon-eec, or libsaxonc"
#endif

#endif
