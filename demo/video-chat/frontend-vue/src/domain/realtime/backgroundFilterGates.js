export const BACKGROUND_FILTER_DEFAULT_GATES = {
  minMedianFps: 20,
  maxMedianDetectMs: 40,
  maxMedianProcessLoad: 0.65,
};

export function evaluateBackgroundFilterGates(input, gates = BACKGROUND_FILTER_DEFAULT_GATES) {
  const medianFps = Number(input?.medianFps || 0);
  const medianDetectMs = Number(input?.medianDetectMs || 0);
  const medianProcessLoad = Number(input?.medianProcessLoad || 0);
  const fpsPass = medianFps >= Number(gates.minMedianFps || BACKGROUND_FILTER_DEFAULT_GATES.minMedianFps);
  const detectPass = medianDetectMs <= Number(gates.maxMedianDetectMs || BACKGROUND_FILTER_DEFAULT_GATES.maxMedianDetectMs);
  const loadPass = medianProcessLoad <= Number(gates.maxMedianProcessLoad || BACKGROUND_FILTER_DEFAULT_GATES.maxMedianProcessLoad);

  return {
    pass: fpsPass && detectPass && loadPass,
    fpsPass,
    detectPass,
    loadPass,
  };
}
