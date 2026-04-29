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
  ctx.imageSmoothingEnabled = true;
  ctx.drawImage(snapshot, 0, 0, previousWidth, previousHeight, 0, 0, nextWidth, nextHeight);
}
