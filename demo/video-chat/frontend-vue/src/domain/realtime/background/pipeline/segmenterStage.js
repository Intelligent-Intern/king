export function createBackgroundSegmenterStage({
  width,
  height,
}) {
  let hasMatteMask = false;
  let latestSourceFrame = null;
  let latestMaskValues = null;
  let latestMaskWidth = 0;
  let latestMaskHeight = 0;

  function update(segmentation, { underLoad = false } = {}) {
    const shouldRefreshMask = segmentation?.matteMaskValues instanceof Float32Array
      && (segmentation?.detectSampleMs !== null || !hasMatteMask)
      && (!underLoad || !hasMatteMask);
    let maskUpdated = false;

    if (shouldRefreshMask) {
      hasMatteMask = true;
      maskUpdated = true;
      latestMaskValues = segmentation.matteMaskValues;
      latestMaskWidth = Math.max(1, Math.round(Number(segmentation?.matteMaskWidth) || width));
      latestMaskHeight = Math.max(1, Math.round(Number(segmentation?.matteMaskHeight) || height));
      latestSourceFrame = segmentation?.sourceFrame || latestSourceFrame;
    }

    return {
      hasMatteMask,
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
    latestMaskValues = null;
    latestMaskWidth = 0;
    latestMaskHeight = 0;
  }

  return {
    getState() {
      return {
        hasMatteMask,
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
