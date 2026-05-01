import type { SfuTilePatchMetadata } from './tilePatchMetadata'

export interface SelectiveTilePatchPlan {
  patchImageData: ImageData
  tilePatch: SfuTilePatchMetadata
  changedTileCount: number
  totalTileCount: number
  patchAreaRatio: number
  selectedTileRatio: number
  matteGuided: boolean
}

export interface SelectiveTilePatchOptions {
  tileWidth: number
  tileHeight: number
  minChangedTileRatio?: number
  maxChangedTileRatio: number
  maxPatchAreaRatio: number
  sampleStride: number
  diffThreshold: number
  cacheEpoch: number
  matteMaskImageData?: ImageData | null
  matteMaskThreshold?: number
}

export function cloneImageData(source: ImageData): ImageData {
  return new ImageData(new Uint8ClampedArray(source.data), source.width, source.height)
}

export function planSelectiveTilePatch(
  currentImageData: ImageData,
  previousImageData: ImageData | null,
  options: SelectiveTilePatchOptions,
): SelectiveTilePatchPlan | null {
  return planTilePatchForLayout(currentImageData, previousImageData, options, {
    layoutMode: 'tile_foreground',
    layerId: 'foreground',
  })
}

export function planBackgroundSnapshotPatch(
  currentImageData: ImageData,
  previousImageData: ImageData | null,
  options: SelectiveTilePatchOptions,
): SelectiveTilePatchPlan | null {
  return planTilePatchForLayout(currentImageData, previousImageData, options, {
    layoutMode: 'background_snapshot',
    layerId: 'background',
  })
}

function planTilePatchForLayout(
  currentImageData: ImageData,
  previousImageData: ImageData | null,
  options: SelectiveTilePatchOptions,
  layout: { layoutMode: 'tile_foreground' | 'background_snapshot'; layerId: 'foreground' | 'background' },
): SelectiveTilePatchPlan | null {
  if (!(previousImageData instanceof ImageData)) return null
  if (
    previousImageData.width !== currentImageData.width
    || previousImageData.height !== currentImageData.height
  ) {
    return null
  }

  const width = currentImageData.width
  const height = currentImageData.height
  const tileWidth = Math.max(16, Math.floor(options.tileWidth || 96))
  const tileHeight = Math.max(16, Math.floor(options.tileHeight || 96))
  const tileColumns = Math.max(1, Math.ceil(width / tileWidth))
  const tileRows = Math.max(1, Math.ceil(height / tileHeight))
  const changedTileIndices: number[] = []
  const matteMaskImageData = options.matteMaskImageData instanceof ImageData ? options.matteMaskImageData : null
  let minTileColumn = tileColumns
  let minTileRow = tileRows
  let maxTileColumn = -1
  let maxTileRow = -1

  for (let tileRow = 0; tileRow < tileRows; tileRow += 1) {
    for (let tileColumn = 0; tileColumn < tileColumns; tileColumn += 1) {
      if (!tileMatchesLayoutMask(matteMaskImageData, tileColumn, tileRow, tileWidth, tileHeight, options, layout.layoutMode)) {
        continue
      }
      if (!tileChanged(currentImageData, previousImageData, tileColumn, tileRow, tileWidth, tileHeight, options)) {
        continue
      }
      const tileIndex = (tileRow * tileColumns) + tileColumn
      changedTileIndices.push(tileIndex)
      minTileColumn = Math.min(minTileColumn, tileColumn)
      minTileRow = Math.min(minTileRow, tileRow)
      maxTileColumn = Math.max(maxTileColumn, tileColumn)
      maxTileRow = Math.max(maxTileRow, tileRow)
    }
  }

  const totalTileCount = tileColumns * tileRows
  if (changedTileIndices.length < 1) return null
  const changedTileRatio = changedTileIndices.length / totalTileCount
  if (changedTileRatio < Math.max(0, Number(options.minChangedTileRatio || 0))) {
    return null
  }
  if (changedTileRatio > Math.max(0.05, options.maxChangedTileRatio || 0.35)) {
    return null
  }

  const roiX = minTileColumn * tileWidth
  const roiY = minTileRow * tileHeight
  const roiWidth = Math.min(width - roiX, (maxTileColumn - minTileColumn + 1) * tileWidth)
  const roiHeight = Math.min(height - roiY, (maxTileRow - minTileRow + 1) * tileHeight)
  if (roiWidth < 1 || roiHeight < 1) return null

  const patchAreaRatio = (roiWidth * roiHeight) / Math.max(1, width * height)
  if (patchAreaRatio > Math.max(0.1, options.maxPatchAreaRatio || 0.55)) {
    return null
  }

  return {
    patchImageData: cropImageData(currentImageData, roiX, roiY, roiWidth, roiHeight),
    tilePatch: {
      layoutMode: layout.layoutMode,
      layerId: layout.layerId,
      cacheEpoch: Math.max(0, Math.floor(options.cacheEpoch || 0)),
      tileColumns,
      tileRows,
      tileWidth,
      tileHeight,
      tileIndices: changedTileIndices,
      roiNormX: roiX / width,
      roiNormY: roiY / height,
      roiNormWidth: roiWidth / width,
      roiNormHeight: roiHeight / height,
    },
    changedTileCount: changedTileIndices.length,
    totalTileCount,
    patchAreaRatio,
    selectedTileRatio: changedTileRatio,
    matteGuided: matteMaskImageData instanceof ImageData,
  }
}

function tileMatchesLayoutMask(
  matteMaskImageData: ImageData | null,
  tileColumn: number,
  tileRow: number,
  tileWidth: number,
  tileHeight: number,
  options: SelectiveTilePatchOptions,
  layoutMode: 'tile_foreground' | 'background_snapshot',
): boolean {
  if (!(matteMaskImageData instanceof ImageData)) return true
  const width = matteMaskImageData.width
  const height = matteMaskImageData.height
  if (width < 1 || height < 1) return true
  const xStart = tileColumn * tileWidth
  const yStart = tileRow * tileHeight
  if (xStart >= width || yStart >= height) return false
  const xEnd = Math.min(width, xStart + tileWidth)
  const yEnd = Math.min(height, yStart + tileHeight)
  const sampleStride = Math.max(1, Math.floor(options.sampleStride || 8))
  const threshold = Math.max(1, Math.min(255, Math.floor(options.matteMaskThreshold || 96)))
  const data = matteMaskImageData.data
  let fgSamples = 0
  let totalSamples = 0

  for (let y = yStart; y < yEnd; y += sampleStride) {
    for (let x = xStart; x < xEnd; x += sampleStride) {
      const offset = ((y * width) + x) * 4
      const alpha = data[offset + 3] ?? data[offset] ?? 0
      if (alpha >= threshold) fgSamples += 1
      totalSamples += 1
    }
  }

  if (totalSamples < 1) return true
  const foregroundRatio = fgSamples / totalSamples
  if (layoutMode === 'tile_foreground') {
    return foregroundRatio >= 0.12
  }
  return foregroundRatio <= 0.45
}

function tileChanged(
  currentImageData: ImageData,
  previousImageData: ImageData,
  tileColumn: number,
  tileRow: number,
  tileWidth: number,
  tileHeight: number,
  options: SelectiveTilePatchOptions,
): boolean {
  const width = currentImageData.width
  const height = currentImageData.height
  const xStart = tileColumn * tileWidth
  const yStart = tileRow * tileHeight
  const xEnd = Math.min(width, xStart + tileWidth)
  const yEnd = Math.min(height, yStart + tileHeight)
  const sampleStride = Math.max(1, Math.floor(options.sampleStride || 8))
  const diffThreshold = Math.max(1, Math.floor(options.diffThreshold || 26))
  const current = currentImageData.data
  const previous = previousImageData.data

  for (let y = yStart; y < yEnd; y += sampleStride) {
    for (let x = xStart; x < xEnd; x += sampleStride) {
      const offset = ((y * width) + x) * 4
      const diff = Math.abs((current[offset] || 0) - (previous[offset] || 0))
        + Math.abs((current[offset + 1] || 0) - (previous[offset + 1] || 0))
        + Math.abs((current[offset + 2] || 0) - (previous[offset + 2] || 0))
      if (diff >= diffThreshold) {
        return true
      }
    }
  }

  return false
}

function cropImageData(source: ImageData, x: number, y: number, width: number, height: number): ImageData {
  const out = new Uint8ClampedArray(width * height * 4)
  const sourceWidth = source.width
  const sourceData = source.data

  for (let row = 0; row < height; row += 1) {
    const sourceOffset = ((y + row) * sourceWidth + x) * 4
    const targetOffset = row * width * 4
    out.set(sourceData.subarray(sourceOffset, sourceOffset + (width * 4)), targetOffset)
  }

  return new ImageData(out, width, height)
}
