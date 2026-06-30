# Fine-Tuning Preparation

King does not fine-tune an already quantized GGUF artifact in place. GGUF is
the local inference artifact. For supervised tuning, King uses the GGUF model as
the tokenizer and runtime compatibility reference, while the training step needs
a trainable base checkpoint for adapter training.

The first target is the compact King Coder baseline around `gemma3:1b`. That
model remains part of the normal runtime profile because it is useful for fast
local diagnostics and low-latency editor assistance. The fine-tune goal is not
general chat polish; it is command selection, exact output formatting, correct
King/PHP snippets, and predictable handling of local router contracts. A larger
GPU model may become the preferred interactive default, but the 1B baseline
must still carry the same capability floor and stay reproducible.

The repository tool for the first phase is:

```bash
bin/king-coder-fine-tune prepare \
  --model=var/inference-models/gemma3-1b.gguf \
  --out=var/fine-tuning/gemma3-1b-coder
```

That command runs through PHP with the King extension loaded. It extracts
source-grounded code examples from the King docs, builds OpenAI-style chat JSONL
for coder tuning, validates each example with `king_inference_tokenize()`, and
writes a reproducible run directory under `var/fine-tuning/`.

The output contains:

- `train.jsonl`
- `validation.jsonl`
- `manifest.json`
- `run.md`

The manifest deliberately separates the tokenizer artifact from the trainable
base checkpoint. A run without `--trainable-base=/path/to/checkpoint-dir` is a
prepared dataset, not a completed fine-tune. That is intentional: claiming a
fine-tuned model without training adapter weights would be worse than doing
nothing.

## Intended Flow

1. Build or install the King extension.
2. Prepare the coder dataset with `bin/king-coder-fine-tune prepare`.
3. Provide a real trainable base checkpoint that matches the runtime family.
4. Train an adapter against `train.jsonl` and evaluate against
   `validation.jsonl`.
5. Merge or load the adapter according to the selected runtime path.
6. Export the final runtime artifact and configure it through the normal King
   inference config.

The current implemented King-owned part is dataset extraction, tokenizer
validation, split generation, and run metadata. Native in-King optimizer and
backpropagation kernels are not implemented yet, so the tool fails closed
instead of pretending a training run happened.
