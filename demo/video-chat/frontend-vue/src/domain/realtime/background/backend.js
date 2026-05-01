function toFinite(value, fallback) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric : fallback;
}

function clampBox(box, maxW, maxH) {
  const x = Math.max(0, Math.min(maxW, box.x));
  const y = Math.max(0, Math.min(maxH, box.y));
  const width = Math.max(0, Math.min(maxW - x, box.width));
  const height = Math.max(0, Math.min(maxH - y, box.height));
  return { x, y, width, height };
}

function resolveFallbackFaceBox(vw, vh) {
  const width = Math.max(120, Math.round(vw * 0.42));
  const height = Math.max(160, Math.round(vh * 0.58));
  const x = Math.round((vw - width) / 2);
  const y = Math.round(vh * 0.1);
  return clampBox({ x, y, width, height }, vw, vh);
}

function toFaceBoxes(raw) {
  const list = Array.isArray(raw) ? raw : [];
  const out = [];
  for (const row of list) {
    const box = row?.boundingBox;
    if (!box) continue;
    const x = toFinite(box.x, NaN);
    const y = toFinite(box.y, NaN);
    const width = toFinite(box.width, NaN);
    const height = toFinite(box.height, NaN);
    if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(width) || !Number.isFinite(height)) continue;
    if (width <= 0 || height <= 0) continue;
    out.push({ x, y, width, height });
  }
  return out;
}

function createCenterMaskBackend() {
  let faces = [];
  return {
    kind: 'center_mask',
    nextFaces(_video, vw, vh) {
      if (!faces.length) {
        faces = [resolveFallbackFaceBox(vw, vh)];
      }
      return { faces, detectSampleMs: null, matteMask: null };
    },
    dispose() {
      faces = [];
    },
  };
}

function createFaceDetectorBackend(opts = {}) {
  const FaceDetectorCtor = window.FaceDetector;
  if (typeof FaceDetectorCtor !== 'function') {
    throw new Error('FaceDetector constructor unavailable');
  }

  const detector = new FaceDetectorCtor({ fastMode: true, maxDetectedFaces: 2 });
  const detectIntervalMs = Math.max(100, Math.min(1200, Math.round(toFinite(opts.detectIntervalMs, 220))));
  const facePaddingPx = Math.max(4, Math.min(64, Math.round(toFinite(opts.facePaddingPx, 14))));

  let faces = [];
  let detectPending = false;
  let lastDetectAt = 0;
  let pendingSampleMs = null;

  return {
    kind: 'face_detector',
    nextFaces(video, vw, vh, nowMs) {
      if (!detectPending && nowMs - lastDetectAt >= detectIntervalMs) {
        detectPending = true;
        lastDetectAt = nowMs;
        const detectStartedAt = performance.now();
        void detector
          .detect(video)
          .then((rows) => {
            const next = toFaceBoxes(rows).map((box) => clampBox({
              x: box.x - facePaddingPx,
              y: box.y - facePaddingPx,
              width: box.width + facePaddingPx * 2,
              height: box.height + facePaddingPx * 2,
            }, vw, vh));
            faces = next;
          })
          .catch(() => {
            // keep last good face boxes.
          })
          .finally(() => {
            pendingSampleMs = Math.max(0, performance.now() - detectStartedAt);
            detectPending = false;
          });
      }
      const sample = pendingSampleMs;
      pendingSampleMs = null;
      return { faces, detectSampleMs: sample, matteMask: null };
    },
    dispose() {
      faces = [];
      detectPending = false;
      lastDetectAt = 0;
      pendingSampleMs = null;
    },
  };
}

export function createBackgroundSegmentationBackend(mode, opts = {}) {
  if (mode === 'face_detector') {
    return createFaceDetectorBackend(opts);
  }
  return createCenterMaskBackend();
}
