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
