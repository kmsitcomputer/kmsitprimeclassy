<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { isExternalHref, safeHref } from '@/utils/safeUrl'

/**
 * Hero banner. Every presentation option is optional, so a block saved before they existed
 * (image + heading + subheading + button only) still renders as `cover` under a dark overlay.
 *
 * Layout reacts to the block's own width (container queries), not the window, so the CMS preview frame
 * shows the real layout of each device:
 *
 *  - image_fit `cover` (default). The picture is an ordinary image: its HEIGHT FOLLOWS ITS OWN ASPECT
 *    RATIO, so nothing is cropped; only a very tall picture is clamped (28rem narrow / 34rem wide) and then
 *    `object-fit: cover` + `image_position` decide that small crop. Promo art with the logo, product or
 *    lettering near the edges therefore stays visible, unlike the old fixed 26-30rem box.
 *    The text goes on an overlay ONLY when the picture, at its own ratio and the block's current width, is
 *    tall enough to hold it (OVERLAY_MIN_HEIGHT) and the block is wide (>= 36rem); the overlay turns sideways
 *    from 56rem. Otherwise (phones, or a wide-but-short picture such as a 3:1 banner on a tablet) the picture
 *    is shown whole and the text sits on a brand panel BELOW it, never on the subject. Forcing a minimum
 *    height instead would crop the picture to make room for the text.
 *  - image_fit `contain`: for logos / packshots that must stay whole: shown uncropped on a brand panel,
 *    beside the text on large widths, above it on narrow ones. No overlay is needed.
 */
const props = defineProps<{
  content: {
    image_url?: string
    heading?: string
    subheading?: string
    cta_label?: string
    cta_url?: string
    image_fit?: 'cover' | 'contain'
    image_position?: 'center' | 'top' | 'bottom' | 'left' | 'right'
    overlay?: 'none' | 'light' | 'dark'
    align?: 'left' | 'center'
  }
}>()

// ---- does the text fit ON the picture? (only the picture's own proportions and the block width decide) ----
const OVERLAY_MIN_HEIGHT = 288 // px: heading + subheading + button + padding of the overlay
const root = ref<HTMLElement | null>(null)
const blockWidth = ref(0)
const ratio = ref(0) // natural width / height of the picture, once known
let ro: ResizeObserver | null = null
function readRatio(img: unknown) {
  if (img instanceof HTMLImageElement && img.naturalWidth > 0 && img.naturalHeight > 0) ratio.value = img.naturalWidth / img.naturalHeight
}
const measureWidth = () => { blockWidth.value = root.value?.getBoundingClientRect().width ?? 0 }
onMounted(() => {
  measureWidth()
  if (typeof ResizeObserver !== 'undefined' && root.value) {
    ro = new ResizeObserver(measureWidth)
    ro.observe(root.value)
  }
})
onBeforeUnmount(() => ro?.disconnect())

const hasImage = computed(() => !!props.content.image_url)
const contain = computed(() => hasImage.value && props.content.image_fit === 'contain')
const cover = computed(() => hasImage.value && !contain.value)
const overlay = computed(() => props.content.overlay ?? 'dark')
const centered = computed(() => props.content.align === 'center')
// Dark text only where a light wash sits on top of the photo, i.e. the wide overlay layout (see overlayFits).
const overlayFits = computed(() => cover.value && ratio.value > 0 && blockWidth.value / ratio.value >= OVERLAY_MIN_HEIGHT)
const darkText = computed(() => overlayFits.value && overlay.value === 'light')
const href = computed(() => (props.content.cta_url ? safeHref(props.content.cta_url) : '#'))
const external = computed(() => isExternalHref(href.value))

const POSITIONS: Record<string, string> = {
  center: 'object-center',
  top: 'object-top',
  bottom: 'object-bottom',
  left: 'object-left',
  right: 'object-right',
}
const positionClass = computed(() => POSITIONS[props.content.image_position ?? 'center'] ?? 'object-center')
</script>

<template>
  <div ref="root" class="@container">
    <section
      class="relative isolate overflow-hidden rounded-3xl bg-gradient-to-br from-brand-600 to-brand-800 text-white"
      :class="[
        contain
          ? 'grid @4xl:grid-cols-2 @xl:min-h-[26rem] @4xl:min-h-[30rem]'
          : cover
            ? 'flex flex-col'
            : 'flex min-h-[18rem] flex-col @xl:min-h-[26rem] @xl:flex-row @xl:items-center @4xl:min-h-[30rem]',
      ]"
    >
      <!-- cover: the picture at its own ratio (clamped only when extreme), overlay layers wide-only -->
      <template v-if="cover">
        <img
          :src="content.image_url"
          alt=""
          :ref="readRatio"
          class="block h-auto max-h-[28rem] w-full object-cover @xl:max-h-[34rem]"
          :class="positionClass"
          decoding="async"
          fetchpriority="high"
          @load="readRatio($event.target)"
        />
        <div
          v-if="overlayFits && overlay === 'dark'"
          class="absolute inset-0 hidden bg-gradient-to-t from-black/75 via-black/40 to-black/10 @xl:block @4xl:bg-gradient-to-r @4xl:from-black/70 @4xl:via-black/35 @4xl:to-transparent"
        />
        <div
          v-else-if="overlayFits && overlay === 'light'"
          class="absolute inset-0 hidden bg-gradient-to-t from-white/90 via-white/60 to-white/10 @xl:block @4xl:bg-gradient-to-r @4xl:from-white/90 @4xl:via-white/55 @4xl:to-transparent"
        />
      </template>

      <!-- contain: the whole picture beside (wide) / above (narrow) the text -->
      <div v-if="contain" class="order-first flex items-center justify-center p-8 @xl:p-10 @4xl:order-last @4xl:p-12">
        <img :src="content.image_url" alt="" class="max-h-56 w-full object-contain @xl:max-h-72 @4xl:max-h-96" :class="positionClass" decoding="async" />
      </div>

      <div
        class="relative z-0 flex w-full flex-col gap-3 px-6 py-8 @xl:gap-4 @xl:px-10 @xl:py-10 @4xl:px-14 @4xl:py-14"
        :class="[
          centered ? 'items-center text-center' : 'items-start text-left',
          contain ? 'justify-center' : '',
          overlayFits ? '@xl:absolute @xl:inset-0 @xl:max-w-3xl @xl:justify-center' : '',
          !overlayFits && !contain ? '@xl:max-w-3xl' : '',
          centered && overlayFits ? '@xl:inset-x-0 @xl:mx-auto' : '',
          darkText ? '@xl:text-stone-900' : '',
        ]"
      >
        <h1
          class="font-display text-balance text-2xl font-semibold leading-tight tracking-tight @xl:text-4xl @4xl:text-5xl"
          :class="overlayFits && overlay === 'none' ? '@xl:[text-shadow:0_2px_12px_rgb(0_0_0/0.55)]' : ''"
        >
          {{ content.heading }}
        </h1>
        <p
          v-if="content.subheading"
          class="max-w-xl text-pretty text-base leading-relaxed text-white/90 @xl:text-lg"
          :class="[darkText ? '@xl:text-stone-700' : '', overlayFits && overlay === 'none' ? '@xl:[text-shadow:0_1px_8px_rgb(0_0_0/0.55)]' : '']"
        >
          {{ content.subheading }}
        </p>
        <a
          v-if="content.cta_label && href"
          :href="href"
          :target="external ? '_blank' : undefined"
          :rel="external ? 'noopener noreferrer' : undefined"
          class="mt-1 inline-flex min-h-11 w-full items-center justify-center gap-1.5 rounded-full px-6 py-3 text-sm font-semibold shadow-lg shadow-black/10 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 @xl:mt-2 @xl:w-fit @xl:text-base"
          :class="darkText
            ? 'bg-white text-brand-700 hover:bg-brand-50 focus-visible:ring-white focus-visible:ring-offset-brand-800 @xl:bg-brand-600 @xl:text-white @xl:hover:bg-brand-700 @xl:focus-visible:ring-brand-600 @xl:focus-visible:ring-offset-white'
            : 'bg-white text-brand-700 hover:bg-brand-50 focus-visible:ring-white focus-visible:ring-offset-brand-800'"
        >
          {{ content.cta_label }}
        </a>
      </div>
    </section>
  </div>
</template>
