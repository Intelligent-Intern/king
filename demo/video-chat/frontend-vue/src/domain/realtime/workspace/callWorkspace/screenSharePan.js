import { isScreenShareMediaSource, isScreenShareUserId } from '../../screenShareIdentity.js';

const panStates = new WeakMap();
const MIN_ZOOM_SCALE = 1;
const MAX_ZOOM_SCALE = 4;
const ZOOM_WHEEL_SENSITIVITY = 0.0015;
const FULLSCREEN_SURFACE_ROLE = 'fullscreen';

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
  const fitScale = Math.min(targetWidth / sourceWidth, targetHeight / sourceHeight);
  const zoomScale = Math.max(MIN_ZOOM_SCALE, Number(panStates.get(node)?.zoomScale || MIN_ZOOM_SCALE));
  const scale = fitScale * zoomScale;
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

function surfaceRoleForNode(node) {
  return String(node?.dataset?.callVideoSurfaceRole || '').trim();
}

function applyPanPosition(state) {
  const { node, target } = state;
  const bounds = panBoundsForNode(node, target);
  state.offsetX = clamp(Number(state.offsetX || 0), -bounds.maxX, bounds.maxX);
  state.offsetY = clamp(Number(state.offsetY || 0), -bounds.maxY, bounds.maxY);
  state.zoomScale = clamp(Number(state.zoomScale || MIN_ZOOM_SCALE), MIN_ZOOM_SCALE, MAX_ZOOM_SCALE);
  const zoomScale = state.zoomScale.toFixed(3);
  const translateX = state.offsetX.toFixed(2);
  const translateY = state.offsetY.toFixed(2);

  node.style.objectFit = 'contain';
  node.style.objectPosition = 'center center';
  node.style.transform = `translate(${translateX}px, ${translateY}px) scale(${zoomScale})`;
  node.style.transformOrigin = 'center center';
  node.style.setProperty('--call-screen-share-zoom-scale', zoomScale);
  node.style.setProperty('--call-screen-share-pan-x', `${translateX}px`);
  node.style.setProperty('--call-screen-share-pan-y', `${translateY}px`);
  node.dataset.callScreenSharePanEnabled = '1';
  node.dataset.callScreenShareZoomScale = zoomScale;
  node.dataset.callScreenShareZoomed = state.zoomScale > MIN_ZOOM_SCALE ? '1' : '0';
  node.dataset.callScreenShareFitMode = state.zoomScale > MIN_ZOOM_SCALE ? 'zoomed' : 'contain';
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
    surfaceRole: '',
    zoomScale: MIN_ZOOM_SCALE,
  };

  const resetZoom = () => {
    state.zoomScale = MIN_ZOOM_SCALE;
    state.offsetX = 0;
    state.offsetY = 0;
    applyPanPosition(state);
  };

  const onPointerDown = (event) => {
    if (event.button !== undefined && event.button !== 0) return;
    const bounds = panBoundsForNode(node, state.target);
    if (state.zoomScale <= MIN_ZOOM_SCALE || (bounds.maxX <= 0 && bounds.maxY <= 0)) return;
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
  const onWheel = (event) => {
    state.surfaceRole = surfaceRoleForNode(node);
    if (state.surfaceRole !== FULLSCREEN_SURFACE_ROLE) return;
    const deltaY = Number(event.deltaY || 0);
    if (!Number.isFinite(deltaY) || deltaY === 0) return;
    const previousZoomScale = state.zoomScale;
    const nextZoomScale = clamp(
      previousZoomScale * Math.exp(-deltaY * ZOOM_WHEEL_SENSITIVITY),
      MIN_ZOOM_SCALE,
      MAX_ZOOM_SCALE,
    );
    if (Math.abs(nextZoomScale - previousZoomScale) < 0.001) return;
    state.zoomScale = nextZoomScale;
    if (state.zoomScale <= MIN_ZOOM_SCALE) {
      state.offsetX = 0;
      state.offsetY = 0;
    }
    applyPanPosition(state);
    event.preventDefault();
  };
  const onDoubleClick = (event) => {
    state.surfaceRole = surfaceRoleForNode(node);
    if (state.surfaceRole !== FULLSCREEN_SURFACE_ROLE || state.zoomScale <= MIN_ZOOM_SCALE) return;
    resetZoom();
    event.preventDefault();
    event.stopPropagation();
  };

  node.addEventListener('pointerdown', onPointerDown);
  node.addEventListener('pointermove', onPointerMove);
  node.addEventListener('pointerup', stopDragging);
  node.addEventListener('pointercancel', stopDragging);
  node.addEventListener('wheel', onWheel, { passive: false });
  node.addEventListener('dblclick', onDoubleClick);
  state.cleanup = () => {
    node.removeEventListener('pointerdown', onPointerDown);
    node.removeEventListener('pointermove', onPointerMove);
    node.removeEventListener('pointerup', stopDragging);
    node.removeEventListener('pointercancel', stopDragging);
    node.removeEventListener('wheel', onWheel);
    node.removeEventListener('dblclick', onDoubleClick);
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
  const previousSurfaceRole = state.surfaceRole;
  state.surfaceRole = surfaceRoleForNode(node);
  if (previousSurfaceRole === FULLSCREEN_SURFACE_ROLE && state.surfaceRole !== FULLSCREEN_SURFACE_ROLE) {
    state.zoomScale = MIN_ZOOM_SCALE;
    state.offsetX = 0;
    state.offsetY = 0;
  }
  node.style.touchAction = 'none';
  node.style.cursor = state.zoomScale > MIN_ZOOM_SCALE ? (state.dragging ? 'grabbing' : 'grab') : 'zoom-in';
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
  delete node.dataset.callScreenShareZoomScale;
  delete node.dataset.callScreenShareZoomed;
  delete node.dataset.callScreenShareFitMode;
  node.style.objectFit = '';
  node.style.objectPosition = '';
  node.style.transform = '';
  node.style.transformOrigin = '';
  node.style.removeProperty('--call-screen-share-zoom-scale');
  node.style.removeProperty('--call-screen-share-pan-x');
  node.style.removeProperty('--call-screen-share-pan-y');
  node.style.touchAction = '';
  node.style.cursor = '';
  return true;
}
