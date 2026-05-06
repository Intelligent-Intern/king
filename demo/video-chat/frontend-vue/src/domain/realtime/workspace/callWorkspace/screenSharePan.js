import { isScreenShareMediaSource, isScreenShareUserId } from '../../screenShareIdentity.js';

const panStates = new WeakMap();

function mediaNodeIsScreenShare(node, userId = 0) {
  if (!(node instanceof HTMLElement)) return false;
  const normalizedUserId = Number(userId || node.dataset?.callVideoSurfaceUserId || node.dataset?.userId || 0);
  return isScreenShareUserId(normalizedUserId)
    || isScreenShareMediaSource(node.dataset?.mediaSource);
}

function sourceSizeForNode(node, target) {
  const targetWidth = Math.max(1, Math.floor(Number(target?.clientWidth || 0)));
  const targetHeight = Math.max(1, Math.floor(Number(target?.clientHeight || 0)));
  if (node instanceof HTMLCanvasElement) {
    return {
      sourceWidth: Math.max(1, Math.floor(Number(node.width || targetWidth))),
      sourceHeight: Math.max(1, Math.floor(Number(node.height || targetHeight))),
      targetWidth,
      targetHeight,
    };
  }
  if (node instanceof HTMLVideoElement) {
    return {
      sourceWidth: Math.max(1, Math.floor(Number(node.videoWidth || targetWidth))),
      sourceHeight: Math.max(1, Math.floor(Number(node.videoHeight || targetHeight))),
      targetWidth,
      targetHeight,
    };
  }
  return {
    sourceWidth: targetWidth,
    sourceHeight: targetHeight,
    targetWidth,
    targetHeight,
  };
}

function panBoundsForNode(node, target) {
  const { sourceWidth, sourceHeight, targetWidth, targetHeight } = sourceSizeForNode(node, target);
  const scale = Math.max(targetWidth / sourceWidth, targetHeight / sourceHeight);
  const renderedWidth = sourceWidth * scale;
  const renderedHeight = sourceHeight * scale;
  return {
    maxX: Math.max(0, (renderedWidth - targetWidth) / 2),
    maxY: Math.max(0, (renderedHeight - targetHeight) / 2),
    renderedWidth,
    renderedHeight,
    targetWidth,
    targetHeight,
  };
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}

function applyPanPosition(state) {
  const { node, target } = state;
  const bounds = panBoundsForNode(node, target);
  state.offsetX = clamp(Number(state.offsetX || 0), -bounds.maxX, bounds.maxX);
  state.offsetY = clamp(Number(state.offsetY || 0), -bounds.maxY, bounds.maxY);

  const positionForAxis = (targetSize, renderedSize, offset) => {
    const denominator = targetSize - renderedSize;
    if (Math.abs(denominator) < 1) return 50;
    const centeredStart = denominator / 2;
    return clamp(((centeredStart + offset) / denominator) * 100, 0, 100);
  };

  node.style.objectFit = 'cover';
  const objectPosition = `${positionForAxis(bounds.targetWidth, bounds.renderedWidth, state.offsetX).toFixed(2)}% ${positionForAxis(bounds.targetHeight, bounds.renderedHeight, state.offsetY).toFixed(2)}%`;
  node.style.objectPosition = objectPosition;
  node.style.setProperty('--call-screen-share-object-position', objectPosition);
  node.dataset.callScreenSharePanEnabled = '1';
}

function ensurePanState(node, target) {
  let state = panStates.get(node);
  if (state) {
    state.target = target;
    return state;
  }

  state = {
    node,
    target,
    dragging: false,
    lastX: 0,
    lastY: 0,
    offsetX: 0,
    offsetY: 0,
  };

  const onPointerDown = (event) => {
    if (event.button !== undefined && event.button !== 0) return;
    const bounds = panBoundsForNode(node, state.target);
    if (bounds.maxX <= 0 && bounds.maxY <= 0) return;
    state.dragging = true;
    state.lastX = Number(event.clientX || 0);
    state.lastY = Number(event.clientY || 0);
    node.dataset.callScreenSharePanDragging = '1';
    try {
      node.setPointerCapture?.(event.pointerId);
    } catch {
      // Pointer capture is best-effort; document-level pointer events still work.
    }
    event.preventDefault();
  };
  const onPointerMove = (event) => {
    if (!state.dragging) return;
    const x = Number(event.clientX || 0);
    const y = Number(event.clientY || 0);
    state.offsetX += x - state.lastX;
    state.offsetY += y - state.lastY;
    state.lastX = x;
    state.lastY = y;
    applyPanPosition(state);
    event.preventDefault();
  };
  const stopDragging = (event) => {
    if (!state.dragging) return;
    state.dragging = false;
    delete node.dataset.callScreenSharePanDragging;
    node.style.cursor = 'grab';
    try {
      node.releasePointerCapture?.(event.pointerId);
    } catch {
      // Matching pointer capture may not exist if the browser released it first.
    }
  };

  node.addEventListener('pointerdown', onPointerDown);
  node.addEventListener('pointermove', onPointerMove);
  node.addEventListener('pointerup', stopDragging);
  node.addEventListener('pointercancel', stopDragging);
  state.cleanup = () => {
    node.removeEventListener('pointerdown', onPointerDown);
    node.removeEventListener('pointermove', onPointerMove);
    node.removeEventListener('pointerup', stopDragging);
    node.removeEventListener('pointercancel', stopDragging);
  };
  panStates.set(node, state);
  return state;
}

export function applyScreenSharePanSurface(node, target, { userId = 0 } = {}) {
  if (!(node instanceof HTMLElement) || !(target instanceof HTMLElement)) return false;
  if (!mediaNodeIsScreenShare(node, userId)) {
    clearScreenSharePanSurface(node);
    return false;
  }
  const state = ensurePanState(node, target);
  node.style.touchAction = 'none';
  node.style.cursor = state.dragging ? 'grabbing' : 'grab';
  applyPanPosition(state);
  return true;
}

export function clearScreenSharePanSurface(node) {
  if (!(node instanceof HTMLElement)) return false;
  const state = panStates.get(node);
  if (state?.cleanup) {
    state.cleanup();
  }
  panStates.delete(node);
  delete node.dataset.callScreenSharePanEnabled;
  delete node.dataset.callScreenSharePanDragging;
  node.style.objectFit = '';
  node.style.objectPosition = '';
  node.style.removeProperty('--call-screen-share-object-position');
  node.style.touchAction = '';
  node.style.cursor = '';
  return true;
}
