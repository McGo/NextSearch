interface DirectoryInstance {
  uuid: string
  name: string
  image_url: string | null
}

interface DirectoryFolder {
  id: number
  uuid: string
  label: string
  instance_name: string
  image_url: string | null
}

interface Directory {
  instances: DirectoryInstance[]
  folders: DirectoryFolder[]
}

// Loaded once, kept for the session — hits and facets look up their images here
// without a request per entry.
const directory = ref<Directory | null>(null)
const loading = ref<Promise<void> | null>(null)

export function useDirectory() {
  const api = useApi()

  async function load(force = false): Promise<void> {
    if (directory.value && !force) return
    if (loading.value && !force) return loading.value

    loading.value = api.get<Directory>('/api/directory')
      .then((data) => {
        directory.value = data
      })
      .catch(() => {
        directory.value = { instances: [], folders: [] }
      })
      .finally(() => {
        loading.value = null
      })

    return loading.value
  }

  // Search hits carry the folder_id — the unique key for the image.
  const folderImageById = computed(() => {
    const map: Record<number, string> = {}
    for (const folder of directory.value?.folders ?? []) {
      if (folder.image_url) map[folder.id] = folder.image_url
    }
    return map
  })

  // Facets go by label. If a label occurs more than once, they share the image
  // — rare for separate folders of the same name, and tolerable.
  const folderImageByLabel = computed(() => {
    const map: Record<string, string> = {}
    for (const folder of directory.value?.folders ?? []) {
      if (folder.image_url) map[folder.label] = folder.image_url
    }
    return map
  })

  const instanceImageByName = computed(() => {
    const map: Record<string, string> = {}
    for (const instance of directory.value?.instances ?? []) {
      if (instance.image_url) map[instance.name] = instance.image_url
    }
    return map
  })

  return {
    directory: readonly(directory),
    load,
    folderImageById,
    folderImageByLabel,
    instanceImageByName
  }
}
