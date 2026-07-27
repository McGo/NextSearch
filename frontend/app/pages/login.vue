<script setup lang="ts">
const { login, twoFactorChallenge } = useAuth()
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

// Two-step: 'credentials' first, 'twofactor' if the account has 2FA on.
const step = ref<'credentials' | 'twofactor'>('credentials')
const code = ref('')
const useRecovery = ref(false)

async function submit() {
  pending.value = true
  error.value = null

  try {
    if (step.value === 'credentials') {
      const { twoFactor } = await login(email.value, password.value, remember.value)
      if (twoFactor) {
        step.value = 'twofactor'
        return
      }
    } else {
      const value = code.value.trim()
      await twoFactorChallenge(useRecovery.value ? { recovery_code: value } : { code: value })
    }

    await navigateTo((route.query.next as string) || '/')
  } catch (e) {
    error.value = e instanceof ApiError
      ? (e.fieldError('code') || e.fieldError('email') || e.message)
      : t('login.failed')
  } finally {
    pending.value = false
  }
}

function backToCredentials() {
  step.value = 'credentials'
  code.value = ''
  error.value = null
}
</script>

<template>
  <div class="min-h-screen flex items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <div class="flex items-center gap-2">
          <img
            :src="branding.has_logo && branding.logo_url ? branding.logo_url : '/logo.svg'"
            :alt="siteName"
            class="h-8 w-auto max-w-[160px] object-contain"
          >
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
        <template v-if="step === 'credentials'">
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
        </template>

        <template v-else>
          <p class="text-sm text-muted">
            {{ useRecovery ? t('login.recoveryHint') : t('login.twoFactorHint') }}
          </p>

          <UFormField
            :label="useRecovery ? t('login.recoveryCode') : t('login.code')"
            name="code"
          >
            <UInput
              v-model="code"
              :type="useRecovery ? 'text' : 'text'"
              :inputmode="useRecovery ? 'text' : 'numeric'"
              autocomplete="one-time-code"
              autofocus
              required
              class="w-full"
            />
          </UFormField>

          <button
            type="button"
            class="text-xs text-primary hover:underline"
            @click="useRecovery = !useRecovery; code = ''"
          >
            {{ useRecovery ? t('login.useCode') : t('login.useRecovery') }}
          </button>
        </template>

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
          :label="step === 'credentials' ? t('login.submit') : t('login.verify')"
        />

        <UButton
          v-if="step === 'twofactor'"
          block
          color="neutral"
          variant="ghost"
          :label="t('login.back')"
          @click="backToCredentials"
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
