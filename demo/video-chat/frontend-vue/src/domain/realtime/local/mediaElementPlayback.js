export async function playMediaElementBestEffort(mediaElement, { timeoutMs = 1500 } = {}) {
  if (!mediaElement || typeof mediaElement.play !== 'function') return false;
  let timeoutId = null;
  try {
    const playResult = mediaElement.play();
    if (!playResult || typeof playResult.then !== 'function') return true;
    return await Promise.race([
      playResult.then(() => true, () => false),
      new Promise((resolve) => {
        timeoutId = setTimeout(() => resolve(false), Math.max(1, Number(timeoutMs || 1500)));
      }),
    ]);
  } catch {
    return false;
  } finally {
    if (timeoutId !== null) clearTimeout(timeoutId);
  }
}
