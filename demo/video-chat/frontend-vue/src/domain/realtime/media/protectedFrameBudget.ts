function normalizedByteLength(value) {
  if (value instanceof ArrayBuffer) return Math.max(0, Number(value.byteLength || 0));
  if (ArrayBuffer.isView(value)) return Math.max(0, Number(value.byteLength || 0));
  if (typeof value === 'string') return Math.max(0, value.length);
  return 0;
}

export function measureProtectedSfuFrameBudget({
  maxPayloadBytes,
  plaintextBytes,
  protectedFrame,
} = {}) {
  const normalizedPlaintextBytes = Math.max(0, Number(plaintextBytes || 0));
  const protectedCiphertextBytes = normalizedByteLength(protectedFrame?.data);
  const protectedEnvelopeBytes = normalizedByteLength(protectedFrame?.envelope);
  const protectedEnvelopeBase64Chars = normalizedByteLength(protectedFrame?.protectedFrame);
  const normalizedMaxPayloadBytes = Math.max(1, Number(maxPayloadBytes || 0));
  const protectionOverheadBytes = Math.max(0, protectedEnvelopeBytes - normalizedPlaintextBytes);
  return {
    ok: protectedEnvelopeBytes > 0 && protectedEnvelopeBytes <= normalizedMaxPayloadBytes,
    metrics: {
      protected_ciphertext_bytes: protectedCiphertextBytes,
      protected_envelope_bytes: protectedEnvelopeBytes,
      protected_envelope_base64_chars: protectedEnvelopeBase64Chars,
      protection_overhead_bytes: protectionOverheadBytes,
      protection_overhead_ratio: normalizedPlaintextBytes > 0
        ? Number((protectionOverheadBytes / normalizedPlaintextBytes).toFixed(6))
        : 0,
    },
  };
}
