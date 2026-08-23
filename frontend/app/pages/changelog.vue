<script setup lang="ts">
/**
 * What changed between versions, in the reader's language.
 *
 * Reached from the version number in the footer. The version this installation
 * runs on is marked, so the page also answers the question the footer raises:
 * how far behind or ahead am I.
 */
import { CHANGELOG, type ChangeKind, type Release } from '~/data/changelog'

const { t, locale } = useI18n()
const config = useRuntimeConfig()
const { date } = useFormat()

useHead({ title: () => t('changelog.title') })

const current = computed(() => config.public.appVersion)

function text(entry: { de: string, en: string }): string {
  return locale.value === 'de' ? entry.de : entry.en
}

function isCurrent(release: Release): boolean {
  return release.version === current.value
}

const kindColor = {
  added: 'success',
  changed: 'info',
  fixed: 'warning'
} as const satisfies Record<ChangeKind, string>
</script>

<template>
  <UContainer class="py-8">
    <div class="mb-6">
      <h1 class="text-xl font-semibold">
        {{ t('changelog.title') }}
      </h1>
      <p class="text-sm text-muted">
        {{ t('changelog.subtitle', { version: current }) }}
      </p>
    </div>

    <div class="max-w-3xl space-y-8">
      <section
        v-for="release in CHANGELOG"
        :key="release.version"
        class="space-y-3"
      >
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 border-b border-default pb-2">
          <h2 class="text-lg font-medium">
            {{ release.version }}
          </h2>

          <span
            v-if="release.date"
            class="text-sm text-muted"
          >{{ date(release.date) }}</span>
          <UBadge
            v-else
            size="sm"
            color="neutral"
            variant="subtle"
            :label="t('changelog.unreleased')"
          />

          <UBadge
            v-if="isCurrent(release)"
            size="sm"
            color="primary"
            variant="subtle"
            :label="t('changelog.installed')"
          />
        </div>

        <p
          v-if="release.summary"
          class="text-sm"
        >
          {{ text(release.summary) }}
        </p>

        <ul class="space-y-2">
          <li
            v-for="(entry, index) in release.entries"
            :key="index"
            class="flex gap-2 text-sm"
          >
            <UBadge
              class="mt-0.5 shrink-0 self-start"
              size="sm"
              variant="subtle"
              :color="kindColor[entry.kind]"
              :label="t(`changelog.kind.${entry.kind}`)"
            />
            <span>{{ text(entry) }}</span>
          </li>
        </ul>
      </section>

      <div class="flex flex-wrap gap-2 border-t border-default pt-4">
        <UButton
          to="/help"
          color="neutral"
          variant="outline"
          size="sm"
          icon="i-lucide-circle-question-mark"
          :label="t('changelog.toHelp')"
        />
        <UButton
          :to="`${config.public.repoUrl}/blob/main/CHANGELOG.md`"
          target="_blank"
          color="neutral"
          variant="ghost"
          size="sm"
          icon="i-simple-icons-github"
          :label="t('changelog.toRepo')"
        />
      </div>
    </div>
  </UContainer>
</template>
