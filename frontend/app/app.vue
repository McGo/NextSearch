<script setup lang="ts">
const config = useRuntimeConfig()
const { user, isAdmin, logout } = useAuth()
const route = useRoute()

useHead({
  titleTemplate: title => (title ? `${title} · ${config.public.appName}` : config.public.appName),
  htmlAttrs: { lang: 'de' },
  meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1' }],
  link: [{ rel: 'icon', href: '/favicon.ico' }]
})

const showChrome = computed(() => route.path !== '/login')
const passwordModalOpen = ref(false)

const navigation = computed(() => [
  { label: 'Suche', to: '/', icon: 'i-lucide-search' },
  ...(isAdmin.value
    ? [
        { label: 'Instanzen', to: '/admin/instances', icon: 'i-lucide-cloud' },
        { label: 'Ordner', to: '/admin/folders', icon: 'i-lucide-folder-tree' },
        { label: 'Benutzer', to: '/admin/users', icon: 'i-lucide-users' },
        { label: 'Status', to: '/admin/status', icon: 'i-lucide-activity' }
      ]
    : [])
])
</script>

<template>
  <UApp>
    <UHeader v-if="showChrome">
      <template #left>
        <NuxtLink
          to="/"
          class="flex items-center gap-2 font-semibold"
        >
          <UIcon
            name="i-lucide-file-search"
            class="size-5 text-primary"
          />
          {{ config.public.appName }}
        </NuxtLink>
      </template>

      <UNavigationMenu :items="navigation" />

      <template #right>
        <UColorModeButton />

        <UDropdownMenu
          v-if="user"
          :items="[[
            { label: user.email, type: 'label' },
            { label: 'Passwort ändern', icon: 'i-lucide-key-round', onSelect: () => { passwordModalOpen = true } },
            { label: 'Abmelden', icon: 'i-lucide-log-out', onSelect: () => logout() }
          ]]"
        >
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-user"
            :label="user.name"
          />
        </UDropdownMenu>
      </template>

      <template #body>
        <UNavigationMenu
          :items="navigation"
          orientation="vertical"
        />
      </template>
    </UHeader>

    <UMain>
      <NuxtPage />
    </UMain>

    <PasswordChangeModal
      v-if="user"
      v-model:open="passwordModalOpen"
    />

    <UFooter v-if="showChrome">
      <template #left>
        <p class="text-xs text-muted">
          Freigaben werden in {{ config.public.appName }} gepflegt und gelten unabhängig
          von den Dateirechten der jeweiligen Nextcloud.
        </p>
      </template>
    </UFooter>
  </UApp>
</template>
