<script setup lang="ts">
import { TAG_COLORS } from '~/composables/useTags'

const { t, locale, locales, setLocale } = useI18n()
useHead({ title: () => t('account.title') })

const api = useApi()
const toast = useToast()
const colorMode = useColorMode()
const { user, refresh: refreshAuth } = useAuth()

type TabKey = 'password' | 'security' | 'tags' | 'api' | 'appearance'
const tabs: { key: TabKey, label: string }[] = [
  { key: 'password', label: t('account.tabs.password') },
  { key: 'security', label: t('account.tabs.security') },
  { key: 'tags', label: t('account.tabs.tags') },
  { key: 'api', label: t('account.tabs.api') },
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

// --- Own tags ---------------------------------------------------------------
const { items: allTags, load: loadTags, create: createTag, remove: removeTag, label: tagLabel } = useTags()

const ownTags = computed(() => allTags.value.filter(tag => tag.is_private))
const newTagName = ref('')
const newTagColor = ref('neutral')
const tagBusy = ref(false)

onMounted(() => {
  loadTags().catch(() => { /* the list just stays empty */ })
})

async function addOwnTag() {
  if (newTagName.value.trim() === '') return

  tagBusy.value = true
  try {
    await createTag({ name: newTagName.value.trim(), color: newTagColor.value })
    newTagName.value = ''
  } catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('tags.createFailed'), color: 'error' })
  } finally {
    tagBusy.value = false
  }
}

async function dropOwnTag(uuid: string) {
  tagBusy.value = true
  try {
    await removeTag(uuid)
  } catch {
    toast.add({ title: t('tags.removeFailed'), color: 'error' })
  } finally {
    tagBusy.value = false
  }
}

// --- API keys ---------------------------------------------------------------
interface ApiKey {
  uuid: string
  name: string
  last_four: string
  expires_at: string | null
  last_used_at: string | null
  created_at: string | null
}

const keys = ref<ApiKey[]>([])
const keyName = ref('')
const keyExpires = ref('')
const keyBusy = ref(false)
const keyError = ref<string | null>(null)
// Shown exactly once, right after creation — afterwards only the hash exists.
const freshKey = ref<string | null>(null)

async function loadKeys() {
  const response = await api.get<{ data: ApiKey[] }>('/api/auth/tokens')
  keys.value = response.data
}

onMounted(() => {
  loadKeys().catch(() => { /* the list just stays empty */ })
})

async function createKey() {
  if (keyName.value.trim() === '') return

  keyBusy.value = true
  keyError.value = null

  try {
    const created = await api.post<ApiKey & { token: string }>('/api/auth/tokens', {
      name: keyName.value.trim(),
      ...(keyExpires.value ? { expires_at: keyExpires.value } : {})
    })

    freshKey.value = created.token
    keyName.value = ''
    keyExpires.value = ''
    await loadKeys()
  } catch (e) {
    keyError.value = e instanceof ApiError
      ? (Object.values(e.errors)[0]?.[0] || e.message)
      : t('account.api.createFailed')
  } finally {
    keyBusy.value = false
  }
}

async function revokeKey(uuid: string) {
  keyBusy.value = true
  try {
    await api.del(`/api/auth/tokens/${uuid}`)
    await loadKeys()
  } catch {
    toast.add({ title: t('account.api.revokeFailed'), color: 'error' })
  } finally {
    keyBusy.value = false
  }
}

async function copyKey() {
  if (!freshKey.value) return
  await navigator.clipboard.writeText(freshKey.value)
  toast.add({ title: t('account.api.copied'), color: 'success' })
}

const { dateTime } = useFormat()

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

    <!-- Own tags -->
    <section
      v-else-if="active === 'tags'"
      class="max-w-md space-y-6"
    >
      <div>
        <h2 class="font-medium">
          {{ t('account.tags.title') }}
        </h2>
        <p class="text-sm text-muted">
          {{ t('account.tags.desc') }}
        </p>
      </div>

      <form
        class="flex flex-wrap items-end gap-2"
        @submit.prevent="addOwnTag"
      >
        <UFormField
          :label="t('account.tags.nameLabel')"
          name="name"
          class="flex-1 min-w-40"
        >
          <UInput
            v-model="newTagName"
            maxlength="120"
            :placeholder="t('account.tags.namePlaceholder')"
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('admin.tags.colorLabel')"
          name="color"
        >
          <USelect
            v-model="newTagColor"
            :items="TAG_COLORS.map(value => ({ value, label: t(`admin.tags.colors.${value}`) }))"
            value-key="value"
            class="w-36"
          />
        </UFormField>

        <UButton
          type="submit"
          icon="i-lucide-plus"
          :loading="tagBusy"
          :label="t('account.tags.add')"
        />
      </form>

      <p
        v-if="ownTags.length === 0"
        class="text-sm text-muted"
      >
        {{ t('account.tags.empty') }}
      </p>

      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
      >
        <li
          v-for="tag in ownTags"
          :key="tag.uuid"
          class="flex items-center gap-3 px-3 py-2"
        >
          <TagChip :tag="tag" />
          <span class="flex-1 truncate text-xs text-muted">{{ tag.slug }}</span>
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :disabled="tagBusy"
            :aria-label="t('tags.remove', { tag: tagLabel(tag) })"
            @click="dropOwnTag(tag.uuid)"
          />
        </li>
      </ul>
    </section>

    <!-- API keys -->
    <section
      v-else-if="active === 'api'"
      class="max-w-2xl space-y-6"
    >
      <div>
        <h2 class="font-medium">
          {{ t('account.api.title') }}
        </h2>
        <p class="text-sm text-muted">
          {{ t('account.api.desc') }}
        </p>
      </div>

      <!-- The one and only time the key is legible. -->
      <div
        v-if="freshKey"
        class="rounded-lg border border-primary/40 bg-primary/5 p-3 space-y-2"
      >
        <p class="text-sm font-medium">
          {{ t('account.api.onceTitle') }}
        </p>
        <p class="text-xs text-muted">
          {{ t('account.api.onceDesc') }}
        </p>
        <div class="flex items-center gap-2">
          <code class="min-w-0 flex-1 truncate rounded bg-elevated px-2 py-1.5 font-mono text-sm select-all">{{ freshKey }}</code>
          <UButton
            size="xs"
            color="neutral"
            variant="outline"
            icon="i-lucide-copy"
            :label="t('account.api.copy')"
            @click="copyKey"
          />
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-x"
            :aria-label="t('common.close')"
            @click="freshKey = null"
          />
        </div>
      </div>

      <form
        class="flex flex-wrap items-end gap-2"
        @submit.prevent="createKey"
      >
        <UFormField
          :label="t('account.api.nameLabel')"
          name="name"
          class="flex-1 min-w-40"
        >
          <UInput
            v-model="keyName"
            maxlength="120"
            :placeholder="t('account.api.namePlaceholder')"
            class="w-full"
          />
        </UFormField>

        <UFormField
          :label="t('account.api.expiresLabel')"
          name="expires_at"
          :hint="t('account.api.expiresHint')"
        >
          <UInput
            v-model="keyExpires"
            type="date"
            class="w-44"
          />
        </UFormField>

        <UButton
          type="submit"
          icon="i-lucide-key"
          :loading="keyBusy"
          :label="t('account.api.create')"
        />
      </form>

      <UAlert
        v-if="keyError"
        color="error"
        variant="subtle"
        icon="i-lucide-triangle-alert"
        :description="keyError"
      />

      <p
        v-if="keys.length === 0"
        class="text-sm text-muted"
      >
        {{ t('account.api.empty') }}
      </p>

      <ul
        v-else
        class="divide-y divide-default rounded-lg border border-default"
      >
        <li
          v-for="key in keys"
          :key="key.uuid"
          class="flex flex-wrap items-center gap-3 px-3 py-2"
        >
          <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium">
              {{ key.name }}
              <span class="font-mono text-xs text-muted">…{{ key.last_four }}</span>
            </p>
            <p class="text-xs text-muted">
              {{ key.last_used_at
                ? t('account.api.lastUsed', { at: dateTime(key.last_used_at) })
                : t('account.api.neverUsed') }}
              <template v-if="key.expires_at">
                · {{ t('account.api.expiresAt', { at: dateTime(key.expires_at) }) }}
              </template>
            </p>
          </div>
          <UButton
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :disabled="keyBusy"
            :label="t('account.api.revoke')"
            @click="revokeKey(key.uuid)"
          />
        </li>
      </ul>

      <p class="text-xs text-muted">
        {{ t('account.api.docsHint') }}
      </p>
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
