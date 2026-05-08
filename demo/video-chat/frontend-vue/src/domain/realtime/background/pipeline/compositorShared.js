import { buildInnerDistanceFeatherAlpha, buildInnerDistanceFeatherMaskValues } from '../maskPostprocess';

const backgroundCanvasCache = new Map();

export function sourceNaturalSize(source, fallbackWidth, fallbackHeight) {
    return {
        width: Math.max(1, source?.videoWidth || source?.naturalWidth || source?.width || fallbackWidth),
        height: Math.max(1, source?.videoHeight || source?.naturalHeight || source?.height || fallbackHeight),
    };
}

export function drawCoverImage(ctx, image, width, height) {
    const { width: iw, height: ih } = sourceNaturalSize(image, width, height);
    const scale = Math.max(width / iw, height / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = (width - dw) * 0.5;
    const dy = (height - dh) * 0.5;
    ctx.drawImage(image, dx, dy, dw, dh);
}

export function drawContainImage(ctx, image, width, height) {
    const { width: iw, height: ih } = sourceNaturalSize(image, width, height);
    const scale = Math.min(width / iw, height / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = (width - dw) * 0.5;
    const dy = (height - dh) * 0.5;
    ctx.drawImage(image, dx, dy, dw, dh);
}

export function resolveCoverUvTransform(image, width, height) {
    const { width: iw, height: ih } = sourceNaturalSize(image, width, height);
    const scale = Math.max(width / iw, height / ih);
    const dw = iw * scale;
    const dh = ih * scale;
    const dx = (width - dw) * 0.5;
    const dy = (height - dh) * 0.5;
    return [
        width / dw,
        height / dh,
        -dx / dw,
        -dy / dh,
    ];
}

export function resolveCanvasColor(color, fallback = '#000010') {
    const value = String(color || '').trim();
    const cssVariable = value.match(/^var\((--[A-Za-z0-9_-]+)\)$/);
    if (!cssVariable || typeof window === 'undefined' || typeof document === 'undefined') {
        return value || fallback;
    }
    const resolved = window.getComputedStyle(document.documentElement).getPropertyValue(cssVariable[1]).trim();
    return resolved || fallback;
}

export function colorToVec4(value) {
    const text = resolveCanvasColor(value, '#000010');
    const match = /^#?([0-9a-f]{6})$/i.exec(text);
    if (!match) return [0, 0, 0, 1];
    const hex = match[1];
    return [
        Number.parseInt(hex.slice(0, 2), 16) / 255,
        Number.parseInt(hex.slice(2, 4), 16) / 255,
        Number.parseInt(hex.slice(4, 6), 16) / 255,
        1,
    ];
}

export async function loadImageCanvas(url) {
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

export function resizeCanvas(targetCanvas, width, height) {
    const nextWidth = Math.max(1, Math.round(Number(width) || 1));
    const nextHeight = Math.max(1, Math.round(Number(height) || 1));
    if (targetCanvas.width !== nextWidth || targetCanvas.height !== nextHeight) {
        targetCanvas.width = nextWidth;
        targetCanvas.height = nextHeight;
        return true;
    }
    return false;
}

export function processMaskForAlpha(mask, width, height) {
    return buildInnerDistanceFeatherMaskValues(mask, width, height);
}

function isImageBitmap(value) {
    return typeof ImageBitmap !== 'undefined' && value instanceof ImageBitmap;
}

export function createMaskCanvasTools(canvas) {
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
    let maskSourceImageData = null;

    function clearMask() {
        if (!maskLayer) return;
        resizeCanvas(maskCanvas, canvas.width, canvas.height);
        maskLayer.clearRect(0, 0, maskCanvas.width, maskCanvas.height);
    }

    function ensureMaskSourceImageData(sourceWidth, sourceHeight) {
        const resizedSource = resizeCanvas(maskSourceCanvas, sourceWidth, sourceHeight);
        if (
            resizedSource
            || !maskSourceImageData
            || maskSourceImageData.width !== sourceWidth
            || maskSourceImageData.height !== sourceHeight
        ) {
            maskSourceImageData = maskSourceLayer.createImageData(sourceWidth, sourceHeight);
        }
        return maskSourceImageData;
    }

    function commitSourceAlpha(sourceAlpha, sourceWidth, sourceHeight) {
        if (!maskLayer || !maskSourceLayer || !sourceAlpha || sourceAlpha.length <= 0) {
            clearMask();
            return false;
        }

        const imageData = ensureMaskSourceImageData(sourceWidth, sourceHeight);
        const data = imageData.data;
        let maxAlpha = 0;
        for (let pixel = 0; pixel < sourceAlpha.length; pixel += 1) {
            const alpha = sourceAlpha[pixel] ?? 0;
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

        maskSourceLayer.putImageData(imageData, 0, 0);
        maskLayer.imageSmoothingEnabled = true;
        maskLayer.imageSmoothingQuality = 'high';
        maskLayer.drawImage(maskSourceCanvas, 0, 0, maskCanvas.width, maskCanvas.height);
        return true;
    }

    function drawMaskBitmap(maskBitmap, maskWidth, maskHeight) {
        if (!isImageBitmap(maskBitmap) || !maskLayer || !maskSourceLayer) {
            clearMask();
            return false;
        }

        const sourceWidth = Math.max(1, Math.round(Number(maskWidth) || maskBitmap.width || 0));
        const sourceHeight = Math.max(1, Math.round(Number(maskHeight) || maskBitmap.height || 0));
        if (sourceWidth <= 1 || sourceHeight <= 1) {
            clearMask();
            return false;
        }

        resizeCanvas(maskSourceCanvas, sourceWidth, sourceHeight);
        maskSourceLayer.clearRect(0, 0, sourceWidth, sourceHeight);
        maskSourceLayer.imageSmoothingEnabled = false;
        maskSourceLayer.drawImage(maskBitmap, 0, 0, sourceWidth, sourceHeight);
        const bitmapData = maskSourceLayer.getImageData(0, 0, sourceWidth, sourceHeight);
        const sourceAlpha = new Uint8ClampedArray(sourceWidth * sourceHeight);
        for (let pixel = 0; pixel < sourceAlpha.length; pixel += 1) {
            sourceAlpha[pixel] = bitmapData.data[pixel * 4 + 3] ?? 0;
        }
        const shapedAlpha = buildInnerDistanceFeatherAlpha(sourceAlpha, sourceWidth, sourceHeight);
        return commitSourceAlpha(shapedAlpha, sourceWidth, sourceHeight);
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

        const shapedValues = processMaskForAlpha(maskValues, sourceWidth, sourceHeight);
        if (!(shapedValues instanceof Float32Array) || shapedValues.length < pixelCount) {
            clearMask();
            return false;
        }

        const sourceAlpha = new Uint8ClampedArray(pixelCount);
        for (let pixel = 0; pixel < pixelCount; pixel += 1) {
            sourceAlpha[pixel] = Math.max(0, Math.min(255, Math.round((Number(shapedValues[pixel]) || 0) * 255)));
        }
        return commitSourceAlpha(sourceAlpha, sourceWidth, sourceHeight);
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

    function getMatteMaskSnapshot() {
        try {
            return maskLayer?.getImageData?.(0, 0, maskCanvas.width, maskCanvas.height) || null;
        } catch {
            return null;
        }
    }

    return {
        clearMask,
        drawDebugCanvases,
        drawMaskBitmap,
        drawMaskValues,
        get maskCanvas() {
            return maskCanvas;
        },
        getMatteMaskSnapshot,
    };
}
