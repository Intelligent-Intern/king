import { test, expect } from '@playwright/test';

test('screen-share mini tile opens fullscreen and fullscreen zoom stays bounded', async ({ page }) => {
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const result = await page.evaluate(async () => {
    const { screenShareUserIdForOwner } = await import('/src/domain/realtime/screenShareIdentity.js');
    const { createVideoFullscreenToggle } = await import('/src/domain/realtime/workspace/callWorkspace/videoFullscreenToggle.ts');
    const { applyScreenSharePanSurface } = await import('/src/domain/realtime/workspace/callWorkspace/screenSharePan.js');

    const ownerUserId = 42;
    const screenShareUserId = screenShareUserIdForOwner(ownerUserId);
    const fullscreenVideoUserId = { value: 0 };
    const callLayoutState = { mode: 'main_mini' };
    let renderCount = 0;
    const { closeVideoFullscreen, toggleVideoFullscreen } = createVideoFullscreenToggle({
      callLayoutState,
      fullscreenVideoUserId,
      nextTick: (callback) => callback?.(),
      renderCallVideoLayout: () => {
        renderCount += 1;
      },
    });

    document.body.innerHTML = `
      <main id="stage">
        <article id="mini-tile" class="workspace-mini-tile">
          <div id="mini-slot" class="workspace-mini-video-slot" data-user-id="${screenShareUserId}"></div>
        </article>
        <section id="fullscreen-overlay" class="workspace-video-fullscreen-overlay">
          <div id="workspace-fullscreen-video-slot" class="workspace-fullscreen-video-slot" data-user-id="${screenShareUserId}"></div>
        </section>
      </main>
    `;

    const miniTile = document.getElementById('mini-tile');
    const fullscreenOverlay = document.getElementById('fullscreen-overlay');
    const fullscreenSlot = document.getElementById('workspace-fullscreen-video-slot');
    const screenCanvas = document.createElement('canvas');
    screenCanvas.width = 1600;
    screenCanvas.height = 900;
    screenCanvas.dataset.mediaSource = 'screen_share';
    screenCanvas.dataset.userId = String(screenShareUserId);
    screenCanvas.dataset.callVideoSurfaceUserId = String(screenShareUserId);
    screenCanvas.dataset.callVideoSurfaceRole = 'fullscreen';
    fullscreenSlot.append(screenCanvas);

    miniTile.addEventListener('dblclick', (event) => {
      event.stopPropagation();
      toggleVideoFullscreen(screenShareUserId);
    });
    fullscreenOverlay.addEventListener('click', () => {
      closeVideoFullscreen();
    });
    fullscreenSlot.addEventListener('click', (event) => {
      event.stopPropagation();
    });

    miniTile.dispatchEvent(new MouseEvent('dblclick', {
      bubbles: true,
      cancelable: true,
    }));
    const openedFullscreenUserId = fullscreenVideoUserId.value;
    const fullscreenLayoutMode = callLayoutState.mode;

    Object.defineProperty(fullscreenSlot, 'clientWidth', { configurable: true, value: 800 });
    Object.defineProperty(fullscreenSlot, 'clientHeight', { configurable: true, value: 600 });
    const panApplied = applyScreenSharePanSurface(screenCanvas, fullscreenSlot, { userId: screenShareUserId });
    const defaultFit = screenCanvas.style.objectFit;
    const defaultFitMode = screenCanvas.dataset.callScreenShareFitMode;

    const fullscreenWheelEvent = new WheelEvent('wheel', {
      bubbles: true,
      cancelable: true,
      deltaY: -1200,
    });
    screenCanvas.dispatchEvent(fullscreenWheelEvent);
    const fullscreenWheelPrevented = fullscreenWheelEvent.defaultPrevented;
    const zoomScale = Number(screenCanvas.dataset.callScreenShareZoomScale || 0);

    screenCanvas.dispatchEvent(new PointerEvent('pointerdown', {
      bubbles: true,
      cancelable: true,
      button: 0,
      clientX: 0,
      clientY: 0,
      pointerId: 1,
    }));
    screenCanvas.dispatchEvent(new PointerEvent('pointermove', {
      bubbles: true,
      cancelable: true,
      clientX: 10_000,
      clientY: 10_000,
      pointerId: 1,
    }));
    screenCanvas.dispatchEvent(new PointerEvent('pointerup', {
      bubbles: true,
      cancelable: true,
      pointerId: 1,
    }));

    const panX = Number.parseFloat(screenCanvas.style.getPropertyValue('--call-screen-share-pan-x') || '0');
    const panY = Number.parseFloat(screenCanvas.style.getPropertyValue('--call-screen-share-pan-y') || '0');

    const resetEvent = new MouseEvent('dblclick', {
      bubbles: true,
      cancelable: true,
    });
    screenCanvas.dispatchEvent(resetEvent);
    const resetStoppedBeforeMini = fullscreenVideoUserId.value;
    const resetFitMode = screenCanvas.dataset.callScreenShareFitMode;
    const resetZoomed = screenCanvas.dataset.callScreenShareZoomed;

    screenCanvas.dataset.callVideoSurfaceRole = 'mini';
    applyScreenSharePanSurface(screenCanvas, fullscreenSlot, { userId: screenShareUserId });
    const miniWheelScaleBefore = screenCanvas.dataset.callScreenShareZoomScale;
    const miniWheelEvent = new WheelEvent('wheel', {
      bubbles: true,
      cancelable: true,
      deltaY: -1200,
    });
    screenCanvas.dispatchEvent(miniWheelEvent);

    screenCanvas.dispatchEvent(new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
    }));
    const afterSlotClickFullscreenUserId = fullscreenVideoUserId.value;

    fullscreenOverlay.dispatchEvent(new MouseEvent('click', {
      bubbles: true,
      cancelable: true,
    }));

    return {
      openedFullscreenUserId,
      expectedScreenShareUserId: screenShareUserId,
      fullscreenLayoutMode,
      renderCount,
      panApplied,
      defaultFit,
      defaultFitMode,
      fullscreenWheelPrevented,
      zoomScale,
      panX,
      panY,
      resetStoppedBeforeMini,
      resetFitMode,
      resetZoomed,
      miniWheelPrevented: miniWheelEvent.defaultPrevented,
      miniWheelScaleBefore,
      miniWheelScaleAfter: screenCanvas.dataset.callScreenShareZoomScale,
      afterSlotClickFullscreenUserId,
      afterOverlayClickFullscreenUserId: fullscreenVideoUserId.value,
      finalLayoutMode: callLayoutState.mode,
    };
  });

  expect(result.openedFullscreenUserId).toBe(result.expectedScreenShareUserId);
  expect(result.fullscreenLayoutMode).toBe('main_only');
  expect(result.renderCount).toBeGreaterThanOrEqual(1);
  expect(result.panApplied).toBe(true);
  expect(result.defaultFit).toBe('contain');
  expect(result.defaultFitMode).toBe('contain');
  expect(result.fullscreenWheelPrevented).toBe(true);
  expect(result.zoomScale).toBeGreaterThan(1);
  expect(Math.abs(result.panX)).toBeLessThanOrEqual(2400);
  expect(Math.abs(result.panY)).toBeLessThanOrEqual(1500);
  expect(result.resetStoppedBeforeMini).toBe(result.expectedScreenShareUserId);
  expect(result.resetFitMode).toBe('contain');
  expect(result.resetZoomed).toBe('0');
  expect(result.miniWheelPrevented).toBe(false);
  expect(result.miniWheelScaleAfter).toBe(result.miniWheelScaleBefore);
  expect(result.afterSlotClickFullscreenUserId).toBe(result.expectedScreenShareUserId);
  expect(result.afterOverlayClickFullscreenUserId).toBe(0);
  expect(result.finalLayoutMode).toBe('main_mini');
});
