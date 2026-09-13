<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useLocaleStore } from '@/stores/locale'
import { SUPPORTED_LOCALES, type LocaleCode } from '@/i18n'
import AppIcon from '@/components/ui/AppIcon.vue'

const { t } = useI18n()
const locale = useLocaleStore()
const open = ref(false)
const rootEl = ref<HTMLDivElement | null>(null)

function choose(code: LocaleCode) {
  locale.setLocale(code)
  open.value = false
}

function onClickOutside(e: MouseEvent) {
  if (rootEl.value && !rootEl.value.contains(e.target as Node)) open.value = false
}

document.addEventListener('click', onClickOutside)
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside))
</script>

<template>
  <div ref="rootEl" class="relative">
    <button
      type="button"
      class="grid h-10 w-10 place-items-center rounded-full text-stone-500 hover:bg-stone-100 dark:text-stone-400 dark:hover:bg-stone-800"
      :aria-label="t('nav.language')"
      @click="open = !open"
    >
      <AppIcon name="globe" :size="19" />
    </button>

    <div
      v-if="open"
      class="absolute end-0 z-40 mt-2 w-44 overflow-hidden rounded-xl border border-stone-200 bg-white py-1 shadow-lg dark:border-stone-800 dark:bg-stone-900"
    >
      <button
        v-for="opt in SUPPORTED_LOCALES"
        :key="opt.code"
        type="button"
        class="flex w-full items-center justify-between px-3.5 py-2 text-start text-sm"
        :class="opt.code === locale.locale ? 'bg-brand-50 font-medium text-brand-700 dark:bg-brand-950 dark:text-brand-300' : 'text-stone-600 hover:bg-stone-50 dark:text-stone-300 dark:hover:bg-stone-800'"
        @click="choose(opt.code)"
      >
        {{ opt.name }}
        <AppIcon v-if="opt.code === locale.locale" name="check" :size="14" />
      </button>
    </div>
  </div>
</template>
