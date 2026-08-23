import { describe, expect, it } from 'vitest'
import { CHANGELOG } from '../app/data/changelog'

/**
 * The changelog is the one piece of text in the interface that is not in the
 * translation files, so nothing catches a missing language there. These checks
 * do — a release that only wrote the English half fails here rather than
 * showing English to a German reader.
 */
describe('changelog', () => {
  it('carries both languages on every entry', () => {
    for (const release of CHANGELOG) {
      for (const entry of release.entries) {
        expect(entry.de.trim(), `${release.version} (de)`).not.toBe('')
        expect(entry.en.trim(), `${release.version} (en)`).not.toBe('')
      }

      if (release.summary) {
        expect(release.summary.de.trim()).not.toBe('')
        expect(release.summary.en.trim()).not.toBe('')
      }
    }
  })

  it('lists every version once', () => {
    const versions = CHANGELOG.map(release => release.version)

    expect(new Set(versions).size).toBe(versions.length)
  })

  it('runs newest first', () => {
    const weight = (version: string) => version
      .split('.')
      .map(part => Number(part).toString().padStart(4, '0'))
      .join('')

    const sorted = [...CHANGELOG].sort((a, b) => weight(b.version).localeCompare(weight(a.version)))

    expect(CHANGELOG.map(r => r.version)).toEqual(sorted.map(r => r.version))
  })

  it('dates a release, or marks it as not out yet', () => {
    for (const release of CHANGELOG) {
      if (release.date !== null) {
        expect(release.date, release.version).toMatch(/^\d{4}-\d{2}-\d{2}$/)
      }
    }

    // At most one version may be waiting to be released — two would mean a
    // release was cut without the page being updated.
    expect(CHANGELOG.filter(release => release.date === null).length).toBeLessThanOrEqual(1)
  })
})
