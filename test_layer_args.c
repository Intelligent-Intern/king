#include <stdio.h>
#include <ggml.h>
#include <llama.h>

int main(int argc, char **argv) {
    struct llama_model_params mparams = llama_model_params_from_gpt_params(NULL);
    struct llama_context_params cparams = llama_context_params_default();
    
    cparams.layer_start = 0;
    cparams.layer_end = 0;
    
    struct llama_model *model = llama_load_model_from_file(argv[1], mparams);
    if (!model) { fprintf(stderr, "failed to load model\n"); return 1; }
    
    struct llama_context *ctx = llama_new_context_with_model(model, cparams);
    if (!ctx) { fprintf(stderr, "failed to create context\n"); return 1; }
    
    printf("layer_start=%d, layer_end=%d\n", cparams.layer_start, cparams.layer_end);
    return 0;
}