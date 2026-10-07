<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSiteStore } from '@/stores/site'
import AppIcon from '@/components/ui/AppIcon.vue'
import { usePwaInstall } from '@/pwa/installPrompt'

/**
 * Permanent public PWA installation landing page (`/install`).
 *
 * Shareable URL for buttons, QR codes, WhatsApp messages and marketing.
 * NOT an APK/download page — it only drives the browser-native install
 * flow through the central engine in `@/pwa/installPrompt.ts`:
 * - Android/Chromium with a captured `beforeinstallprompt` → CTA invokes
 *   the saved native prompt on explicit click (never auto-triggered).
 * - iOS/iPadOS (no `beforeinstallprompt`) → localized manual
 *   "Share → Add to Home Screen" instructions, always visible, no toggle.
 * - Already installed / standalone → installed state only, no CTA.
 * - Anything else → honest explanatory note, no fake success/download.
 */

const { t } = useI18n()
const site = useSiteStore()
const { canInstall, showIosInstallOption, isInstalled, isStandalone, promptInstall } =
  usePwaInstall()

const installing = ref(false)

async function onInstall() {
  if (installing.value || !canInstall.value) return
  installing.value = true
  try {
    await promptInstall()
  } finally {
    installing.value = false
  }
}
</script>

<template>
  <main class="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center px-6 py-16 text-center">
    <img
      v-if="site.logoUrl"
      :src="site.logoUrl"
      :alt="site.siteTitle"
      class="mb-6 h-14 w-auto object-contain"
    />
    <div
      v-else
      class="mb-6 flex h-14 w-14 items-center justify-center rounded-2xl bg-[#8f1d3c] text-xl font-bold text-white"
      aria-hidden="true"
    >
      PC
    </div>
    <h1 class="text-xl font-bold text-stone-900 dark:text-stone-100">
      {{ site.siteTitle || 'PrimeClassy Cake & Cookies' }}
    </h1>
    <p class="mt-2 text-sm text-stone-600 dark:text-stone-300">
      {{ t('pwa.installPageSubtitle') }}
    </p>

    <template v-if="isInstalled || isStandalone">
      <p role="status" class="mt-6 rounded-xl bg-stone-100 px-4 py-3 text-sm font-medium text-stone-700 dark:bg-stone-800 dark:text-stone-200">
        {{ t('pwa.installAlreadyInstalled') }}
      </p>
    </template>

    <template v-else-if="canInstall">
      <button
        type="button"
        :disabled="installing"
        class="mt-6 inline-flex items-center gap-2 rounded-full bg-[#8f1d3c] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#7a1834] disabled:opacity-60"
        @click="onInstall"
      >
        <AppIcon name="download" :size="18" />
        {{ t('pwa.installApp') }}
      </button>
    </template>

    <template v-else-if="showIosInstallOption">
      <div class="mt-6 w-full rounded-2xl border border-stone-200 bg-white p-4 text-left dark:border-stone-700 dark:bg-stone-900">
        <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">
          {{ t('pwa.installGuideTitle') }}
        </p>
        <p class="mt-1 text-sm leading-relaxed text-stone-600 dark:text-stone-300">
          {{ t('pwa.installGuideMessage') }}
        </p>
      </div>
    </template>

    <template v-else>
      <p role="status" class="mt-6 rounded-xl bg-stone-100 px-4 py-3 text-sm text-stone-600 dark:bg-stone-800 dark:text-stone-300">
        {{ t('pwa.installUnavailableNote') }}
      </p>
    </template>

    <RouterLink
      :to="{ name: 'home' }"
      class="mt-6 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
    >
      {{ t('pwa.installBackHome') }}
    </RouterLink>
  </main>
</template>
