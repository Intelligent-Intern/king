import {
    colorToVec4,
    createMaskCanvasTools,
    loadImageCanvas,
    resolveCoverUvTransform,
} from './compositorShared';

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
uniform float uMaskFlipY;
uniform int uEffect;
uniform int uBackgroundMode;
uniform int uHasMask;
varying vec2 vUv;

float readMask(vec2 uv) {
  vec2 maskUv = uMaskFlipY > 0.5 ? vec2(uv.x, 1.0 - uv.y) : uv;
  vec4 maskColor = texture2D(uMask, maskUv);
  return clamp(maskColor.a < 0.999 ? maskColor.a : maskColor.r, 0.0, 1.0);
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

  float maskAlpha = uHasMask == 1 ? readMask(vUv) : 0.0;
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

function isImageBitmap(value) {
    return typeof ImageBitmap !== 'undefined' && value instanceof ImageBitmap;
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

export function createWebGlBackgroundCompositorStage({
    canvas,
    getBackgroundColor,
    getBackgroundImageUrl,
    getBlurPx,
    getShowSourceUntilMask,
    video,
}) {
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
        uMaskFlipY: gl.getUniformLocation(program, 'uMaskFlipY'),
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
        latestMaskBitmap = isImageBitmap(maskBitmap) ? maskBitmap : null;
        latestMaskValues = maskValues instanceof Float32Array ? maskValues : null;
        latestMaskWidth = Math.max(1, Math.round(Number(maskWidth) || latestMaskBitmap?.width || canvas.width));
        latestMaskHeight = Math.max(1, Math.round(Number(maskHeight) || latestMaskBitmap?.height || canvas.height));

        if (latestMaskBitmap) {
            const drawn = maskTools.drawMaskBitmap(latestMaskBitmap, latestMaskWidth, latestMaskHeight);
            if (!drawn) {
                hasUploadedMask = false;
                return false;
            }
            uploadTexture(gl, textures.mask, 1, maskTools.maskCanvas);
            latestMaskFlipY = 0;
            hasUploadedMask = true;
            return true;
        }

        if (latestMaskValues) {
            const drawn = maskTools.drawMaskValues(
                latestMaskValues,
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
                latestMaskValues,
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

        const requestedBackgroundColor = String(getBackgroundColor?.() || '').trim();
        setBackgroundImageUrl(getBackgroundImageUrl?.() || '');
        const blurPx = Math.max(1, Math.round(Number(getBlurPx?.() || 3)));
        const foregroundSource = sourceFrame || video;

        if (maskUpdated) {
            uploadMask({ maskBitmap, maskHeight, maskValues, maskWidth });
        }

        const hasRenderableMask = hasUploadedMask && hasMatteMask;
        const showSourceUntilMask = getShowSourceUntilMask?.() === true;
        const warmupPlaceholder = !hasRenderableMask && mode === 'replace' && !showSourceUntilMask;
        const backgroundColor = warmupPlaceholder ? '#061a4a' : requestedBackgroundColor;
        gl.viewport(0, 0, canvas.width, canvas.height);
        gl.useProgram(program);
        gl.bindBuffer(gl.ARRAY_BUFFER, vertexBuffer);
        gl.enableVertexAttribArray(locations.aPosition);
        gl.vertexAttribPointer(locations.aPosition, 2, gl.FLOAT, false, 0, 0);
        uploadTexture(gl, textures.frame, 0, mode === 'off' ? video : foregroundSource);

        let backgroundMode = 0;
        let backgroundUvTransform = [1, 1, 0, 0];
        if (mode === 'replace' && backgroundImageCanvas && !warmupPlaceholder) {
            backgroundMode = 1;
            backgroundUvTransform = resolveCoverUvTransform(backgroundImageCanvas, canvas.width, canvas.height);
            uploadTexture(gl, textures.background, 2, backgroundImageCanvas);
        } else if (mode === 'blur' || (mode === 'replace' && !backgroundColor)) {
            backgroundMode = 2;
        }

        gl.uniform1i(locations.uEffect, mode === 'off' || (!hasRenderableMask && showSourceUntilMask) ? 0 : 1);
        gl.uniform1i(locations.uBackgroundMode, backgroundMode);
        gl.uniform1i(locations.uHasMask, hasRenderableMask ? 1 : 0);
        gl.uniform1f(locations.uBlurPx, Math.max(blurPx, hasRenderableMask ? 1 : 6));
        gl.uniform1f(locations.uMaskFlipY, latestMaskFlipY);
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
