/**
 * Worker-based MediaPipe segmentation backend.
 *
 * Wraps imageSegmenterWorker.js and exposes the same interface as
 * backendMediapipe.js / backendTfjs.js:
 *   { kind, nextFaces(video, vw, vh, nowMs), dispose() }
 *
 * Key differences from the old backends:
 *  - Segmentation runs in a dedicated worker thread (no main-thread blocking)
 *  - Uses selfie_multiclass_256x256 + CATEGORY_MASK → full person silhouette
 *  - Returns faces=[] (no face detection; compositor uses the mask directly)
 *  - matteMaskBitmap is a MediaPipe-drawn alpha mask updated on every new inference result
 *
 * The returned sourceFrame is matched to the mask result so the compositor can
 * compose pixels from the same moment in time.
 */

const VIDEOCHAT_CDN_ORIGIN = String(import.meta.env.VITE_VIDEOCHAT_CDN_ORIGIN || '').replace(/\/+$/, '');
const MEDIAPIPE_MODEL_BASE_PATH = '/cdn/vendor/mediapipe/models/';
const MEDIAPIPE_WASM_BASE_PATH = '/wasm/';
const MODEL_PATH = `${VIDEOCHAT_CDN_ORIGIN}${MEDIAPIPE_MODEL_BASE_PATH}selfie_multiclass_256x256.tflite`;
const WASM_PATH = `${VIDEOCHAT_CDN_ORIGIN}${MEDIAPIPE_WASM_BASE_PATH}`;
const INIT_TIMEOUT_MS = 15000;

export async function createWorkerSegmenterBackend(opts = {}) {
    const modelAssetPath = opts.modelAssetPath || MODEL_PATH;
    const delegate = opts.delegate || 'GPU';
    //const detectIntervalMs = Math.max(1, Math.min(1, Math.round(Number(opts.detectIntervalMs || 100))));
    const wasmPath = opts.wasmPath || WASM_PATH;
    const workerUrl = new URL('./workers/imageSegmenterWorker.js', import.meta.url);
    const worker = new Worker(workerUrl, {
        type: 'module'
    });

    // Wait for READY before sending INIT
    await new Promise((resolve, reject) => {
        const timeout = setTimeout(() => reject(new Error('Worker READY timeout')), 5000);
        worker.onmessage = (e) => {
            if (e.data?.type === 'READY') {
                console.log('Worker is ready');
                clearTimeout(timeout);
                resolve();
            }
        };
        worker.onerror = (e) => {
            console.error('Worker error before READY:', e);
            clearTimeout(timeout);
            reject(new Error(`Worker error: ${e.message}`));
        };
    });
    console.log("worker", worker);
    console.log('Created worker segmenter backend with config', {
        modelAssetPath,
        delegate,
        //detectIntervalMs,
        wasmPath,
    });

    // Wait for INIT_DONE (or INIT_ERROR / timeout).
    const ready = await new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            reject(new Error('WorkerSegmenter: init timeout'));
        }, INIT_TIMEOUT_MS);

        worker.onmessage = (event) => {
            const { type } = event.data;
            if (type === 'INIT_DONE') {
                clearTimeout(timer);
                resolve(event.data.labels || []);
                console.log('WorkerSegmenter initialized with labels:', event.data.labels);
            } else if (type === 'INIT_ERROR') {
                clearTimeout(timer);
                reject(new Error(`WorkerSegmenter: ${event.data.error}`));
            }
        };

        worker.onerror = (err) => {
            clearTimeout(timer);
            reject(new Error(`WorkerSegmenter worker error: ${err.message}`));
        };

        worker.postMessage({ type: 'INIT', modelAssetPath, delegate, wasmPath });
    });

    const pendingFrameCanvas = document.createElement('canvas');
    pendingFrameCanvas.width = 1;
    pendingFrameCanvas.height = 1;
    const pendingFrameCtx = pendingFrameCanvas.getContext('2d', { alpha: false });
    const resultFrameCanvas = document.createElement('canvas');
    resultFrameCanvas.width = 1;
    resultFrameCanvas.height = 1;
    const resultFrameCtx = resultFrameCanvas.getContext('2d', { alpha: false });

    let labels = Array.isArray(ready) ? ready : [];
    let pendingFrame = false;
    let lastDetectAt = -Infinity;
    let pendingSampleMs = null;
    let pendingMaskBitmap = null;
    let pendingMaskValues = null;
    let pendingMaskWidth = 0;
    let pendingMaskHeight = 0;
    let pendingResultFrame = false;
    let disposed = false;
    let hasQueuedFrame = false;
    let queuedFrameParams = null;

    function queueLatestFrame(params) {
        hasQueuedFrame = true;
        queuedFrameParams = params;
    }

    function dispatchFrame(params) {
        if (!params || disposed) return;
        const { video, sourceWidth, sourceHeight, targetWidth, targetHeight, timestampMs, nowMs } = params;
        pendingFrame = true;
        lastDetectAt = nowMs;
/*         console.log('[dispatchFrame] Dispatching frame to worker with timestampMs', timestampMs, 'and source/target sizes', sourceWidth, sourceHeight, targetWidth, targetHeight);
        console.log('[dispatchFrame] params', params); */

//[dispatchFrame] params {video: video, sourceWidth: 640, sourceHeight: 480, targetWidth: 640, targetHeight: 480, …}
        if (!pendingFrameCtx) {
            pendingFrame = false;
            return;
        }
        if (pendingFrameCanvas.width !== targetWidth || pendingFrameCanvas.height !== targetHeight) {
            pendingFrameCanvas.width = targetWidth;
            pendingFrameCanvas.height = targetHeight;
        }
        pendingFrameCtx.drawImage(video, 0, 0, sourceWidth, sourceHeight, 0, 0, targetWidth, targetHeight);

        createImageBitmap(pendingFrameCanvas).then((bitmap) => {
            if (disposed) {
                bitmap.close();
                return;
            }
            worker.postMessage(
                { type: 'SEGMENT_VIDEO', bitmap, timestampMs },
                [bitmap],
            );
        }).catch(() => {
            pendingFrame = false;
            if (hasQueuedFrame && queuedFrameParams) {
                const nextParams = queuedFrameParams;
                hasQueuedFrame = false;
                queuedFrameParams = null;
                dispatchFrame(nextParams);
            }
        });
    }

    worker.onmessage = (event) => {
        const { type } = event.data;

        if (type === 'SEGMENT_RESULT') {
            pendingFrame = false;
            const { maskBitmap, maskValues, width, height, inferenceMs, inferenceTime } = event.data;
            const sample = typeof inferenceMs === 'number'
                ? inferenceMs
                : typeof inferenceTime === 'number'
                    ? inferenceTime
                    : null;
            pendingSampleMs = typeof sample === 'number' ? Math.max(0, sample) : null;
            if (maskBitmap instanceof ImageBitmap && width > 0 && height > 0) {
                pendingMaskBitmap?.close?.();
                pendingMaskBitmap = maskBitmap;
                pendingMaskValues = null;
                pendingMaskWidth = Math.max(1, Math.round(Number(width) || 1));
                pendingMaskHeight = Math.max(1, Math.round(Number(height) || 1));
            } else if (maskValues instanceof Float32Array && width > 0 && height > 0) {
                pendingMaskBitmap?.close?.();
                pendingMaskBitmap = null;
                pendingMaskValues = maskValues;
                pendingMaskWidth = Math.max(1, Math.round(Number(width) || 1));
                pendingMaskHeight = Math.max(1, Math.round(Number(height) || 1));
            }
            if (pendingSampleMs !== null && resultFrameCtx) {
                if (resultFrameCanvas.width !== pendingFrameCanvas.width || resultFrameCanvas.height !== pendingFrameCanvas.height) {
                    resultFrameCanvas.width = pendingFrameCanvas.width;
                    resultFrameCanvas.height = pendingFrameCanvas.height;
                }
                resultFrameCtx.drawImage(pendingFrameCanvas, 0, 0, resultFrameCanvas.width, resultFrameCanvas.height);
                pendingResultFrame = true;
            }
            if (hasQueuedFrame && queuedFrameParams) {
                const nextParams = queuedFrameParams;
                hasQueuedFrame = false;
                queuedFrameParams = null;
                dispatchFrame(nextParams);
            }
        } else if (type === 'SEGMENT_ERROR') {
            pendingFrame = false;
            if (hasQueuedFrame && queuedFrameParams) {
                const nextParams = queuedFrameParams;
                hasQueuedFrame = false;
                queuedFrameParams = null;
                dispatchFrame(nextParams);
            }
        }
        // SEGMENT_ERROR is logged but we just continue - next frame will retry.
    };

    return {
        kind: 'worker-segmenter',
        labels,

        nextFaces(video, vw, vh, nowMs) {
            if (disposed) return { faces: [], detectSampleMs: null, matteMaskBitmap: null, matteMaskValues: null };

            const sourceWidth = Math.max(1, Math.round(Number(video?.videoWidth) || Number(vw) || 1));
            const sourceHeight = Math.max(1, Math.round(Number(video?.videoHeight) || Number(vh) || 1));
            const targetWidth = Math.max(1, Math.round(Number(vw) || sourceWidth));
            const targetHeight = Math.max(1, Math.round(Number(vh) || sourceHeight));

            const hasFrame = video instanceof HTMLVideoElement
                && video.readyState >= 2
                && !video.ended
                && sourceWidth > 1
                && sourceHeight > 1;

            if (!hasFrame) {
                return { faces: [], detectSampleMs: null, matteMaskBitmap: null, matteMaskValues: null };
            }

            // Dispatch a new frame if the interval has elapsed and the worker is free.
            //if (!pendingFrame && nowMs - lastDetectAt >= detectIntervalMs) {
            const timestampMs = Number.isFinite(video.currentTime)
                ? Math.max(0, Math.round(video.currentTime * 1000))
                : Math.max(0, Math.round(nowMs));
            const frameParams = {
                video,
                sourceWidth,
                sourceHeight,
                targetWidth,
                targetHeight,
                timestampMs,
                nowMs,
            };

            if (!pendingFrame) {
                dispatchFrame(frameParams);
            } else {
                queueLatestFrame(frameParams);
            }

            const sample = pendingSampleMs;
            pendingSampleMs = null;
            const maskBitmap = pendingMaskBitmap;
            const maskValues = pendingMaskValues;
            const maskWidth = pendingMaskWidth;
            const maskHeight = pendingMaskHeight;
            pendingMaskBitmap = null;
            pendingMaskValues = null;
            pendingMaskWidth = 0;
            pendingMaskHeight = 0;
            const sourceFrame = pendingResultFrame ? resultFrameCanvas : null;
            pendingResultFrame = false;

            return {
                faces: [],
                detectSampleMs: sample,
                matteMaskBitmap: maskBitmap,
                matteMaskValues: maskValues,
                matteMaskWidth: maskWidth,
                matteMaskHeight: maskHeight,
                sourceFrame,
            };
        },

        dispose() {
            if (disposed) return;
            disposed = true;
            try {
                worker.postMessage({ type: 'CLEANUP' });
            } catch { /* ignore */ }
            // Terminate after a short grace period for the cleanup message.
            setTimeout(() => {
                try { worker.terminate(); } catch { /* ignore */ }
            }, 200);
            pendingFrameCanvas.width = 1;
            pendingFrameCanvas.height = 1;
            resultFrameCanvas.width = 1;
            resultFrameCanvas.height = 1;
            pendingFrame = false;
            pendingSampleMs = null;
            pendingMaskBitmap?.close?.();
            pendingMaskBitmap = null;
            pendingMaskValues = null;
            pendingMaskWidth = 0;
            pendingMaskHeight = 0;
            pendingResultFrame = false;
            hasQueuedFrame = false;
            queuedFrameParams = null;
        },
    };
}
