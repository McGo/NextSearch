<script setup lang="ts">
// The page behind an invitation link. An administrator created the account and
// passed the link on; here the invited person sets their own password.

interface InvitationInfo {
  name: string
  email: string
  expires_at: string
}

const route = useRoute()
const api = useApi()
const toast = useToast()
const config = useRuntimeConfig()
const { t } = useI18n()
const { refresh } = useAuth()
const { dateTime } = useFormat()
const { state: branding, load: loadBranding } = useBranding()

const siteName = computed(() => branding.value.site_name || config.public.appName)

useHead({ title: () => t('invite.title') })

const token = computed(() => String(route.params.token))
const info = ref<InvitationInfo | null>(null)
const loading = ref(true)
const invalid = ref<string | null>(null)

const password = ref('')
const repeat = ref('')
const pending = ref(false)
const error = ref<string | null>(null)

onMounted(async () => {
  loadBranding()

  try {
    const response = await api.get<{ invitation: InvitationInfo }>(`/api/auth/invitations/${token.value}`)
    info.value = response.invitation
  } catch (e) {
    invalid.value = e instanceof ApiError ? e.message : t('invite.invalid')
  } finally {
    loading.value = false
  }
})

async function submit() {
  pending.value = true
  error.value = null

  try {
    const response = await api.post<{ signed_in: boolean, message: string }>(`/api/auth/invitations/${token.value}`, {
      password: password.value,
      password_confirmation: repeat.value
    })

    toast.add({ title: response.message, color: 'success' })

    if (response.signed_in) {
      await refresh()
      await navigateTo('/')
    } else {
      // The account has two-factor; the regular sign-in asks for the code.
      await navigateTo('/login')
    }
  } catch (e) {
    error.value = e instanceof ApiError
      ? (e.fieldError('password') || e.message)
      : t('invite.failed')
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
            :src="branding.has_logo && branding.logo_url ? branding.logo_url : '/logo.svg'"
            :alt="siteName"
            class="h-8 w-auto max-w-[160px] object-contain"
          >
          <div>
            <h1 class="font-semibold">
              {{ siteName }}
            </h1>
            <p class="text-sm text-muted">
              {{ t('invite.subtitle') }}
            </p>
          </div>
        </div>
      </template>

      <div
        v-if="loading"
        class="flex justify-center py-8"
      >
        <UIcon
          name="i-lucide-loader-circle"
          class="animate-spin size-6 text-muted"
        />
      </div>

      <div
        v-else-if="invalid"
        class="space-y-4"
      >
        <UAlert
          color="warning"
          variant="subtle"
          icon="i-lucide-link-2-off"
          :description="invalid"
        />
        <UButton
          to="/login"
          block
          color="neutral"
          variant="outline"
          :label="t('invite.toLogin')"
        />
      </div>

      <form
        v-else-if="info"
        class="space-y-4"
        @submit.prevent="submit"
      >
        <p class="text-sm">
          {{ t('invite.greeting', { name: info.name }) }}
        </p>
        <p class="text-sm text-muted">
          {{ t('invite.hint', { email: info.email, at: dateTime(info.expires_at) }) }}
        </p>

        <!-- Lets the password manager file the new password under the right account. -->
        <input
          type="email"
          name="email"
          autocomplete="username"
          :value="info.email"
          class="hidden"
          readonly
        >

        <UFormField
          :label="t('password.new')"
          :hint="t('password.newHint')"
          name="password"
        >
          <UInput
            v-model="password"
            type="password"
            autocomplete="new-password"
            autofocus
            required
            minlength="12"
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('password.repeat')"
          name="password_confirmation"
        >
          <UInput
            v-model="repeat"
            type="password"
            autocomplete="new-password"
            required
            class="w-full"
          />
        </UFormField>

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
          :label="t('invite.submit')"
        />
      </form>
    </UCard>
  </div>
</template>
