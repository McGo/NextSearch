<script setup lang="ts">
const { login } = useAuth()
const route = useRoute()
const config = useRuntimeConfig()
const { t } = useI18n()
const { state: branding, load: loadBranding } = useBranding()

onMounted(loadBranding)

const siteName = computed(() => branding.value.site_name || config.public.appName)

useHead({ title: () => t('login.submit') })

const email = ref('')
const password = ref('')
const remember = ref(false)
const pending = ref(false)
const error = ref<string | null>(null)

async function submit() {
  pending.value = true
  error.value = null

  try {
    await login(email.value, password.value, remember.value)
    await navigateTo((route.query.next as string) || '/')
  } catch (e) {
    error.value = e instanceof ApiError
      ? (e.fieldError('email') || e.message)
      : t('login.failed')
  } finally {
    pending.value = false
  }
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <div class="flex items-center gap-2">
          <img
            v-if="branding.has_logo && branding.logo_url"
            :src="branding.logo_url"
            :alt="siteName"
            class="h-8 w-auto max-w-[160px] object-contain"
          >
          <UIcon
            v-else
            name="i-lucide-file-search"
            class="size-6 text-primary"
          />
          <div>
            <h1 class="font-semibold">
              {{ siteName }}
            </h1>
            <p class="text-sm text-muted">
              {{ t('login.subtitle') }}
            </p>
          </div>
        </div>
      </template>

      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UFormField
          :label="t('login.email')"
          name="email"
        >
          <UInput
            v-model="email"
            type="email"
            autocomplete="username"
            autofocus
            required
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('login.password')"
          name="password"
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full"
          />
        </UFormField>

        <UCheckbox
          v-model="remember"
          :label="t('login.remember')"
        />

        <UAlert
          v-if="error"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :description="error"
        />

        <UButton
          type="submit"
          block
          :loading="pending"
          :label="t('login.submit')"
        />
      </form>

      <template
        v-if="config.public.repoUrl"
        #footer
      >
        <UButton
          :to="config.public.repoUrl"
          target="_blank"
          rel="noopener"
          color="neutral"
          variant="link"
          size="xs"
          icon="i-simple-icons-github"
          :label="t('nav.github')"
        />
      </template>
    </UCard>
  </div>
</template>
