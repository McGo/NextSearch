const ICONS: Record<string, string> = {
  pdf: 'i-lucide-file-text',
  doc: 'i-lucide-file-type', docx: 'i-lucide-file-type', odt: 'i-lucide-file-type', rtf: 'i-lucide-file-type',
  xls: 'i-lucide-table', xlsx: 'i-lucide-table', ods: 'i-lucide-table', csv: 'i-lucide-table',
  ppt: 'i-lucide-presentation', pptx: 'i-lucide-presentation', odp: 'i-lucide-presentation',
  eml: 'i-lucide-mail', msg: 'i-lucide-mail',
  md: 'i-lucide-file-code', txt: 'i-lucide-file-code', html: 'i-lucide-file-code', htm: 'i-lucide-file-code',
  png: 'i-lucide-image', jpg: 'i-lucide-image', jpeg: 'i-lucide-image',
  tif: 'i-lucide-image', tiff: 'i-lucide-image', gif: 'i-lucide-image', webp: 'i-lucide-image',
  epub: 'i-lucide-book-open'
}

export function useFormat() {
  const bytes = (value: number): string => {
    if (value < 1024) return `${value} B`
    const units = ['KB', 'MB', 'GB', 'TB']
    let size = value / 1024
    let unit = 0

    while (size >= 1024 && unit < units.length - 1) {
      size /= 1024
      unit++
    }

    return `${size.toFixed(size < 10 ? 1 : 0).replace('.', ',')} ${units[unit]}`
  }

  const date = (timestamp: number | string | null): string => {
    if (timestamp === null) return '—'
    const value = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp)
    return value.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' })
  }

  const dateTime = (timestamp: number | string | null): string => {
    if (timestamp === null) return '—'
    const value = typeof timestamp === 'number' ? new Date(timestamp * 1000) : new Date(timestamp)
    return value.toLocaleString('de-DE', {
      day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
    })
  }

  const icon = (extension: string | null): string =>
    (extension && ICONS[extension]) || 'i-lucide-file'

  const facetValue = (facet: string, value: string): string => {
    if (facet !== 'ocr_used') return value
    return value === 'true' ? 'per OCR erkannt' : 'aus Textlayer'
  }

  return { bytes, date, dateTime, icon, facetValue }
}
