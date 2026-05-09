import assert from 'node:assert/strict';

import { screenShareUserIdForOwner } from '../../src/domain/realtime/screenShareIdentity.js';

class FakeStyle {
  constructor() {
    this.values = new Map();
    this.objectFit = '';
    this.objectPosition = '';
    this.transform = '';
    this.transformOrigin = '';
    this.touchAction = '';
    this.cursor = '';
  }

  setProperty(name, value) {
    this.values.set(name, String(value));
  }

  removeProperty(name) {
    this.values.delete(name);
  }

  getPropertyValue(name) {
    return this.values.get(name) || '';
  }
}

class FakeElement {
  constructor({ clientWidth = 0, clientHeight = 0 } = {}) {
    this.clientWidth = clientWidth;
    this.clientHeight = clientHeight;
    this.dataset = {};
    this.listeners = new Map();
    this.style = new FakeStyle();
  }

  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(listener);
  }

  removeEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    this.listeners.set(type, listeners.filter((candidate) => candidate !== listener));
  }

  dispatch(type, event = {}) {
    for (const listener of this.listeners.get(type) || []) {
      listener(event);
    }
  }

  setPointerCapture() {}

  releasePointerCapture() {}
}

class FakeCanvasElement extends FakeElement {
  constructor({ width = 0, height = 0, clientWidth = 0, clientHeight = 0 } = {}) {
    super({ clientWidth, clientHeight });
    this.width = width;
    this.height = height;
  }
}

class FakeVideoElement extends FakeElement {}

globalThis.HTMLElement = FakeElement;
globalThis.HTMLCanvasElement = FakeCanvasElement;
globalThis.HTMLVideoElement = FakeVideoElement;

const {
  applyScreenSharePanSurface,
  clearScreenSharePanSurface,
} = await import('../../src/domain/realtime/workspace/callWorkspace/screenSharePan.js');

const target = new FakeElement({ clientWidth: 800, clientHeight: 600 });
const node = new FakeCanvasElement({ width: 1600, height: 900 });
node.dataset.mediaSource = 'screen_share';
node.dataset.callVideoSurfaceRole = 'fullscreen';
const screenShareUserId = screenShareUserIdForOwner(42);

assert.equal(applyScreenSharePanSurface(node, target, { userId: screenShareUserId }), true, 'screen-share surfaces are accepted');
assert.equal(node.style.objectFit, 'contain', 'screen-share default fit is non-clipping contain');
assert.equal(node.dataset.callScreenShareFitMode, 'contain', 'screen-share starts in fit-to-screen mode');
assert.equal(node.dataset.callScreenShareZoomed, '0', 'screen-share starts unzoomed');

let wheelPrevented = false;
node.dispatch('wheel', {
  deltaY: -700,
  preventDefault: () => {
    wheelPrevented = true;
  },
});
assert.equal(wheelPrevented, true, 'fullscreen wheel zoom is opt-in and consumes the wheel event');
assert.equal(node.dataset.callScreenShareZoomed, '1', 'wheel zoom marks the screen-share surface as zoomed');
assert.ok(Number(node.dataset.callScreenShareZoomScale) > 1, 'wheel zoom increases the zoom scale');

let pointerPrevented = false;
node.dispatch('pointerdown', {
  button: 0,
  clientX: 0,
  clientY: 0,
  pointerId: 1,
  preventDefault: () => {
    pointerPrevented = true;
  },
});
node.dispatch('pointermove', {
  clientX: 9999,
  clientY: 9999,
  preventDefault: () => {},
});
assert.equal(pointerPrevented, true, 'dragging starts only after zoom creates pannable bounds');
assert.ok(Number.parseFloat(node.style.getPropertyValue('--call-screen-share-pan-x')) < 800, 'horizontal pan is clamped to rendered bounds');
assert.ok(Number.parseFloat(node.style.getPropertyValue('--call-screen-share-pan-y')) < 600, 'vertical pan is clamped to rendered bounds');

let doubleClickStopped = false;
node.dispatch('dblclick', {
  preventDefault: () => {},
  stopPropagation: () => {
    doubleClickStopped = true;
  },
});
assert.equal(doubleClickStopped, true, 'double-click reset stays inside the fullscreen screen-share surface');
assert.equal(node.dataset.callScreenShareFitMode, 'contain', 'double-click reset returns to non-clipping fit mode');
assert.equal(node.dataset.callScreenShareZoomed, '0', 'double-click reset clears zoomed state');
assert.equal(node.style.getPropertyValue('--call-screen-share-pan-x'), '0.00px', 'double-click reset clears horizontal pan');
assert.equal(node.style.getPropertyValue('--call-screen-share-pan-y'), '0.00px', 'double-click reset clears vertical pan');

node.dataset.callVideoSurfaceRole = 'mini';
node.dispatch('wheel', {
  deltaY: -700,
  preventDefault: () => {
    throw new Error('mini screen-share tile must not consume wheel zoom');
  },
});
assert.equal(node.dataset.callScreenShareZoomed, '0', 'mini screen-share tile remains unzoomed by wheel');

assert.equal(clearScreenSharePanSurface(node), true, 'screen-share zoom state can be cleared');
assert.equal(node.dataset.callScreenSharePanEnabled, undefined, 'clear removes pan state marker');
assert.equal(node.style.objectFit, '', 'clear restores object fit ownership to normal video layout');

console.log('[screen-share-pan-zoom-contract] PASS');
