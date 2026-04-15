function quantile(sorted, q) {
  if (!Array.isArray(sorted) || sorted.length === 0) return 0;
  const idx = Math.min(sorted.length - 1, Math.max(0, Math.ceil(sorted.length * q) - 1));
  return Number(sorted[idx] || 0);
}

function median(values) {
  if (!Array.isArray(values) || values.length === 0) return 0;
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  if (sorted.length % 2 === 1) return Number(sorted[mid] || 0);
  const a = Number(sorted[mid - 1] || 0);
  const b = Number(sorted[mid] || 0);
  return (a + b) / 2;
}

export class BackgroundFilterBaselineCollector {
  constructor(maxSamples = 10) {
    this.maxSamples = Math.max(3, Math.min(120, Math.round(Number(maxSamples) || 10)));
    this.samples = [];
  }

  reset() {
    this.samples = [];
  }

  sampleCount() {
    return this.samples.length;
  }

  push(sample) {
    this.samples.push(sample);
    if (this.samples.length < this.maxSamples) return null;
    return this.summarize();
  }

  summarize() {
    if (this.samples.length === 0) return null;
    const fpsList = this.samples.map((x) => Number(x?.fps || 0)).sort((a, b) => a - b);
    const detectMsList = this.samples.map((x) => Number(x?.avgDetectMs || 0)).sort((a, b) => a - b);
    const detectFpsList = this.samples.map((x) => Number(x?.detectFps || 0)).sort((a, b) => a - b);
    const processMsList = this.samples.map((x) => Number(x?.avgProcessMs || 0)).sort((a, b) => a - b);
    const processLoadList = this.samples.map((x) => Number(x?.processLoad || 0)).sort((a, b) => a - b);
    const last = this.samples[this.samples.length - 1] || {};

    return {
      sampleCount: this.samples.length,
      medianFps: median(fpsList),
      p95Fps: quantile(fpsList, 0.95),
      medianDetectMs: median(detectMsList),
      p95DetectMs: quantile(detectMsList, 0.95),
      medianDetectFps: median(detectFpsList),
      p95DetectFps: quantile(detectFpsList, 0.95),
      medianProcessMs: median(processMsList),
      p95ProcessMs: quantile(processMsList, 0.95),
      medianProcessLoad: median(processLoadList),
      p95ProcessLoad: quantile(processLoadList, 0.95),
      width: Number(last.width || 0),
      height: Number(last.height || 0),
      targetFps: Number(last.targetFps || 0),
      sourceWidth: Number(last.sourceWidth || 0),
      sourceHeight: Number(last.sourceHeight || 0),
      sourceFps: Number(last.sourceFps || 0),
    };
  }
}
