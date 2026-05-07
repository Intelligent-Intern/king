const backgroundCanvasCache = new Map();

function sourceNaturalSize(source, fallbackWidth, fallbackHeight) {
    return {
        width: Math.max(1, source?.videoWidth || source?.naturalWidth || source?.width || fallbackWidth),
        height: Math.max(1, source?.videoHeight || source?.naturalHeight || source?.height || fallbackHeight),
    };
}

const WEBGL_VERTEX_SHADER = `
attribute vec2 aPosition;
varying vec2 vUv;

void main(void) {
  vUv = aPosition * 0.5 + 0.5;
  gl_Position = vec4(aPosition, 0.0, 1.0);
}
`;

const WEBGL_FRAGMENT_SHADER = `
precision highp float;

uniform sampler2D uFrame;
uniform sampler2D uMask;
uniform sampler2D uBackground;
uniform vec2 uOutputSize;
uniform vec2 uMaskSize;
uniform vec4 uBackgroundColor;
uniform vec4 uBackgroundUvTransform;
uniform float uBlurPx;
uniform float uMaskFeather;
uniform float uMaskFlipY;
uniform float uMaskHigh;
uniform float uMaskLow;
uniform int uEffect;
uniform int uBackgroundMode;
uniform int uHasMask;
varying vec2 vUv;

float readMask(vec2 uv) {
  vec2 maskUv = uMaskFlipY > 0.5 ? vec2(uv.x, 1.0 - uv.y) : uv;
  vec4 maskColor = texture2D(uMask, maskUv);
  return maskColor.a < 0.999 ? maskColor.a : maskColor.r;
}

float featherMask(vec2 uv) {
  vec2 texel = vec2(max(uMaskFeather, 0.0)) / uMaskSize;
  float center = readMask(uv);
  if (uMaskFeather <= 0.0) {
    return center;
  }

  float sum = center * 4.0;
  sum += readMask(uv + texel * vec2(-1.0, 0.0)) * 2.0;
  sum += readMask(uv + texel * vec2(1.0, 0.0)) * 2.0;
  sum += readMask(uv + texel * vec2(0.0, -1.0)) * 2.0;
  sum += readMask(uv + texel * vec2(0.0, 1.0)) * 2.0;
  sum += readMask(uv + texel * vec2(-1.0, -1.0));
  sum += readMask(uv + texel * vec2(1.0, -1.0));
  sum += readMask(uv + texel * vec2(-1.0, 1.0));
  sum += readMask(uv + texel * vec2(1.0, 1.0));
  return sum / 16.0;
}

vec4 readBlurredFrame(vec2 uv) {
  vec2 texel = vec2(max(uBlurPx, 1.0)) / uOutputSize;
  vec4 sum = texture2D(uFrame, uv) * 0.20;
  sum += texture2D(uFrame, uv + texel * vec2(-1.0, 0.0)) * 0.12;
  sum += texture2D(uFrame, uv + texel * vec2(1.0, 0.0)) * 0.12;
  sum += texture2D(uFrame, uv + texel * vec2(0.0, -1.0)) * 0.12;
  sum += texture2D(uFrame, uv + texel * vec2(0.0, 1.0)) * 0.12;
  sum += texture2D(uFrame, uv + texel * vec2(-0.707, -0.707)) * 0.08;
  sum += texture2D(uFrame, uv + texel * vec2(0.707, -0.707)) * 0.08;
  sum += texture2D(uFrame, uv + texel * vec2(-0.707, 0.707)) * 0.08;
  sum += texture2D(uFrame, uv + texel * vec2(0.707, 0.707)) * 0.08;
  return sum;
}

void main(void) {
  vec4 frame = texture2D(uFrame, vUv);
  if (uEffect == 0) {
    gl_FragColor = frame;
    return;
  }

  float maskAlpha = uHasMask == 1 ? smoothstep(uMaskLow, uMaskHigh, featherMask(vUv)) : 0.0;
  vec4 background = uBackgroundColor;

  if (uBackgroundMode == 1) {
    vec2 backgroundUv = vUv * uBackgroundUvTransform.xy + uBackgroundUvTransform.zw;
    background = texture2D(uBackground, backgroundUv);
  } else if (uBackgroundMode == 2) {
    background = readBlurredFrame(vUv);
  }

  gl_FragColor = vec4(mix(background.rgb, frame.rgb, maskAlpha), 1.0);
}
`;

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

function resolveCoverUvTransform(image, width, height) {
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

function resolveCanvasColor(color, fallback = '#000010') {
    const value = String(color || '').trim();
    const cssVariable = value.match(/^var\((--[A-Za-z0-9_-]+)\)$/);
    if (!cssVariable || typeof window === 'undefined' || typeof document === 'undefined') {
        return value || fallback;
    }
    const resolved = window.getComputedStyle(document.documentElement).getPropertyValue(cssVariable[1]).trim();
    return resolved || fallback;
}

function colorToVec4(value) {
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

function processMaskForAlpha(mask, width, height) {
    if (!(mask instanceof Float32Array)) return mask;
    const processed = new Float32Array(mask.length);
    const threshold = 0.5;
    const blurRadius = 2;

    for (let i = 0; i < mask.length; i += 1) {
        const value = Number(mask[i]) || 0;
        processed[i] = value > threshold
            ? Math.min(1, (value - threshold) / (1 - threshold))
            : 0;
    }

    return blurMask(processed, width, height, blurRadius);
}

function blurMask(mask, width, height, radius) {
    const output = new Float32Array(mask.length);
    for (let y = 0; y < height; y += 1) {
        for (let x = 0; x < width; x += 1) {
            let sum = 0;
            let count = 0;
            for (let ky = -radius; ky <= radius; ky += 1) {
                for (let kx = -radius; kx <= radius; kx += 1) {
                    const nx = Math.max(0, Math.min(width - 1, x + kx));
                    const ny = Math.max(0, Math.min(height - 1, y + ky));
                    sum += mask[ny * width + nx];
                    count += 1;
                }
            }
            output[y * width + x] = sum / count;
        }
    }
    return output;
}

function createMaskCanvasTools(canvas) {
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

    function drawMaskBitmap(maskBitmap) {
        if (!(maskBitmap instanceof ImageBitmap) || !maskLayer) {
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
            debugMaskCtx.fillStyle = 'rgba(255, 80, 80, 0.9)';
            debugMaskCtx.font = '12px monospace';
            debugMaskCtx.fillText('mask empty', 8, 18);
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

function createCanvasBackgroundCompositorStage({
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
            ctx.globalCompositeOperation = 'copy';
            ctx.filter = 'none';
            drawContainImage(ctx, video, canvas.width, canvas.height);
            ctx.restore();
            return;
        }

        if (hasMatteMask && !maskUpdated) return;

        let hasRenderableMask = false;
        if (maskUpdated) {
            hasRenderableMask = maskBitmap instanceof ImageBitmap
                ? maskTools.drawMaskBitmap(maskBitmap, maskWidth, maskHeight)
                : maskTools.drawMaskValues(processMaskForAlpha(maskValues, maskWidth, maskHeight), maskWidth, maskHeight);
        } else {
            hasRenderableMask = Boolean(hasMatteMask);
        }

        maskTools.drawDebugCanvases(foregroundSource);

        if (!hasRenderableMask) {
            ctx.save();
            ctx.globalCompositeOperation = 'copy';
            ctx.filter = mode === 'replace' ? 'none' : `blur(${blurPx}px)`;
            drawCoverImage(ctx, video, canvas.width, canvas.height);
            ctx.restore();
            return;
        }

        ctx.save();
        ctx.globalCompositeOperation = 'copy';
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

function createShader(gl, type, source) {
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
        const message = gl.getShaderInfoLog(shader) || 'shader_compile_failed';
        gl.deleteShader(shader);
        throw new Error(message);
    }
    return shader;
}

function createProgram(gl) {
    const vertexShader = createShader(gl, gl.VERTEX_SHADER, WEBGL_VERTEX_SHADER);
    const fragmentShader = createShader(gl, gl.FRAGMENT_SHADER, WEBGL_FRAGMENT_SHADER);
    const program = gl.createProgram();
    gl.attachShader(program, vertexShader);
    gl.attachShader(program, fragmentShader);
    gl.linkProgram(program);
    gl.deleteShader(vertexShader);
    gl.deleteShader(fragmentShader);
    if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
        const message = gl.getProgramInfoLog(program) || 'program_link_failed';
        gl.deleteProgram(program);
        throw new Error(message);
    }
    return program;
}

function createTexture(gl) {
    const texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    return texture;
}

function uploadTexture(gl, texture, unit, source) {
    gl.activeTexture(gl.TEXTURE0 + unit);
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
    gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, source);
}

function createWebGlBackgroundCompositorStage({
    canvas,
    getBackgroundColor,
    getBackgroundImageUrl,
    getBlurPx,
    video,
}) {
    console.log('[BackgroundFilter] Using WebGL compositor');
    const gl = canvas.getContext('webgl2', {
        alpha: false,
        desynchronized: true,
        premultipliedAlpha: false,
        preserveDrawingBuffer: false,
    }) || canvas.getContext('webgl', {
        alpha: false,
        desynchronized: true,
        premultipliedAlpha: false,
        preserveDrawingBuffer: false,
    });
    if (!gl) throw new Error('webgl compositor unavailable');

    const program = createProgram(gl);
    const locations = {
        aPosition: gl.getAttribLocation(program, 'aPosition'),
        uBackground: gl.getUniformLocation(program, 'uBackground'),
        uBackgroundColor: gl.getUniformLocation(program, 'uBackgroundColor'),
        uBackgroundMode: gl.getUniformLocation(program, 'uBackgroundMode'),
        uBackgroundUvTransform: gl.getUniformLocation(program, 'uBackgroundUvTransform'),
        uBlurPx: gl.getUniformLocation(program, 'uBlurPx'),
        uEffect: gl.getUniformLocation(program, 'uEffect'),
        uFrame: gl.getUniformLocation(program, 'uFrame'),
        uHasMask: gl.getUniformLocation(program, 'uHasMask'),
        uMask: gl.getUniformLocation(program, 'uMask'),
        uMaskFeather: gl.getUniformLocation(program, 'uMaskFeather'),
        uMaskFlipY: gl.getUniformLocation(program, 'uMaskFlipY'),
        uMaskHigh: gl.getUniformLocation(program, 'uMaskHigh'),
        uMaskLow: gl.getUniformLocation(program, 'uMaskLow'),
        uMaskSize: gl.getUniformLocation(program, 'uMaskSize'),
        uOutputSize: gl.getUniformLocation(program, 'uOutputSize'),
    };
    const vertexBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, vertexBuffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([
        -1, -1,
        1, -1,
        -1, 1,
        -1, 1,
        1, -1,
        1, 1,
    ]), gl.STATIC_DRAW);

    const textures = {
        background: createTexture(gl),
        frame: createTexture(gl),
        mask: createTexture(gl),
    };
    const maskTools = createMaskCanvasTools(canvas);
    let backgroundImageCanvas = null;
    let backgroundImageUrl = '';
    let hasUploadedMask = false;
    let latestMaskBitmap = null;
    let latestMaskValues = null;
    let latestMaskWidth = 0;
    let latestMaskHeight = 0;
    let latestMaskFlipY = 0;

    gl.useProgram(program);
    gl.uniform1i(locations.uFrame, 0);
    gl.uniform1i(locations.uMask, 1);
    gl.uniform1i(locations.uBackground, 2);

    function setBackgroundImageUrl(url) {
        const nextUrl = String(url || '').trim();
        if (nextUrl === backgroundImageUrl) return;
        backgroundImageUrl = nextUrl;
        backgroundImageCanvas = null;
        if (!backgroundImageUrl) return;
        loadImageCanvas(backgroundImageUrl).then((imageCanvas) => {
            if (backgroundImageUrl !== nextUrl) return;
            backgroundImageCanvas = imageCanvas;
            if (backgroundImageCanvas && !gl.isContextLost()) {
                uploadTexture(gl, textures.background, 2, backgroundImageCanvas);
            }
        });
    }

    function uploadMask({ maskBitmap, maskValues, maskWidth, maskHeight }) {
        latestMaskBitmap = maskBitmap instanceof ImageBitmap ? maskBitmap : null;
        latestMaskValues = maskValues instanceof Float32Array ? maskValues : null;
        latestMaskWidth = Math.max(1, Math.round(Number(maskWidth) || latestMaskBitmap?.width || canvas.width));
        latestMaskHeight = Math.max(1, Math.round(Number(maskHeight) || latestMaskBitmap?.height || canvas.height));

        if (latestMaskBitmap) {
            uploadTexture(gl, textures.mask, 1, latestMaskBitmap);
            latestMaskFlipY = 1;
            hasUploadedMask = true;
            if (document.getElementById('backgroundPipelineDebugDialog')) {
                maskTools.drawMaskBitmap(latestMaskBitmap, latestMaskWidth, latestMaskHeight);
            }
            return true;
        }

        if (latestMaskValues) {
            const drawn = maskTools.drawMaskValues(
                processMaskForAlpha(latestMaskValues, latestMaskWidth, latestMaskHeight),
                latestMaskWidth,
                latestMaskHeight,
            );
            if (!drawn) {
                hasUploadedMask = false;
                return false;
            }
            uploadTexture(gl, textures.mask, 1, maskTools.maskCanvas);
            latestMaskFlipY = 0;
            hasUploadedMask = true;
            return true;
        }

        hasUploadedMask = false;
        latestMaskFlipY = 0;
        maskTools.clearMask();
        return false;
    }

    function ensureMaskCanvasForSnapshot() {
        if (latestMaskBitmap) {
            maskTools.drawMaskBitmap(latestMaskBitmap, latestMaskWidth, latestMaskHeight);
        } else if (latestMaskValues) {
            maskTools.drawMaskValues(
                processMaskForAlpha(latestMaskValues, latestMaskWidth, latestMaskHeight),
                latestMaskWidth,
                latestMaskHeight,
            );
        }
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
        if (gl.isContextLost()) return;

        const backgroundColor = String(getBackgroundColor?.() || '').trim();
        setBackgroundImageUrl(getBackgroundImageUrl?.() || '');
        const blurPx = Math.max(1, Math.round(Number(getBlurPx?.() || 3)));
        const foregroundSource = sourceFrame || video;

        if (hasMatteMask && !maskUpdated && mode !== 'off') return;

        if (maskUpdated) {
            uploadMask({ maskBitmap, maskHeight, maskValues, maskWidth });
        }

        const hasRenderableMask = hasUploadedMask && hasMatteMask;

        gl.viewport(0, 0, canvas.width, canvas.height);
        gl.useProgram(program);
        gl.bindBuffer(gl.ARRAY_BUFFER, vertexBuffer);
        gl.enableVertexAttribArray(locations.aPosition);
        gl.vertexAttribPointer(locations.aPosition, 2, gl.FLOAT, false, 0, 0);
        uploadTexture(gl, textures.frame, 0, mode === 'off' ? video : foregroundSource);

        let backgroundMode = 0;
        let backgroundUvTransform = [1, 1, 0, 0];
        if (mode === 'replace' && !hasRenderableMask) {
            backgroundMode = 0;
        } else if (mode === 'replace' && backgroundImageCanvas) {
            backgroundMode = 1;
            backgroundUvTransform = resolveCoverUvTransform(backgroundImageCanvas, canvas.width, canvas.height);
            uploadTexture(gl, textures.background, 2, backgroundImageCanvas);
        } else if (mode === 'blur' && !backgroundColor) {
            backgroundMode = 2;
        }

        gl.uniform1i(locations.uEffect, mode === 'off' || (mode === 'replace' && !hasRenderableMask) ? 0 : 1);
        gl.uniform1i(locations.uBackgroundMode, backgroundMode);
        gl.uniform1i(locations.uHasMask, hasRenderableMask ? 1 : 0);
        gl.uniform1f(locations.uBlurPx, blurPx);
        gl.uniform1f(locations.uMaskFeather, 4.35);
        gl.uniform1f(locations.uMaskFlipY, latestMaskFlipY);
        gl.uniform1f(locations.uMaskLow, 0.12);
        gl.uniform1f(locations.uMaskHigh, 0.88);
        gl.uniform2f(locations.uOutputSize, canvas.width, canvas.height);
        gl.uniform2f(locations.uMaskSize, latestMaskWidth || canvas.width, latestMaskHeight || canvas.height);
        gl.uniform4fv(locations.uBackgroundColor, colorToVec4(backgroundColor || '#000000'));
        gl.uniform4fv(locations.uBackgroundUvTransform, backgroundUvTransform);
        gl.drawArrays(gl.TRIANGLES, 0, 6);

        if (document.getElementById('backgroundPipelineDebugDialog')) {
            ensureMaskCanvasForSnapshot();
            maskTools.drawDebugCanvases(foregroundSource);
        }
    }

    function reset() {
        hasUploadedMask = false;
        latestMaskBitmap = null;
        latestMaskValues = null;
        latestMaskWidth = 0;
        latestMaskHeight = 0;
        latestMaskFlipY = 0;
        maskTools.clearMask();
    }

    return {
        backend: 'webgl',
        getMatteMaskSnapshot() {
            ensureMaskCanvasForSnapshot();
            return maskTools.getMatteMaskSnapshot();
        },
        render,
        reset,
        setBackgroundImageUrl,
    };
}

export function createBackgroundCompositorStage(options = {}) {
    console.log('[BackgroundFilter] Initializing compositor stage', options);
    const preferWebGl = options.preferWebGl !== false;
    if (preferWebGl) {
        try {
            return createWebGlBackgroundCompositorStage(options);
        } catch (error) {
            console.warn('[BackgroundFilter] WebGL compositor unavailable; falling back to canvas.', error);
        }
    }
    return createCanvasBackgroundCompositorStage(options);
}
