export function createBackgroundCompositorStage({
  backgroundLayer,
  backgroundLayerCanvas,
  canvas,
  ctx,
  drawCoverImage,
  getBackgroundColor,
  getBackgroundImage,
  getBlurPx,
  maskLayerCanvas,
  personLayer,
  personLayerCanvas,
  video,
}) {
  let lastBackgroundRefreshAt = 0;
  const backgroundRefreshIntervalMs = 180;

  function render({ hasMatteMask, mode = 'blur', now }) {
    const backgroundColor = String(getBackgroundColor?.() || '').trim();
    const blurPx = Math.max(1, Math.round(Number(getBlurPx?.() || 3)));

    if (mode === 'off') {
      ctx.save();
      ctx.filter = 'none';
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      ctx.restore();
      return;
    }

    if (getBackgroundImage()) {
      ctx.save();
      drawCoverImage(ctx, getBackgroundImage(), canvas.width, canvas.height);
      ctx.restore();
    } else if (backgroundColor) {
      ctx.save();
      ctx.fillStyle = backgroundColor;
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
    } else {
      if (now - lastBackgroundRefreshAt >= backgroundRefreshIntervalMs || lastBackgroundRefreshAt === 0) {
        backgroundLayer.save();
        backgroundLayer.filter = `blur(${Math.max(1, Math.round(blurPx * 0.9))}px)`;
        backgroundLayer.drawImage(video, 0, 0, backgroundLayerCanvas.width, backgroundLayerCanvas.height);
        backgroundLayer.restore();
        lastBackgroundRefreshAt = now;
      }
      ctx.drawImage(backgroundLayerCanvas, 0, 0, canvas.width, canvas.height);
    }

    if (hasMatteMask) {
      personLayer.save();
      personLayer.globalCompositeOperation = 'copy';
      personLayer.filter = 'none';
      personLayer.drawImage(video, 0, 0, canvas.width, canvas.height);
      personLayer.restore();
      personLayer.save();
      personLayer.globalCompositeOperation = 'destination-in';
      personLayer.drawImage(maskLayerCanvas, 0, 0, canvas.width, canvas.height);
      personLayer.restore();
      ctx.drawImage(personLayerCanvas, 0, 0, canvas.width, canvas.height);
      return;
    }

    ctx.save();
    ctx.filter = mode === 'replace' ? 'none' : `blur(${blurPx}px)`;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    ctx.restore();
  }

  function reset() {
    lastBackgroundRefreshAt = 0;
  }

  return {
    render,
    reset,
  };
}
