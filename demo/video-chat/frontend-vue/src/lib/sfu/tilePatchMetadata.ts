export type SfuLayoutMode = 'full_frame' | 'tile_foreground' | 'background_snapshot'
export type SfuLayerId = 'full' | 'foreground' | 'background'

export interface SfuTilePatchMetadata {
  layoutMode: SfuLayoutMode
  layerId: SfuLayerId
  cacheEpoch: number
  tileColumns: number
  tileRows: number
  tileWidth: number
  tileHeight: number
  tileIndices: number[]
  roiNormX: number
  roiNormY: number
  roiNormWidth: number
  roiNormHeight: number
}

export function normalizeTilePatchMetadata(value: unknown): SfuTilePatchMetadata | null {
  if (!value || typeof value !== 'object') return null
  const source = value as Record<string, unknown>
  const layoutMode = normalizeLayoutMode(source.layoutMode ?? source.layout_mode)
  const layerId = normalizeLayerId(source.layerId ?? source.layer_id)
  const cacheEpoch = normalizeInteger(source.cacheEpoch ?? source.cache_epoch, 0)
  const tileColumns = normalizeInteger(source.tileColumns ?? source.tile_columns, 0)
  const tileRows = normalizeInteger(source.tileRows ?? source.tile_rows, 0)
  const tileWidth = normalizeInteger(source.tileWidth ?? source.tile_width, 0)
  const tileHeight = normalizeInteger(source.tileHeight ?? source.tile_height, 0)
  const tileIndices = normalizeTileIndices(source.tileIndices ?? source.tile_indices)
  const roiNormX = normalizeUnitInterval(source.roiNormX ?? source.roi_norm_x, 0)
  const roiNormY = normalizeUnitInterval(source.roiNormY ?? source.roi_norm_y, 0)
  const roiNormWidth = normalizeUnitInterval(source.roiNormWidth ?? source.roi_norm_width, 1)
  const roiNormHeight = normalizeUnitInterval(source.roiNormHeight ?? source.roi_norm_height, 1)

  if (layoutMode === 'full_frame') {
    return {
      layoutMode,
      layerId: 'full',
      cacheEpoch,
      tileColumns: 0,
      tileRows: 0,
      tileWidth: 0,
      tileHeight: 0,
      tileIndices: [],
      roiNormX: 0,
      roiNormY: 0,
      roiNormWidth: 1,
      roiNormHeight: 1,
    }
  }

  if (
    tileColumns < 1
    || tileRows < 1
    || tileWidth < 1
    || tileHeight < 1
    || tileIndices.length < 1
  ) {
    return null
  }

  return {
    layoutMode,
    layerId,
    cacheEpoch,
    tileColumns,
    tileRows,
    tileWidth,
    tileHeight,
    tileIndices,
    roiNormX,
    roiNormY,
    roiNormWidth,
    roiNormHeight,
  }
}

export function flattenTilePatchMetadata(metadata: SfuTilePatchMetadata | null): Record<string, unknown> {
  if (!metadata) return {}
  return {
    layout_mode: metadata.layoutMode,
    layer_id: metadata.layerId,
    cache_epoch: metadata.cacheEpoch,
    tile_columns: metadata.tileColumns,
    tile_rows: metadata.tileRows,
    tile_width: metadata.tileWidth,
    tile_height: metadata.tileHeight,
    tile_indices: metadata.tileIndices,
    roi_norm_x: metadata.roiNormX,
    roi_norm_y: metadata.roiNormY,
    roi_norm_width: metadata.roiNormWidth,
    roi_norm_height: metadata.roiNormHeight,
  }
}

export function serializeTilePatchMetadata(metadata: SfuTilePatchMetadata | null): string {
  const normalized = normalizeTilePatchMetadata(metadata)
  if (!normalized) return ''
  return JSON.stringify(flattenTilePatchMetadata(normalized))
}

export function parseTilePatchMetadataJson(value: string): SfuTilePatchMetadata | null {
  const normalized = String(value || '').trim()
  if (normalized === '') return null
  try {
    return normalizeTilePatchMetadata(JSON.parse(normalized))
  } catch {
    return null
  }
}

function normalizeLayoutMode(value: unknown): SfuLayoutMode {
  const normalized = String(value || '').trim()
  if (normalized === 'tile_foreground' || normalized === 'background_snapshot') {
    return normalized
  }
  return 'full_frame'
}

function normalizeLayerId(value: unknown): SfuLayerId {
  const normalized = String(value || '').trim()
  if (normalized === 'foreground' || normalized === 'background') {
    return normalized
  }
  return 'full'
}

function normalizeInteger(value: unknown, fallback: number): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized) || normalized < 0) return fallback
  return Math.floor(normalized)
}

function normalizeUnitInterval(value: unknown, fallback: number): number {
  const normalized = Number(value)
  if (!Number.isFinite(normalized)) return fallback
  return Math.max(0, Math.min(1, normalized))
}

function normalizeTileIndices(value: unknown): number[] {
  if (!Array.isArray(value)) return []
  const next = []
  for (const entry of value) {
    const normalized = Number(entry)
    if (!Number.isFinite(normalized) || normalized < 0) continue
    next.push(Math.floor(normalized))
  }
  return next
}
