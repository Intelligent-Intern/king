export function reportSfuClientUnavailableAfterEncode({
  getSfuClientBufferedAmount,
  handleWlvcFrameSendFailure,
  trackId,
  trace,
  timestamp,
}) {
  handleWlvcFrameSendFailure(
    getSfuClientBufferedAmount(),
    trackId,
    'sfu_client_unavailable_after_encode',
    {
      reason: 'sfu_client_unavailable_after_encode',
      stage: 'sfu_client_send_ready',
      source: 'publisher_pipeline',
      transportPath: 'publisher_sfu_client',
      message: 'SFU client disappeared before an encoded publisher frame could be sent.',
      publisherFrameTraceId: trace?.id || '',
      publisherPathTraceStages: trace?.stages?.join?.('>') || '',
      timestamp,
    },
  );
}
