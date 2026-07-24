interface IndexingStatus {
  running: boolean
  pending: number
  indexed: number
}

/**
 * Polls whether indexing is running. While it is, it refreshes every few
 * seconds and calls `onFinished` once it goes idle, so the search page can
 * pull in the documents that just finished.
 */
export function useIndexingStatus(onFinished?: () => void) {
  const api = useApi()
  const status = ref<IndexingStatus | null>(null)
  let timer: ReturnType<typeof setInterval> | undefined
  let wasRunning = false

  async function poll() {
    try {
      const next = await api.get<IndexingStatus>('/api/indexing-status')
      status.value = next

      if (wasRunning && !next.running) {
        onFinished?.()
      }
      wasRunning = next.running
    } catch {
      // A failed heartbeat shouldn't disturb the page.
    }
  }

  onMounted(() => {
    poll()
    timer = setInterval(poll, 4000)
  })
  onUnmounted(() => clearInterval(timer))

  return { status }
}
