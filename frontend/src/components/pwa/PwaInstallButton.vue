<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import AppIcon from '@/components/ui/AppIcon.vue'
import { usePwaInstall } from '@/pwa/installPrompt'

/**
 * User-facing PWA install action (PWA V1.1).
 *
 * - Renders NOTHING unless the native install prompt was captured
 *   (`canInstall`) or the conservative iOS guidance path applies.
 * - Hidden in standalone mode / after `appinstalled` (handled centrally
 *   in `@/pwa/installPrompt`).
 * - Native click invokes the saved `beforeinstallprompt`; on iOS it opens
 *   a small localized "Share → Add to Home Screen" instruction instead.
 *   Never an APK/download link.
 */

withDefaults(defineProps<{ variant?: 'header' | 'footer' }>(), { variant: 'header' })

const { t } = useI18n()
const {
  canInstall,
  showIosInstallOption,
  showInstallAction,
  showIosGuide,
  promptInstall,
  openIosGuide,
  closeIosGuide,
} = usePwaInstall()

const installing = ref(false)

async function onClick() {
  if (installing.value) return
  // iOS has no beforeinstallprompt — toggle the manual guidance panel.
  if (!canInstall.value && showIosInstallOption.value) {
    if (showIosGuide.value) closeIosGuide()
    else openIosGuide()
    return
  }
  installing.value = true
  try {
    await promptInstall()
  } finally {
    installing.value = false
  }
}
</script>

<template>
  <div v-if="showInstallAction" class="relative shrink-0">
    <button
      v-if="variant === 'header'"
      type="button"
      :disabled="installing"
      class="flex h-10 items-center gap-1.5 rounded-full px-2.5 text-stone-500 hover:bg-stone-100 disabled:opacity-60 sm:px-3 dark:text-stone-400 dark:hover:bg-stone-800"
      :aria-label="t('pwa.installApp')"
      :title="t('pwa.installApp')"
      @click="onClick"
    >
      <AppIcon name="download" :size="19" />
      <span class="hidden text-xs font-medium md:inline">{{ t('pwa.installApp') }}</span>
    </button>
    <button
      v-else
      type="button"
      :disabled="installing"
      class="inline-flex items-center gap-1.5 text-xs hover:text-stone-600 hover:underline disabled:opacity-60 dark:hover:text-stone-300"
      @click="onClick"
    >
      <AppIcon name="download" :size="14" />
      {{ t('pwa.installApp') }}
    </button>

    <div
      v-if="showIosGuide"
      role="dialog"
      :aria-label="t('pwa.installGuideTitle')"
      class="absolute right-0 top-full z-40 mt-2 w-64 rounded-xl border border-stone-200 bg-white p-3 text-left shadow-lg dark:border-stone-700 dark:bg-stone-900"
    >
      <p class="text-sm font-semibold text-stone-800 dark:text-stone-100">
        {{ t('pwa.installGuideTitle') }}
      </p>
      <p class="mt-1 text-xs leading-relaxed text-stone-600 dark:text-stone-300">
        {{ t('pwa.installGuideMessage') }}
      </p>
      <button
        type="button"
        class="mt-2 rounded-full bg-stone-100 px-3 py-1 text-xs font-medium text-stone-700 hover:bg-stone-200 dark:bg-stone-800 dark:text-stone-200 dark:hover:bg-stone-700"
        @click="closeIosGuide"
      >
        {{ t('common.close') }}
      </button>
    </div>
  </div>
</template>
