export interface RemoteTile<TStream> {
  userId: string
  name: string
  stream: TStream
}

export interface RemoteTilePruneResult<TStream> {
  tiles: RemoteTile<TStream>[]
  removedUserIds: string[]
}

export function upsertRemoteTile<TStream>(
  tiles: RemoteTile<TStream>[],
  nextTile: RemoteTile<TStream>
): RemoteTile<TStream>[] {
  const normalizedUserId = nextTile.userId.trim()
  if (normalizedUserId === '') {
    return tiles
  }

  const normalizedTile: RemoteTile<TStream> = {
    ...nextTile,
    userId: normalizedUserId,
  }

  const existingIndex = tiles.findIndex((entry) => entry.userId === normalizedUserId)
  if (existingIndex === -1) {
    return [...tiles, normalizedTile]
  }

  const updated = [...tiles]
  updated[existingIndex] = normalizedTile
  return updated
}

export function pruneRemoteTiles<TStream>(
  tiles: RemoteTile<TStream>[],
  activeUserIds: string[]
): RemoteTilePruneResult<TStream> {
  const activeSet = new Set(activeUserIds.map((userId) => userId.trim()).filter((userId) => userId !== ''))
  const keptTiles: RemoteTile<TStream>[] = []
  const removedUserIds: string[] = []

  for (const tile of tiles) {
    if (activeSet.has(tile.userId)) {
      keptTiles.push(tile)
      continue
    }

    removedUserIds.push(tile.userId)
  }

  return {
    tiles: keptTiles,
    removedUserIds,
  }
}
