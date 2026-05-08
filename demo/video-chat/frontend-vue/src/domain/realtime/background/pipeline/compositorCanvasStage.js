import {
    createMaskCanvasTools,
    drawContainImage,
    drawCoverImage,
    loadImageCanvas,
    resolveCanvasColor,
} from './compositorShared';

function isImageBitmap(value) {
    return typeof ImageBitmap !== 'undefined' && value instanceof ImageBitmap;
}

export function createCanvasBackgroundCompositorStage({
    canvas,
    getBackgroundColor,
    getBackgroundImageUrl,
    getBlurPx,
    video,
}) {
    const ctx = canvas.getContext('2d', { alpha: true, desynchronized: true });
    if (!ctx) throw new Error('2d compositor unavailable');

    const maskTools = createMaskCanvasTools(canvas);
    let backgroundImageCanvas = null;
    let backgroundImageUrl = '';

    function setBackgroundImageUrl(url) {
        const nextUrl = String(url || '').trim();
        if (nextUrl === backgroundImageUrl) return;
        backgroundImageUrl = nextUrl;
        backgroundImageCanvas = null;
        if (!backgroundImageUrl) return;
        loadImageCanvas(backgroundImageUrl).then((imageCanvas) => {
            if (backgroundImageUrl !== nextUrl) return;
            backgroundImageCanvas = imageCanvas;
        });
    }

    function drawBackground(source, mode, backgroundColor, blurPx) {
        ctx.save();
        ctx.globalCompositeOperation = 'destination-over';
        if (mode === 'replace' && backgroundImageCanvas) {
            ctx.filter = 'none';
            drawCoverImage(ctx, backgroundImageCanvas, canvas.width, canvas.height);
        } else if (backgroundColor) {
            ctx.filter = 'none';
            ctx.fillStyle = resolveCanvasColor(backgroundColor, '#000010');
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        } else if (mode === 'blur') {
            ctx.filter = `blur(${blurPx}px)`;
            drawCoverImage(ctx, source, canvas.width, canvas.height);
        } else {
            ctx.filter = 'none';
            drawContainImage(ctx, source, canvas.width, canvas.height);
        }
        ctx.restore();
    }

    function render({
        hasMatteMask,
        maskBitmap = null,
        maskHeight = 0,
        maskUpdated = false,
        maskValues = null,
        maskWidth = 0,
        mode = 'blur',
        sourceFrame = null,
    }) {
        const backgroundColor = String(getBackgroundColor?.() || '').trim();
        setBackgroundImageUrl(getBackgroundImageUrl?.() || '');
        const blurPx = Math.max(1, Math.round(Number(getBlurPx?.() || 3)));
        const foregroundSource = sourceFrame || video;

        if (mode === 'off') {
            ctx.save();
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.globalCompositeOperation = 'source-over';
            ctx.filter = 'none';
            drawContainImage(ctx, video, canvas.width, canvas.height);
            ctx.restore();
            return;
        }

        if (hasMatteMask && !maskUpdated) return;

        let hasRenderableMask = false;
        if (maskUpdated) {
            hasRenderableMask = isImageBitmap(maskBitmap)
                ? maskTools.drawMaskBitmap(maskBitmap, maskWidth, maskHeight)
                : maskTools.drawMaskValues(maskValues, maskWidth, maskHeight);
        } else {
            hasRenderableMask = Boolean(hasMatteMask);
        }
        maskTools.drawDebugCanvases(foregroundSource);

        if (!hasRenderableMask) {
            ctx.save();
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.globalCompositeOperation = 'source-over';
            ctx.filter = `blur(${Math.max(blurPx, 6)}px)`;
            drawCoverImage(ctx, video, canvas.width, canvas.height);
            ctx.restore();
            return;
        }

        ctx.save();
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.globalCompositeOperation = 'source-over';
        ctx.filter = 'none';
        drawContainImage(ctx, foregroundSource, canvas.width, canvas.height);
        ctx.restore();

        ctx.save();
        ctx.globalCompositeOperation = 'destination-in';
        ctx.filter = 'none';
        ctx.drawImage(maskTools.maskCanvas, 0, 0, canvas.width, canvas.height);
        ctx.restore();

        drawBackground(foregroundSource, mode, backgroundColor, blurPx);
    }

    return {
        backend: 'canvas',
        getMatteMaskSnapshot: () => maskTools.getMatteMaskSnapshot(),
        render,
        reset: () => maskTools.clearMask(),
        setBackgroundImageUrl,
    };
}
