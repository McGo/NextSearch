<script setup lang="ts">
/**
 * The consent screen an MCP client sends the user to.
 *
 * Everything worth deciding is on one card: who is asking, where the code goes
 * afterwards, and what the connection will be allowed to do. Reading is part of
 * the deal — a connection that may see nothing is pointless — writing tags is a
 * switch the user can leave off.
 *
 * The page never redirects on its own except where the protocol requires it:
 * a request naming a client or a callback we do not know stops here with a
 * message, because sending a code to an address nobody registered is exactly
 * the case this screen exists to prevent.
 */
interface ConsentScope {
  id: string
  required: boolean
}

interface Consent {
  client: {
    name: string
    uri: string | null
    logo: string | null
    redirect_host: string
    registered_at: string | null
    known: boolean
  }
  scopes: ConsentScope[]
  resource: string | null
}

const { t } = useI18n()
const route = useRoute()
const api = useApi()
const { user } = useAuth()
const { dateTime } = useFormat()

useHead({ title: () => t('oauth.title') })

const consent = ref<Consent | null>(null)
const loading = ref(true)
const busy = ref<'approve' | 'deny' | null>(null)
const error = ref<string | null>(null)
const writeGranted = ref(true)

// The parameters the client put in the URL travel on unchanged — the backend
// checks them again on approval, so nothing here is trusted twice.
const query = computed(() => route.fullPath.split('?')[1] ?? '')

const optionalScopes = computed(() => consent.value?.scopes.filter(s => !s.required) ?? [])
const requiredScopes = computed(() => consent.value?.scopes.filter(s => s.required) ?? [])

function scopeLabel(id: string): string {
  return t(`oauth.scopes.${id.replace(':', '_')}.label`)
}

function scopeHint(id: string): string {
  return t(`oauth.scopes.${id.replace(':', '_')}.hint`)
}

/** Leaves the application for the client's callback. */
function handOver(url: string) {
  window.location.href = url
}

onMounted(async () => {
  try {
    const response = await api.get<Consent & { redirect_to?: string, message?: string }>(
      `/api/oauth/authorize?${query.value}`
    )

    // A request the client got wrong is reported where the client is
    // listening, not left hanging on this page.
    if (response.redirect_to) {
      handOver(response.redirect_to)
      return
    }

    consent.value = response
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('oauth.failed')
  } finally {
    loading.value = false
  }
})

async function decide(action: 'approve' | 'deny') {
  busy.value = action
  error.value = null

  const scopes = [
    ...requiredScopes.value.map(s => s.id),
    ...(writeGranted.value ? optionalScopes.value.map(s => s.id) : [])
  ]

  try {
    const path = action === 'approve'
      ? `/api/oauth/authorize?${query.value}`
      : `/api/oauth/authorize/deny?${query.value}`

    const { redirect_to: target } = await api.post<{ redirect_to: string }>(path, { scopes })

    handOver(target)
  } catch (e) {
    error.value = e instanceof ApiError ? e.message : t('oauth.failed')
    busy.value = null
  }
}
</script>

<template>
  <UContainer class="py-10 flex justify-center">
    <div class="w-full max-w-md space-y-4">
      <div
        v-if="loading"
        class="flex items-center gap-2 text-sm text-muted"
      >
        <UIcon
          name="i-lucide-loader-circle"
          class="size-4 animate-spin"
        />
        {{ t('oauth.checking') }}
      </div>

      <!-- A request we will not forward. No buttons: there is nothing to grant. -->
      <UCard v-else-if="!consent">
        <div class="space-y-4">
          <div class="flex items-start gap-3">
            <UIcon
              name="i-lucide-shield-alert"
              class="size-6 text-error shrink-0"
            />
            <div>
              <h1 class="font-medium">
                {{ t('oauth.refusedTitle') }}
              </h1>
              <p class="text-sm text-muted mt-1">
                {{ error || t('oauth.failed') }}
              </p>
            </div>
          </div>

          <UButton
            to="/"
            color="neutral"
            variant="outline"
            icon="i-lucide-arrow-left"
            :label="t('oauth.backToSearch')"
          />
        </div>
      </UCard>

      <UCard v-else>
        <div class="space-y-5">
          <div class="flex items-start gap-3">
            <img
              v-if="consent.client.logo"
              :src="consent.client.logo"
              alt=""
              class="size-10 rounded-lg object-contain bg-elevated p-1"
            >
            <UIcon
              v-else
              name="i-lucide-plug"
              class="size-10 rounded-lg bg-elevated p-2 text-muted"
            />

            <div class="min-w-0">
              <h1 class="font-medium">
                {{ t('oauth.heading', { app: consent.client.name }) }}
              </h1>
              <p class="text-sm text-muted">
                {{ t('oauth.subtitle', { email: user?.email ?? '' }) }}
              </p>
            </div>
          </div>

          <!-- A client seen before is worth saying so: the second prompt from
               the same application is routine, the first one is not. -->
          <UAlert
            v-if="!consent.client.known"
            color="warning"
            variant="subtle"
            icon="i-lucide-info"
            :description="t('oauth.firstTime', { host: consent.client.redirect_host })"
          />

          <div class="space-y-3">
            <p class="text-sm font-medium">
              {{ t('oauth.permissions') }}
            </p>

            <div
              v-for="scope in requiredScopes"
              :key="scope.id"
              class="flex items-start gap-2 text-sm"
            >
              <UIcon
                name="i-lucide-check"
                class="size-4 mt-0.5 text-primary shrink-0"
              />
              <div>
                <p>{{ scopeLabel(scope.id) }}</p>
                <p class="text-xs text-muted">
                  {{ scopeHint(scope.id) }}
                </p>
              </div>
            </div>

            <div
              v-for="scope in optionalScopes"
              :key="scope.id"
              class="flex items-start justify-between gap-3 rounded-lg border border-default p-3"
            >
              <div class="text-sm">
                <p>{{ scopeLabel(scope.id) }}</p>
                <p class="text-xs text-muted">
                  {{ scopeHint(scope.id) }}
                </p>
              </div>
              <USwitch
                v-model="writeGranted"
                :aria-label="scopeLabel(scope.id)"
              />
            </div>
          </div>

          <dl class="rounded-lg bg-elevated/50 p-3 text-xs space-y-1">
            <div class="flex justify-between gap-2">
              <dt class="text-muted">
                {{ t('oauth.redirectHost') }}
              </dt>
              <dd class="font-mono truncate">
                {{ consent.client.redirect_host }}
              </dd>
            </div>
            <div
              v-if="consent.client.registered_at"
              class="flex justify-between gap-2"
            >
              <dt class="text-muted">
                {{ t('oauth.registeredAt') }}
              </dt>
              <dd>{{ dateTime(consent.client.registered_at) }}</dd>
            </div>
          </dl>

          <p class="text-xs text-muted">
            {{ t('oauth.scopeNote') }}
          </p>

          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            :description="error"
          />

          <div class="flex gap-2">
            <UButton
              icon="i-lucide-check"
              :loading="busy === 'approve'"
              :disabled="busy !== null"
              :label="t('oauth.allow')"
              @click="decide('approve')"
            />
            <UButton
              color="neutral"
              variant="ghost"
              :loading="busy === 'deny'"
              :disabled="busy !== null"
              :label="t('oauth.deny')"
              @click="decide('deny')"
            />
          </div>
        </div>
      </UCard>
    </div>
  </UContainer>
</template>
