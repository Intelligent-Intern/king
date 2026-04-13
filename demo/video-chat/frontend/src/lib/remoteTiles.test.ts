import { describe, expect, it } from 'vitest'
import { pruneRemoteTiles, upsertRemoteTile } from './remoteTiles'

describe('remoteTiles helpers', () => {
  it('upserts remote tiles by user id', () => {
    const first = upsertRemoteTile([], {
      userId: 'u-1',
      name: 'Ada',
      stream: 'stream-a',
    })

    const second = upsertRemoteTile(first, {
      userId: 'u-1',
      name: 'Ada Updated',
      stream: 'stream-b',
    })

    expect(second).toEqual([
      {
        userId: 'u-1',
        name: 'Ada Updated',
        stream: 'stream-b',
      },
    ])
  })

  it('ignores malformed user ids during upsert', () => {
    const result = upsertRemoteTile([], {
      userId: '   ',
      name: 'Nope',
      stream: 'stream',
    })

    expect(result).toEqual([])
  })

  it('prunes stale tiles and returns removed user ids', () => {
    const result = pruneRemoteTiles([
      { userId: 'u-1', name: 'Ada', stream: 's1' },
      { userId: 'u-2', name: 'Bea', stream: 's2' },
      { userId: 'u-3', name: 'Cam', stream: 's3' },
    ], ['u-1', 'u-3'])

    expect(result.tiles).toEqual([
      { userId: 'u-1', name: 'Ada', stream: 's1' },
      { userId: 'u-3', name: 'Cam', stream: 's3' },
    ])
    expect(result.removedUserIds).toEqual(['u-2'])
  })
})
