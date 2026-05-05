export function resizeCanvasPreservingFrame(canvas, width, height) {
  if (!(canvas instanceof HTMLCanvasElement)) return;
  const nextWidth = Math.max(1, Math.floor(Number(width || 0)));
  const nextHeight = Math.max(1, Math.floor(Number(height || 0)));
  if (canvas.width === nextWidth && canvas.height === nextHeight) return;
  const previousWidth = Math.max(0, Number(canvas.width || 0));
  const previousHeight = Math.max(0, Number(canvas.height || 0));
  let snapshot = null;
  if (previousWidth > 0 && previousHeight > 0) {
    snapshot = document.createElement('canvas');
    snapshot.width = previousWidth;
    snapshot.height = previousHeight;
    const snapshotCtx = snapshot.getContext('2d');
    if (snapshotCtx) {
      snapshotCtx.drawImage(canvas, 0, 0);
    }
  }

  canvas.width = nextWidth;
  canvas.height = nextHeight;
  if (!snapshot) return;

  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  const scale = Math.min(nextWidth / previousWidth, nextHeight / previousHeight);
  const scaledWidth = Math.max(1, Math.round(previousWidth * scale));
  const scaledHeight = Math.max(1, Math.round(previousHeight * scale));
  const offsetX = Math.floor((nextWidth - scaledWidth) / 2);
  const offsetY = Math.floor((nextHeight - scaledHeight) / 2);
  ctx.imageSmoothingEnabled = true;
  ctx.clearRect(0, 0, nextWidth, nextHeight);
  ctx.drawImage(snapshot, 0, 0, previousWidth, previousHeight, offsetX, offsetY, scaledWidth, scaledHeight);
}

export function resizeCanvas(canvas, width, height) {
  if (!(canvas instanceof HTMLCanvasElement)) return;
  if (canvas.width !== width) canvas.width = width;
  if (canvas.height !== height) canvas.height = height;
}

export function clearCanvas(canvas) {
  if (!(canvas instanceof HTMLCanvasElement)) return;
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

export function putImageDataOntoCanvas(canvas, imageData, x, y) {
  if (!(canvas instanceof HTMLCanvasElement) || !(imageData instanceof ImageData)) return false;
  const ctx = canvas.getContext('2d');
  if (!ctx) return false;
  ctx.putImageData(imageData, x, y);
  return true;
}

export function softDeblockDecodedCanvas(canvas, {
  frameQuality = 0,
  layoutMode = 'full_frame',
} = {}) {
  if (!(canvas instanceof HTMLCanvasElement)) return false;
  const width = Math.max(0, Number(canvas.width || 0));
  const height = Math.max(0, Number(canvas.height || 0));
  if (width < 2 || height < 2) return false;

  const normalizedLayoutMode = String(layoutMode || 'full_frame').trim().toLowerCase();
  const normalizedQuality = Math.max(0, Math.floor(Number(frameQuality || 0)));
  const isPatchComposited = normalizedLayoutMode === 'tile_foreground' || normalizedLayoutMode === 'background_snapshot';
  const shouldDeblock = isPatchComposited || normalizedQuality <= 52;
  if (!shouldDeblock) return false;

  const blurPx = isPatchComposited ? 0.42 : 0.28;
  const blendAlpha = isPatchComposited ? 0.34 : 0.24;
  let scratch = canvas.__kingRtSoftDeblockCanvas;
  if (!(scratch instanceof HTMLCanvasElement)) {
    scratch = document.createElement('canvas');
    canvas.__kingRtSoftDeblockCanvas = scratch;
  }
  if (scratch.width !== width) scratch.width = width;
  if (scratch.height !== height) scratch.height = height;

  const scratchCtx = scratch.getContext('2d');
  const ctx = canvas.getContext('2d');
  if (!scratchCtx || !ctx) return false;
  scratchCtx.clearRect(0, 0, width, height);
  scratchCtx.drawImage(canvas, 0, 0);
  ctx.save();
  ctx.globalAlpha = blendAlpha;
  ctx.filter = `blur(${blurPx}px)`;
  ctx.drawImage(scratch, 0, 0);
  ctx.restore();
  return true;
}
