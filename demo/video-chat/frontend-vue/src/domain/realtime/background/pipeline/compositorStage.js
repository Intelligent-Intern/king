import { createCanvasBackgroundCompositorStage } from './compositorCanvasStage';
import { createWebGlBackgroundCompositorStage } from './compositorWebglStage';

export function createBackgroundCompositorStage(options = {}) {
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
