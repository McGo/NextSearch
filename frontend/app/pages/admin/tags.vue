<script setup lang="ts">
import type { Tag, TagInput } from '~/composables/useTags'
import { TAG_COLORS } from '~/composables/useTags'

const { t } = useI18n()
useHead({ title: () => t('admin.tags.title') })

const toast = useToast()
const { loadAll, create, update, remove, label } = useTags()

const rows = ref<Tag[]>([])
const pending = ref(true)

// The instance vocabulary. Private tags belong to their owners and are none of
// an administrator's business here.
const vocabulary = computed(() => rows.value.filter(tag => !tag.is_private))

async function reload() {
  pending.value = true
  try {
    rows.value = await loadAll()
  } finally {
    pending.value = false
  }
}

onMounted(reload)

// --- Form -------------------------------------------------------------------

const emptyForm = (): TagInput & { uuid?: string } => ({
  name: '',
  color: 'neutral',
  description: '',
  owner: 'instance',
  scope: 'global',
  assign_policy: 'everyone',
  position: 0,
  enabled: true,
  auto_rule: null,
  auto_duration_minutes: null
})

const form = reactive(emptyForm())
const editing = ref<Tag | null>(null)
const open = ref(false)
const saving = ref(false)
const formError = ref<string | null>(null)

const isSystem = computed(() => editing.value?.is_system === true)
const automatic = computed({
  get: () => form.auto_rule === 'new_document',
  set: (on: boolean) => {
    form.auto_rule = on ? 'new_document' : null
    form.auto_duration_minutes = on ? (form.auto_duration_minutes ?? 7 * 24 * 60) : null
  }
})

function openCreate() {
  Object.assign(form, emptyForm())
  editing.value = null
  formError.value = null
  open.value = true
}

function openEdit(tag: Tag) {
  Object.assign(form, {
    uuid: tag.uuid,
    name: label(tag),
    color: tag.color,
    description: tag.description ?? '',
    scope: tag.scope,
    assign_policy: tag.assign_policy,
    position: tag.position,
    enabled: tag.enabled,
    auto_rule: tag.auto_rule,
    auto_duration_minutes: tag.auto_duration_minutes
  })
  editing.value = tag
  formError.value = null
  open.value = true
}

async function submit() {
  saving.value = true
  formError.value = null

  const payload: Partial<TagInput> = {
    name: form.name.trim(),
    color: form.color,
    description: form.description?.trim() || null,
    position: Number(form.position) || 0,
    enabled: form.enabled
  }

  // A system tag keeps its identity — only the label, the colour, the duration
  // and the on/off switch are up for discussion.
  if (!isSystem.value) {
    payload.assign_policy = form.assign_policy
    payload.auto_rule = form.auto_rule
  }
  if (form.auto_rule !== null || isSystem.value) {
    payload.auto_duration_minutes = form.auto_duration_minutes
  }

  try {
    if (editing.value) {
      await update(editing.value.uuid, payload)
    } else {
      await create({ ...payload, name: payload.name!, owner: 'instance', scope: form.scope })
    }

    open.value = false
    await reload()
    toast.add({ title: t('admin.tags.saved'), color: 'success' })
  } catch (e) {
    formError.value = e instanceof ApiError
      ? (Object.values(e.errors)[0]?.[0] || e.message)
      : t('admin.tags.saveFailed')
  } finally {
    saving.value = false
  }
}

const removing = ref<string | null>(null)

async function confirmRemove(tag: Tag) {
  removing.value = tag.uuid
  try {
    await remove(tag.uuid)
    await reload()
  } catch (e) {
    toast.add({ title: e instanceof ApiError ? e.message : t('admin.tags.removeFailed'), color: 'error' })
  } finally {
    removing.value = null
  }
}

// --- Display ----------------------------------------------------------------

/** "7 Tage" reads better than "10080 Minuten". */
function duration(minutes: number | null): string {
  if (minutes === null) return t('admin.tags.durationForever')
  if (minutes % (24 * 60) === 0) return t('admin.tags.days', { count: minutes / (24 * 60) }, minutes / (24 * 60))
  if (minutes % 60 === 0) return t('admin.tags.hours', { count: minutes / 60 }, minutes / 60)
  return t('admin.tags.minutes', { count: minutes }, minutes)
}

const colorOptions = TAG_COLORS.map(value => ({ value, label: t(`admin.tags.colors.${value}`) }))
const scopeOptions = [
  { value: 'global', label: t('admin.tags.scopes.global') },
  { value: 'personal', label: t('admin.tags.scopes.personal') }
]
const policyOptions = [
  { value: 'everyone', label: t('admin.tags.policies.everyone') },
  { value: 'admin', label: t('admin.tags.policies.admin') }
]
</script>

<template>
  <UContainer class="py-8 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold">
          {{ t('admin.tags.title') }}
        </h1>
        <p class="text-sm text-muted">
          {{ t('admin.tags.subtitle') }}
        </p>
      </div>

      <UButton
        icon="i-lucide-plus"
        :label="t('admin.tags.new')"
        @click="openCreate"
      />
    </div>

    <div
      v-if="pending"
      class="flex justify-center py-16"
    >
      <UIcon
        name="i-lucide-loader-circle"
        class="animate-spin size-6 text-muted"
      />
    </div>

    <p
      v-else-if="vocabulary.length === 0"
      class="text-sm text-muted"
    >
      {{ t('admin.tags.empty') }}
    </p>

    <ul
      v-else
      class="divide-y divide-default rounded-xl border border-default"
    >
      <li
        v-for="tag in vocabulary"
        :key="tag.uuid"
        class="flex flex-wrap items-center gap-3 p-4"
      >
        <TagChip :tag="tag" />

        <div class="min-w-0 flex-1 space-y-0.5">
          <p class="flex flex-wrap items-center gap-2 text-xs text-muted">
            <span>{{ t(`admin.tags.scopes.${tag.scope}`) }}</span>
            <span class="opacity-60">·</span>
            <span>{{ t(`admin.tags.policies.${tag.assign_policy}`) }}</span>
            <template v-if="tag.auto_rule">
              <span class="opacity-60">·</span>
              <span>{{ t('admin.tags.autoFor', { duration: duration(tag.auto_duration_minutes) }) }}</span>
            </template>
            <UBadge
              v-if="!tag.enabled"
              size="sm"
              color="neutral"
              variant="subtle"
              :label="t('admin.tags.disabled')"
            />
          </p>
          <p
            v-if="tag.description"
            class="truncate text-sm"
          >
            {{ tag.description }}
          </p>
        </div>

        <div class="flex shrink-0 items-center gap-1">
          <UButton
            size="xs"
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            :aria-label="t('common.edit')"
            @click="openEdit(tag)"
          />
          <UButton
            v-if="!tag.is_system"
            size="xs"
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :loading="removing === tag.uuid"
            :aria-label="t('common.delete')"
            @click="confirmRemove(tag)"
          />
          <UIcon
            v-else
            name="i-lucide-lock"
            class="size-4 text-muted"
            :title="t('admin.tags.systemLocked')"
          />
        </div>
      </li>
    </ul>

    <UModal
      v-model:open="open"
      :title="editing ? t('admin.tags.editTitle') : t('admin.tags.newTitle')"
    >
      <template #body>
        <form
          class="space-y-4"
          @submit.prevent="submit"
        >
          <UAlert
            v-if="isSystem"
            color="info"
            variant="subtle"
            icon="i-lucide-info"
            :description="t('admin.tags.systemHint')"
          />

          <UFormField
            :label="t('admin.tags.nameLabel')"
            name="name"
          >
            <UInput
              v-model="form.name"
              autofocus
              maxlength="120"
              class="w-full"
            />
          </UFormField>

          <div class="grid grid-cols-2 gap-3">
            <UFormField
              :label="t('admin.tags.colorLabel')"
              name="color"
            >
              <USelect
                v-model="form.color"
                :items="colorOptions"
                value-key="value"
                class="w-full"
              />
            </UFormField>

            <UFormField
              :label="t('admin.tags.positionLabel')"
              name="position"
              :hint="t('admin.tags.positionHint')"
            >
              <UInput
                v-model="form.position"
                type="number"
                min="0"
                max="9999"
                class="w-full"
              />
            </UFormField>
          </div>

          <UFormField
            :label="t('admin.tags.descriptionLabel')"
            name="description"
          >
            <UTextarea
              v-model="form.description"
              :rows="2"
              maxlength="500"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="!editing"
            :label="t('admin.tags.scopeLabel')"
            name="scope"
            :hint="t('admin.tags.scopeHint')"
          >
            <USelect
              v-model="form.scope"
              :items="scopeOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <UFormField
            v-if="!isSystem"
            :label="t('admin.tags.policyLabel')"
            name="assign_policy"
          >
            <USelect
              v-model="form.assign_policy"
              :items="policyOptions"
              value-key="value"
              class="w-full"
            />
          </UFormField>

          <div class="space-y-3 rounded-lg border border-default p-3">
            <USwitch
              v-if="!isSystem"
              v-model="automatic"
              :label="t('admin.tags.autoLabel')"
              :description="t('admin.tags.autoDesc')"
              :disabled="form.scope === 'personal'"
            />

            <UFormField
              v-if="automatic"
              :label="t('admin.tags.durationLabel')"
              name="auto_duration_minutes"
              :hint="t('admin.tags.durationHint')"
            >
              <UInput
                v-model="form.auto_duration_minutes"
                type="number"
                min="1"
                max="525600"
                class="w-full"
              />
            </UFormField>
          </div>

          <USwitch
            v-model="form.enabled"
            :label="t('admin.tags.enabledLabel')"
            :description="t('admin.tags.enabledDesc')"
          />

          <UAlert
            v-if="formError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="formError"
          />

          <div class="flex justify-end gap-2 pt-2">
            <UButton
              color="neutral"
              variant="ghost"
              :label="t('common.cancel')"
              @click="open = false"
            />
            <UButton
              type="submit"
              :loading="saving"
              :label="t('common.save')"
            />
          </div>
        </form>
      </template>
    </UModal>
  </UContainer>
</template>
