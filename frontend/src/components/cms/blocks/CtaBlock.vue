<script setup lang="ts">
import { computed } from 'vue'
import { isExternalHref, safeHref } from '@/utils/safeUrl'

/**
 * Call-to-action band. Generic on purpose: heading + button are required, a short description is
 * optional, and `theme` picks the colour treatment (default `dark` keeps the look of blocks saved
 * before themes existed, only with better spacing). With a description and left alignment the text
 * and the button sit side by side on wide screens; centered / description-less CTAs stack.
 */
const props = defineProps<{
  content: {
    heading?: string
    description?: string
    button_label?: string
    button_url?: string
    theme?: 'brand' | 'dark' | 'light' | 'sand'
    align?: 'left' | 'center'
  }
}>()

const THEMES = {
  brand: {
    panel: 'bg-gradient-to-br from-brand-600 to-brand-800 text-white',
    description: 'text-white/85',
    button: 'bg-white text-brand-700 hover:bg-brand-50 focus-visible:ring-white focus-visible:ring-offset-brand-800',
    glow: 'bg-white/10',
  },
  dark: {
    panel: 'bg-stone-900 text-white dark:bg-stone-800',
    description: 'text-stone-300',
    button: 'bg-brand-500 text-white hover:bg-brand-600 focus-visible:ring-brand-400 focus-visible:ring-offset-stone-900',
    glow: 'bg-brand-500/20',
  },
  light: {
    panel: 'border border-stone-200 bg-white text-stone-900 dark:border-stone-800 dark:bg-stone-900 dark:text-stone-50',
    description: 'text-stone-600 dark:text-stone-300',
    button: 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:ring-brand-600 focus-visible:ring-offset-white dark:focus-visible:ring-offset-stone-900',
    glow: 'bg-brand-500/10',
  },
  sand: {
    panel: 'bg-stone-100 text-stone-900 dark:bg-stone-800 dark:text-stone-50',
    description: 'text-stone-600 dark:text-stone-300',
    button: 'bg-stone-900 text-white hover:bg-stone-700 focus-visible:ring-stone-900 focus-visible:ring-offset-stone-100 dark:bg-white dark:text-stone-900 dark:hover:bg-stone-200',
    glow: 'bg-brand-500/10',
  },
} as const

const theme = computed(() => THEMES[props.content.theme ?? 'dark'] ?? THEMES.dark)
const centered = computed(() => props.content.align === 'center')
const sideBySide = computed(() => !centered.value && !!props.content.description)
const href = computed(() => safeHref(props.content.button_url))
const external = computed(() => isExternalHref(href.value))
</script>

<template>
  <section class="relative isolate overflow-hidden rounded-3xl px-5 py-6 sm:px-10 sm:py-12 lg:px-14" :class="theme.panel">
    <!-- soft decorative glow; purely visual -->
    <div aria-hidden="true" class="pointer-events-none absolute -right-16 -top-20 -z-10 h-64 w-64 rounded-full blur-3xl" :class="theme.glow" />
    <div aria-hidden="true" class="pointer-events-none absolute -bottom-24 -left-12 -z-10 h-56 w-56 rounded-full blur-3xl" :class="theme.glow" />

    <div
      class="mx-auto flex w-full max-w-5xl flex-col gap-4 sm:gap-6"
      :class="[
        centered ? 'items-center text-center' : 'items-start text-left',
        sideBySide ? 'md:flex-row md:items-center md:justify-between md:gap-10' : '',
      ]"
    >
      <div class="flex flex-col gap-2 sm:gap-3" :class="centered ? 'items-center' : 'items-start'">
        <h2 class="font-display text-balance text-xl font-semibold leading-tight tracking-tight sm:text-3xl lg:text-4xl" :class="centered ? 'max-w-3xl' : 'max-w-2xl'">
          {{ content.heading }}
        </h2>
        <p v-if="content.description" class="max-w-xl text-pretty text-sm leading-relaxed sm:text-base" :class="theme.description">
          {{ content.description }}
        </p>
      </div>

      <a
        v-if="content.button_label && href"
        :href="href"
        :target="external ? '_blank' : undefined"
        :rel="external ? 'noopener noreferrer' : undefined"
        class="inline-flex min-h-11 w-fit shrink-0 items-center justify-center rounded-full px-5 py-2.5 text-sm font-semibold shadow-lg shadow-black/10 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 sm:w-auto sm:px-7 sm:py-3 sm:text-base"
        :class="theme.button"
      >
        {{ content.button_label }}
      </a>
    </div>
  </section>
</template>
