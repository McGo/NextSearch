<script setup lang="ts">
const open = defineModel<boolean>('open', { default: false })

const api = useApi()
const toast = useToast()

const form = reactive({
  current_password: '',
  password: '',
  password_confirmation: ''
})
const saving = ref(false)
const error = ref<string | null>(null)

watch(open, (isOpen) => {
  if (isOpen) {
    form.current_password = ''
    form.password = ''
    form.password_confirmation = ''
    error.value = null
  }
})

async function submit() {
  saving.value = true
  error.value = null

  try {
    await api.put('/api/auth/password', form)
    open.value = false
    toast.add({ title: 'Passwort geändert.', color: 'success' })
  } catch (e) {
    error.value = e instanceof ApiError
      ? (e.fieldError('current_password') || e.fieldError('password') || e.message)
      : 'Das Passwort ließ sich nicht ändern.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UModal
    v-model:open="open"
    title="Passwort ändern"
  >
    <template #body>
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <UFormField
          label="Aktuelles Passwort"
          name="current_password"
        >
          <UInput
            v-model="form.current_password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Neues Passwort"
          name="password"
          hint="Mindestens zwölf Zeichen"
        >
          <UInput
            v-model="form.password"
            type="password"
            autocomplete="new-password"
            required
            class="w-full"
          />
        </UFormField>

        <UFormField
          label="Neues Passwort wiederholen"
          name="password_confirmation"
        >
          <UInput
            v-model="form.password_confirmation"
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

        <div class="flex justify-end gap-2 pt-2">
          <UButton
            color="neutral"
            variant="ghost"
            label="Abbrechen"
            @click="open = false"
          />
          <UButton
            type="submit"
            :loading="saving"
            label="Ändern"
          />
        </div>
      </form>
    </template>
  </UModal>
</template>
