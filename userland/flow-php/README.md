# Repo-Local Flow PHP Userland Helpers

This directory holds repo-local PHP helpers for the active Flow PHP / ETL
integration batch.

It is not a published Composer package and it is not presented as the final
public package layout. The point is to keep real userland adapter code in the
repository while the contract is still being proven.

The current source adapter contract lives in
`userland/flow-php/src/StreamingSource.php`.

Current helpers:

- `King\Flow\ObjectStoreByteSource`
- `King\Flow\HttpByteSource`
- `King\Flow\McpByteSource`
- `King\Flow\SourceCursor`
- `King\Flow\SourcePumpResult`

The contract is intentionally small:

- pump bounded byte chunks without whole-payload materialization
- surface a serializable cursor after each delivered chunk
- allow restart by replay-and-skip or direct range-offset resume, depending on
  transport
- layer line-oriented record consumption on top through `pumpLines()`
