<script setup lang="ts">
const { t, locale, locales, setLocale } = useI18n()
useHead({ title: () => t('account.title') })

const api = useApi()
const toast = useToast()
const colorMode = useColorMode()
const { user, refresh: refreshAuth } = useAuth()

type TabKey = 'password' | 'security' | 'appearance'
const tabs: { key: TabKey, label: string }[] = [
  { key: 'password', label: t('account.tabs.password') },
  { key: 'security', label: t('account.tabs.security') },
  { key: 'appearance', label: t('account.tabs.appearance') }
]
const active = ref<TabKey>('password')

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

// --- Two-factor -------------------------------------------------------------
interface Enrolment { secret: string, qr: string, recovery_codes: string[] }

const twoFactorEnabled = computed(() => user.value?.two_factor_enabled === true)
const enrolment = ref<Enrolment | null>(null)
const confirmCode = ref('')
const recoveryCodes = ref<string[] | null>(null)
const disablePassword = ref('')
const tfaBusy = ref(false)
const tfaError = ref<string | null>(null)

async function startEnrolment() {
  tfaBusy.value = true
  tfaError.value = null
  try {
    enrolment.value = await api.post<Enrolment>('/api/auth/two-factor')
    recoveryCodes.value = enrolment.value.recovery_codes
  } catch (e) {
    tfaError.value = e instanceof ApiError ? e.message : t('account.security.failed')
  } finally {
    tfaBusy.value = false
  }
}

async function confirmEnrolment() {
  tfaBusy.value = true
  tfaError.value = null
  try {
    await api.post('/api/auth/two-factor/confirm', { code: confirmCode.value.trim() })
    await refreshAuth()
    enrolment.value = null
    confirmCode.value = ''
    toast.add({ title: t('account.security.enabled'), color: 'success' })
  } catch (e) {
    tfaError.value = e instanceof ApiError ? (e.fieldError('code') || e.message) : t('account.security.failed')
  } finally {
    tfaBusy.value = false
  }
}

function cancelEnrolment() {
  enrolment.value = null
  confirmCode.value = ''
  tfaError.value = null
}

async function showRecoveryCodes() {
  const res = await api.get<{ recovery_codes: string[] }>('/api/auth/two-factor/recovery-codes')
  recoveryCodes.value = res.recovery_codes
}

async function regenerateRecoveryCodes() {
  const res = await api.post<{ recovery_codes: string[] }>('/api/auth/two-factor/recovery-codes')
  recoveryCodes.value = res.recovery_codes
  toast.add({ title: t('account.security.recoveryRegenerated'), color: 'success' })
}

async function disableTwoFactor() {
  tfaBusy.value = true
  tfaError.value = null
  try {
    await api.del('/api/auth/two-factor', { password: disablePassword.value })
    await refreshAuth()
    disablePassword.value = ''
    recoveryCodes.value = null
    toast.add({ title: t('account.security.disabled'), color: 'success' })
  } catch (e) {
    tfaError.value = e instanceof ApiError ? (e.fieldError('password') || e.message) : t('account.security.failed')
  } finally {
    tfaBusy.value = false
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
        @click="active = tab.key"
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

    <!-- Security: two-factor -->
    <section
      v-else-if="active === 'security'"
      class="max-w-md space-y-6"
    >
      <div>
        <h2 class="font-medium">
          {{ t('account.security.title') }}
        </h2>
        <p class="text-sm text-muted">
          {{ t('account.security.desc') }}
        </p>
      </div>

      <UAlert
        v-if="tfaError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="tfaError"
      />

      <!-- Already on -->
      <template v-if="twoFactorEnabled">
        <UAlert
          color="success"
          variant="subtle"
          icon="i-lucide-shield-check"
          :title="t('account.security.onTitle')"
          :description="t('account.security.onDesc')"
        />

        <div
          v-if="recoveryCodes"
          class="rounded-lg border border-default p-3"
        >
          <p class="text-xs text-muted mb-2">
            {{ t('account.security.recoveryHint') }}
          </p>
          <ul class="grid grid-cols-2 gap-1 font-mono text-sm">
            <li
              v-for="rc in recoveryCodes"
              :key="rc"
            >
              {{ rc }}
            </li>
          </ul>
        </div>

        <div class="flex flex-wrap gap-2">
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-list"
            :label="t('account.security.showRecovery')"
            @click="showRecoveryCodes"
          />
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-refresh-cw"
            :label="t('account.security.regenerate')"
            @click="regenerateRecoveryCodes"
          />
        </div>

        <form
          class="space-y-3 border-t border-default pt-4"
          @submit.prevent="disableTwoFactor"
        >
          <UFormField
            :label="t('account.security.disableLabel')"
            name="password"
          >
            <UInput
              v-model="disablePassword"
              type="password"
              autocomplete="current-password"
              class="w-full"
            />
          </UFormField>
          <UButton
            type="submit"
            color="error"
            variant="soft"
            icon="i-lucide-shield-off"
            :loading="tfaBusy"
            :label="t('account.security.disable')"
          />
        </form>
      </template>

      <!-- Enrolment in progress -->
      <template v-else-if="enrolment">
        <p class="text-sm">
          {{ t('account.security.scanHint') }}
        </p>
        <img
          :src="enrolment.qr"
          alt=""
          class="rounded-lg border border-default bg-white p-2"
          width="220"
          height="220"
        >
        <p class="text-xs text-muted">
          {{ t('account.security.manualHint') }}
          <span class="font-mono break-all select-all">{{ enrolment.secret }}</span>
        </p>

        <div class="rounded-lg border border-default p-3">
          <p class="text-xs text-muted mb-2">
            {{ t('account.security.saveRecovery') }}
          </p>
          <ul class="grid grid-cols-2 gap-1 font-mono text-sm">
            <li
              v-for="rc in enrolment.recovery_codes"
              :key="rc"
            >
              {{ rc }}
            </li>
          </ul>
        </div>

        <form
          class="space-y-3"
          @submit.prevent="confirmEnrolment"
        >
          <UFormField
            :label="t('account.security.confirmLabel')"
            name="code"
          >
            <UInput
              v-model="confirmCode"
              inputmode="numeric"
              autocomplete="one-time-code"
              autofocus
              class="w-full"
            />
          </UFormField>
          <div class="flex gap-2">
            <UButton
              type="submit"
              icon="i-lucide-shield-check"
              :loading="tfaBusy"
              :label="t('account.security.confirm')"
            />
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="cancelEnrolment"
            />
          </div>
        </form>
      </template>

      <!-- Off -->
      <template v-else>
        <UButton
          icon="i-lucide-shield-plus"
          :loading="tfaBusy"
          :label="t('account.security.enable')"
          @click="startEnrolment"
        />
      </template>
    </section>

    <!-- Appearance: language + theme -->
    <section
      v-else-if="active === 'appearance'"
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
