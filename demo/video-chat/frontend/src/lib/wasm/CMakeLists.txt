# CMakeLists.txt — WLVC Wavelet Video Codec (Emscripten/WASM)
#
# Build:
#   mkdir -p build && cd build
#   emcmake cmake .. -DCMAKE_BUILD_TYPE=Release
#   emmake make
#
# Output: wlvc.js + wlvc.wasm

cmake_minimum_required(VERSION 3.10)
project(wlvc CXX)

set(CMAKE_CXX_STANDARD 17)
set(CMAKE_CXX_STANDARD_REQUIRED ON)

# Source files
set(SOURCES
    cpp/dwt.cpp
    cpp/quantize.cpp
    cpp/entropy.cpp
    cpp/motion.cpp
    cpp/audio.cpp
    cpp/codec.cpp
    cpp/exports.cpp
)

# Output target
add_executable(wlvc ${SOURCES})

# Emscripten-specific flags
if(EMSCRIPTEN)
    set(CMAKE_CXX_FLAGS "${CMAKE_CXX_FLAGS} -O3 -msimd128")
    
    set(EMSCRIPTEN_LINKER_FLAGS
        -s WASM=1
        -s ALLOW_MEMORY_GROWTH=1
        -s INITIAL_MEMORY=16777216
        -s MAXIMUM_MEMORY=134217728
        -s STACK_SIZE=1048576
        -s EXCEPTION_CATCHING=0
        -s DISABLE_EXCEPTION_CATCHING=1
    )

    set(CMAKE_EXE_LINKER_FLAGS "${CMAKE_EXE_LINKER_FLAGS} ${EMSCRIPTEN_LINKER_FLAGS}")

    # Output files: wlvc.js + wlvc.wasm
    set_target_properties(wlvc PROPERTIES
        OUTPUT_NAME "wlvc"
    )
endif()

# Install to parent directory (src/lib/wasm/)
install(FILES
    ${CMAKE_CURRENT_BINARY_DIR}/wlvc.js
    ${CMAKE_CURRENT_BINARY_DIR}/wlvc.wasm
    DESTINATION ${CMAKE_CURRENT_SOURCE_DIR}
)
