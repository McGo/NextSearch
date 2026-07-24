<script setup lang="ts">
const config = useRuntimeConfig()
const { user, isAdmin, logout } = useAuth()
const route = useRoute()
const { t, locale, locales, setLocale } = useI18n()

useHead({
  titleTemplate: title => (title ? `${title} · ${config.public.appName}` : config.public.appName),
  htmlAttrs: { lang: () => locale.value },
  meta: [{ name: 'viewport', content: 'width=device-width, initial-scale=1' }],
  link: [{ rel: 'icon', href: '/favicon.ico' }]
})

const showChrome = computed(() => route.path !== '/login')
const passwordModalOpen = ref(false)

const navigation = computed(() => [
  { label: t('nav.search'), to: '/', icon: 'i-lucide-search' },
  ...(isAdmin.value
    ? [
        { label: t('nav.instances'), to: '/admin/instances', icon: 'i-lucide-cloud' },
        { label: t('nav.folders'), to: '/admin/folders', icon: 'i-lucide-folder-tree' },
        { label: t('nav.users'), to: '/admin/users', icon: 'i-lucide-users' },
        { label: t('nav.status'), to: '/admin/status', icon: 'i-lucide-activity' }
      ]
    : [])
])

// Locales carry a `flag` emoji (see nuxt.config); the switcher identifies every
// language by it, and the header shows the active one.
// `flag` is a custom locale property, read through a small cast while the
// locale's own typing (its code union) is kept for setLocale.
const flagOf = (l: unknown) => (l as { flag?: string } | undefined)?.flag

const currentFlag = computed(() =>
  flagOf(locales.value.find(l => l.code === locale.value)) ?? '🌐'
)

// One entry per configured locale; contributors extend this by adding a locale.
const languageItems = computed(() =>
  locales.value.map(l => ({
    label: `${flagOf(l) ?? ''} ${l.name ?? l.code}`.trim(),
    onSelect: () => setLocale(l.code)
  }))
)

const userMenuItems = computed(() => [
  [{ label: user.value?.email ?? '', type: 'label' as const }],
  [
    { label: t('userMenu.changePassword'), icon: 'i-lucide-key-round', onSelect: () => { passwordModalOpen.value = true } },
    { label: t('userMenu.logout'), icon: 'i-lucide-log-out', onSelect: () => logout() }
  ]
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
        <UButton
          v-if="config.public.repoUrl"
          :to="config.public.repoUrl"
          target="_blank"
          rel="noopener"
          color="neutral"
          variant="ghost"
          icon="i-simple-icons-github"
          :aria-label="t('nav.github')"
        />

        <UDropdownMenu :items="languageItems">
          <UButton
            color="neutral"
            variant="ghost"
            class="gap-1"
            :aria-label="t('language.label')"
          >
            <span class="text-base leading-none">{{ currentFlag }}</span>
            <UIcon
              name="i-lucide-chevron-down"
              class="size-3.5 text-dimmed"
            />
          </UButton>
        </UDropdownMenu>

        <UColorModeButton />

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
          {{ t('footer.note', { app: config.public.appName }) }}
        </p>
      </template>
    </UFooter>
  </UApp>
</template>
