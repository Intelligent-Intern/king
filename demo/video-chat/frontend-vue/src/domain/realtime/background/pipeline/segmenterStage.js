export function createBackgroundSegmenterStage({
  buildInnerFeatherMask,
  maskLayer,
  smoothFaceBoxes,
  getTemporalSmoothingAlpha,
  video,
  videoSampleLayer,
  width,
  height,
}) {
  let faces = [];
  let smoothedFaces = [];
  let hasMatteMask = false;
  let previousMaskAlpha = null;

  function update(segmentation, { preferFastMatte = false, underLoad = false, vw = width, vh = height } = {}) {
    const nextFaces = Array.isArray(segmentation?.faces) ? segmentation.faces : faces;
    faces = nextFaces;
    smoothedFaces = smoothFaceBoxes(
      smoothedFaces,
      faces,
      Number(getTemporalSmoothingAlpha?.() ?? 0.3),
    );

    const shouldRefreshMask = Boolean(segmentation?.matteMask)
      && (segmentation?.detectSampleMs !== null || !hasMatteMask)
      && (!underLoad || !hasMatteMask);

    if (shouldRefreshMask && segmentation?.matteMask) {
      if (!previousMaskAlpha || previousMaskAlpha.length !== width * height) {
        previousMaskAlpha = new Uint8ClampedArray(width * height);
      }
      const updatedMask = buildInnerFeatherMask(
        maskLayer,
        segmentation.matteMask,
        videoSampleLayer,
        video,
        width,
        height,
        smoothedFaces.map((face) => ({
          x: Number(face?.x || 0),
          y: Number(face?.y || 0),
          width: Number(face?.width || 0),
          height: Number(face?.height || 0),
        })),
        vw,
        vh,
        previousMaskAlpha,
        preferFastMatte || underLoad,
      );
      if (updatedMask) {
        hasMatteMask = true;
      }
    }

    return {
      faces,
      hasMatteMask,
      smoothedFaces,
    };
  }

  function reset() {
    faces = [];
    smoothedFaces = [];
    hasMatteMask = false;
    previousMaskAlpha = null;
  }

  return {
    getState() {
      return {
        faces,
        hasMatteMask,
        smoothedFaces,
      };
    },
    getMatteMaskSnapshot() {
      try {
        return maskLayer.getImageData(0, 0, width, height);
      } catch {
        return null;
      }
    },
    reset,
    update,
  };
}
