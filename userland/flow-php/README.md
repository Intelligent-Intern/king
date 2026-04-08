# Repo-Local Flow PHP Userland Helpers

This directory holds repo-local PHP helpers for the active Flow PHP / ETL
integration batch.

It is not a published Composer package and it is not presented as the final
public package layout. The point is to keep real userland adapter code in the
repository while the contract is still being proven.

The current source and sink adapter contracts live in
`userland/flow-php/src/StreamingSource.php` and
`userland/flow-php/src/StreamingSink.php`.

Current helpers:

- `King\Flow\ObjectStoreByteSource`
- `King\Flow\HttpByteSource`
- `King\Flow\McpByteSource`
- `King\Flow\SourceCursor`
- `King\Flow\SourcePumpResult`
- `King\Flow\ObjectStoreByteSink`
- `King\Flow\HttpByteSink`
- `King\Flow\McpByteSink`
- `King\Flow\SinkCursor`
- `King\Flow\SinkWriteResult`
- `King\Flow\SinkFailure`

The contract is intentionally small:

- pump bounded byte chunks without whole-payload materialization
- surface a serializable cursor after each delivered chunk
- allow restart by replay-and-skip or direct range-offset resume, depending on
  transport
- layer line-oriented record consumption on top through `pumpLines()`
- flush bounded byte writes without inventing whole-payload string staging as
  the public contract
- keep partial-failure state explicit through serializable sink cursors and
  failure results instead of transport-specific folklore
