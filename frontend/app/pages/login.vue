<script setup lang="ts">
const { login } = useAuth()
const route = useRoute()
const config = useRuntimeConfig()

useHead({ title: 'Anmelden' })

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
    await navigateTo((route.query.weiter as string) || '/')
  } catch (e) {
    error.value = e instanceof ApiError
      ? (e.fieldError('email') || e.message)
      : 'Die Anmeldung ist fehlgeschlagen.'
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
          <UIcon
            name="i-lucide-file-search"
            class="size-6 text-primary"
          />
          <div>
            <h1 class="font-semibold">
              {{ config.public.appName }}
            </h1>
            <p class="text-sm text-muted">
              Volltextsuche über Ihre Nextcloud-Ordner
            </p>
          </div>
        </div>
      </template>

      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UFormField
          label="E-Mail"
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
          label="Passwort"
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
          label="Angemeldet bleiben"
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
          label="Anmelden"
        />
      </form>
    </UCard>
  </div>
</template>
