import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const frontendRoot = path.resolve(__dirname, '../..');
const originalConsoleLog = console.log.bind(console);

console.log = (...args) => {
  if (String(args[0] || '').startsWith('[BackgroundFilter]')) return;
  originalConsoleLog(...args);
};

class FakeImageBitmap {}

class FakeImageData {
  constructor(widthOrData, heightOrWidth, maybeHeight) {
    if (widthOrData instanceof Uint8ClampedArray) {
      this.data = widthOrData;
      this.width = Math.max(1, Math.round(Number(heightOrWidth) || 1));
      this.height = Math.max(1, Math.round(Number(maybeHeight) || 1));
      return;
    }
    this.width = Math.max(1, Math.round(Number(widthOrData) || 1));
    this.height = Math.max(1, Math.round(Number(heightOrWidth) || 1));
    this.data = new Uint8ClampedArray(this.width * this.height * 4);
  }
}

function clampByte(value) {
  return Math.max(0, Math.min(255, Math.round(Number(value) || 0)));
}

function parseColor(value) {
  const text = String(value || '').trim();
  const hex = /^#?([0-9a-f]{6})$/i.exec(text);
  if (hex) {
    return [
      Number.parseInt(hex[1].slice(0, 2), 16),
      Number.parseInt(hex[1].slice(2, 4), 16),
      Number.parseInt(hex[1].slice(4, 6), 16),
      255,
    ];
  }
  return [0, 0, 0, 255];
}

function sourceWidth(source) {
  return Math.max(1, Math.round(Number(source?.videoWidth || source?.naturalWidth || source?.width || 1)));
}

function sourceHeight(source) {
  return Math.max(1, Math.round(Number(source?.videoHeight || source?.naturalHeight || source?.height || 1)));
}

function sourceData(source) {
  return source?.data || source?._data || null;
}

function readSourcePixel(source, x, y) {
  const width = sourceWidth(source);
  const data = sourceData(source);
  assert.ok(data, 'fake source must expose pixel data');
  const px = Math.max(0, Math.min(width - 1, Math.floor(x)));
  const py = Math.max(0, Math.min(sourceHeight(source) - 1, Math.floor(y)));
  const offset = (py * width + px) * 4;
  return [
    data[offset] ?? 0,
    data[offset + 1] ?? 0,
    data[offset + 2] ?? 0,
    data[offset + 3] ?? 255,
  ];
}

function writePixel(data, width, x, y, color, op = 'source-over') {
  const offset = (y * width + x) * 4;
  const da = (data[offset + 3] ?? 0) / 255;
  const sa = (color[3] ?? 255) / 255;

  if (op === 'copy') {
    data[offset] = clampByte(color[0]);
    data[offset + 1] = clampByte(color[1]);
    data[offset + 2] = clampByte(color[2]);
    data[offset + 3] = clampByte(color[3] ?? 255);
    return;
  }

  if (op === 'destination-in') {
    data[offset] = clampByte((data[offset] ?? 0) * sa);
    data[offset + 1] = clampByte((data[offset + 1] ?? 0) * sa);
    data[offset + 2] = clampByte((data[offset + 2] ?? 0) * sa);
    data[offset + 3] = clampByte((data[offset + 3] ?? 0) * sa);
    return;
  }

  if (op === 'destination-over') {
    const outA = da + sa * (1 - da);
    if (outA <= 0) return;
    data[offset] = clampByte(((data[offset] ?? 0) * da + color[0] * sa * (1 - da)) / outA);
    data[offset + 1] = clampByte(((data[offset + 1] ?? 0) * da + color[1] * sa * (1 - da)) / outA);
    data[offset + 2] = clampByte(((data[offset + 2] ?? 0) * da + color[2] * sa * (1 - da)) / outA);
    data[offset + 3] = clampByte(outA * 255);
    return;
  }

  const outA = sa + da * (1 - sa);
  if (outA <= 0) return;
  data[offset] = clampByte((color[0] * sa + (data[offset] ?? 0) * da * (1 - sa)) / outA);
  data[offset + 1] = clampByte((color[1] * sa + (data[offset + 1] ?? 0) * da * (1 - sa)) / outA);
  data[offset + 2] = clampByte((color[2] * sa + (data[offset + 2] ?? 0) * da * (1 - sa)) / outA);
  data[offset + 3] = clampByte(outA * 255);
}

class Fake2dContext {
  constructor(canvas) {
    this.canvas = canvas;
    this.fillStyle = '#000000';
    this.filter = 'none';
    this.font = '';
    this.globalCompositeOperation = 'source-over';
    this.imageSmoothingEnabled = true;
    this.imageSmoothingQuality = 'high';
    this.stack = [];
  }

  save() {
    this.stack.push({
      fillStyle: this.fillStyle,
      filter: this.filter,
      globalCompositeOperation: this.globalCompositeOperation,
    });
  }

  restore() {
    const next = this.stack.pop();
    if (!next) return;
    this.fillStyle = next.fillStyle;
    this.filter = next.filter;
    this.globalCompositeOperation = next.globalCompositeOperation;
  }

  clearRect(x, y, width, height) {
    const left = Math.max(0, Math.floor(x));
    const top = Math.max(0, Math.floor(y));
    const right = Math.min(this.canvas.width, Math.ceil(x + width));
    const bottom = Math.min(this.canvas.height, Math.ceil(y + height));
    for (let py = top; py < bottom; py += 1) {
      for (let px = left; px < right; px += 1) {
        const offset = (py * this.canvas.width + px) * 4;
        this.canvas.data[offset] = 0;
        this.canvas.data[offset + 1] = 0;
        this.canvas.data[offset + 2] = 0;
        this.canvas.data[offset + 3] = 0;
      }
    }
  }

  fillRect(x, y, width, height) {
    const color = parseColor(this.fillStyle);
    const left = Math.max(0, Math.floor(x));
    const top = Math.max(0, Math.floor(y));
    const right = Math.min(this.canvas.width, Math.ceil(x + width));
    const bottom = Math.min(this.canvas.height, Math.ceil(y + height));
    for (let py = top; py < bottom; py += 1) {
      for (let px = left; px < right; px += 1) {
        writePixel(this.canvas.data, this.canvas.width, px, py, color, this.globalCompositeOperation);
      }
    }
  }

  drawImage(source, ...args) {
    const sourceW = sourceWidth(source);
    const sourceH = sourceHeight(source);
    let sx = 0;
    let sy = 0;
    let sw = sourceW;
    let sh = sourceH;
    let dx = 0;
    let dy = 0;
    let dw = sourceW;
    let dh = sourceH;

    if (args.length === 4) {
      [dx, dy, dw, dh] = args;
    } else if (args.length === 8) {
      [sx, sy, sw, sh, dx, dy, dw, dh] = args;
    } else {
      throw new Error(`unsupported drawImage arity ${args.length}`);
    }

    const left = Math.max(0, Math.floor(dx));
    const top = Math.max(0, Math.floor(dy));
    const right = Math.min(this.canvas.width, Math.ceil(dx + dw));
    const bottom = Math.min(this.canvas.height, Math.ceil(dy + dh));
    if (dw <= 0 || dh <= 0 || sw <= 0 || sh <= 0) return;

    for (let py = top; py < bottom; py += 1) {
      for (let px = left; px < right; px += 1) {
        const u = Math.max(0, Math.min(1, (px + 0.5 - dx) / dw));
        const v = Math.max(0, Math.min(1, (py + 0.5 - dy) / dh));
        const srcX = sx + u * sw;
        const srcY = sy + v * sh;
        const color = readSourcePixel(source, srcX, srcY);
        writePixel(this.canvas.data, this.canvas.width, px, py, color, this.globalCompositeOperation);
      }
    }
  }

  createImageData(width, height) {
    return new FakeImageData(width, height);
  }

  putImageData(imageData, dx, dy) {
    for (let y = 0; y < imageData.height; y += 1) {
      for (let x = 0; x < imageData.width; x += 1) {
        const targetX = dx + x;
        const targetY = dy + y;
        if (targetX < 0 || targetY < 0 || targetX >= this.canvas.width || targetY >= this.canvas.height) continue;
        const sourceOffset = (y * imageData.width + x) * 4;
        const targetOffset = (targetY * this.canvas.width + targetX) * 4;
        this.canvas.data[targetOffset] = imageData.data[sourceOffset];
        this.canvas.data[targetOffset + 1] = imageData.data[sourceOffset + 1];
        this.canvas.data[targetOffset + 2] = imageData.data[sourceOffset + 2];
        this.canvas.data[targetOffset + 3] = imageData.data[sourceOffset + 3];
      }
    }
  }

  getImageData(x, y, width, height) {
    const imageData = new FakeImageData(width, height);
    for (let py = 0; py < imageData.height; py += 1) {
      for (let px = 0; px < imageData.width; px += 1) {
        const srcX = x + px;
        const srcY = y + py;
        const targetOffset = (py * imageData.width + px) * 4;
        if (srcX < 0 || srcY < 0 || srcX >= this.canvas.width || srcY >= this.canvas.height) continue;
        const sourceOffset = (srcY * this.canvas.width + srcX) * 4;
        imageData.data[targetOffset] = this.canvas.data[sourceOffset];
        imageData.data[targetOffset + 1] = this.canvas.data[sourceOffset + 1];
        imageData.data[targetOffset + 2] = this.canvas.data[sourceOffset + 2];
        imageData.data[targetOffset + 3] = this.canvas.data[sourceOffset + 3];
      }
    }
    return imageData;
  }

  fillText() {}
}

class FakeCanvas {
  constructor(width = 2, height = 2, { webgl = null } = {}) {
    this._width = Math.max(1, Math.round(width));
    this._height = Math.max(1, Math.round(height));
    this.data = new Uint8ClampedArray(this._width * this._height * 4);
    this.context2d = new Fake2dContext(this);
    this.webgl = webgl;
  }

  get width() {
    return this._width;
  }

  set width(value) {
    this._width = Math.max(1, Math.round(Number(value) || 1));
    this.data = new Uint8ClampedArray(this._width * this._height * 4);
  }

  get height() {
    return this._height;
  }

  set height(value) {
    this._height = Math.max(1, Math.round(Number(value) || 1));
    this.data = new Uint8ClampedArray(this._width * this._height * 4);
  }

  getContext(type) {
    if (type === '2d') return this.context2d;
    if (type === 'webgl2' || type === 'webgl') return this.webgl;
    return null;
  }
}

class FakeWebGl {
  constructor() {
    this.ACTIVE_TEXTURE = 0;
    this.ARRAY_BUFFER = 0x8892;
    this.CLAMP_TO_EDGE = 0x812f;
    this.COMPILE_STATUS = 0x8b81;
    this.FLOAT = 0x1406;
    this.FRAGMENT_SHADER = 0x8b30;
    this.LINEAR = 0x2601;
    this.LINK_STATUS = 0x8b82;
    this.RGBA = 0x1908;
    this.STATIC_DRAW = 0x88e4;
    this.TEXTURE0 = 0x84c0;
    this.TEXTURE_2D = 0x0de1;
    this.TEXTURE_MAG_FILTER = 0x2800;
    this.TEXTURE_MIN_FILTER = 0x2801;
    this.TEXTURE_WRAP_S = 0x2802;
    this.TEXTURE_WRAP_T = 0x2803;
    this.TRIANGLES = 0x0004;
    this.UNPACK_FLIP_Y_WEBGL = 0x9240;
    this.UNSIGNED_BYTE = 0x1401;
    this.VERTEX_SHADER = 0x8b31;
    this.activeUnit = 0;
    this.drawCalls = 0;
    this.frameUploads = [];
    this.uniforms = new Map();
  }

  createShader(type) {
    return { type };
  }

  shaderSource() {}

  compileShader() {}

  getShaderParameter(_shader, parameter) {
    return parameter === this.COMPILE_STATUS;
  }

  getShaderInfoLog() {
    return '';
  }

  deleteShader() {}

  createProgram() {
    return {};
  }

  attachShader() {}

  linkProgram() {}

  getProgramParameter(_program, parameter) {
    return parameter === this.LINK_STATUS;
  }

  getProgramInfoLog() {
    return '';
  }

  deleteProgram() {}

  createBuffer() {
    return {};
  }

  bindBuffer() {}

  bufferData() {}

  createTexture() {
    return {};
  }

  bindTexture() {}

  texParameteri() {}

  getAttribLocation(_program, name) {
    return name;
  }

  getUniformLocation(_program, name) {
    return name;
  }

  useProgram() {}

  uniform1i(location, value) {
    this.uniforms.set(location, value);
  }

  activeTexture(unit) {
    this.activeUnit = unit - this.TEXTURE0;
  }

  pixelStorei() {}

  texImage2D(...args) {
    const source = args.at(-1);
    if (this.activeUnit === 0) {
      this.frameUploads.push(source);
    }
  }

  isContextLost() {
    return false;
  }

  viewport() {}

  enableVertexAttribArray() {}

  vertexAttribPointer() {}

  uniform1f(location, value) {
    this.uniforms.set(location, value);
  }

  uniform2f(location, x, y) {
    this.uniforms.set(location, [x, y]);
  }

  uniform4fv(location, value) {
    this.uniforms.set(location, [...value]);
  }

  drawArrays() {
    this.drawCalls += 1;
  }
}

function installDom() {
  globalThis.ImageBitmap = FakeImageBitmap;
  globalThis.ImageData = FakeImageData;
  globalThis.document = {
    createElement(tagName) {
      assert.equal(tagName, 'canvas', 'compositor contract fake DOM only supports canvas creation');
      return new FakeCanvas(2, 2);
    },
    getElementById() {
      return null;
    },
  };
}

function solidFrame(name, color) {
  const frame = {
    currentTime: 0,
    ended: false,
    label: name,
    readyState: 4,
    videoHeight: 2,
    videoWidth: 2,
    width: 2,
    height: 2,
    data: new Uint8ClampedArray(2 * 2 * 4),
    setColor(nextColor) {
      const rgba = [...nextColor.slice(0, 3), nextColor[3] ?? 255];
      for (let i = 0; i < this.data.length; i += 4) {
        this.data[i] = rgba[0];
        this.data[i + 1] = rgba[1];
        this.data[i + 2] = rgba[2];
        this.data[i + 3] = rgba[3];
      }
    },
  };
  frame.setColor(color);
  return frame;
}

function solidCanvas(color) {
  const canvas = new FakeCanvas(2, 2);
  canvas.context2d.fillStyle = '#000000';
  canvas.context2d.clearRect(0, 0, 2, 2);
  for (let y = 0; y < 2; y += 1) {
    for (let x = 0; x < 2; x += 1) {
      writePixel(canvas.data, canvas.width, x, y, color, 'copy');
    }
  }
  return canvas;
}

function pixelAt(canvas, x = 0, y = 0) {
  const offset = (y * canvas.width + x) * 4;
  return [
    canvas.data[offset],
    canvas.data[offset + 1],
    canvas.data[offset + 2],
    canvas.data[offset + 3],
  ];
}

function assertPixelNear(canvas, expected, label) {
  const actual = pixelAt(canvas);
  for (let i = 0; i < 4; i += 1) {
    assert.ok(
      Math.abs(actual[i] - expected[i]) <= 2,
      `${label}: expected ${expected.join(',')} but got ${actual.join(',')}`,
    );
  }
}

function createStage(createBackgroundCompositorStage, { preferWebGl = false, webgl = null, video }) {
  const canvas = new FakeCanvas(2, 2, { webgl });
  const stage = createBackgroundCompositorStage({
    canvas,
    getBackgroundColor: () => '#061a4a',
    getBackgroundImageUrl: () => '',
    getBlurPx: () => 3,
    preferWebGl,
    video,
  });
  return { canvas, stage };
}

try {
  installDom();
  const moduleUrl = pathToFileURL(path.join(
    frontendRoot,
    'src/domain/realtime/background/pipeline/compositorStage.js',
  )).href;
  const { createBackgroundCompositorStage } = await import(moduleUrl);
  const fullMask = new Float32Array([1, 1, 1, 1]);
  const emptyMask = new Float32Array([0, 0, 0, 0]);
  const red = [230, 20, 20, 255];
  const green = [20, 210, 70, 255];
  const cyan = [20, 180, 220, 255];
  const yellow = [235, 220, 30, 255];
  const purple = [170, 40, 210, 255];

  {
    const video = solidFrame('warmup-source', red);
    const { canvas, stage } = createStage(createBackgroundCompositorStage, { video });
    stage.render({ hasMatteMask: false, maskUpdated: false, mode: 'replace' });
    assertPixelNear(canvas, red, 'canvas warmup/segmentation-unavailable frame must render source, not blue background');
  }

  {
    const video = solidFrame('fresh-video', red);
    const staleSourceFrame = solidCanvas(red);
    const { canvas, stage } = createStage(createBackgroundCompositorStage, { video });
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: true,
      maskValues: fullMask,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    assertPixelNear(canvas, red, 'canvas first matte frame must render the matched source frame');

    video.setColor(green);
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: false,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    assertPixelNear(canvas, green, 'canvas stale-mask reuse must keep rendering current video frames');
  }

  {
    const video = solidFrame('empty-mask-source', yellow);
    const { canvas, stage } = createStage(createBackgroundCompositorStage, { video });
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: true,
      maskValues: emptyMask,
      maskWidth: 2,
      mode: 'replace',
    });
    assertPixelNear(canvas, yellow, 'canvas empty first mask must render source instead of full background');

    video.setColor(purple);
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: false,
      maskWidth: 2,
      mode: 'replace',
    });
    assertPixelNear(canvas, purple, 'canvas empty-mask reuse must not turn into a full-background flash');
  }

  {
    const video = solidFrame('backend-reset-source', red);
    const staleSourceFrame = solidCanvas(red);
    const { canvas, stage } = createStage(createBackgroundCompositorStage, { video });
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: true,
      maskValues: fullMask,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    stage.reset();
    video.setColor(cyan);
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: false,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    assertPixelNear(canvas, cyan, 'canvas backend reset warmup must render source until a new mask arrives');
  }

  {
    const video = solidFrame('webgl-video', red);
    const staleSourceFrame = solidCanvas(red);
    const gl = new FakeWebGl();
    const { stage } = createStage(createBackgroundCompositorStage, {
      preferWebGl: true,
      video,
      webgl: gl,
    });
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: true,
      maskValues: fullMask,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    const drawCallsAfterFirstMask = gl.drawCalls;
    video.setColor(green);
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: false,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    assert.equal(gl.drawCalls, drawCallsAfterFirstMask + 1, 'WebGL stale-mask reuse must draw a new frame');
    assert.equal(gl.frameUploads.at(-1), video, 'WebGL stale-mask reuse must upload the current video, not a stale sourceFrame');

    stage.reset();
    stage.render({
      hasMatteMask: true,
      maskHeight: 2,
      maskUpdated: false,
      maskWidth: 2,
      mode: 'replace',
      sourceFrame: staleSourceFrame,
    });
    assert.equal(gl.uniforms.get('uEffect'), 0, 'WebGL backend-reset warmup must bypass replacement until a mask is uploaded');
    assert.equal(gl.frameUploads.at(-1), video, 'WebGL backend-reset warmup must render source video');
  }

  originalConsoleLog('[background-compositor-warmup-safety-contract] PASS');
} catch (error) {
  console.error(`[background-compositor-warmup-safety-contract] FAIL: ${error.message}`);
  process.exit(1);
}
