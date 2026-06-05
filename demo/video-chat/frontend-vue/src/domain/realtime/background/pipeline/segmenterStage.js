export function createBackgroundSegmenterStage({
  width,
  height,
}) {
  let hasMatteMask = false;
  let latestSourceFrame = null;
  let latestMaskBitmap = null;
  let latestMaskValues = null;
  let latestMaskWidth = 0;
  let latestMaskHeight = 0;

  function update(segmentation, { underLoad = false } = {}) {
    const hasBitmapMask = segmentation?.matteMaskBitmap instanceof ImageBitmap;
    const hasValueMask = segmentation?.matteMaskValues instanceof Float32Array;
    const shouldRefreshMask = (hasBitmapMask || hasValueMask)
      && (segmentation?.detectSampleMs !== null || !hasMatteMask)
      && (!underLoad || !hasMatteMask);
    let maskUpdated = false;

    if (shouldRefreshMask) {
      hasMatteMask = true;
      maskUpdated = true;
      if (latestMaskBitmap && latestMaskBitmap !== segmentation.matteMaskBitmap) {
        latestMaskBitmap.close?.();
      }
      latestMaskBitmap = hasBitmapMask ? segmentation.matteMaskBitmap : null;
      latestMaskValues = hasValueMask ? segmentation.matteMaskValues : null;
      latestMaskWidth = Math.max(1, Math.round(Number(segmentation?.matteMaskWidth) || width));
      latestMaskHeight = Math.max(1, Math.round(Number(segmentation?.matteMaskHeight) || height));
      latestSourceFrame = segmentation?.sourceFrame || latestSourceFrame;
    }

    return {
      hasMatteMask,
      maskBitmap: latestMaskBitmap,
      maskHeight: latestMaskHeight,
      maskUpdated,
      maskValues: latestMaskValues,
      maskWidth: latestMaskWidth,
      sourceFrame: latestSourceFrame,
    };
  }

  function reset() {
    hasMatteMask = false;
    latestSourceFrame = null;
    latestMaskBitmap?.close?.();
    latestMaskBitmap = null;
    latestMaskValues = null;
    latestMaskWidth = 0;
    latestMaskHeight = 0;
  }

  return {
    getState() {
      return {
        hasMatteMask,
        maskBitmap: latestMaskBitmap,
        maskHeight: latestMaskHeight,
        maskUpdated: false,
        maskValues: latestMaskValues,
        maskWidth: latestMaskWidth,
        sourceFrame: latestSourceFrame,
      };
    },
    reset,
    update,
  };
}
