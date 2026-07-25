<script setup lang="ts">
const config = useRuntimeConfig()
const { user, isAdmin, logout } = useAuth()
const route = useRoute()
const { t, locale } = useI18n()
const { state: branding, load: loadBranding } = useBranding()

onMounted(loadBranding)

// The site name comes from branding (admin-editable), falling back to the
// configured default.
const siteName = computed(() => branding.value.site_name || config.public.appName)

useHead({
  titleTemplate: title => (title ? `${title} · ${siteName.value}` : siteName.value),
  htmlAttrs: { lang: () => locale.value },
  meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1' }],
  // Favicon follows the uploaded logo (with cache-busting), else the default.
  link: [{
    rel: 'icon',
    href: () => branding.value.has_logo
      ? `/api/branding/icon/favicon?v=${branding.value.logo_url?.split('v=')[1] ?? ''}`
      : '/favicon.ico'
  }]
})

const showChrome = computed(() => route.path !== '/login')

// The header/bottom navigation — the same two entries for everyone. Everything
// admin lives in the user menu instead.
const navigation = computed(() => [
  { label: t('nav.search'), to: '/', icon: 'i-lucide-search' },
  { label: t('nav.savedSearches'), to: '/saved-searches', icon: 'i-lucide-bookmark' }
])

function isActive(to: string): boolean {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

const userMenuItems = computed(() => [
  [{ label: user.value?.email ?? '', type: 'label' as const }],
  [
    { label: t('userMenu.account'), icon: 'i-lucide-user-cog', to: '/account' }
  ],
  // Administration, its own group with a heading, below the account entry.
  ...(isAdmin.value
    ? [[
        { label: t('userMenu.admin'), type: 'label' as const },
        { label: t('userMenu.userManagement'), icon: 'i-lucide-users', to: '/admin/users' },
        { label: t('nav.instances'), icon: 'i-lucide-cloud', to: '/admin/instances' },
        { label: t('nav.folders'), icon: 'i-lucide-folder-tree', to: '/admin/folders' },
        { label: t('nav.status'), icon: 'i-lucide-activity', to: '/admin/status' },
        { label: t('nav.appearance'), icon: 'i-lucide-palette', to: '/admin/settings' }
      ]]
    : []),
  [
    { label: t('userMenu.logout'), icon: 'i-lucide-log-out', onSelect: () => logout() }
  ]
])
</script>

<template>
  <UApp>
    <UHeader
      v-if="showChrome"
      :toggle="false"
    >
      <template #left>
        <NuxtLink
          to="/"
          class="flex items-center gap-2 font-semibold"
        >
          <img
            v-if="branding.has_logo && branding.logo_url"
            :src="branding.logo_url"
            :alt="siteName"
            class="h-7 w-auto max-w-[160px] object-contain"
          >
          <UIcon
            v-else
            name="i-lucide-file-search"
            class="size-5 text-primary"
          />
          {{ siteName }}
        </NuxtLink>
      </template>

      <UNavigationMenu :items="navigation" />

      <template #right>
        <UDropdownMenu
          v-if="user"
          :items="userMenuItems"
        >
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-user"
            :label="user.name"
          />
        </UDropdownMenu>
      </template>
    </UHeader>

    <!-- Extra bottom padding on mobile so content clears the bottom nav bar. -->
    <UMain :class="showChrome ? 'pb-20 md:pb-0' : ''">
      <NuxtPage />
    </UMain>

    <!-- Mobile navigation: a bottom bar instead of the header nav, so it stays
         reachable as an installed PWA. -->
    <nav
      v-if="showChrome"
      class="md:hidden fixed bottom-0 inset-x-0 z-40 border-t border-default bg-default/95 backdrop-blur"
      style="padding-bottom: env(safe-area-inset-bottom)"
    >
      <div class="flex">
        <NuxtLink
          v-for="item in navigation"
          :key="item.to"
          :to="item.to"
          class="flex-1 flex flex-col items-center gap-0.5 py-2.5 text-xs"
          :class="isActive(item.to) ? 'text-primary' : 'text-muted'"
        >
          <UIcon
            :name="item.icon"
            class="size-5"
          />
          {{ item.label }}
        </NuxtLink>
      </div>
    </nav>

    <UFooter>
      <template #left>
        <div class="flex items-center gap-3 text-xs text-muted">
          <UButton
            v-if="config.public.repoUrl"
            :to="config.public.repoUrl"
            target="_blank"
            rel="noopener"
            color="neutral"
            variant="ghost"
            size="xs"
            icon="i-simple-icons-github"
            :aria-label="t('nav.github')"
          />
          <span>{{ siteName }}</span>
        </div>
      </template>

      <template #right>
        <span class="text-xs text-muted font-mono">v{{ config.public.appVersion }}</span>
      </template>
    </UFooter>
  </UApp>
</template>
