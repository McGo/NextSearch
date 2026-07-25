<script setup lang="ts">
const { t, locale, locales, setLocale } = useI18n()
useHead({ title: () => t('account.title') })

const api = useApi()
const toast = useToast()
const colorMode = useColorMode()

const tabs = [
  { key: 'password', label: t('account.tabs.password') },
  { key: 'appearance', label: t('account.tabs.appearance') }
]
const active = ref<'password' | 'appearance'>('password')

// --- Password ---------------------------------------------------------------
const pwForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const pwSaving = ref(false)
const pwError = ref<string | null>(null)

async function submitPassword() {
  pwSaving.value = true
  pwError.value = null

  try {
    await api.put('/api/auth/password', pwForm)
    toast.add({ title: t('password.changed'), color: 'success' })
    pwForm.current_password = ''
    pwForm.password = ''
    pwForm.password_confirmation = ''
  } catch (e) {
    pwError.value = e instanceof ApiError
      ? (e.fieldError('current_password') || e.fieldError('password') || e.message)
      : t('password.failed')
  } finally {
    pwSaving.value = false
  }
}

// --- Appearance -------------------------------------------------------------
const flagOf = (l: unknown) => (l as { flag?: string } | undefined)?.flag

const themeOptions = [
  { value: 'system', label: t('account.theme.system'), icon: 'i-lucide-monitor' },
  { value: 'light', label: t('account.theme.light'), icon: 'i-lucide-sun' },
  { value: 'dark', label: t('account.theme.dark'), icon: 'i-lucide-moon' }
]
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        {{ t('account.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('account.subtitle') }}
      </p>
    </div>

    <!-- Underline tab navigation -->
    <div class="border-b border-default flex gap-1">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="px-3 py-2 text-sm border-b-2 -mb-px transition-colors"
        :class="active === tab.key
          ? 'border-primary text-highlighted font-medium'
          : 'border-transparent text-muted hover:text-default'"
        @click="active = (tab.key as 'password' | 'appearance')"
      >
        {{ tab.label }}
      </button>
    </div>

    <!-- Password -->
    <section
      v-if="active === 'password'"
      class="max-w-md"
    >
      <form
        class="space-y-4"
        @submit.prevent="submitPassword"
      >
        <UFormField
          :label="t('password.current')"
          name="current_password"
        >
          <UInput
            v-model="pwForm.current_password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('password.new')"
          name="password"
          :hint="t('password.newHint')"
        >
          <UInput
            v-model="pwForm.password"
            type="password"
            autocomplete="new-password"
            required
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('password.repeat')"
          name="password_confirmation"
        >
          <UInput
            v-model="pwForm.password_confirmation"
            type="password"
            autocomplete="new-password"
            required
            class="w-full"
          />
        </UFormField>

        <UAlert
          v-if="pwError"
          color="error"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          :description="pwError"
        />

        <UButton
          type="submit"
          :loading="pwSaving"
          :label="t('password.submit')"
        />
      </form>
    </section>

    <!-- Appearance: language + theme -->
    <section
      v-else
      class="space-y-8 max-w-md"
    >
      <div class="space-y-2">
        <h2 class="text-sm font-medium">
          {{ t('account.language') }}
        </h2>
        <div class="flex flex-col gap-1">
          <button
            v-for="l in locales"
            :key="l.code"
            type="button"
            class="flex items-center gap-2 rounded-md px-3 py-2 text-sm text-left transition-colors"
            :class="l.code === locale ? 'bg-elevated text-highlighted' : 'hover:bg-elevated/50 text-default'"
            @click="setLocale(l.code)"
          >
            <span class="text-base leading-none">{{ flagOf(l) }}</span>
            <span class="flex-1">{{ l.name ?? l.code }}</span>
            <UIcon
              v-if="l.code === locale"
              name="i-lucide-check"
              class="size-4 text-primary"
            />
          </button>
        </div>
      </div>

      <div class="space-y-2">
        <h2 class="text-sm font-medium">
          {{ t('account.theme.title') }}
        </h2>
        <div class="inline-flex rounded-lg border border-default p-1 gap-1">
          <button
            v-for="option in themeOptions"
            :key="option.value"
            type="button"
            class="flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm transition-colors"
            :class="colorMode.preference === option.value
              ? 'bg-elevated text-highlighted'
              : 'text-muted hover:text-default'"
            @click="colorMode.preference = option.value"
          >
            <UIcon
              :name="option.icon"
              class="size-4"
            />
            {{ option.label }}
          </button>
        </div>
      </div>
    </section>
  </UContainer>
</template>
