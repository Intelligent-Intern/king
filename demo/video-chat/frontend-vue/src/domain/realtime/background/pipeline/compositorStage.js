const backgroundCanvasCache = new Map();

function sourceNaturalSize(source, fallbackWidth, fallbackHeight) {
    return {
        width: Math.max(1, source?.videoWidth || source?.naturalWidth || source?.width || fallbackWidth),
        height: Math.max(1, source?.videoHeight || source?.naturalHeight || source?.height || fallbackHeight),
    };
}

function drawCoverImage(ctx, image, width, height) {
    const { width: iw, height: ih } = sourceNaturalSize(image, width, height);
    const scale = Math.max(width / iw, height / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = (width - dw) * 0.5;
    const dy = (height - dh) * 0.5;
    ctx.drawImage(image, dx, dy, dw, dh);
}

function drawContainImage(ctx, image, width, height) {
    const { width: iw, height: ih } = sourceNaturalSize(image, width, height);
    const scale = Math.min(width / iw, height / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = (width - dw) * 0.5;
    const dy = (height - dh) * 0.5;
    ctx.drawImage(image, dx, dy, dw, dh);
}

function resolveCanvasColor(color, fallback = '#000010') {
    const value = String(color || '').trim();
    const cssVariable = value.match(/^var\((--[A-Za-z0-9_-]+)\)$/);
    if (!cssVariable || typeof window === 'undefined' || typeof document === 'undefined') {
        return value || fallback;
    }
    const resolved = window.getComputedStyle(document.documentElement).getPropertyValue(cssVariable[1]).trim();
    return resolved || fallback;
}

async function loadImageCanvas(url) {
    const src = String(url || '').trim();
    if (!src) return null;
    if (backgroundCanvasCache.has(src)) return backgroundCanvasCache.get(src);

    const promise = new Promise((resolve) => {
        const image = new Image();
        image.decoding = 'async';
        image.onload = async () => {
            try {
                await image.decode?.();
            } catch {
                // The onload event already confirmed the image is usable.
            }
            const width = Math.max(1, image.naturalWidth || image.width || 1);
            const height = Math.max(1, image.naturalHeight || image.height || 1);
            const imageCanvas = document.createElement('canvas');
            imageCanvas.width = width;
            imageCanvas.height = height;
            const imageCtx = imageCanvas.getContext('2d', { alpha: false });
            if (!imageCtx) {
                resolve(null);
                return;
            }
            imageCtx.drawImage(image, 0, 0, width, height);
            resolve(imageCanvas);
        };
        image.onerror = () => resolve(null);
        image.src = src;
    });
    backgroundCanvasCache.set(src, promise);
    return promise;
}

export function createBackgroundCompositorStage({
    canvas,
    ctx,
    getBackgroundColor,
    getBackgroundImageUrl,
    getBlurPx,
    getMattePreset,
    getShowSourceUntilMask,
    video,
}) {
    const maskCanvas = document.createElement('canvas');
    const maskLayer = maskCanvas.getContext('2d', {
        alpha: true,
        willReadFrequently: true,
    });
    const maskSourceCanvas = document.createElement('canvas');
    const maskSourceLayer = maskSourceCanvas.getContext('2d', {
        alpha: true,
        willReadFrequently: true,
    });
    const foregroundCanvas = document.createElement('canvas');
    const foregroundLayer = foregroundCanvas.getContext('2d', {
        alpha: true,
        desynchronized: true,
    });
    let maskSourceImageData = null;
    let backgroundImageCanvas = null;
    let backgroundImageUrl = '';

    function resizeCanvas(targetCanvas, width, height) {
        const nextWidth = Math.max(1, Math.round(Number(width) || 1));
        const nextHeight = Math.max(1, Math.round(Number(height) || 1));
        if (targetCanvas.width !== nextWidth || targetCanvas.height !== nextHeight) {
            targetCanvas.width = nextWidth;
            targetCanvas.height = nextHeight;
            return true;
        }
        return false;
    }

    function compositeAlpha(value, mode, backgroundColor, backgroundImageUrl, mattePreset) {
        const normalized = Math.max(0, Math.min(1, Number(value) || 0));
        const replacementLike = mode === 'replace'
            || String(backgroundColor || '').trim() !== ''
            || String(backgroundImageUrl || '').trim() !== ''
            || String(mattePreset || '').trim().toLowerCase() === 'replace';
        const low = replacementLike ? 0.38 : 0.14;
        const high = replacementLike ? 0.72 : 0.38;
        const t = Math.max(0, Math.min(1, (normalized - low) / Math.max(1e-6, high - low)));
        return t * t * (3 - 2 * t);
    }

    function processMaskForAlpha(mask, width, height, mode, backgroundColor, backgroundImageUrl, mattePreset) {
        if (!(mask instanceof Float32Array)) return mask;
        const processed = new Float32Array(mask.length);
        for (let i = 0; i < mask.length; i += 1) {
            processed[i] = compositeAlpha(mask[i], mode, backgroundColor, backgroundImageUrl, mattePreset);
        }
        return processed;
    }
    function clearMask() {
        if (!maskLayer) return;
        resizeCanvas(maskCanvas, canvas.width, canvas.height);
        maskLayer.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
    }

    function drawMaskBitmap(maskBitmap, maskWidth, maskHeight) {
        if (!(maskBitmap instanceof ImageBitmap) || !maskLayer) {
            clearMask();
            return false;
        }

        const sourceWidth = Math.max(1, Math.round(Number(maskWidth) || maskBitmap.width || 0));
        const sourceHeight = Math.max(1, Math.round(Number(maskHeight) || maskBitmap.height || 0));
        if (sourceWidth <= 1 || sourceHeight <= 1) {
            clearMask();
            return false;
        }

        resizeCanvas(maskCanvas, canvas.width, canvas.height);
        maskLayer.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
        maskLayer.imageSmoothingEnabled = true;
        maskLayer.imageSmoothingQuality = 'high';
        maskLayer.drawImage(maskBitmap, 0, 0, maskCanvas.width, maskCanvas.height);
        return true;
    }

    function drawMaskValues(maskValues, maskWidth, maskHeight) {
        if (!(maskValues instanceof Float32Array) || !maskLayer || !maskSourceLayer) {
            clearMask();
            return false;
        }

        const sourceWidth = Math.max(1, Math.round(Number(maskWidth) || 0));
        const sourceHeight = Math.max(1, Math.round(Number(maskHeight) || 0));
        const pixelCount = sourceWidth * sourceHeight;
        if (pixelCount <= 1 || maskValues.length < pixelCount) {
            clearMask();
            return false;
        }

        const resizedSource = resizeCanvas(maskSourceCanvas, sourceWidth, sourceHeight);
        if (
            resizedSource
            || !maskSourceImageData
            || maskSourceImageData.width !== sourceWidth
            || maskSourceImageData.height !== sourceHeight
        ) {
            maskSourceImageData = maskSourceLayer.createImageData(sourceWidth, sourceHeight);
        }

        const data = maskSourceImageData.data;
        let maxAlpha = 0;
        for (let pixel = 0; pixel < pixelCount; pixel += 1) {
            const alpha = Math.max(0, Math.min(255, Math.round((Number(maskValues[pixel]) || 0) * 255)));
            const offset = pixel * 4;
            data[offset] = 255;
            data[offset + 1] = 255;
            data[offset + 2] = 255;
            data[offset + 3] = alpha;
            if (alpha > maxAlpha) maxAlpha = alpha;
        }

        resizeCanvas(maskCanvas, canvas.width, canvas.height);
        maskLayer.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
        if (maxAlpha <= 0) return false;

        maskSourceLayer.putImageData(maskSourceImageData, 0, 0);
        maskLayer.imageSmoothingEnabled = true;
        maskLayer.imageSmoothingQuality = 'high';
        maskLayer.drawImage(maskSourceCanvas, 0, 0, maskCanvas.width, maskCanvas.height);
        return true;
    }

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

    function drawBackground(source, mode, backgroundColor, backgroundImageUrl, blurPx, compositeOperation = 'source-over') {
        ctx.save();
        ctx.globalCompositeOperation = compositeOperation;
        if (mode === 'replace' && backgroundImageCanvas) {
            ctx.filter = 'none';
            drawCoverImage(ctx, backgroundImageCanvas, canvas.width, canvas.height);
        } else if (backgroundColor) {
            ctx.filter = 'none';
            ctx.fillStyle = resolveCanvasColor(backgroundColor, '#000010');
            ctx.fillRect(0, 0, canvas.width, canvas.height);
        } else if (mode === 'replace' || String(backgroundImageUrl || '').trim() !== '') {
            ctx.filter = 'none';
            ctx.fillStyle = '#061a4a';
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

    function drawForegroundLayer(source) {
        if (!foregroundLayer) return false;
        resizeCanvas(foregroundCanvas, canvas.width, canvas.height);
        foregroundLayer.save();
        foregroundLayer.clearRect(0, 0, foregroundCanvas.width, foregroundCanvas.height);
        foregroundLayer.globalCompositeOperation = 'source-over';
        foregroundLayer.filter = 'none';
        drawContainImage(foregroundLayer, source, foregroundCanvas.width, foregroundCanvas.height);
        foregroundLayer.globalCompositeOperation = 'destination-in';
        foregroundLayer.drawImage(maskCanvas, 0, 0, foregroundCanvas.width, foregroundCanvas.height);
        foregroundLayer.restore();
        return true;
    }

    function drawDebugCanvases(source) {
        const debugRoot = document.getElementById('backgroundPipelineDebugDialog');
        const debugMaskCanvas = debugRoot?.querySelector?.('#maskDebug') || null;
        const debugMaskCtx = debugMaskCanvas?.getContext?.('2d');
        if (!debugRoot || !debugMaskCtx) return;

        const width = Math.max(1, maskCanvas.width || debugMaskCanvas.width);
        const height = Math.max(1, maskCanvas.height || debugMaskCanvas.height);
        if (debugMaskCanvas.width !== width || debugMaskCanvas.height !== height) {
            debugMaskCanvas.width = width;
            debugMaskCanvas.height = height;
        }

        debugMaskCtx.clearRect(0, 0, width, height);
        debugMaskCtx.drawImage(maskCanvas, 0, 0, width, height);

        const maskImage = debugMaskCtx.getImageData(0, 0, width, height);
        const data = maskImage.data;
        let nonZeroPixels = 0;
        for (let i = 0; i < data.length; i += 4) {
            const signal = data[i + 3] ?? 0;
            if (signal > 0) nonZeroPixels += 1;
            data[i] = signal;
            data[i + 1] = signal;
            data[i + 2] = signal;
            data[i + 3] = signal;
        }
        debugMaskCtx.putImageData(maskImage, 0, 0);

        if (nonZeroPixels === 0) {
            const previousAlpha = debugMaskCtx.globalAlpha;
            debugMaskCtx.globalAlpha = 0.9;
            debugMaskCtx.fillStyle = resolveCanvasColor('var(--color-error)', '#ef4423');
            debugMaskCtx.font = '12px monospace';
            debugMaskCtx.fillText('mask empty', 8, 18);
            debugMaskCtx.globalAlpha = previousAlpha;
        }

        const personOnlyCanvas = debugRoot.querySelector?.('#personDebug') || null;
        const personOnlyCtx = personOnlyCanvas?.getContext?.('2d');
        if (!personOnlyCtx) return;

        personOnlyCanvas.width = width;
        personOnlyCanvas.height = height;
        personOnlyCtx.clearRect(0, 0, width, height);
        drawContainImage(personOnlyCtx, source, width, height);
        personOnlyCtx.globalCompositeOperation = 'destination-in';
        personOnlyCtx.drawImage(maskCanvas, 0, 0, width, height);
        personOnlyCtx.globalCompositeOperation = 'source-over';
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
        const backgroundImageUrl = String(getBackgroundImageUrl?.() || '').trim();
        const mattePreset = String(getMattePreset?.() || '').trim();
        setBackgroundImageUrl(backgroundImageUrl);
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

        let hasRenderableMask = false;
        if (maskUpdated) {
            hasRenderableMask = maskBitmap instanceof ImageBitmap
                ? drawMaskBitmap(maskBitmap, maskWidth, maskHeight)
                : drawMaskValues(
                    processMaskForAlpha(maskValues, maskWidth, maskHeight, mode, backgroundColor, backgroundImageUrl, mattePreset),
                    maskWidth,
                    maskHeight
                );
        } else {
            hasRenderableMask = Boolean(hasMatteMask);
        }
        drawDebugCanvases(foregroundSource);

        if (!hasRenderableMask) {
            ctx.save();
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.globalCompositeOperation = 'source-over';
            if (getShowSourceUntilMask?.() === true) {
                ctx.filter = 'none';
                drawContainImage(ctx, foregroundSource, canvas.width, canvas.height);
            } else if (backgroundColor) {
                ctx.filter = 'none';
                ctx.fillStyle = resolveCanvasColor(backgroundColor, '#000010');
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            } else if (mode === 'replace' && backgroundImageCanvas) {
                ctx.filter = 'none';
                drawCoverImage(ctx, backgroundImageCanvas, canvas.width, canvas.height);
            } else {
                ctx.filter = 'none';
                ctx.fillStyle = '#061a4a';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
            }
            ctx.restore();
            return;
        }

        ctx.save();
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        drawBackground(foregroundSource, mode, backgroundColor, backgroundImageUrl, blurPx);
        ctx.restore();

        if (drawForegroundLayer(foregroundSource)) {
            ctx.save();
            ctx.globalCompositeOperation = 'source-over';
            ctx.filter = 'none';
            ctx.drawImage(foregroundCanvas, 0, 0, canvas.width, canvas.height);
            ctx.restore();
        }
    }

    function reset() {
        clearMask();
    }

    function getMatteMaskSnapshot() {
        try {
            return maskLayer?.getImageData?.(0, 0, maskCanvas.width, maskCanvas.height) || null;
        } catch {
            return null;
        }
    }

    return {
        getMatteMaskSnapshot,
        render,
        reset,
        setBackgroundImageUrl,
    };
}
