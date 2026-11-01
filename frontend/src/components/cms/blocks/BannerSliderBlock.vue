<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isExternalHref, safeHref } from '@/utils/safeUrl'

/**
 * Banner slider (CMS block `banner_slider`). Dependency-free on purpose: the project has no slider
 * library, and what is needed — translate-based track, timer, arrows, dots, swipe — is small.
 *
 * Layout reacts to the slider's own width (container queries) so the CMS preview frame can show the real
 * mobile layout. Narrow (< 36rem, phones): the picture is an ordinary image at its own aspect ratio (the
 * mobile image when there is one, else the desktop image): never cropped, stretched, letterboxed or
 * padded with filler. The carousel is exactly as tall as the active slide; the text sits on a panel BELOW
 * the picture and the controls overlay the bottom of the picture only.
 * Wide (>= 36rem): the desktop image is ALSO an ordinary image whose height follows its own ratio (so a
 * 3:1 or 21:9 banner is shown whole); only an extreme ratio is clamped (max 36rem tall; min 15rem when the
 * slide has text, so the overlay text always fits) and `cover` then decides that small crop. Text sits on
 * an overlay over the picture; the arrows overlay its sides.
 *
 * Accessibility: WAI-ARIA carousel pattern (region + roledescription, one labelled group per slide,
 * off-screen slides are `inert`), a visible pause/play control whenever autoplay is on, autoplay stops
 * while the pointer is over or focus is inside the slider and while the tab is hidden, arrow keys /
 * Home / End navigate when the slider has focus, and `prefers-reduced-motion` disables autoplay and
 * the slide transition.
 */
export interface SliderSlide {
  key?: string
  image_url?: string | null
  mobile_image_url?: string | null
  alt?: string | null
  title?: string | null
  description?: string | null
  button_label?: string | null
  button_url?: string | null
  overlay?: 'none' | 'light' | 'dark' | null
  content_position?: 'left' | 'center' | 'right' | null
}

const props = defineProps<{
  content: {
    layout?: 'contained' | 'wide'
    autoplay?: boolean | number | string
    interval_seconds?: number | string
    show_arrows?: boolean | number | string
    show_dots?: boolean | number | string
    slides?: SliderSlide[]
  }
  /** CMS preview only: force the image the given device would get (container queries already pick the layout). */
  previewDevice?: 'mobile' | 'desktop'
}>()

// The admin API echoes whatever was submitted ("1"/"0"), the public feed too: accept all truthy shapes.
const flag = (v: unknown, fallback: boolean) => (v === undefined || v === null ? fallback : !(v === false || v === 0 || v === '0' || v === 'false' || v === ''))

const slides = computed(() => (props.content.slides ?? []).filter((s) => !!s.image_url))
const count = computed(() => slides.value.length)
const wide = computed(() => props.content.layout === 'wide')
const showArrows = computed(() => flag(props.content.show_arrows, true) && count.value > 1)
const showDots = computed(() => flag(props.content.show_dots, true) && count.value > 1)
const intervalMs = computed(() => Math.min(10, Math.max(3, Number(props.content.interval_seconds) || 5)) * 1000)

const reducedMotion = ref(false)
const autoplayWanted = computed(() => flag(props.content.autoplay, false) && count.value > 1)
const canAutoplay = computed(() => autoplayWanted.value && !reducedMotion.value)

const current = ref(0)
const hovered = ref(false)
const focused = ref(false)
const tabHidden = ref(false)
const userPaused = ref(false)
const running = computed(() => canAutoplay.value && !hovered.value && !focused.value && !tabHidden.value && !userPaused.value)

let timer: ReturnType<typeof setTimeout> | null = null
function clearTimer() {
  if (timer) clearTimeout(timer)
  timer = null
}
function arm() {
  clearTimer()
  if (running.value) timer = setTimeout(() => go(current.value + 1), intervalMs.value)
}

function go(index: number) {
  if (!count.value) return
  current.value = ((index % count.value) + count.value) % count.value
}
const next = () => go(current.value + 1)
const prev = () => go(current.value - 1)

// Re-arm on any change that affects the schedule (slide change re-starts the full interval).
watch([current, running, intervalMs, count], arm)
watch(count, (n) => {
  if (current.value >= n) current.value = 0
})

let mql: MediaQueryList | null = null
const onMotion = () => { reducedMotion.value = !!mql?.matches }
const onVisibility = () => { tabHidden.value = document.hidden }

onMounted(() => {
  if (typeof window !== 'undefined' && window.matchMedia) {
    mql = window.matchMedia('(prefers-reduced-motion: reduce)')
    onMotion()
    mql.addEventListener?.('change', onMotion)
  }
  document.addEventListener('visibilitychange', onVisibility)
  onVisibility()
  // Measure synchronously first: the observer reports one frame later, which would paint the tallest slide's
  // natural height for a frame (a visible jump on warm-cache loads).
  measureAll()
  if (typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(onResize)
    observed.forEach((_, el) => ro!.observe(el))
  }
  arm()
})
onBeforeUnmount(() => {
  ro?.disconnect()
  ro = null
  observed.clear()
  clearTimer()
  mql?.removeEventListener?.('change', onMotion)
  document.removeEventListener('visibilitychange', onVisibility)
})

// ---- Height follows the picture (narrow layout) -------------------------------------------------------
// Nothing forces a ratio on a slide: its picture is a normal `h-auto` image, so a 3:1 banner is 3:1 and a
// 3:4 mobile asset is 3:4. All slides sit in one row (`items-start`), so each keeps its OWN height and the
// carousel box takes the ACTIVE slide's height (animated, none under reduced motion). That removes empty
// bands for every ratio; slides that differ in ratio change the height when they change, which is the
// honest cost of not cropping. Until a picture has loaded its box reserves a 21:9 placeholder, so the
// page does not jump when the usual banner arrives. (Wide layout: fixed 21:9 banner, measured the same.)
const slideH = ref<number[]>([])
const pictureH = ref<number[]>([])
const loaded = ref<Record<string, boolean>>({})
const activeH = computed(() => slideH.value[current.value] || 0)
const activePictureH = computed(() => pictureH.value[current.value] || 0)
const slideKey = (s: SliderSlide, i: number) => s.key ?? String(i)
// A picture finishing (or failing) to load is precisely what changes a slide's height, so re-measure right
// after Vue has patched the DOM instead of relying only on the observer's delivery timing.
const markLoaded = (s: SliderSlide, i: number) => {
  loaded.value[slideKey(s, i)] = true
  void nextTick(measureAll)
}
function checkComplete(el: unknown, s: SliderSlide, i: number) {
  if (el instanceof HTMLImageElement && el.complete && !loaded.value[slideKey(s, i)]) markLoaded(s, i)
}

let ro: ResizeObserver | null = null
const observed = new Map<Element, { kind: 's' | 'p'; i: number }>()
function track(kind: 's' | 'p', i: number) {
  return (el: unknown) => {
    if (!(el instanceof HTMLElement)) return
    observed.set(el, { kind, i })
    ro?.observe(el)
  }
}
function measure(el: Element) {
  const meta = observed.get(el)
  if (!meta) return
  const h = Math.round(el.getBoundingClientRect().height * 100) / 100
  const list = meta.kind === 's' ? slideH : pictureH
  if (list.value[meta.i] !== h) {
    const copy = list.value.slice()
    copy[meta.i] = h
    list.value = copy
  }
}
function measureAll() {
  observed.forEach((_, el) => measure(el))
}
function onResize(entries: ResizeObserverEntry[]) {
  for (const entry of entries) measure(entry.target)
}
watch(current, () => void nextTick(measureAll))

function onKeydown(e: KeyboardEvent) {
  if (count.value < 2) return
  if (e.key === 'ArrowRight') { e.preventDefault(); next() }
  else if (e.key === 'ArrowLeft') { e.preventDefault(); prev() }
  else if (e.key === 'Home') { e.preventDefault(); go(0) }
  else if (e.key === 'End') { e.preventDefault(); go(count.value - 1) }
}
function onFocusOut(e: FocusEvent) {
  const root = e.currentTarget as HTMLElement
  if (!root.contains(e.relatedTarget as Node | null)) focused.value = false
}

// Touch swipe (horizontal intent only, so vertical page scroll is never hijacked).
let touchX = 0
let touchY = 0
function onTouchStart(e: TouchEvent) {
  touchX = e.touches[0]?.clientX ?? 0
  touchY = e.touches[0]?.clientY ?? 0
}
function onTouchEnd(e: TouchEvent) {
  const t = e.changedTouches[0]
  if (!t) return
  const dx = t.clientX - touchX
  const dy = t.clientY - touchY
  if (Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) (dx < 0 ? next : prev)()
}

const ALIGN: Record<string, string> = {
  left: 'items-start text-left',
  center: 'items-center text-center',
  right: 'items-end text-right',
}
const JUSTIFY: Record<string, string> = { left: '@xl:justify-start', center: '@xl:justify-center', right: '@xl:justify-end' }

// Must match the narrow/wide switch of the layout: a page container is the viewport minus its side padding
// (px-4 below 640px) and the layout turns wide at 36rem of container width, i.e. a 608px viewport.
const MOBILE_IMAGE_MEDIA = '(max-width: 607px)'
const showPictureSource = (s: SliderSlide) => !props.previewDevice && !!s.mobile_image_url
const imageSrc = (s: SliderSlide) => (props.previewDevice === 'mobile' ? s.mobile_image_url || s.image_url : s.image_url) ?? undefined
const position = (s: SliderSlide) => s.content_position ?? 'left'
const hasText = (s: SliderSlide) => !!(s.title || s.description || (s.button_label && safeHref(s.button_url)))
const onLight = (s: SliderSlide) => (s.overlay ?? 'dark') === 'light'
</script>

<template>
  <div v-if="count" class="@container">
    <section
      class="group/slider relative select-none outline-none"
      :class="wide ? 'w-full' : 'mx-auto w-full max-w-5xl'"
      role="region"
      aria-roledescription="carousel"
      aria-label="Banner"
      tabindex="0"
      @keydown="onKeydown"
      @mouseenter="hovered = true"
      @mouseleave="hovered = false"
      @focusin="focused = true"
      @focusout="onFocusOut"
      @touchstart.passive="onTouchStart"
      @touchend.passive="onTouchEnd"
    >
      <!-- The box is as tall as the ACTIVE slide (measured); `overflow-hidden` clips the taller/shorter neighbours. -->
      <div
        class="relative overflow-hidden bg-stone-100 dark:bg-stone-900"
        :class="[wide ? 'rounded-2xl' : 'rounded-3xl', reducedMotion || !activeH ? '' : 'transition-[height] duration-300 ease-out']"
        :style="activeH ? { height: activeH + 'px' } : undefined"
      >
        <div
          class="flex items-start"
          :class="reducedMotion ? '' : 'transition-transform duration-500 ease-out'"
          :style="{ transform: `translateX(-${current * 100}%)` }"
          aria-live="off"
          :aria-atomic="false"
        >
          <div
            v-for="(slide, i) in slides"
            :key="slide.key ?? i"
            :ref="track('s', i)"
            class="relative flex w-full min-w-0 shrink-0 flex-col @xl:block"
            :class="onLight(slide) ? 'bg-white @xl:bg-transparent' : 'bg-stone-900 @xl:bg-transparent'"
            role="group"
            aria-roledescription="slide"
            :aria-label="`${i + 1} / ${count}`"
            :aria-hidden="i !== current"
            :inert="i !== current ? true : undefined"
          >
            <!-- picture: its own aspect ratio on narrow widths (21:9 placeholder only until it has loaded),
                 the whole 21:9 banner on wide ones -->
            <div
              :ref="track('p', i)"
              class="relative w-full"
              :class="loaded[slideKey(slide, i)] ? '' : 'aspect-[21/9]'"
            >
              <picture class="block">
                <source v-if="showPictureSource(slide)" :media="MOBILE_IMAGE_MEDIA" :srcset="slide.mobile_image_url ?? undefined" />
                <img
                  :ref="(el) => checkComplete(el, slide, i)"
                  :src="imageSrc(slide)"
                  :alt="slide.alt ?? slide.title ?? ''"
                  class="block h-auto w-full @xl:max-h-[36rem] @xl:object-cover"
                  :class="hasText(slide) ? '@xl:min-h-[15rem]' : ''"
                  :loading="i === 0 ? 'eager' : 'lazy'"
                  :fetchpriority="i === 0 ? 'high' : 'auto'"
                  decoding="async"
                  draggable="false"
                  @load="markLoaded(slide, i)"
                  @error="markLoaded(slide, i)"
                />
              </picture>
            </div>

            <template v-if="hasText(slide)">
              <div
                v-if="(slide.overlay ?? 'dark') === 'dark'"
                class="absolute inset-0 hidden bg-gradient-to-r from-black/65 via-black/25 to-transparent @xl:block"
                :class="position(slide) === 'right' ? '@xl:bg-gradient-to-l' : ''"
              />
              <div
                v-else-if="slide.overlay === 'light'"
                class="absolute inset-0 hidden bg-gradient-to-r from-white/85 via-white/45 to-transparent @xl:block"
                :class="position(slide) === 'right' ? '@xl:bg-gradient-to-l' : ''"
              />
              <!-- text: a panel under the picture on narrow widths, centred on the overlay on wide ones.
                   The wide side padding keeps it clear of the arrow buttons. -->
              <div class="flex px-5 pb-6 pt-4 @xl:absolute @xl:inset-0 @xl:items-center @xl:px-16 @xl:py-6" :class="JUSTIFY[position(slide)]">
                <div class="flex w-full flex-col gap-2 @xl:w-auto @xl:max-w-xl @xl:gap-3" :class="[ALIGN[position(slide)], onLight(slide) ? 'text-stone-900' : 'text-white']">
                  <h2 v-if="slide.title" class="font-display text-balance text-xl font-semibold leading-tight @xl:text-3xl @4xl:text-4xl" :class="slide.overlay === 'none' ? '@xl:[text-shadow:0_2px_12px_rgb(0_0_0/0.55)]' : ''">
                    {{ slide.title }}
                  </h2>
                  <p v-if="slide.description" class="line-clamp-3 text-pretty text-sm @xl:text-base" :class="[onLight(slide) ? 'text-stone-700' : 'text-white/90', slide.overlay === 'none' ? '@xl:[text-shadow:0_1px_8px_rgb(0_0_0/0.55)]' : '']">
                    {{ slide.description }}
                  </p>
                  <a
                    v-if="slide.button_label && safeHref(slide.button_url)"
                    :href="safeHref(slide.button_url)"
                    :target="isExternalHref(safeHref(slide.button_url)) ? '_blank' : undefined"
                    :rel="isExternalHref(safeHref(slide.button_url)) ? 'noopener noreferrer' : undefined"
                    class="mt-1 inline-flex min-h-11 w-fit items-center rounded-full px-5 py-2 text-sm font-semibold shadow-md transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                    :class="onLight(slide) ? 'bg-brand-600 text-white hover:bg-brand-700 focus-visible:ring-brand-600' : 'bg-white text-brand-700 hover:bg-brand-50 focus-visible:ring-white'"
                  >
                    {{ slide.button_label }}
                  </a>
                </div>
              </div>
            </template>
          </div>
        </div>

        <template v-if="showArrows">
          <button
            type="button"
            class="absolute left-3 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-stone-900 shadow transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 @xl:flex"
            aria-label="Slide sebelumnya"
            @click="prev"
          >
            <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.5 4.5 7 10l5.5 5.5" /></svg>
          </button>
          <button
            type="button"
            class="absolute right-3 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/85 text-stone-900 shadow transition hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600 @xl:flex"
            aria-label="Slide berikutnya"
            @click="next"
          >
            <svg viewBox="0 0 20 20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7.5 4.5 5.5 5.5-5.5 5.5" /></svg>
          </button>
        </template>

        <!-- controls: on narrow widths a layer exactly as tall as the ACTIVE picture (full box until measured),
             so they sit on the bottom of the picture and never on the text panel -->
        <div
          v-if="showDots || autoplayWanted"
          class="pointer-events-none absolute inset-x-0 top-0 flex h-[var(--picture-h,100%)] items-end justify-center gap-2 px-3 pb-2 @xl:bottom-3 @xl:top-auto @xl:h-auto @xl:pb-0"
          :style="activePictureH ? { '--picture-h': activePictureH + 'px' } : undefined"
        >
          <button
            v-if="autoplayWanted"
            type="button"
            class="pointer-events-auto flex h-8 w-8 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/65 focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
            :aria-label="userPaused || reducedMotion ? 'Putar slide otomatis' : 'Jeda slide otomatis'"
            :disabled="reducedMotion"
            :aria-pressed="userPaused || reducedMotion"
            @click="userPaused = !userPaused"
          >
            <svg v-if="userPaused || reducedMotion" viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="currentColor" aria-hidden="true"><path d="M6 4.5v11l9-5.5z" /></svg>
            <svg v-else viewBox="0 0 20 20" class="h-3.5 w-3.5" fill="currentColor" aria-hidden="true"><path d="M5.5 4h3.2v12H5.5zM11.3 4h3.2v12h-3.2z" /></svg>
          </button>
          <div v-if="showDots" class="pointer-events-auto flex items-center gap-1.5 rounded-full bg-black/45 px-2.5 py-2" role="group" aria-label="Pilih slide">
            <button
              v-for="(slide, i) in slides"
              :key="`dot-${slide.key ?? i}`"
              type="button"
              class="h-2 rounded-full transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-white"
              :class="i === current ? 'w-5 bg-white' : 'w-2 bg-white/55 hover:bg-white/80'"
              :aria-label="`Slide ${i + 1} dari ${count}`"
              :aria-current="i === current ? 'true' : undefined"
              @click="go(i)"
            />
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
