function normalizeBudgetNumber(value, fallback = 0) {
  const normalized = Number(value);
  if (!Number.isFinite(normalized)) return fallback;
  return Math.max(0, normalized);
}

export function resolveSfuReceiverCountAwareSendBudget({
  baseBufferedBytes = 0,
  baseWireBytesPerSecond = 0,
  chunkCount = 1,
  receiverCount = 0,
  websocketBufferedAmount = 0,
} = {}) {
  const normalizedReceiverCount = Math.max(0, Math.floor(normalizeBudgetNumber(receiverCount)));
  const effectiveReceiverCount = Math.max(1, normalizedReceiverCount);
  const normalizedChunkCount = Math.max(1, Math.floor(normalizeBudgetNumber(chunkCount, 1)));
  const chunkPressureRatio = Math.max(1, normalizedChunkCount / 16);
  const fanoutPressureRatio = Math.max(1, Math.sqrt(effectiveReceiverCount));
  const pressureRatio = Math.max(chunkPressureRatio, fanoutPressureRatio);
  const receiverAdjustedBufferedBytes = Math.max(
    1,
    Math.floor(normalizeBudgetNumber(baseBufferedBytes) / pressureRatio),
  );
  const receiverAdjustedWireBytesPerSecond = Math.max(
    1,
    Math.floor(normalizeBudgetNumber(baseWireBytesPerSecond) / fanoutPressureRatio),
  );
  const normalizedBufferedAmount = normalizeBudgetNumber(websocketBufferedAmount);

  return {
    receiver_count: normalizedReceiverCount,
    effective_receiver_count: effectiveReceiverCount,
    chunk_count: normalizedChunkCount,
    fanout_pressure_ratio: fanoutPressureRatio,
    chunk_pressure_ratio: chunkPressureRatio,
    send_budget_pressure_ratio: pressureRatio,
    receiver_adjusted_buffered_bytes: receiverAdjustedBufferedBytes,
    receiver_adjusted_wire_bytes_per_second: receiverAdjustedWireBytesPerSecond,
    websocket_buffered_amount: normalizedBufferedAmount,
    receiver_count_budget_exceeded: normalizedBufferedAmount >= receiverAdjustedBufferedBytes,
  };
}
